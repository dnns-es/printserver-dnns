<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/drivers.php';
require_once __DIR__ . '/ddns.php';
require_once __DIR__ . '/license.php';

// action=download_driver devuelve archivo binario, no JSON
$action = $_GET['action'] ?? $_POST['action'] ?? '';
if ($action !== 'download_driver') {
    header('Content-Type: application/json; charset=utf-8');
}

// Acciones PUBLICAS (sin login)
$publicActions = ['auth_login', 'auth_qr_consume', 'auth_qr_pending_create', 'auth_qr_status', 'server_time'];

// Si no es accion publica, requiere login
if (!in_array($action, $publicActions)) {
    requireLogin();
}

// Acciones que requieren admin
$adminActions = [
    'scan_network', 'add_printer', 'delete_printer', 'test_printer',
    'printer_defaults', 'cancel_job', 'cancel_all', 'set_quota',
    'register_cartridge_change',
    'fetch_drivers', 'upload_driver', 'delete_driver', 'fetch_driver_url',
    'list_users', 'create_user', 'update_user', 'delete_user',
    'list_audit_log', 'auth_qr_approve', 'auth_qr_cancel', 'auth_qr_info',
    'ddns_get', 'ddns_save', 'ddns_test',
    'system_info', 'list_timezones', 'set_timezone',
    'license_info', 'license_activate', 'license_reset',
    'license_request', 'license_check_request'
];
if (in_array($action, $adminActions)) requireAdmin();

