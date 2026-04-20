<?php
/**
 * Sistema de licencias del Print Server.
 * - Licencia Free por defecto (1 impresora, 1 usuario)
 * - Hardware ID para vincular licencia a hardware
 * - Bloqueos en add_printer/create_user cuando limite alcanzado
 * - Activacion via codigo (fase 1 local, fase 2 con Passkey DNNS)
 */

/** Hardware ID unico del server (machine-id + MAC) */
function getHardwareId() {
    static $hw = null;
    if ($hw !== null) return $hw;
    $mid = @file_get_contents('/etc/machine-id') ?: '';
    $mac = '';
    if (is_callable('shell_exec')) {
        $mac = @shell_exec("ip -o link show | awk '/ether/ {print \$(NF-2); exit}' 2>/dev/null") ?: '';
    }
    $data = trim($mid) . '|' . trim($mac);
    $hw = substr(hash('sha256', $data), 0, 32);
    return $hw;
}

/** Planes predefinidos */
function licensePlans() {
    return [
        'free' => [
            'nombre' => 'Free',
            'max_impresoras' => 1,
            'max_usuarios' => 1,
            'descripcion' => 'Uso personal',
        ],
        'basic' => [
            'nombre' => 'Basic',
            'max_impresoras' => 3,
            'max_usuarios' => 5,
            'descripcion' => 'Oficina pequena',
        ],
        'pro' => [
            'nombre' => 'Pro',
            'max_impresoras' => 10,
            'max_usuarios' => 20,
            'descripcion' => 'Oficina mediana',
        ],
        'enterprise' => [
            'nombre' => 'Enterprise',
            'max_impresoras' => 999,
            'max_usuarios' => 999,
            'descripcion' => 'Sin limites practicos',
        ],
    ];
}

