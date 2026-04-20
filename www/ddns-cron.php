<?php
/**
 * Script cron DDNS - ejecutar cada minuto desde crontab.
 * Solo hace update si corresponde segun intervalo configurado.
 *   * * * * * /usr/bin/php /var/www/printserver/ddns-cron.php >> /var/log/ddns.log 2>&1
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/ddns.php';

$result = ddnsMaybeUpdate();
if ($result) {
    echo '[' . date('Y-m-d H:i:s') . '] ';
    echo ($result['ok'] ? 'OK  ' : 'ERR ') . ($result['msg'] ?? '') . ' ip=' . ($result['ip'] ?? '') . "\n";
}