switch ($action) {

    // === AUTH ===
    case 'auth_check':
        echo json_encode(['ok' => true, 'logged_in' => isLoggedIn(), 'user' => currentUser()]);
        exit;

    case 'server_time':
        echo json_encode([
            'ok' => true,
            'timestamp' => time(),
            'datetime' => date('Y-m-d H:i:s'),
            'timezone' => date_default_timezone_get(),
            'tz_offset' => date('P'),
        ]);
        exit;

    case 'license_info':
        requireAdmin();
        // Sync pasivo: si han pasado >60s desde el ultimo sync, revalida con Passkey
        $syncFile = '/tmp/printserver-last-sync';
        $last = is_file($syncFile) ? (int)file_get_contents($syncFile) : 0;
        if (time() - $last > 300) {
            @file_put_contents($syncFile, time());
            syncLicenseWithPasskey();
        }
        $lic = getCurrentLicense();
        $usage = getLicenseUsage();
        $plans = licensePlans();
        $currentPlan = $plans[$lic['tipo']] ?? $plans['free'];
        echo json_encode([
            'ok' => true,
            'licencia' => $lic,
            'plan' => $currentPlan,
            'usage' => $usage,
            'hardware_id' => getHardwareId(),
            'plans_available' => $plans,
        ]);
        exit;

    case 'license_activate':
        requireAdmin();
        $code = $_POST['code'] ?? '';
        $res = activateLicense($code);
        $cu = currentUser();
        if ($res['ok']) {
            auditLog($cu['id'], $cu['email'], 'license_activate', $code);
        } else {
            auditLog($cu['id'], $cu['email'], 'license_activate_failed', $code);
        }
        echo json_encode($res);
        exit;

    case 'license_reset':
        requireAdmin();
        $res = resetLicenseToFree();
        $cu = currentUser();
        auditLog($cu['id'], $cu['email'], 'license_reset', 'reset to Free');
        echo json_encode($res);
        exit;

    case 'license_request':
        requireAdmin();
        $plan = $_POST['plan'] ?? '';
        $duracion = max(1, min(12, (int)($_POST['duracion_meses'] ?? 1)));
        $email = $_POST['email'] ?? '';
        $empresa = $_POST['empresa'] ?? '';
        $telefono = $_POST['telefono'] ?? '';
        $notas = $_POST['notas'] ?? '';
        if (!in_array($plan, ['basic','pro','enterprise'])) {
            echo json_encode(['ok' => false, 'error' => 'Plan invalido']); exit;
        }
        $lic = getCurrentLicense();
        $passkeyUrl = defined('PASSKEY_URL') ? PASSKEY_URL : 'https://passkey.dnns.es';
        // Determinar tipo de cambio
        $order = ['free'=>0,'basic'=>1,'pro'=>2,'enterprise'=>3];
        $tipo = 'upgrade';
        if ($lic['tipo'] === $plan) $tipo = 'renovacion';
        elseif ($order[$plan] < $order[$lic['tipo']]) $tipo = 'downgrade';
        $payload = [
            'hw_id' => getHardwareId(),
            'producto' => 'printserver',
            'plan_solicitado' => $plan,
            'plan_actual' => $lic['tipo'],
            'duracion_meses' => $duracion,
            'tipo_cambio' => $tipo,
            'codigo_actual' => $lic['activation_code'] ?? null,
            'email' => $email,
            'nombre' => $_POST['nombre'] ?? currentUser()['nombre'],
            'empresa' => $empresa,
            'telefono' => $telefono,
            'notas' => $notas,
            'server_url' => (($_SERVER['HTTPS'] ?? 'off') === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'],
            'server_version' => 'v1',
        ];
        $ch = curl_init($passkeyUrl . '/api/solicitudes/crear');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $resp = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $data = json_decode($resp, true);
        if ($http !== 200 || !$data || !($data['ok'] ?? false)) {
            echo json_encode(['ok' => false, 'error' => $data['error'] ?? "HTTP $http"]); exit;
        }
        // Guardar solicitud_id en config para polling
        db()->prepare("INSERT INTO config (clave, valor) VALUES ('pending_request_id', ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)")
            ->execute([(string)$data['solicitud_id']]);
        db()->prepare("INSERT INTO config (clave, valor) VALUES ('pending_request_plan', ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)")
            ->execute([$plan]);
        $cu = currentUser();
        auditLog($cu['id'], $cu['email'], 'license_request', "plan=$plan solicitud=" . $data['solicitud_id']);
        echo json_encode($data);
        exit;

    case 'license_check_request':
        requireAdmin();
        $row = db()->query("SELECT valor FROM config WHERE clave='pending_request_id'")->fetch(PDO::FETCH_ASSOC);
        if (!$row) { echo json_encode(['ok' => true, 'no_pending' => true]); exit; }
        $id = (int)$row['valor'];
        $passkeyUrl = defined('PASSKEY_URL') ? PASSKEY_URL : 'https://passkey.dnns.es';
        $ch = curl_init($passkeyUrl . '/api/solicitudes/estado/' . $id);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_SSL_VERIFYPEER => false]);
        $resp = curl_exec($ch);
        curl_close($ch);
        $data = json_decode($resp, true);
        if (!$data) { echo json_encode(['ok' => false, 'error' => 'Sin respuesta de Passkey']); exit; }

        // Si aprobada y tenemos código, auto-activar
        if (($data['estado'] ?? '') === 'approved' && !empty($data['codigo'])) {
            $act = activateLicense($data['codigo']);
            if ($act['ok']) {
                // Notificar a Passkey que activamos
                $ch2 = curl_init($passkeyUrl . '/api/solicitudes/activada/' . $id);
                curl_setopt_array($ch2, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 5, CURLOPT_SSL_VERIFYPEER => false]);
                curl_exec($ch2); curl_close($ch2);
                db()->exec("DELETE FROM config WHERE clave IN ('pending_request_id','pending_request_plan')");
                $data['auto_activated'] = true;
            }
        }
        if (($data['estado'] ?? '') === 'rejected') {
            db()->exec("DELETE FROM config WHERE clave IN ('pending_request_id','pending_request_plan')");
        }
        echo json_encode($data);
        exit;

    case 'system_info':
        requireAdmin();
        $uptime = @shell_exec("uptime -p 2>/dev/null");
        $hostname = @gethostname();
        $load = @sys_getloadavg();
        $disk = @disk_free_space('/');
        $diskTotal = @disk_total_space('/');
        $memInfo = @file_get_contents('/proc/meminfo');
        $memTotal = $memFree = 0;
        if ($memInfo) {
            if (preg_match('/MemTotal:\s+(\d+)/', $memInfo, $m)) $memTotal = (int)$m[1] * 1024;
            if (preg_match('/MemAvailable:\s+(\d+)/', $memInfo, $m)) $memFree = (int)$m[1] * 1024;
        }
        $ntp = @shell_exec("timedatectl show --property=NTPSynchronized --value 2>/dev/null");
        echo json_encode([
            'ok' => true,
            'timestamp' => time(),
            'datetime' => date('Y-m-d H:i:s'),
            'timezone' => date_default_timezone_get(),
            'tz_offset' => date('P'),
            'tz_name_short' => date('T'),
            'php_version' => PHP_VERSION,
            'hostname' => $hostname,
            'uptime' => trim((string)$uptime),
            'load_avg' => $load,
            'disk_free' => $disk,
            'disk_total' => $diskTotal,
            'mem_total' => $memTotal,
            'mem_free' => $memFree,
            'ntp_sync' => trim((string)$ntp) === 'yes',
        ]);
        exit;

    case 'list_timezones':
        requireAdmin();
        $zones = DateTimeZone::listIdentifiers();
        echo json_encode(['ok' => true, 'timezones' => $zones]);
        exit;

    case 'set_timezone':
        requireAdmin();
        $tz = $_POST['timezone'] ?? '';
        if (!in_array($tz, DateTimeZone::listIdentifiers())) {
            echo json_encode(['ok' => false, 'error' => 'Zona horaria inválida']);
            exit;
        }
        $ret = null; $out = [];
        @exec('sudo timedatectl set-timezone ' . escapeshellarg($tz) . ' 2>&1', $out, $ret);
        $cu = currentUser();
        auditLog($cu['id'], $cu['email'], 'set_timezone', $tz);
        echo json_encode(['ok' => $ret === 0, 'output' => implode("\n", $out)]);
        exit;

    case 'auth_login':
        $email = $_POST['email'] ?? '';
        $password = $_POST['password'] ?? '';
        $res = authenticate($email, $password);
        echo json_encode($res);
        exit;

    case 'auth_logout':
        logout();
        echo json_encode(['ok' => true]);
        exit;

    case 'auth_qr_generate':
        $u = currentUser();
        $token = generateQrToken($u['id']);
        $base = (($_SERVER['HTTPS'] ?? 'off') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ? 'https://' : 'http://';
        $base .= $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['REQUEST_URI']), '/');
        $url = $base . '/login.php?qr=' . $token;
        auditLog($u['id'], $u['email'], 'qr_generate', $url);
        echo json_encode(['ok' => true, 'url' => $url, 'qr_img' => 'https://api.qrserver.com/v1/create-qr-code/?size=240x240&data=' . urlencode($url)]);
        exit;

    case 'auth_qr_consume':
        $token = $_GET['token'] ?? $_POST['token'] ?? '';
        if (consumeQrToken($token)) {
            header('Location: index.php');
            exit;
        }
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Token invalido o expirado']);
        exit;

    // === QR REVERSO: movil autoriza PC ===
    case 'auth_qr_pending_create':
        // login.php genera un token pendiente y muestra QR al PC
        $token = createPendingQrToken();
        $base = (($_SERVER['HTTPS'] ?? 'off') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ? 'https://' : 'http://';
        $base .= $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['REQUEST_URI']), '/');
        $url = $base . '/qr-approve.php?t=' . $token;
        echo json_encode([
            'ok' => true,
            'token' => $token,
            'url' => $url,
            'qr_img' => 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' . urlencode($url)
        ]);
        exit;

    case 'auth_qr_status':
        // login.php hace polling cada 2s
        $token = $_GET['token'] ?? $_POST['token'] ?? '';
        echo json_encode(checkPendingQrStatus($token));
        exit;

    case 'auth_qr_approve':
        // Movil (logueado) aprueba el token del PC
        $u = currentUser();
        $token = $_POST['token'] ?? '';
        $res = approvePendingQrToken($token, $u['id'], $u['email']);
        echo json_encode($res);
        exit;

    case 'auth_qr_cancel':
        $u = currentUser();
        $token = $_POST['token'] ?? '';
        echo json_encode(cancelPendingQrToken($token, $u['id']));
        exit;

    case 'auth_qr_info':
        $token = $_GET['token'] ?? '';
        $info = getPendingTokenInfo($token);
        echo json_encode($info ? ['ok' => true, 'info' => $info] : ['ok' => false]);
        exit;

    // === USUARIOS (admin) ===
    case 'list_users':
        echo json_encode(['ok' => true, 'users' => listUsers()]);
        exit;

    case 'create_user':
        if (!canAddUser()) {
            echo json_encode(['ok' => false, 'error' => licenseErrorMsg('usuarios'), 'license_blocked' => true]);
            exit;
        }
        $res = createUser(
            $_POST['email'] ?? '',
            $_POST['nombre'] ?? '',
            $_POST['password'] ?? '',
            $_POST['rol'] ?? 'user',
            $_POST['telefono'] ?? ''
        );
        if ($res['ok']) {
            $cu = currentUser();
            auditLog($cu['id'], $cu['email'], 'user_create', ($_POST['email'] ?? '') . ' rol=' . ($_POST['rol'] ?? 'user'));
        }
        echo json_encode($res);
        exit;

    case 'update_user':
        $id = (int)($_POST['id'] ?? 0);
        $data = array_intersect_key($_POST, array_flip(['nombre','email','rol','activo','telefono','password']));
        $res = updateUser($id, $data);
        if ($res['ok']) {
            $cu = currentUser();
            auditLog($cu['id'], $cu['email'], 'user_update', "id=$id");
        }
        echo json_encode($res);
        exit;

    case 'delete_user':
        $id = (int)($_POST['id'] ?? 0);
        $cu = currentUser();
        if ($id === $cu['id']) { echo json_encode(['ok' => false, 'error' => 'No puedes borrarte a ti mismo']); exit; }
        $res = deleteUser($id);
        auditLog($cu['id'], $cu['email'], 'user_delete', "id=$id");
        echo json_encode($res);
        exit;

    case 'list_audit_log':
        $limit = min(500, max(10, (int)($_GET['limit'] ?? 100)));
        $rows = db()->query("SELECT * FROM audit_log ORDER BY created_at DESC LIMIT {$limit}")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['ok' => true, 'log' => $rows]);
        exit;

    // === DDNS ===
    case 'ddns_get':
        $cfg = ddnsGetConfig();
        // No enviar password al cliente (solo indica si hay)
        $cfg['ddns_has_password'] = !empty($cfg['ddns_password']);
        unset($cfg['ddns_password']);
        echo json_encode(['ok' => true, 'config' => $cfg]);
        exit;

    case 'ddns_save':
        $data = [
            'ddns_enabled' => isset($_POST['enabled']) && $_POST['enabled'] === '1' ? '1' : '0',
            'ddns_provider' => $_POST['provider'] ?? 'dynu',
            'ddns_hostname' => trim($_POST['hostname'] ?? ''),
            'ddns_username' => trim($_POST['username'] ?? ''),
            'ddns_interval' => max(60, (int)($_POST['interval'] ?? 300)),
        ];
        // Solo actualizar password si se envia uno nuevo (no vacio)
        if (!empty($_POST['password'])) {
            $data['ddns_password'] = $_POST['password'];
        }
        ddnsSaveConfig($data);
        $cu = currentUser();
        auditLog($cu['id'], $cu['email'], 'ddns_config', "provider={$data['ddns_provider']} host={$data['ddns_hostname']}");
        // Si esta habilitado, hacer un update de prueba
        $testResult = null;
        if ($data['ddns_enabled'] === '1' && $data['ddns_hostname']) {
            $testResult = ddnsUpdate(true);
        }
        echo json_encode(['ok' => true, 'test' => $testResult]);
        exit;

    case 'ddns_test':
        $res = ddnsUpdate(true);
        $cu = currentUser();
        auditLog($cu['id'], $cu['email'], 'ddns_test', $res['msg'] ?? '');
        echo json_encode($res);
        exit;

    // === DRIVERS ===
    case 'list_all_drivers':
        // Lista drivers de todas las impresoras agrupados por impresora
        $printers = db()->query("SELECT id, nombre, ip, fabricante, modelo, cups_name FROM impresoras ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($printers as &$p) {
            $p['drivers'] = listDriversForPrinter($p['id']);
            if (!$p['fabricante']) $p['fabricante'] = detectManufacturer($p['nombre']);
            if (!$p['modelo']) $p['modelo'] = extractModel($p['nombre'], $p['fabricante'] ?? '');
            $p['search_url'] = $p['fabricante'] ? getSearchURL($p['fabricante'], $p['modelo']) : null;
        }
        echo json_encode(['ok' => true, 'printers' => $printers]);
        break;

    case 'fetch_drivers':
        $id = (int)($_POST['printer_id'] ?? 0);
        $res = fetchDriversForPrinter($id);
        if ($res['ok']) logActivity('info', "Busqueda drivers: {$res['manufacturer']} {$res['model']} - {$res['downloaded']} descargados");
        echo json_encode($res);
        break;

    case 'fetch_driver_url':
        $id = (int)($_POST['printer_id'] ?? 0);
        $so = $_POST['so'] ?? 'generic';
        $nombre = $_POST['nombre'] ?? '';
        $url = $_POST['url'] ?? '';
        if (!$id || !$url) {
            echo json_encode(['ok' => false, 'error' => 'Impresora y URL obligatorios']);
            break;
        }
        if (!preg_match('#^https?://#i', $url)) {
            echo json_encode(['ok' => false, 'error' => 'URL debe empezar por http:// o https://']);
            break;
        }
        $printer = db()->query("SELECT * FROM impresoras WHERE id = {$id}")->fetch(PDO::FETCH_ASSOC);
        if (!$printer) { echo json_encode(['ok' => false, 'error' => 'Impresora no encontrada']); break; }

        $fabricante = $printer['fabricante'] ?: detectManufacturer($printer['nombre']);
        $modelo = $printer['modelo'] ?: extractModel($printer['nombre'], $fabricante ?? '');
        $dir = DRIVERS_DIR . '/' . preg_replace('/[^a-zA-Z0-9_-]/', '_', ($fabricante ?? 'generic') . '_' . ($modelo ?? 'unknown'));
        if (!is_dir($dir)) @mkdir($dir, 0755, true);

        // Obtener el nombre del archivo del URL (puede venir con query params, los quitamos)
        $urlPath = parse_url($url, PHP_URL_PATH);
        $fname = basename($urlPath) ?: ('driver_' . time() . '.bin');
        $fname = preg_replace('/[^a-zA-Z0-9._-]/', '_', $fname);
        $dest = $dir . '/' . $fname;

        // Descargar (con headers para evitar 403 de algunos CDN)
        @set_time_limit(600);
        $res = downloadFile($url, $dest, 300);

        if (!$res['ok']) {
            echo json_encode(['ok' => false, 'error' => 'Error al descargar: ' . $res['error']]);
            break;
        }

        // Verificar que descargo algo razonable
        if ($res['size'] < 1024) {
            @unlink($dest);
            echo json_encode(['ok' => false, 'error' => 'Archivo descargado muy pequeno (' . $res['size'] . 'B). Revisa la URL.']);
            break;
        }

        $ins = db()->prepare("INSERT INTO drivers (impresora_id, sistema_operativo, nombre, archivo, tamano, url_origen, fabricante) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $ins->execute([$id, $so, $nombre ?: $fname, $dest, $res['size'], $url, $fabricante]);
        logActivity('info', "Driver descargado desde URL: {$fname} (" . number_format($res['size']/1048576, 1) . " MB) para {$printer['nombre']}");
        echo json_encode(['ok' => true, 'id' => db()->lastInsertId(), 'size' => $res['size'], 'filename' => $fname]);
        break;

    case 'upload_driver':
        $id = (int)($_POST['printer_id'] ?? 0);
        $so = $_POST['so'] ?? 'generic';
        $nombre = $_POST['nombre'] ?? '';
        if (!isset($_FILES['driver_file']) || $_FILES['driver_file']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['ok' => false, 'error' => 'Archivo no recibido']);
            break;
        }
        $printer = db()->query("SELECT * FROM impresoras WHERE id = {$id}")->fetch(PDO::FETCH_ASSOC);
        if (!$printer) { echo json_encode(['ok' => false, 'error' => 'Impresora no encontrada']); break; }

        $fabricante = $printer['fabricante'] ?: detectManufacturer($printer['nombre']);
        $modelo = $printer['modelo'] ?: extractModel($printer['nombre'], $fabricante ?? '');
        $dir = DRIVERS_DIR . '/' . preg_replace('/[^a-zA-Z0-9_-]/', '_', ($fabricante ?? 'generic') . '_' . ($modelo ?? 'unknown'));
        if (!is_dir($dir)) @mkdir($dir, 0755, true);

        $origName = basename($_FILES['driver_file']['name']);
        $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $origName);
        $dest = $dir . '/' . $safeName;
        if (!move_uploaded_file($_FILES['driver_file']['tmp_name'], $dest)) {
            echo json_encode(['ok' => false, 'error' => 'Error al guardar archivo']);
            break;
        }
        chmod($dest, 0644);
        $ins = db()->prepare("INSERT INTO drivers (impresora_id, sistema_operativo, nombre, archivo, tamano, fabricante) VALUES (?, ?, ?, ?, ?, ?)");
        $ins->execute([$id, $so, $nombre ?: $origName, $dest, filesize($dest), $fabricante]);
        logActivity('info', "Driver subido: {$origName} para {$printer['nombre']} ({$so})");
        echo json_encode(['ok' => true, 'id' => db()->lastInsertId()]);
        break;

    case 'delete_driver':
        $id = (int)($_POST['id'] ?? 0);
        $row = db()->query("SELECT * FROM drivers WHERE id = {$id}")->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            if (file_exists($row['archivo'])) @unlink($row['archivo']);
            db()->exec("DELETE FROM drivers WHERE id = {$id}");
            logActivity('info', "Driver eliminado: " . basename($row['archivo']));
        }
        echo json_encode(['ok' => true]);
        break;

    case 'download_driver':
        $id = (int)($_GET['id'] ?? 0);
        $row = db()->query("SELECT * FROM drivers WHERE id = {$id}")->fetch(PDO::FETCH_ASSOC);
        if (!$row || !file_exists($row['archivo'])) {
            http_response_code(404);
            exit('Driver no encontrado');
        }
        db()->exec("UPDATE drivers SET descargas = descargas + 1 WHERE id = {$id}");
        $fname = basename($row['archivo']);
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $fname . '"');
        header('Content-Length: ' . filesize($row['archivo']));
        header('X-Accel-Buffering: no');
        readfile($row['archivo']);
        exit;

    // === ESCANEO DE RED ===
    case 'scan_network':
        $range = $_POST['range'] ?? NETWORK_RANGE;
        $result = cupsExec("sudo nmap -sn " . escapeshellarg($range) . " --open -oG -");
        $hosts = [];
        preg_match_all('/Host:\s+([\d.]+)\s+\(([^)]*)\)/', $result['output'], $matches, PREG_SET_ORDER);

        foreach ($matches as $m) {
            $ip = $m[1];
            $hostname = $m[2] ?: $ip;
            $portCheck = cupsExec("sudo nmap -p 631,9100,515 --open " . escapeshellarg($ip) . " -oG -");
            $ports = [];
            if (preg_match('/631\/open\//', $portCheck['output'])) $ports[] = 'IPP (631)';
            if (preg_match('/9100\/open\//', $portCheck['output'])) $ports[] = 'JetDirect (9100)';
            if (preg_match('/515\/open\//', $portCheck['output'])) $ports[] = 'LPD (515)';

            if (!empty($ports)) {
                $modelo = 'Desconocido';
                $ippInfo = cupsExec("ipptool -tv ipp://{$ip}/ipp/print get-printer-attributes.test 2>/dev/null | grep printer-make-and-model");
                if (preg_match('/=\s*(.+)/', $ippInfo['output'], $dm)) {
                    $modelo = trim($dm[1]);
                } else {
                    $snmp = cupsExec("snmpget -v1 -c public -t 1 " . escapeshellarg($ip) . " 1.3.6.1.2.1.25.3.2.1.3.1 2>/dev/null");
                    if (strpos($snmp['output'], 'STRING') !== false && preg_match('/STRING:\s*"?([^"\n]+)"?/', $snmp['output'], $sm)) {
                        $modelo = trim($sm[1]);
                    }
                }

                $hosts[] = [
                    'ip' => $ip,
                    'hostname' => $hostname,
                    'ports' => $ports,
                    'modelo' => str_replace('"', '', $modelo)
                ];
            }
        }
        echo json_encode(['ok' => true, 'printers' => $hosts]);
        break;

    // === IMPRESORAS ===
    case 'list_printers':
        $stmt = db()->query("SELECT * FROM impresoras ORDER BY nombre");
        $printers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($printers as &$p) {
            if ($p['cups_name']) {
                $status = cupsExec("lpstat -p " . escapeshellarg($p['cups_name']));
                $p['cups_status'] = trim($status['output']);
            }
        }
        echo json_encode(['ok' => true, 'printers' => $printers]);
        break;

    case 'add_printer':
        if (!canAddPrinter()) {
            echo json_encode(['ok' => false, 'error' => licenseErrorMsg('impresoras'), 'license_blocked' => true]);
            break;
        }
        $nombre = $_POST['nombre'] ?? '';
        $ip = $_POST['ip'] ?? '';
        $uri = $_POST['uri'] ?? '';
        $ubicacion = $_POST['ubicacion'] ?? '';

        if (!$nombre || !$ip) {
            echo json_encode(['ok' => false, 'error' => 'Nombre e IP son obligatorios']);
            break;
        }

        if (!$uri) $uri = "ipp://{$ip}/ipp/print";
        $cupsName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $nombre);

        // Intentar detectar driver nativo
        $drivers = cupsExec("lpinfo -m 2>/dev/null | grep -i escpr | grep -i " . escapeshellarg($ip));
        $driverModel = 'everywhere';
        if (!$drivers['output']) {
            // Buscar por marca genérica
            $ippInfo = cupsExec("ipptool -tv " . escapeshellarg($uri) . " get-printer-attributes.test 2>/dev/null | grep printer-make-and-model");
            if (preg_match('/=\s*(.+)/', $ippInfo['output'], $dm)) {
                $make = trim($dm[1]);
                $search = cupsExec("lpinfo -m 2>/dev/null | grep -i escpr | grep -i \"" . explode(' ', $make)[0] . "\"");
                if ($search['output']) {
                    $firstLine = explode("\n", trim($search['output']))[0];
                    $driverModel = trim(explode(' ', $firstLine, 2)[0]);
                }
            }
        } else {
            $firstLine = explode("\n", trim($drivers['output']))[0];
            $driverModel = trim(explode(' ', $firstLine, 2)[0]);
        }

        $cmd = sprintf(
            'sudo lpadmin -p %s -E -v %s -m %s -L %s -D %s',
            escapeshellarg($cupsName),
            escapeshellarg($uri),
            escapeshellarg($driverModel),
            escapeshellarg($ubicacion),
            escapeshellarg($nombre)
        );
        $res = cupsExec($cmd);

        if ($res['code'] !== 0 && $driverModel !== 'everywhere') {
            // Fallback a everywhere
            $cmd = sprintf(
                'sudo lpadmin -p %s -E -v %s -m everywhere -L %s -D %s',
                escapeshellarg($cupsName),
                escapeshellarg($uri),
                escapeshellarg($ubicacion),
                escapeshellarg($nombre)
            );
            $res = cupsExec($cmd);
        }

        if ($res['code'] !== 0) {
            echo json_encode(['ok' => false, 'error' => 'Error CUPS: ' . $res['output']]);
            break;
        }

        // Configurar politica de errores
        cupsExec("sudo lpadmin -p " . escapeshellarg($cupsName) . " -o printer-error-policy=retry-current-job");

        $stmt = db()->prepare("INSERT INTO impresoras (nombre, ip, uri, cups_name, ubicacion, driver) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$nombre, $ip, $uri, $cupsName, $ubicacion, $driverModel]);
        logActivity('info', "Impresora agregada: {$nombre} ({$ip})");

        echo json_encode(['ok' => true, 'id' => db()->lastInsertId(), 'cups_name' => $cupsName]);
        break;

    case 'delete_printer':
        $id = (int)($_POST['id'] ?? 0);
        $printer = db()->query("SELECT * FROM impresoras WHERE id = {$id}")->fetch(PDO::FETCH_ASSOC);
        if ($printer && $printer['cups_name']) {
            cupsExec('sudo lpadmin -x ' . escapeshellarg($printer['cups_name']));
        }
        db()->exec("DELETE FROM impresoras WHERE id = {$id}");
        logActivity('info', "Impresora eliminada: " . ($printer['nombre'] ?? $id));
        echo json_encode(['ok' => true]);
        break;

    case 'test_printer':
        $id = (int)($_POST['id'] ?? 0);
        $printer = db()->query("SELECT * FROM impresoras WHERE id = {$id}")->fetch(PDO::FETCH_ASSOC);
        if (!$printer || !$printer['cups_name']) {
            echo json_encode(['ok' => false, 'error' => 'Impresora no encontrada']);
            break;
        }
        $res = cupsExec('sudo lp -d ' . escapeshellarg($printer['cups_name']) . ' -t "Test DNNS" /etc/hostname');
        logActivity('info', "Pagina de prueba enviada a: " . $printer['nombre']);
        echo json_encode(['ok' => $res['code'] === 0, 'output' => $res['output']]);
        break;

    case 'printer_defaults':
        $id = (int)($_POST['id'] ?? 0);
        $printer = db()->query("SELECT * FROM impresoras WHERE id = {$id}")->fetch(PDO::FETCH_ASSOC);
        if (!$printer || !$printer['cups_name']) {
            echo json_encode(['ok' => false, 'error' => 'Impresora no encontrada']);
            break;
        }
        $media = $_POST['media'] ?? '';
        $sides = $_POST['sides'] ?? '';
        $quality = $_POST['quality'] ?? '';
        $cn = escapeshellarg($printer['cups_name']);
        if ($media) cupsExec("sudo lpadmin -p {$cn} -o media=" . escapeshellarg($media));
        if ($sides) cupsExec("sudo lpadmin -p {$cn} -o sides=" . escapeshellarg($sides));
        if ($quality) cupsExec("sudo lpadmin -p {$cn} -o print-quality=" . escapeshellarg($quality));
        logActivity('info', "Configuracion actualizada: " . $printer['nombre']);
        echo json_encode(['ok' => true]);
        break;

    case 'printer_options':
        $id = (int)($_GET['id'] ?? 0);
        $printer = db()->query("SELECT * FROM impresoras WHERE id = {$id}")->fetch(PDO::FETCH_ASSOC);
        if (!$printer || !$printer['cups_name']) {
            echo json_encode(['ok' => false]);
            break;
        }
        $res = cupsExec("lpoptions -p " . escapeshellarg($printer['cups_name']) . " -l 2>/dev/null");
        echo json_encode(['ok' => true, 'options' => $res['output']]);
        break;

    // === ESTADO IMPRESORA IPP ===
    case 'printer_status':
        $id = (int)($_GET['id'] ?? 0);
        $printer = db()->query("SELECT * FROM impresoras WHERE id = {$id}")->fetch(PDO::FETCH_ASSOC);
        if (!$printer) {
            echo json_encode(['ok' => false]);
            break;
        }
        $uri = $printer['uri'] ?: "ipp://{$printer['ip']}/ipp/print";
        $res = cupsExec("ipptool -tv " . escapeshellarg($uri) . " get-printer-attributes.test 2>/dev/null");
        $data = ['state' => '', 'reasons' => '', 'ink' => [], 'model' => ''];

        if (preg_match('/printer-state\s+\(enum\)\s+=\s+(\S+)/', $res['output'], $m)) {
            $states = ['3' => 'idle', '4' => 'processing', '5' => 'stopped', 'idle' => 'idle', 'processing' => 'processing', 'stopped' => 'stopped'];
            $data['state'] = $states[$m[1]] ?? $m[1];
        }
        if (preg_match('/printer-state-reasons\s+\(keyword\)\s+=\s+(.+)/', $res['output'], $m))
            $data['reasons'] = trim($m[1]);
        if (preg_match('/printer-make-and-model.+=\s+(.+)/', $res['output'], $m))
            $data['model'] = trim($m[1]);

        // Niveles de tinta via IPP (más fiable que SNMP)
        $inks = [];
        $names = []; $levels = []; $colors = [];

        if (preg_match('/marker-names.*?=\s*(.+)/m', $res['output'], $nm))
            $names = array_map('trim', explode(',', $nm[1]));
        if (preg_match('/marker-levels.*?=\s*(.+)/m', $res['output'], $lm))
            $levels = array_map('trim', explode(',', $lm[1]));
        if (preg_match('/marker-colors.*?=\s*(.+)/m', $res['output'], $cm))
            $colors = array_map('trim', explode(',', $cm[1]));

        for ($i = 0; $i < count($names); $i++) {
            $l = isset($levels[$i]) ? (int)$levels[$i] : 0;
            $c = isset($colors[$i]) ? $colors[$i] : '#69c350';
            $inks[] = ['name' => $names[$i], 'level' => $l < 0 ? -1 : $l, 'color' => $c];

            // Guardar snapshot y detectar cambio de cartucho
            if ($l >= 0) {
                $color = $names[$i];
                // Ultimo snapshot
                $last = db()->prepare("SELECT nivel FROM ink_snapshots WHERE impresora_id = ? AND color = ? ORDER BY created_at DESC LIMIT 1");
                $last->execute([$printer['id'], $color]);
                $lastLevel = $last->fetchColumn();

                // Solo guardar si cambio el nivel (evitar spam)
                if ($lastLevel === false || abs($lastLevel - $l) >= 1) {
                    db()->prepare("INSERT INTO ink_snapshots (impresora_id, color, nivel) VALUES (?, ?, ?)")
                        ->execute([$printer['id'], $color, $l]);
                }

                // Detectar cambio de cartucho: nivel anterior bajo (<30) y ahora alto (>70)
                if ($lastLevel !== false && $lastLevel < 30 && $l > 70) {
                    // Contar paginas desde el ultimo cambio de este color
                    $lastChange = db()->prepare("SELECT created_at FROM cartuchos WHERE impresora_id = ? AND color = ? ORDER BY created_at DESC LIMIT 1");
                    $lastChange->execute([$printer['id'], $color]);
                    $since = $lastChange->fetchColumn() ?: '2000-01-01';
                    $pages = db()->prepare("SELECT COUNT(*) FROM trabajos WHERE impresora_id = ? AND created_at >= ?");
                    $pages->execute([$printer['id'], $since]);
                    $pageCount = (int)$pages->fetchColumn();

                    db()->prepare("INSERT INTO cartuchos (impresora_id, color, nivel_anterior, nivel_nuevo, paginas_desde_ultimo) VALUES (?, ?, ?, ?, ?)")
                        ->execute([$printer['id'], $color, $lastLevel, $l, $pageCount]);
                    logActivity('warning', "Cartucho cambiado: {$color} en {$printer['nombre']} ({$lastLevel}% -> {$l}%, {$pageCount} paginas)");
                }
            }
        }
        $data['ink'] = $inks;

        echo json_encode(['ok' => true, 'status' => $data]);
        break;

    case 'cartridge_history':
        $id = (int)($_GET['id'] ?? 0);
        $where = $id ? "WHERE c.impresora_id = {$id}" : "";
        $stmt = db()->query("SELECT c.*, i.nombre as impresora FROM cartuchos c
                             LEFT JOIN impresoras i ON c.impresora_id = i.id
                             {$where} ORDER BY c.created_at DESC LIMIT 50");
        echo json_encode(['ok' => true, 'history' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        break;

    case 'register_cartridge_change':
        $printerId = (int)($_POST['printer_id'] ?? 0);
        $color = $_POST['color'] ?? '';
        if (!$printerId || !$color) {
            echo json_encode(['ok' => false, 'error' => 'Impresora y color obligatorios']);
            break;
        }
        $lastChange = db()->prepare("SELECT created_at FROM cartuchos WHERE impresora_id = ? AND color = ? ORDER BY created_at DESC LIMIT 1");
        $lastChange->execute([$printerId, $color]);
        $since = $lastChange->fetchColumn() ?: '2000-01-01';
        $pages = db()->prepare("SELECT COUNT(*) FROM trabajos WHERE impresora_id = ? AND created_at >= ?");
        $pages->execute([$printerId, $since]);
        $pageCount = (int)$pages->fetchColumn();

        $lastSnap = db()->prepare("SELECT nivel FROM ink_snapshots WHERE impresora_id = ? AND color = ? ORDER BY created_at DESC LIMIT 1");
        $lastSnap->execute([$printerId, $color]);
        $lastLevel = (int)$lastSnap->fetchColumn();

        db()->prepare("INSERT INTO cartuchos (impresora_id, color, nivel_anterior, nivel_nuevo, paginas_desde_ultimo) VALUES (?, ?, ?, 100, ?)")
            ->execute([$printerId, $color, $lastLevel, $pageCount]);
        $printer = db()->query("SELECT nombre FROM impresoras WHERE id = {$printerId}")->fetchColumn();
        logActivity('warning', "Cartucho registrado manualmente: {$color} en {$printer} ({$pageCount} paginas)");
        echo json_encode(['ok' => true, 'pages' => $pageCount]);
        break;

    // === COLA DE IMPRESION ===
    case 'list_jobs':
        syncCupsJobs();
        $res = cupsExec('lpstat -o -W not-completed 2>/dev/null');
        $cupsJobs = [];
        if ($res['output']) {
            foreach (explode("\n", trim($res['output'])) as $line) {
                if (preg_match('/^(\S+)-(\d+)\s+(.+?)\s{2,}(\d+)\s+(.+)$/', $line, $m)) {
                    $cupsJobs[] = [
                        'printer' => $m[1],
                        'job_id' => $m[2],
                        'user' => trim($m[3]),
                        'size' => $m[4],
                        'date' => $m[5]
                    ];
                }
            }
        }

        $stmt = db()->query("SELECT t.*, i.nombre as impresora_nombre FROM trabajos t
                             LEFT JOIN impresoras i ON t.impresora_id = i.id
                             ORDER BY t.created_at DESC LIMIT 50");
        $dbJobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['ok' => true, 'active' => $cupsJobs, 'history' => $dbJobs]);
        break;

    case 'cancel_job':
        $jobId = (int)($_POST['job_id'] ?? 0);
        $res = cupsExec('sudo cancel ' . $jobId);
        db()->prepare("UPDATE trabajos SET estado = 'cancelado' WHERE cups_job_id = ?")->execute([$jobId]);
        logActivity('info', "Trabajo cancelado: #{$jobId}");
        echo json_encode(['ok' => $res['code'] === 0, 'output' => $res['output']]);
        break;

    case 'cancel_all':
        $printer = $_POST['printer'] ?? '';
        if ($printer) {
            $res = cupsExec('sudo cancel -a ' . escapeshellarg($printer));
        } else {
            $res = cupsExec('sudo cancel -a');
        }
        db()->exec("UPDATE trabajos SET estado = 'cancelado' WHERE estado IN ('pendiente','imprimiendo')");
        logActivity('warning', 'Todos los trabajos cancelados' . ($printer ? " en {$printer}" : ''));
        echo json_encode(['ok' => $res['code'] === 0]);
        break;

    // === IMPRIMIR DESDE PANEL ===
    case 'print_file':
        if (!isset($_FILES['file'])) {
            echo json_encode(['ok' => false, 'error' => 'No se recibio archivo']);
            break;
        }
        $printerId = (int)($_POST['printer_id'] ?? 0);
        $copies = max(1, (int)($_POST['copies'] ?? 1));
        $printer = db()->query("SELECT * FROM impresoras WHERE id = {$printerId}")->fetch(PDO::FETCH_ASSOC);
        if (!$printer) {
            $printer = db()->query("SELECT * FROM impresoras WHERE estado = 'activa' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        }
        if (!$printer || !$printer['cups_name']) {
            echo json_encode(['ok' => false, 'error' => 'No hay impresora disponible']);
            break;
        }

        $file = $_FILES['file'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'bmp', 'txt', 'doc', 'docx', 'odt'];
        if (!in_array($ext, $allowed)) {
            echo json_encode(['ok' => false, 'error' => 'Formato no soportado. Usa: ' . implode(', ', $allowed)]);
            break;
        }

        $tmpFile = '/tmp/print_' . uniqid() . '.' . $ext;
        move_uploaded_file($file['tmp_name'], $tmpFile);

        $cmd = sprintf('sudo lp -d %s -n %d -t %s %s',
            escapeshellarg($printer['cups_name']),
            $copies,
            escapeshellarg($file['name']),
            escapeshellarg($tmpFile)
        );
        $res = cupsExec($cmd);

        if ($res['code'] === 0) {
            $jobId = 0;
            if (preg_match('/request id is \S+-(\d+)/', $res['output'], $jm)) $jobId = (int)$jm[1];
            $ins = db()->prepare("INSERT INTO trabajos (cups_job_id, impresora_id, usuario, documento, copias, estado) VALUES (?, ?, ?, ?, ?, ?)");
            $ins->execute([$jobId, $printer['id'], 'Panel Web', $file['name'], $copies, 'imprimiendo']);
            logActivity('info', "Impresion desde panel: {$file['name']} x{$copies} en {$printer['nombre']}");
        }

        // Limpiar archivo temporal despues de un rato
        @unlink($tmpFile);

        echo json_encode(['ok' => $res['code'] === 0, 'output' => $res['output']]);
        break;

    // === ESTADO GENERAL ===
    case 'status':
        syncCupsJobs();
        $printerCount = db()->query("SELECT COUNT(*) FROM impresoras")->fetchColumn();
        $jobsToday = db()->query("SELECT COUNT(*) FROM trabajos WHERE DATE(created_at) = CURDATE()")->fetchColumn();
        $cupsRunning = cupsExec('lpstat -r 2>/dev/null');
        $activeJobs = cupsExec('lpstat -o 2>/dev/null');
        $activeCount = $activeJobs['output'] ? count(array_filter(explode("\n", trim($activeJobs['output'])))) : 0;

        // Alertas
        $alerts = [];
        $printers = db()->query("SELECT * FROM impresoras WHERE estado = 'activa'")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($printers as $p) {
            $st = cupsExec("lpstat -p " . escapeshellarg($p['cups_name']) . " 2>/dev/null");
            if (strpos($st['output'], 'disabled') !== false) {
                $alerts[] = ['type' => 'error', 'msg' => $p['nombre'] . ' esta deshabilitada'];
            }
        }

        echo json_encode([
            'ok' => true,
            'printers' => (int)$printerCount,
            'jobs_today' => (int)$jobsToday,
            'active_jobs' => $activeCount,
            'cups_running' => strpos($cupsRunning['output'], 'running') !== false,
            'alerts' => $alerts,
            'cups_lan_host' => defined('CUPS_LAN_HOST') ? CUPS_LAN_HOST : $_SERVER['HTTP_HOST'],
        ]);
        break;

    // === ESTADISTICAS ===
    case 'stats':
        $period = $_GET['period'] ?? 'month';
        $data = [];

        if ($period === 'month') {
            $stmt = db()->query("SELECT DATE(created_at) as dia, COUNT(*) as total
                                 FROM trabajos
                                 WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                                 GROUP BY DATE(created_at) ORDER BY dia");
            $data['daily'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // Por usuario
        $stmt = db()->query("SELECT usuario, COUNT(*) as total
                             FROM trabajos
                             WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                             GROUP BY usuario ORDER BY total DESC LIMIT 10");
        $data['by_user'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Por impresora
        $stmt = db()->query("SELECT i.nombre, COUNT(*) as total
                             FROM trabajos t LEFT JOIN impresoras i ON t.impresora_id = i.id
                             WHERE t.created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                             GROUP BY t.impresora_id ORDER BY total DESC");
        $data['by_printer'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Totales
        $data['total_month'] = db()->query("SELECT COUNT(*) FROM trabajos WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)")->fetchColumn();
        $data['total_week'] = db()->query("SELECT COUNT(*) FROM trabajos WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)")->fetchColumn();
        $data['total_today'] = db()->query("SELECT COUNT(*) FROM trabajos WHERE DATE(created_at) = CURDATE()")->fetchColumn();

        echo json_encode(['ok' => true, 'stats' => $data]);
        break;

    // === CUOTAS ===
    case 'quotas':
        $stmt = db()->query("SELECT * FROM cuotas ORDER BY usuario");
        echo json_encode(['ok' => true, 'quotas' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        break;

    case 'set_quota':
        $usuario = $_POST['usuario'] ?? '';
        $limite = (int)($_POST['limite'] ?? 0);
        if (!$usuario || $limite <= 0) {
            echo json_encode(['ok' => false, 'error' => 'Usuario y limite son obligatorios']);
            break;
        }
        $stmt = db()->prepare("INSERT INTO cuotas (usuario, limite_mensual) VALUES (?, ?)
                               ON DUPLICATE KEY UPDATE limite_mensual = ?");
        $stmt->execute([$usuario, $limite, $limite]);
        logActivity('info', "Cuota establecida: {$usuario} = {$limite} pags/mes");
        echo json_encode(['ok' => true]);
        break;

    case 'quota_usage':
        $stmt = db()->query("SELECT t.usuario, COUNT(*) as usado,
                             COALESCE(c.limite_mensual, 0) as limite
                             FROM trabajos t
                             LEFT JOIN cuotas c ON t.usuario = c.usuario
                             WHERE t.created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
                             GROUP BY t.usuario");
        echo json_encode(['ok' => true, 'usage' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        break;

    // === LOG ===
    case 'log':
        $limit = (int)($_GET['limit'] ?? 30);
        $stmt = db()->query("SELECT * FROM log_actividad ORDER BY created_at DESC LIMIT {$limit}");
        echo json_encode(['ok' => true, 'log' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        break;

    default:
        echo json_encode(['ok' => false, 'error' => 'Accion no valida']);
}
