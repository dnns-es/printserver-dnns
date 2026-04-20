<?php
/**
 * DDNS client para Dynu, DuckDNS, No-IP, Afraid.org
 * Guarda config en tabla `config` y hace update periodico.
 *
 * Claves en tabla config:
 *   ddns_enabled       0|1
 *   ddns_provider      dynu|duckdns|noip|afraid
 *   ddns_hostname      mihost.dynu.net
 *   ddns_username      user (no aplica a duckdns)
 *   ddns_password      pass o token
 *   ddns_interval      segundos entre updates (300 default)
 *   ddns_last_ip       ultima IP publicada
 *   ddns_last_update   timestamp ultimo intento
 *   ddns_last_status   mensaje del ultimo intento
 *   ddns_last_success  timestamp ultimo update OK
 */

/** Leer config */
function ddnsGetConfig() {
    $rows = db()->query("SELECT clave, valor FROM config WHERE clave LIKE 'ddns_%'")->fetchAll(PDO::FETCH_KEY_PAIR);
    return array_merge([
        'ddns_enabled' => '0',
        'ddns_provider' => 'dynu',
        'ddns_hostname' => '',
        'ddns_username' => '',
        'ddns_password' => '',
        'ddns_interval' => '300',
        'ddns_last_ip' => '',
        'ddns_last_update' => '',
        'ddns_last_status' => '',
        'ddns_last_success' => '',
    ], $rows);
}

/** Guardar clave config */
function ddnsSet($key, $value) {
    db()->prepare("INSERT INTO config (clave, valor) VALUES (?, ?)
                   ON DUPLICATE KEY UPDATE valor = VALUES(valor)")->execute([$key, $value]);
}

/** Guardar varias a la vez */
function ddnsSaveConfig(array $data) {
    $allowed = ['ddns_enabled','ddns_provider','ddns_hostname','ddns_username','ddns_password','ddns_interval'];
    foreach ($allowed as $k) {
        if (isset($data[$k])) ddnsSet($k, (string)$data[$k]);
    }
}

/** Obtener IP publica usando varios proveedores con fallback */
function ddnsGetPublicIP() {
    $services = [
        'https://api.ipify.org',
        'https://ifconfig.me/ip',
        'https://icanhazip.com',
        'https://checkip.amazonaws.com',
    ];
    foreach ($services as $url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $ip = trim(curl_exec($ch));
        curl_close($ch);
        if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
    }
    return null;
}

/** Ejecutar update al proveedor. Devuelve ['ok'=>bool, 'msg'=>string, 'ip'=>?] */
function ddnsUpdate($force = false) {
    $cfg = ddnsGetConfig();
    if ($cfg['ddns_enabled'] !== '1') {
        return ['ok' => false, 'msg' => 'DDNS deshabilitado'];
    }
    if (!$cfg['ddns_hostname']) {
        return ['ok' => false, 'msg' => 'Falta hostname'];
    }

    $ip = ddnsGetPublicIP();
    if (!$ip) {
        ddnsSet('ddns_last_update', date('Y-m-d H:i:s'));
        ddnsSet('ddns_last_status', 'Error: no se pudo obtener IP publica');
        return ['ok' => false, 'msg' => 'No se pudo obtener IP publica'];
    }

    // Si la IP no ha cambiado y no es force, saltar
    if (!$force && $cfg['ddns_last_ip'] === $ip && $cfg['ddns_last_success']) {
        $sinceSuccess = time() - strtotime($cfg['ddns_last_success']);
        if ($sinceSuccess < 24 * 3600) {
            return ['ok' => true, 'msg' => 'IP sin cambios ('.$ip.')', 'ip' => $ip, 'skipped' => true];
        }
    }

    $url = ddnsBuildUrl($cfg['ddns_provider'], $cfg, $ip);
    if (!$url) {
        return ['ok' => false, 'msg' => 'Proveedor no soportado'];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT => 'PrintServerDNNS-DDNS/1.0',
    ]);
    // Auth HTTP Basic para Dynu y No-IP
    if (in_array($cfg['ddns_provider'], ['dynu','noip']) && $cfg['ddns_username']) {
        curl_setopt($ch, CURLOPT_USERPWD, $cfg['ddns_username'].':'.$cfg['ddns_password']);
    }
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    $result = ddnsParseResponse($cfg['ddns_provider'], $response, $httpCode, $err);
    $result['ip'] = $ip;

    // Guardar estado
    ddnsSet('ddns_last_update', date('Y-m-d H:i:s'));
    ddnsSet('ddns_last_status', $result['msg']);
    if ($result['ok']) {
        ddnsSet('ddns_last_ip', $ip);
        ddnsSet('ddns_last_success', date('Y-m-d H:i:s'));
    }

    return $result;
}

