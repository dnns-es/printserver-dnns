<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

$token = preg_replace('/[^a-f0-9]/', '', $_GET['t'] ?? '');

// Si no esta logueado, redirigir a login guardando el token en session para retomar
if (!isLoggedIn()) {
    $_SESSION['pending_qr_approve'] = $token;
    header('Location: login.php');
    exit;
}

// Si venia de redirect tras login, recuperar el token guardado
if (!$token && !empty($_SESSION['pending_qr_approve'])) {
    $token = $_SESSION['pending_qr_approve'];
    unset($_SESSION['pending_qr_approve']);
}

$user = currentUser();
$info = $token ? getPendingTokenInfo($token) : null;

// Procesar aprobacion/cancelacion
$done = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'approve') {
        $res = approvePendingQrToken($token, $user['id'], $user['email']);
        $done = $res['ok'] ? 'approved' : ('error:' . ($res['error'] ?? ''));
    } elseif ($action === 'cancel') {
        cancelPendingQrToken($token, $user['id']);
        $done = 'cancelled';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Autorizar acceso - Print Server DNNS</title>
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#69c350">
    <link rel="apple-touch-icon" href="icon-192.png">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
               background: linear-gradient(135deg, #69c350 0%, #3d8c27 100%);
               min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 16px; }
        .card { background: #fff; border-radius: 16px; padding: 28px 24px; max-width: 380px;
                width: 100%; box-shadow: 0 10px 40px rgba(0,0,0,.15); text-align: center; }
        .icon { font-size: 48px; margin-bottom: 8px; }
        h1 { font-size: 18px; margin-bottom: 8px; color: #2c2c2c; }
        p { font-size: 14px; color: #555; margin-bottom: 16px; line-height: 1.5; }
        .info-box { background: #f8f8f8; border-radius: 10px; padding: 14px; margin-bottom: 18px; font-size: 13px; text-align: left; }
        .info-box div { margin: 4px 0; color: #555; }
        .info-box strong { color: #2c2c2c; }
        .buttons { display: flex; gap: 10px; }
        .btn { flex: 1; padding: 12px; border: none; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; transition: all .15s; }
        .btn-approve { background: #69c350; color: #fff; }
        .btn-approve:hover { background: #5aad42; }
        .btn-cancel { background: #e8e8e8; color: #444; }
        .btn-cancel:hover { background: #d5d5d5; }
        .msg { padding: 16px; border-radius: 10px; font-size: 14px; margin-bottom: 14px; }
        .msg.success { background: #edf7ea; color: #3d8c27; border: 1px solid #c7e5ba; }
        .msg.error { background: #fde8e8; color: #c0392b; border: 1px solid #f5c6c6; }
        .user-chip { display: inline-block; background: #edf7ea; color: #3d8c27; padding: 4px 10px; border-radius: 12px; font-size: 12px; margin-bottom: 16px; }
        a { color: #69c350; text-decoration: none; }
    </style>
</head>
<body>
<div class="card">
<?php if ($done === 'approved'): ?>
    <div class="icon">&#10003;</div>
    <h1>Acceso autorizado</h1>
    <div class="msg success">El PC ya est&aacute; entrando en el panel.</div>
    <p style="font-size:12px;color:#888;">Puedes cerrar esta ventana.</p>
    <p><a href="index.php">Ir al panel</a></p>
<?php elseif ($done === 'cancelled'): ?>
    <div class="icon">&#10060;</div>
    <h1>Acceso cancelado</h1>
    <div class="msg error">Has rechazado el acceso.</div>
    <p><a href="index.php">Ir al panel</a></p>
<?php elseif (strpos((string)$done, 'error:') === 0): ?>
    <div class="icon">&#9888;&#65039;</div>
    <h1>Error</h1>
    <div class="msg error"><?= htmlspecialchars(substr($done, 6)) ?></div>
    <p><a href="index.php">Ir al panel</a></p>
<?php elseif (!$info): ?>
    <div class="icon">&#10060;</div>
    <h1>Token no v&aacute;lido</h1>
    <p>El c&oacute;digo QR no existe, ha expirado o ya fue usado.</p>
    <p><a href="index.php">Ir al panel</a></p>
<?php elseif (strtotime($info['expires_at']) < time()): ?>
    <div class="icon">&#8987;</div>
    <h1>C&oacute;digo QR expirado</h1>
    <p>El QR ha caducado. Recarga la pantalla de login del PC para generar uno nuevo.</p>
    <p><a href="index.php">Ir al panel</a></p>
<?php elseif ($info['status'] !== 'pending'): ?>
    <div class="icon">&#8505;&#65039;</div>
    <h1>QR ya procesado</h1>
    <p>Este c&oacute;digo ya fue <?= htmlspecialchars($info['status']) ?>.</p>
    <p><a href="index.php">Ir al panel</a></p>
<?php else: ?>
    <div class="icon">&#128274;</div>
    <h1>Autorizar acceso desde PC</h1>
    <div class="user-chip">&#9679; Sesion actual: <?= htmlspecialchars($user['email']) ?></div>
    <p>Un dispositivo est&aacute; pidiendo acceso al panel. Si fuiste t&uacute;, aprueba el acceso.</p>

    <div class="info-box">
        <div><strong>IP del PC:</strong> <?= htmlspecialchars($info['created_by_ip']) ?></div>
        <div><strong>Hora petici&oacute;n:</strong> <?= htmlspecialchars($info['created_at']) ?></div>
        <div><strong>Expira:</strong> <?= htmlspecialchars($info['expires_at']) ?></div>
    </div>

    <form method="POST">
        <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
        <div class="buttons">
            <button class="btn btn-cancel" name="action" value="cancel">Rechazar</button>
            <button class="btn btn-approve" name="action" value="approve">Aprobar</button>
        </div>
    </form>
<?php endif; ?>
</div>
</body>
</html>
