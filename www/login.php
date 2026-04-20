<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

// QR login token via URL (flujo: movil logueado escanea QR generado desde panel)
if (isset($_GET['qr'])) {
    if (consumeQrToken($_GET['qr'])) {
        header('Location: index.php');
        exit;
    }
    $qrError = 'Token QR invalido o expirado';
}

// Si ya esta logueado, redirect al panel
if (isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';
    $result = authenticate($email, $password);
    if ($result['ok']) {
        header('Location: index.php');
        exit;
    }
    $error = $result['error'];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Print Server DNNS</title>
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#69c350">
    <link rel="apple-touch-icon" href="icon-192.png">
    <link rel="icon" type="image/png" href="icon-192.png">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
               background: linear-gradient(135deg, #69c350 0%, #3d8c27 100%);
               min-height: 100vh; display: flex; align-items: center; justify-content: center;
               padding: 20px; }
        .card { background: #fff; border-radius: 16px; padding: 32px 28px; max-width: 400px;
                width: 100%; box-shadow: 0 10px 40px rgba(0,0,0,.15); }
        .logo { text-align: center; font-size: 22px; font-weight: 700; margin-bottom: 4px; color: #2c2c2c; }
        .logo span { color: #69c350; }
        .subtitle { text-align: center; font-size: 13px; color: #888; margin-bottom: 24px; }
        .tabs { display: flex; gap: 0; margin-bottom: 20px; border-bottom: 1px solid #eee; }
        .tab { flex: 1; padding: 10px; text-align: center; cursor: pointer; font-size: 13px; font-weight: 500; color: #888; border-bottom: 2px solid transparent; transition: all .15s; }
        .tab.active { color: #69c350; border-bottom-color: #69c350; }
        .tab-content { display: none; }
        .tab-content.active { display: block; }
        .form-group { margin-bottom: 14px; }
        .form-group label { display: block; font-size: 12px; color: #888; margin-bottom: 4px; text-transform: uppercase; letter-spacing: .5px; }
        .form-group input { width: 100%; padding: 10px 12px; border-radius: 8px; border: 1px solid #ddd; font-size: 14px; transition: all .15s; }
        .form-group input:focus { outline: none; border-color: #69c350; box-shadow: 0 0 0 3px rgba(105,195,80,.15); }
        .btn-primary { width: 100%; padding: 11px; border: none; border-radius: 8px; background: #69c350; color: #fff; font-size: 14px; font-weight: 600; cursor: pointer; transition: all .15s; }
        .btn-primary:hover { background: #5aad42; }
        .error { background: #fde8e8; border: 1px solid #f5c6c6; color: #c0392b; padding: 10px 12px; border-radius: 8px; font-size: 13px; margin-bottom: 16px; }
        .footer { text-align: center; margin-top: 20px; font-size: 11px; color: #999; }
        .lock-icon { width: 48px; height: 48px; margin: 0 auto 12px; background: #edf7ea; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 22px; }

        .qr-area { text-align: center; }
        .qr-box { display: inline-block; padding: 14px; background: #fff; border: 1px solid #eee; border-radius: 12px; margin-bottom: 10px; }
        .qr-box img { display: block; }
        .qr-status { font-size: 13px; color: #888; margin-top: 10px; }
        .qr-status.waiting { color: #4a90d9; }
        .qr-status.approved { color: #3d8c27; font-weight: 600; }
        .qr-status.error { color: #c0392b; }
        .qr-hint { font-size: 11px; color: #999; margin-top: 8px; line-height: 1.4; }
        .spinner { display: inline-block; width: 12px; height: 12px; border: 2px solid #ddd; border-top-color: #4a90d9; border-radius: 50%; animation: spin .7s linear infinite; vertical-align: middle; margin-right: 4px; }
        @keyframes spin { to { transform: rotate(360deg); } }
    </style>
</head>
<body>
<div class="card">
    <div class="lock-icon">&#128274;</div>
    <div class="logo">Print Server <span>DNNS</span></div>
    <div class="subtitle">Inicia sesi&oacute;n</div>

    <?php if (!empty($error)): ?>
        <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if (!empty($qrError)): ?>
        <div class="error"><?= htmlspecialchars($qrError) ?></div>
    <?php endif; ?>

    <div class="tabs">
        <div class="tab active" data-tab="password">&#128273; Contrase&ntilde;a</div>
        <div class="tab" data-tab="qr">&#128241; C&oacute;digo QR</div>
    </div>

    <div id="tab-password" class="tab-content active">
        <form method="POST" autocomplete="on">
            <div class="form-group">
                <label>Email</label>
                <input type="email" name="email" required autofocus autocomplete="username">
            </div>
            <div class="form-group">
                <label>Contrase&ntilde;a</label>
                <input type="password" name="password" required autocomplete="current-password">
            </div>
            <button type="submit" class="btn-primary">Entrar</button>
        </form>
    </div>

    <div id="tab-qr" class="tab-content">
        <div class="qr-area">
            <div class="qr-box" id="qrBox">
                <div style="width:200px;height:200px;display:flex;align-items:center;justify-content:center;color:#999;">Generando QR...</div>
            </div>
            <div class="qr-status waiting" id="qrStatus"><span class="spinner"></span> Esperando autorizaci&oacute;n...</div>
            <div class="qr-hint">
                Escanea el QR con tu m&oacute;vil ya logueado y aprueba el acceso. V&aacute;lido 3 minutos.
            </div>
        </div>
    </div>

    <div class="footer">DNNS Print Server</div>
</div>

<script>
// PWA version check forzar recarga si hay version nueva
var PWA_VERSION_KEY = 'printserver-dnns-version';
(function checkVersionOnStart() {
    fetch('sw-version.json?t=' + Date.now(), { cache: 'no-store' })
        .then(function(r) { return r.ok ? r.json() : null; })
        .then(function(data) {
            if (!data || !data.version) return;
            var installed = localStorage.getItem(PWA_VERSION_KEY);
            if (installed && installed !== data.version) {
                localStorage.setItem(PWA_VERSION_KEY, data.version);
                if ('caches' in window) {
                    caches.keys().then(function(keys) {
                        return Promise.all(keys.map(function(k) { return caches.delete(k); }));
                    }).then(function() { window.location.reload(true); });
                } else { window.location.reload(true); }
            } else if (!installed) {
                localStorage.setItem(PWA_VERSION_KEY, data.version);
            }
        }).catch(function() {});
})();

// Tab switcher
document.querySelectorAll('.tab').forEach(t => {
    t.addEventListener('click', () => {
        document.querySelectorAll('.tab').forEach(x => x.classList.remove('active'));
        document.querySelectorAll('.tab-content').forEach(x => x.classList.remove('active'));
        t.classList.add('active');
        document.getElementById('tab-' + t.dataset.tab).classList.add('active');
        if (t.dataset.tab === 'qr') initQrLogin();
    });
});

// QR login
let qrToken = null;
let qrPolling = null;

function initQrLogin() {
    if (qrToken) return;
    fetch('api.php?action=auth_qr_pending_create').then(r => r.json()).then(d => {
        if (!d.ok) return;
        qrToken = d.token;
        document.getElementById('qrBox').innerHTML = `<img src="${d.qr_img}" width="200" height="200" alt="QR">`;
        startPolling();
    });
}

function startPolling() {
    if (qrPolling) clearInterval(qrPolling);
    qrPolling = setInterval(() => {
        if (!qrToken) return;
        fetch('api.php?action=auth_qr_status&token=' + qrToken).then(r => r.json()).then(d => {
            const statusEl = document.getElementById('qrStatus');
            if (d.status === 'approved' && d.logged_in) {
                statusEl.className = 'qr-status approved';
                statusEl.innerHTML = '&#10003; Autorizado! Entrando...';
                clearInterval(qrPolling);
                setTimeout(() => location.href = 'index.php', 600);
            } else if (d.status === 'expired' || d.status === 'cancelled' || d.status === 'invalid') {
                statusEl.className = 'qr-status error';
                statusEl.textContent = d.status === 'cancelled' ? 'Cancelado desde el movil' : 'Expirado. Recarga para uno nuevo.';
                clearInterval(qrPolling);
            }
        }).catch(() => {});
    }, 2000);
}
</script>
</body>
</html>