/** Construir URL de update segun proveedor */
function ddnsBuildUrl($provider, $cfg, $ip) {
    $host = urlencode($cfg['ddns_hostname']);
    $ipq = urlencode($ip);
    switch ($provider) {
        case 'dynu':
            // https://api.dynu.com/nic/update?hostname=xxx&myip=yyy (con Basic Auth user:pass)
            return "https://api.dynu.com/nic/update?hostname={$host}&myip={$ipq}";
        case 'duckdns':
            // https://www.duckdns.org/update?domains=NAME&token=TOKEN&ip=IP
            // NAME es hostname sin .duckdns.org
            $name = preg_replace('/\.duckdns\.org$/i', '', $cfg['ddns_hostname']);
            $token = urlencode($cfg['ddns_password']);
            return "https://www.duckdns.org/update?domains=" . urlencode($name) . "&token={$token}&ip={$ipq}";
        case 'noip':
            // https://dynupdate.no-ip.com/nic/update?hostname=xxx&myip=yyy (Basic Auth)
            return "https://dynupdate.no-ip.com/nic/update?hostname={$host}&myip={$ipq}";
        case 'afraid':
            // https://sync.afraid.org/u/TOKEN/?address=IP
            $token = urlencode($cfg['ddns_password']);
            return "https://sync.afraid.org/u/{$token}/?address={$ipq}";
        default:
            return null;
    }
}

/** Parsear respuesta del proveedor */
function ddnsParseResponse($provider, $response, $httpCode, $err) {
    $r = trim((string)$response);
    if ($err) return ['ok' => false, 'msg' => 'Curl: ' . $err];
    if ($httpCode >= 500) return ['ok' => false, 'msg' => 'HTTP '.$httpCode.' (server error)'];

    switch ($provider) {
        case 'dynu':
        case 'noip':
            // good / nochg / nohost / badauth / badagent / abuse / 911
            if (stripos($r, 'good') === 0) return ['ok' => true, 'msg' => 'OK: IP actualizada ('.$r.')'];
            if (stripos($r, 'nochg') === 0) return ['ok' => true, 'msg' => 'OK: sin cambios ('.$r.')'];
            if (stripos($r, 'badauth') !== false) return ['ok' => false, 'msg' => 'Error: usuario/password invalido'];
            if (stripos($r, 'nohost') !== false) return ['ok' => false, 'msg' => 'Error: hostname no registrado'];
            return ['ok' => false, 'msg' => 'Respuesta: '.$r];
        case 'duckdns':
            if ($r === 'OK') return ['ok' => true, 'msg' => 'OK'];
            if ($r === 'KO') return ['ok' => false, 'msg' => 'Error: token o dominio invalido'];
            return ['ok' => false, 'msg' => 'Respuesta: '.$r];
        case 'afraid':
            if (stripos($r, 'has not changed') !== false) return ['ok' => true, 'msg' => 'OK: sin cambios'];
            if (stripos($r, 'updated') !== false) return ['ok' => true, 'msg' => 'OK: actualizado'];
            if (stripos($r, 'ERROR') !== false) return ['ok' => false, 'msg' => 'Error: '.$r];
            return ['ok' => true, 'msg' => $r];
    }
    return ['ok' => false, 'msg' => 'Proveedor desconocido'];
}

/** Llamada periodica desde cron. No lanza nada si no toca */
function ddnsMaybeUpdate() {
    $cfg = ddnsGetConfig();
    if ($cfg['ddns_enabled'] !== '1') return null;
    $interval = max(60, (int)$cfg['ddns_interval']);
    $last = strtotime($cfg['ddns_last_update'] ?: '2000-01-01');
    if (time() - $last < $interval) return null;
    return ddnsUpdate();
}
