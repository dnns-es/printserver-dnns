<?php
/**
 * Revalidacion periodica de licencia contra Passkey DNNS.
 *   0 * * * * /usr/bin/php /var/www/printserver/license-revalidate.php >> /var/log/license-revalidate.log 2>&1
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/license.php';

$lic = getCurrentLicense();
$hwId = getHardwareId();
$passkeyUrl = defined('PASSKEY_URL') ? PASSKEY_URL : 'https://passkey.dnns.es';
$ts = date('Y-m-d H:i:s');

function _validarPasskey($codigo, $hwId, $passkeyUrl) {
    $ch = curl_init($passkeyUrl . '/api/solicitudes/validar');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['codigo' => $codigo, 'hw_id' => $hwId, 'producto' => 'printserver']),
        CURLOPT_TIMEOUT => 15, CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $resp = curl_exec($ch); $http = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($http !== 200) return ['_http' => $http];
    return json_decode($resp, true) ?: ['_http' => $http];
}

// Caso A: licencia Free -> buscar si algun codigo historico sigue siendo valido
if ($lic['tipo'] === 'free') {
    $codigos = db()->query("SELECT DISTINCT activation_code FROM licencia WHERE activation_code IS NOT NULL AND activation_code <> '' ORDER BY id DESC")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($codigos as $codigo) {
        $d = _validarPasskey($codigo, $hwId, $passkeyUrl);
        if (!empty($d['ok']) && ($d['estado'] ?? '') === 'valid') {
            echo "[$ts] Recuperando licencia {$d['plan']} con codigo $codigo\n";
            $r = activateLicense($codigo);
            if (!empty($r['ok'])) { logActivity('info', "Licencia ${d['plan']} recuperada automaticamente"); exit(0); }
        }
    }
    exit(0);
}

if (empty($lic['activation_code'])) exit(0);

$d = _validarPasskey($lic['activation_code'], $hwId, $passkeyUrl);
if (isset($d['_http']) && !isset($d['ok'])) {
    echo "[$ts] Passkey unreachable (HTTP ${d['_http']}) - keeping current license\n";
    exit(0);
}

if (!empty($d['ok']) && ($d['estado'] ?? '') === 'valid') {
    // Sincronizar expires_at si cambio
    $nuevoExp = !empty($d['expires_at']) ? date('Y-m-d H:i:s', strtotime($d['expires_at'])) : null;
    if (($lic['expires_at'] ?: null) !== $nuevoExp) {
        db()->prepare("UPDATE licencia SET expires_at = ? WHERE id = ?")->execute([$nuevoExp, $lic['id']]);
        echo "[$ts] expires_at sincronizado: $nuevoExp\n";
    } else {
        echo "[$ts] Licencia valida ({$d['plan']})\n";
    }
    exit(0);
}

$motivo = $d['estado'] ?? 'unknown';
$msg = $d['mensaje'] ?? 'Sin detalle';
echo "[$ts] Licencia INVALIDA ($motivo): $msg - reseteando a Free\n";
resetLicenseToFree();
logActivity('error', "Licencia revocada/invalida ($motivo): $msg - resetada a Free");
exit(0);