/** Obtener licencia actual (crea Free si no existe) */
function getCurrentLicense() {
    $row = db()->query("SELECT * FROM licencia ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        // Crear Free por defecto
        $plans = licensePlans();
        $free = $plans['free'];
        db()->prepare("INSERT INTO licencia (tipo, max_impresoras, max_usuarios, hw_id, activated_at) VALUES (?, ?, ?, ?, NOW())")
            ->execute(['free', $free['max_impresoras'], $free['max_usuarios'], getHardwareId()]);
        $row = db()->query("SELECT * FROM licencia ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    }
    return $row;
}

/** Uso actual: cuantas impresoras y usuarios hay */
function getLicenseUsage() {
    $printers = (int)db()->query("SELECT COUNT(*) FROM impresoras")->fetchColumn();
    $users = (int)db()->query("SELECT COUNT(*) FROM usuarios WHERE activo = 1")->fetchColumn();
    return ['impresoras' => $printers, 'usuarios' => $users];
}

/** Verifica si puede anadir mas impresoras */
function canAddPrinter() {
    $lic = getCurrentLicense();
    $usage = getLicenseUsage();
    return $usage['impresoras'] < (int)$lic['max_impresoras'];
}

/** Verifica si puede crear mas usuarios */
function canAddUser() {
    $lic = getCurrentLicense();
    $usage = getLicenseUsage();
    return $usage['usuarios'] < (int)$lic['max_usuarios'];
}

/** Mensaje de error por limite alcanzado */
function licenseErrorMsg($tipo) {
    $lic = getCurrentLicense();
    $plans = licensePlans();
    $actual = $plans[$lic['tipo']]['nombre'] ?? 'Free';
    return "Licencia $actual alcanzada ({$lic['max_'.$tipo]} $tipo max). Actualiza tu licencia para anadir mas.";
}

/** Validacion del codigo de activacion. FASE 1: local simple. FASE 2: Passkey DNNS */
function activateLicense($code) {
    $code = trim($code);
    if (!$code) return ['ok' => false, 'error' => 'Codigo vacio'];

    // Intentar parsear codigo. Formatos aceptados:
    // - FREE-XXXX         -> licencia free (reset)
    // - BASIC-XXXX        -> basic
    // - PRO-XXXX          -> pro
    // - ENTERPRISE-XXXX   -> enterprise
    // - JWT completo      -> fase 2 con Passkey (pendiente)

    $plans = licensePlans();
    $tipo = null;
    foreach (['enterprise','pro','basic','free'] as $t) {
        if (stripos($code, $t . '-') === 0) { $tipo = $t; break; }
    }

    if (!$tipo) {
        return ['ok' => false, 'error' => 'Formato invalido. Usa FREE-XXX, BASIC-XXX, PRO-XXX o ENTERPRISE-XXX'];
    }

    $plan = $plans[$tipo];
    $hwId = getHardwareId();
    $clienteMatch = [];
    preg_match('/[A-Z]+-(.+)/i', $code, $clienteMatch);
    $cliente = $clienteMatch[1] ?? '';

    // Consultar Passkey para obtener expires_at y validar
    $expiresAt = null;
    $passkeyUrl = defined('PASSKEY_URL') ? PASSKEY_URL : 'https://passkey.dnns.es';
    $ch = curl_init($passkeyUrl . '/api/solicitudes/validar');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['codigo' => $code, 'hw_id' => $hwId, 'producto' => 'printserver']),
        CURLOPT_TIMEOUT => 15, CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($http === 200) {
        $d = json_decode($resp, true);
        if (!empty($d['ok']) && ($d['estado'] ?? '') === 'valid') {
            if (!empty($d['plan'])) $tipo = $d['plan'];
            $plan = $plans[$tipo] ?? $plan;
            if (!empty($d['expires_at'])) $expiresAt = date('Y-m-d H:i:s', strtotime($d['expires_at']));
        } else {
            return ['ok' => false, 'error' => $d['mensaje'] ?? 'Licencia no valida en Passkey'];
        }
    } else {
        return ['ok' => false, 'error' => 'No se pudo contactar con Passkey (HTTP ' . $http . ')'];
    }

    db()->prepare("INSERT INTO licencia (tipo, max_impresoras, max_usuarios, hw_id, activation_code, cliente, activated_at, expires_at)
                   VALUES (?, ?, ?, ?, ?, ?, NOW(), ?)")
        ->execute([$tipo, $plan['max_impresoras'], $plan['max_usuarios'], $hwId, $code, $cliente, $expiresAt]);

    return ['ok' => true, 'license' => getCurrentLicense(), 'plan' => $plan];
}

/** Resetear a Free */
function resetLicenseToFree() {
    $plans = licensePlans();
    $free = $plans['free'];
    db()->prepare("INSERT INTO licencia (tipo, max_impresoras, max_usuarios, hw_id, activation_code, activated_at)
                   VALUES ('free', ?, ?, ?, NULL, NOW())")
        ->execute([$free['max_impresoras'], $free['max_usuarios'], getHardwareId()]);
    return ['ok' => true];
}

/**
 * Sincroniza el estado de la licencia con Passkey.
 * - Si free y hay historico de codigos, intenta reactivar
 * - Si activa, actualiza expires_at o resetea si es invalida
 * Devuelve ['synced'=>bool, 'mensaje'=>string].
 */
function syncLicenseWithPasskey() {
    $passkeyUrl = defined('PASSKEY_URL') ? PASSKEY_URL : 'https://passkey.dnns.es';
    $lic = getCurrentLicense();
    $hwId = getHardwareId();

    $validar = function($codigo) use ($passkeyUrl, $hwId) {
        $ch = curl_init($passkeyUrl . '/api/solicitudes/validar');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode(['codigo' => $codigo, 'hw_id' => $hwId, 'producto' => 'printserver']),
            CURLOPT_TIMEOUT => 10, CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $resp = curl_exec($ch); $http = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if ($http !== 200) return ['_http' => $http];
        return json_decode($resp, true) ?: ['_http' => $http];
    };

    if ($lic['tipo'] === 'free') {
        $codigos = db()->query("SELECT DISTINCT activation_code FROM licencia WHERE activation_code IS NOT NULL AND activation_code <> '' ORDER BY id DESC")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($codigos as $codigo) {
            $d = $validar($codigo);
            if (!empty($d['ok']) && ($d['estado'] ?? '') === 'valid') {
                $r = activateLicense($codigo);
                if (!empty($r['ok'])) return ['synced' => true, 'mensaje' => 'Licencia ' . $d['plan'] . ' recuperada'];
            }
        }
        return ['synced' => false, 'mensaje' => 'Free sin codigos validos'];
    }

    if (empty($lic['activation_code'])) return ['synced' => false, 'mensaje' => 'Sin codigo activo'];

    $d = $validar($lic['activation_code']);
    if (isset($d['_http']) && !isset($d['ok'])) return ['synced' => false, 'mensaje' => 'Passkey no accesible'];

    if (!empty($d['ok']) && ($d['estado'] ?? '') === 'valid') {
        $nuevoExp = !empty($d['expires_at']) ? date('Y-m-d H:i:s', strtotime($d['expires_at'])) : null;
        if (($lic['expires_at'] ?: null) !== $nuevoExp) {
            db()->prepare("UPDATE licencia SET expires_at = ? WHERE id = ?")->execute([$nuevoExp, $lic['id']]);
            return ['synced' => true, 'mensaje' => 'expires_at actualizado a ' . ($nuevoExp ?: 'NULL')];
        }
        return ['synced' => true, 'mensaje' => 'Licencia valida sin cambios'];
    }

    $motivo = $d['estado'] ?? 'unknown';
    resetLicenseToFree();
    if (function_exists('logActivity')) logActivity('error', "Licencia invalida ($motivo) - resetada a Free");
    return ['synced' => true, 'mensaje' => "Licencia invalida ($motivo) - reset a Free"];
}
