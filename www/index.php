<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

// QR login desde URL
if (isset($_GET['qr_login'])) {
    if (consumeQrToken($_GET['qr_login'])) {
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
        exit;
    }
    header('Location: login.php?qr=' . urlencode($_GET['qr_login']));
    exit;
}

// TODO protegido: sin sesion -> login.php
requireLogin();

$currentUser = currentUser();
$isAdmin = isAdmin();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Print Server DNNS</title>
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#69c350">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="PrintServer">
    <link rel="apple-touch-icon" href="icon-192.png">
    <link rel="icon" type="image/png" href="icon-192.png">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f5f5f5; color: #2c2c2c; min-height: 100vh; }

        .header { background: #fff; border-bottom: 1px solid #e0e0e0; padding: 12px 20px; display: flex; align-items: center; justify-content: space-between; box-shadow: 0 1px 3px rgba(0,0,0,.06); position: sticky; top: 0; z-index: 10; }
        .header h1 { font-size: 18px; font-weight: 600; }
        .header h1 span { color: #69c350; }
        .header h1 #serverClock { color: #888; font-weight: 400; font-size: 13px; margin-left: 14px; padding: 3px 10px; background: #f5f5f5; border-radius: 6px; font-variant-numeric: tabular-nums; }
        .header h1 #serverClock.out-of-sync { color: #c0392b; background: #fde8e8; }
        .header-right { display: flex; gap: 12px; align-items: center; }
        .status-pill { display: flex; align-items: center; gap: 5px; background: #fff; border: 1px solid #e0e0e0; padding: 4px 10px; border-radius: 16px; font-size: 12px; }
        .dot { width: 7px; height: 7px; border-radius: 50%; }
        .dot.g { background: #69c350; }
        .dot.r { background: #e74c3c; }
        .alert-badge { background: #e74c3c; color: #fff; font-size: 10px; padding: 2px 6px; border-radius: 10px; display: none; }

        .modal { position: fixed; inset: 0; background: rgba(0,0,0,.5); display: flex; align-items: center; justify-content: center; z-index: 100; }
        .modal.hidden { display: none; }
        .modal-content { background: #fff; border-radius: 10px; padding: 24px; min-width: 320px; max-width: 90vw; box-shadow: 0 8px 32px rgba(0,0,0,.2); }
        .locked-tab { opacity: .55; cursor: pointer; }
        .locked-tab::after { content: ' \1F512'; font-size: 10px; margin-left: auto; }
        .locked-tab:hover { opacity: .8; background: #f5f5f5; }

        .container { display: flex; min-height: calc(100vh - 49px); }
        .sidebar { width: 200px; background: #fff; border-right: 1px solid #e0e0e0; padding: 12px 0; flex-shrink: 0; }
        .nav-item { display: flex; align-items: center; gap: 8px; padding: 9px 16px; cursor: pointer; color: #888; font-size: 13px; transition: all .15s; }
        .nav-item:hover { background: #f5f5f5; color: #2c2c2c; }
        .nav-item.active { background: #edf7ea; color: #69c350; border-right: 3px solid #69c350; font-weight: 500; }
        .main { flex: 1; overflow-y: auto; padding: 20px; }

        .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; margin-bottom: 20px; }
        .stat-card { background: #fff; border: 1px solid #e0e0e0; border-radius: 10px; padding: 16px; box-shadow: 0 1px 2px rgba(0,0,0,.04); }
        .stat-card .label { font-size: 11px; color: #999; text-transform: uppercase; letter-spacing: .5px; }
        .stat-card .value { font-size: 24px; font-weight: 700; margin-top: 2px; }
        .stat-card .value.blue { color: #4a90d9; }
        .stat-card .value.green { color: #69c350; }
        .stat-card .value.orange { color: #f5a623; }
        .stat-card .value.red { color: #e74c3c; }

        .panel { background: #fff; border: 1px solid #e0e0e0; border-radius: 10px; margin-bottom: 16px; box-shadow: 0 1px 2px rgba(0,0,0,.04); }
        .panel-header { display: flex; align-items: center; justify-content: space-between; padding: 12px 16px; border-bottom: 1px solid #eee; }
        .panel-title { font-size: 14px; font-weight: 600; }
        .panel-body { padding: 16px; }

        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; font-size: 10px; text-transform: uppercase; letter-spacing: .5px; color: #999; padding: 6px 10px; border-bottom: 1px solid #eee; }
        td { padding: 8px 10px; border-bottom: 1px solid #f5f5f5; font-size: 13px; color: #444; }
        tr:hover td { background: #fafafa; }

        .badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 500; }
        .badge.activa, .badge.completado { background: #edf7ea; color: #3d8c27; }
        .badge.inactiva, .badge.cancelado { background: #f0f0f0; color: #888; }
        .badge.error { background: #fde8e8; color: #c0392b; }
        .badge.pendiente { background: #fef3e0; color: #d4850a; }
        .badge.imprimiendo { background: #e8f0fe; color: #3a7bd5; }

        .btn { padding: 6px 14px; border-radius: 6px; border: none; cursor: pointer; font-size: 12px; font-weight: 500; transition: all .15s; display: inline-flex; align-items: center; gap: 5px; }
        .btn-primary { background: #69c350; color: #fff; }
        .btn-primary:hover { background: #5aad42; }
        .btn-danger { background: #e74c3c; color: #fff; }
        .btn-danger:hover { background: #c0392b; }
        .btn-secondary { background: #e8e8e8; color: #444; }
        .btn-secondary:hover { background: #d5d5d5; }
        .btn-sm { padding: 4px 8px; font-size: 11px; }
        .btn:disabled { opacity: .5; cursor: not-allowed; }

        .form-group { margin-bottom: 12px; }
        .form-group label { display: block; font-size: 12px; color: #888; margin-bottom: 3px; }
        .form-group input, .form-group select { width: 100%; padding: 7px 10px; border-radius: 6px; border: 1px solid #ddd; background: #fff; color: #2c2c2c; font-size: 13px; }
        .form-group input:focus, .form-group select:focus { outline: none; border-color: #69c350; box-shadow: 0 0 0 2px rgba(105,195,80,.15); }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }

        .scan-results { max-height: 280px; overflow-y: auto; }
        .scan-item { display: flex; align-items: center; justify-content: space-between; padding: 10px; border: 1px solid #e0e0e0; border-radius: 6px; margin-bottom: 6px; background: #fafafa; }
        .scan-item:hover { border-color: #69c350; }
        .scan-info h4 { font-size: 13px; margin-bottom: 1px; }
        .scan-info p { font-size: 11px; color: #999; }
        .scan-ports { display: flex; gap: 3px; margin-top: 3px; }
        .scan-ports span { font-size: 10px; background: #e8e8e8; color: #555; padding: 1px 5px; border-radius: 3px; }

        .log-entry { padding: 6px 10px; border-left: 3px solid #ddd; margin-bottom: 4px; font-size: 12px; color: #444; }
        .log-entry.info { border-color: #69c350; }
        .log-entry.warning { border-color: #f5a623; }
        .log-entry.error { border-color: #e74c3c; }
        .log-time { color: #999; font-size: 10px; }

        .spinner { display: inline-block; width: 14px; height: 14px; border: 2px solid #ddd; border-top-color: #69c350; border-radius: 50%; animation: spin .6s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }
        .hidden { display: none; }

        .toast { position: fixed; bottom: 20px; right: 20px; background: #fff; border: 1px solid #e0e0e0; padding: 10px 16px; border-radius: 6px; font-size: 13px; z-index: 999; animation: slideIn .3s; box-shadow: 0 4px 12px rgba(0,0,0,.1); }
        .toast.success { border-color: #69c350; }
        .toast.error { border-color: #e74c3c; }
        @keyframes slideIn { from { transform: translateY(20px); opacity: 0; } }

        /* Upload area */
        .upload-zone { border: 2px dashed #ddd; border-radius: 8px; padding: 30px; text-align: center; cursor: pointer; transition: all .2s; color: #999; }
        .upload-zone:hover, .upload-zone.dragover { border-color: #69c350; background: #f5faf3; color: #69c350; }
        .upload-zone input { display: none; }

        /* Ink bars */
        .ink-bar { height: 6px; border-radius: 3px; background: #eee; margin-top: 3px; overflow: hidden; }
        .ink-bar .fill { height: 100%; border-radius: 3px; transition: width .3s; }
        .ink-label { font-size: 11px; display: flex; justify-content: space-between; margin-top: 6px; color: #666; }

        /* Chart */
        .chart-bars { display: flex; align-items: flex-end; gap: 3px; height: 120px; padding-top: 10px; }
        .chart-bar { flex: 1; background: #69c350; border-radius: 3px 3px 0 0; min-width: 8px; position: relative; transition: height .3s; }
        .chart-bar:hover { background: #5aad42; }
        .chart-bar .chart-tip { display: none; position: absolute; top: -20px; left: 50%; transform: translateX(-50%); background: #333; color: #fff; font-size: 10px; padding: 2px 6px; border-radius: 3px; white-space: nowrap; }
        .chart-bar:hover .chart-tip { display: block; }
        .chart-labels { display: flex; gap: 3px; font-size: 9px; color: #999; margin-top: 4px; }
        .chart-labels span { flex: 1; text-align: center; }

        /* Quota bar */
        .quota-bar { height: 8px; border-radius: 4px; background: #eee; overflow: hidden; }
        .quota-fill { height: 100%; border-radius: 4px; }
        .quota-fill.ok { background: #69c350; }
        .quota-fill.warn { background: #f5a623; }
        .quota-fill.over { background: #e74c3c; }

        /* Instructions */
        .steps { counter-reset: step; }
        .step { display: flex; gap: 12px; margin-bottom: 14px; }
        .step-num { width: 24px; height: 24px; background: #69c350; color: #fff; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 600; flex-shrink: 0; }
        .step-text { font-size: 13px; line-height: 1.5; }
        .step-text code { background: #f0f0f0; padding: 2px 6px; border-radius: 3px; font-size: 12px; font-family: monospace; }
        .os-tabs { display: flex; gap: 8px; margin-bottom: 16px; }
        .os-tab { padding: 6px 16px; border-radius: 6px; cursor: pointer; font-size: 13px; border: 1px solid #ddd; }
        .os-tab.active { background: #69c350; color: #fff; border-color: #69c350; }

        /* Responsive */
        @media (max-width: 768px) {
            .sidebar { display: none; }
            .container { flex-direction: column; }
            .main { padding: 12px; }
            .stats { grid-template-columns: repeat(2, 1fr); gap: 8px; }
            .form-row { grid-template-columns: 1fr; }
            .header { padding: 10px 14px; }
            .header h1 { font-size: 15px; }
            .mobile-nav { display: flex !important; }
        }
        .mobile-nav { display: none; position: fixed; bottom: 0; left: 0; right: 0; background: #fff; border-top: 1px solid #e0e0e0; padding: 6px 0; z-index: 10; justify-content: space-around; }
        .mobile-nav-item { display: flex; flex-direction: column; align-items: center; font-size: 10px; color: #888; cursor: pointer; padding: 4px 8px; }
        .mobile-nav-item.active { color: #69c350; }
    </style>
</head>
<body>

<div class="header">
    <h1>&#9113; Print Server <span>DNNS</span> <span id="serverClock" title="Hora del servidor"></span></h1>
    <div class="header-right">
        <span class="alert-badge" id="alertBadge">0</span>
        <div class="status-pill">
            <div class="dot" id="cupsStatus"></div>
            <span id="cupsLabel">CUPS</span>
        </div>
        <div class="status-pill" style="border-color:<?= $isAdmin ? '#69c350' : '#4a90d9' ?>;color:<?= $isAdmin ? '#3d8c27' : '#2667a8' ?>;">
            <div class="dot g"></div>
            <span><?= htmlspecialchars($currentUser['nombre']) ?> <?= $isAdmin ? '(Admin)' : '' ?></span>
        </div>
        <button class="btn btn-secondary btn-sm" onclick="openQrLogin()" title="QR para loguear otro dispositivo">QR</button>
        <button class="btn btn-secondary btn-sm" onclick="doLogout()">Salir</button>
    </div>
</div>

<!-- QR LOGIN MODAL -->
<div id="qrModal" class="modal hidden">
    <div class="modal-content" style="text-align:center;">
        <h3 style="margin-bottom:10px;font-size:16px;">&#128241; Login con QR</h3>
        <p style="color:#888;font-size:13px;margin-bottom:14px;">Escanea este QR desde otro dispositivo para iniciar sesi&oacute;n como <strong><?= htmlspecialchars($currentUser['email']) ?></strong>. V&aacute;lido 15 min.</p>
        <div id="qrImage" style="padding:14px;background:#fff;border:1px solid #eee;border-radius:8px;display:inline-block;"></div>
        <p id="qrUrl" style="font-size:10px;color:#999;margin-top:10px;word-break:break-all;"></p>
        <button class="btn btn-secondary" onclick="closeQr()" style="margin-top:10px;">Cerrar</button>
    </div>
</div>

<!-- REQUEST LICENSE MODAL -->
<div id="requestLicenseModal" class="modal hidden">
    <div class="modal-content" style="min-width:400px;max-width:480px;">
        <h3 style="margin-bottom:10px;font-size:16px;">&#128272; Solicitar licencia <span id="reqLicPlanName" style="color:#69c350;"></span></h3>
        <p id="reqLicUpgradeInfo" style="color:#888;font-size:12px;margin-bottom:14px;"></p>

        <div class="form-group"><label>Duraci&oacute;n</label>
            <select id="reqLicDuracion">
                <option value="1">1 mes</option>
                <option value="6" selected>6 meses</option>
                <option value="12">12 meses</option>
            </select>
        </div>
        <div class="form-group"><label>Email de contacto</label>
            <input type="email" id="reqLicEmail" value="<?= htmlspecialchars($currentUser['email']) ?>"></div>
        <div class="form-group"><label>Empresa</label>
            <input type="text" id="reqLicEmpresa" placeholder="Nombre de la empresa"></div>
        <div class="form-group"><label>Tel&eacute;fono (opcional)</label>
            <input type="text" id="reqLicTelefono"></div>
        <div class="form-group"><label>Notas (opcional)</label>
            <textarea id="reqLicNotas" rows="2" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:6px;font-family:inherit;font-size:13px;"></textarea></div>
        <input type="hidden" id="reqLicPlan">
        <div style="display:flex;gap:8px;justify-content:flex-end;">
            <button class="btn btn-secondary" onclick="closeRequestLicense()">Cancelar</button>
            <button class="btn btn-primary" onclick="sendRequestLicense()">Enviar solicitud</button>
        </div>
    </div>
</div>

<div class="container">
    <div class="sidebar">
        <div class="nav-item active" data-tab="dashboard">&#9632; Dashboard</div>
        <div class="nav-item" data-tab="queue">&#9776; Cola</div>
        <div class="nav-item" data-tab="print">&#10064; Imprimir</div>
        <div class="nav-item" data-tab="cartridges">&#9679; Cartuchos</div>
        <div class="nav-item" data-tab="connect">&#9432; Conectar PC</div>
        <div class="nav-item" data-tab="drivers">&#128190; Drivers</div>
        <?php if ($isAdmin): ?>
        <div style="padding:10px 16px 4px;font-size:10px;color:#bbb;text-transform:uppercase;letter-spacing:.5px;">Admin</div>
        <div class="nav-item" data-tab="printers">&#9113; Impresoras</div>
        <div class="nav-item" data-tab="stats">&#9733; Estad&iacute;sticas</div>
        <div class="nav-item" data-tab="quotas">&#9878; Cuotas</div>
        <div class="nav-item" data-tab="scanner">&#8982; Escanear Red</div>
        <div class="nav-item" data-tab="users">&#128101; Usuarios</div>
        <div class="nav-item" data-tab="ddns">&#127760; DDNS</div>
        <div class="nav-item" data-tab="system">&#9881; Sistema</div>
        <div class="nav-item" data-tab="license">&#128273; Licencia</div>
        <div class="nav-item" data-tab="audit">&#128269; Audit Log</div>
        <div class="nav-item" data-tab="log">&#9881; Log Actividad</div>
        <?php endif; ?>
    </div>

    <div class="main">
        <!-- DASHBOARD -->
        <div id="tab-dashboard" class="tab-content">
            <div class="stats">
                <div class="stat-card"><div class="label">Impresoras</div><div class="value blue" id="statPrinters">-</div></div>
                <div class="stat-card"><div class="label">Hoy</div><div class="value green" id="statJobsToday">-</div></div>
                <div class="stat-card"><div class="label">Cola</div><div class="value orange" id="statActiveJobs">-</div></div>
            </div>
            <div id="alertsBox"></div>
            <div class="panel">
                <div class="panel-header">
                    <span class="panel-title">Trabajos Activos</span>
                    <button class="btn btn-danger btn-sm" onclick="cancelAll()">Cancelar Todos</button>
                </div>
                <div class="panel-body">
                    <table><thead><tr><th>ID</th><th>Impresora</th><th>Usuario</th><th>Tamano</th><th>Fecha</th><th></th></tr></thead>
                    <tbody id="activeJobsBody"></tbody></table>
                </div>
            </div>
            <div class="panel" id="inkPanel" style="display:none">
                <div class="panel-header"><span class="panel-title">Estado Impresoras</span></div>
                <div class="panel-body" id="inkBody"></div>
            </div>
        </div>

        <!-- IMPRESORAS -->
        <div id="tab-printers" class="tab-content hidden">
            <div class="panel">
                <div class="panel-header">
                    <span class="panel-title">Impresoras Configuradas</span>
                    <button class="btn btn-primary btn-sm" onclick="toggleEl('addPrinterForm')">+ Agregar</button>
                </div>
                <div class="panel-body">
                    <div id="addPrinterForm" class="hidden" style="margin-bottom:16px;padding:14px;border:1px solid #e0e0e0;border-radius:8px;">
                        <div class="form-row">
                            <div class="form-group"><label>Nombre</label><input type="text" id="pName" placeholder="HP LaserJet Oficina"></div>
                            <div class="form-group"><label>IP</label><input type="text" id="pIP" placeholder="IP-IMPRESORA"></div>
                        </div>
                        <div class="form-row">
                            <div class="form-group"><label>URI (auto si vacio)</label><input type="text" id="pURI" placeholder="ipp://IP-IMPRESORA/ipp/print"></div>
                            <div class="form-group"><label>Ubicacion</label><input type="text" id="pLocation" placeholder="Oficina principal"></div>
                        </div>
                        <button class="btn btn-primary" onclick="addPrinter()">Guardar</button>
                        <button class="btn btn-secondary" onclick="toggleEl('addPrinterForm')">Cancelar</button>
                    </div>
                    <table><thead><tr><th>Nombre</th><th>IP</th><th>Conectar desde PC</th><th>Ubicacion</th><th>Estado</th><th></th></tr></thead>
                    <tbody id="printersBody"></tbody></table>
                </div>
            </div>
        </div>

        <!-- COLA -->
        <div id="tab-queue" class="tab-content hidden">
            <div class="panel">
                <div class="panel-header"><span class="panel-title">Cola de Impresion</span><button class="btn btn-secondary btn-sm" onclick="loadJobs()">Actualizar</button></div>
                <div class="panel-body">
                    <h3 style="font-size:13px;margin-bottom:10px;color:#888;">Activos</h3>
                    <table><thead><tr><th>ID</th><th>Impresora</th><th>Usuario</th><th>Tamano</th><th>Fecha</th><th></th></tr></thead>
                    <tbody id="queueActiveBody"></tbody></table>
                    <h3 style="font-size:13px;margin:16px 0 10px;color:#888;">Historial</h3>
                    <table><thead><tr><th>Documento</th><th>Impresora</th><th>Usuario</th><th>Estado</th><th>Fecha</th></tr></thead>
                    <tbody id="queueHistoryBody"></tbody></table>
                </div>
            </div>
        </div>

        <!-- IMPRIMIR -->
        <div id="tab-print" class="tab-content hidden">
            <div class="panel">
                <div class="panel-header"><span class="panel-title">Imprimir Archivo</span></div>
                <div class="panel-body">
                    <div class="form-row" style="margin-bottom:14px;">
                        <div class="form-group"><label>Impresora</label><select id="printPrinter"></select></div>
                        <div class="form-group"><label>Copias</label><input type="number" id="printCopies" value="1" min="1" max="99"></div>
                    </div>
                    <div class="upload-zone" id="uploadZone" onclick="document.getElementById('uploadFile').click()">
                        <input type="file" id="uploadFile" accept=".pdf,.jpg,.jpeg,.png,.gif,.bmp,.txt,.doc,.docx,.odt">
                        <div style="font-size:32px;margin-bottom:8px;">&#128424;</div>
                        <div>Arrastra un archivo o haz clic para seleccionar</div>
                        <div style="font-size:11px;margin-top:6px;">PDF, JPG, PNG, TXT, DOC, DOCX, ODT</div>
                    </div>
                    <div id="uploadInfo" class="hidden" style="margin-top:12px;padding:12px;border:1px solid #e0e0e0;border-radius:6px;display:flex;align-items:center;justify-content:space-between;">
                        <span id="uploadFileName"></span>
                        <button class="btn btn-primary" onclick="printFile()">Imprimir</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- ESTADISTICAS -->
        <div id="tab-stats" class="tab-content hidden">
            <div class="stats">
                <div class="stat-card"><div class="label">Hoy</div><div class="value green" id="stToday">-</div></div>
                <div class="stat-card"><div class="label">Semana</div><div class="value blue" id="stWeek">-</div></div>
                <div class="stat-card"><div class="label">Mes</div><div class="value orange" id="stMonth">-</div></div>
            </div>
            <div class="panel">
                <div class="panel-header"><span class="panel-title">Ultimos 30 dias</span></div>
                <div class="panel-body">
                    <div class="chart-bars" id="chartBars"></div>
                    <div class="chart-labels" id="chartLabels"></div>
                </div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                <div class="panel"><div class="panel-header"><span class="panel-title">Por Usuario</span></div>
                <div class="panel-body"><table><thead><tr><th>Usuario</th><th>Trabajos</th></tr></thead><tbody id="statsByUser"></tbody></table></div></div>
                <div class="panel"><div class="panel-header"><span class="panel-title">Por Impresora</span></div>
                <div class="panel-body"><table><thead><tr><th>Impresora</th><th>Trabajos</th></tr></thead><tbody id="statsByPrinter"></tbody></table></div></div>
            </div>
        </div>

        <!-- CUOTAS -->
        <div id="tab-quotas" class="tab-content hidden">
            <div class="panel">
                <div class="panel-header"><span class="panel-title">Cuotas por Usuario</span><button class="btn btn-primary btn-sm" onclick="toggleEl('addQuotaForm')">+ Cuota</button></div>
                <div class="panel-body">
                    <div id="addQuotaForm" class="hidden" style="margin-bottom:14px;padding:14px;border:1px solid #e0e0e0;border-radius:8px;">
                        <div class="form-row">
                            <div class="form-group"><label>Usuario</label><input type="text" id="qUser" placeholder="Oberlus"></div>
                            <div class="form-group"><label>Limite paginas/mes</label><input type="number" id="qLimit" value="100" min="1"></div>
                        </div>
                        <button class="btn btn-primary" onclick="setQuota()">Guardar</button>
                    </div>
                    <table><thead><tr><th>Usuario</th><th>Usado</th><th>Limite</th><th>Progreso</th></tr></thead>
                    <tbody id="quotasBody"></tbody></table>
                </div>
            </div>
        </div>

        <!-- CARTUCHOS -->
        <div id="tab-cartridges" class="tab-content hidden">
            <div class="panel">
                <div class="panel-header">
                    <span class="panel-title">Registrar Cambio de Cartucho</span>
                </div>
                <div class="panel-body">
                    <div class="form-row" style="margin-bottom:14px;">
                        <div class="form-group"><label>Impresora</label><select id="cartPrinter" onchange="loadCartridgeColors()"></select></div>
                        <div class="form-group"><label>Color</label><select id="cartColor"></select></div>
                    </div>
                    <button class="btn btn-primary" onclick="registerCartridgeChange()">Registrar Cambio</button>
                </div>
            </div>
            <div class="panel">
                <div class="panel-header"><span class="panel-title">Historial de Cambios</span></div>
                <div class="panel-body">
                    <table>
                        <thead><tr><th>Fecha</th><th>Impresora</th><th>Color</th><th>Nivel al cambiar</th><th>Paginas impresas</th><th>Rendimiento</th></tr></thead>
                        <tbody id="cartridgeHistory"></tbody>
                    </table>
                </div>
            </div>
            <div class="panel" id="cartridgeStatsPanel" style="display:none">
                <div class="panel-header"><span class="panel-title">Rendimiento medio por cartucho</span></div>
                <div class="panel-body" id="cartridgeStats"></div>
            </div>
        </div>

        <!-- ESCANER -->
        <div id="tab-scanner" class="tab-content hidden">
            <div class="panel">
                <div class="panel-header"><span class="panel-title">Buscar Impresoras en Red</span></div>
                <div class="panel-body">
                    <div style="display:flex;gap:10px;margin-bottom:16px;">
                        <input type="text" id="scanRange" value="RANGO-RED-LOCAL" style="padding:6px 10px;border-radius:6px;border:1px solid #ddd;width:200px;font-size:13px;">
                        <button class="btn btn-primary" id="scanBtn" onclick="scanNetwork()">Escanear</button>
                    </div>
                    <div id="scanStatus" class="hidden" style="margin-bottom:12px;color:#888;font-size:13px;"><span class="spinner"></span> Escaneando red...</div>
                    <div class="scan-results" id="scanResults"></div>
                </div>
            </div>
        </div>

        <!-- CONECTAR PC -->
        <div id="tab-connect" class="tab-content hidden">
            <div class="panel">
                <div class="panel-header"><span class="panel-title">Como Conectar tu PC</span></div>
                <div class="panel-body">
                    <div class="os-tabs">
                        <div class="os-tab active" onclick="showOS('win')">Windows</div>
                        <div class="os-tab" onclick="showOS('mac')">Mac</div>
                        <div class="os-tab" onclick="showOS('linux')">Linux</div>
                    </div>
                    <div id="os-win" class="os-content">
                        <div class="steps">
                            <div class="step"><div class="step-num">1</div><div class="step-text">Abre <strong>Configuracion</strong> > <strong>Impresoras y escaneres</strong> > <strong>Agregar impresora</strong></div></div>
                            <div class="step"><div class="step-num">2</div><div class="step-text">Clic en <strong>"La impresora que quiero no aparece"</strong></div></div>
                            <div class="step"><div class="step-num">3</div><div class="step-text">Selecciona <strong>"Seleccionar una impresora compartida por nombre"</strong></div></div>
                            <div class="step"><div class="step-num">4</div><div class="step-text">Pega la URL de la impresora (la encuentras en la seccion <strong>Impresoras</strong> del panel)</div></div>
                            <div class="step"><div class="step-num">5</div><div class="step-text">Cuando pida driver, selecciona <strong>Microsoft PWG Raster Class Driver</strong></div></div>
                            <div class="step"><div class="step-num">6</div><div class="step-text">Dale un nombre y marca como predeterminada si quieres</div></div>
                        </div>
                    </div>
                    <div id="os-mac" class="os-content hidden">
                        <div class="steps">
                            <div class="step"><div class="step-num">1</div><div class="step-text">Abre <strong>Preferencias del Sistema</strong> > <strong>Impresoras y Escaneres</strong></div></div>
                            <div class="step"><div class="step-num">2</div><div class="step-text">Clic en <strong>+</strong> y selecciona la pestana <strong>IP</strong></div></div>
                            <div class="step"><div class="step-num">3</div><div class="step-text">Protocolo: <strong>IPP</strong>, Direccion: <code id="macAddr">IP-DEL-SERVER</code>, Cola: <code>printers/Epson_WF2840</code></div></div>
                            <div class="step"><div class="step-num">4</div><div class="step-text">Selecciona driver <strong>Generic IPP Everywhere</strong></div></div>
                        </div>
                    </div>
                    <div id="os-linux" class="os-content hidden">
                        <div class="steps">
                            <div class="step"><div class="step-num">1</div><div class="step-text">Abre un terminal y ejecuta:</div></div>
                            <div class="step"><div class="step-num">2</div><div class="step-text"><code id="linuxCmd">lpadmin -p Epson -E -v ipp://IP-DEL-SERVER:631/printers/Epson_WF2840 -m everywhere</code></div></div>
                            <div class="step"><div class="step-num">3</div><div class="step-text">O abre <strong>http://localhost:631</strong> en el navegador y agrega impresora IPP</div></div>
                        </div>
                    </div>
                    <div class="panel" style="margin-top:16px;">
                        <div class="panel-header"><span class="panel-title">URLs de Conexion</span></div>
                        <div class="panel-body" id="connectURLs"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- USUARIOS -->
        <div id="tab-users" class="tab-content hidden">
            <div class="panel">
                <div class="panel-header">
                    <span class="panel-title">Usuarios del Sistema</span>
                    <button class="btn btn-primary btn-sm" onclick="toggleEl('addUserForm')">+ Nuevo Usuario</button>
                </div>
                <div class="panel-body">
                    <div id="addUserForm" class="hidden" style="margin-bottom:14px;padding:14px;border:1px solid #e0e0e0;border-radius:8px;">
                        <h4 style="margin-bottom:10px;font-size:14px;">Nuevo Usuario</h4>
                        <div class="form-row">
                            <div class="form-group"><label>Email</label><input type="email" id="uEmail" placeholder="usuario@empresa.com"></div>
                            <div class="form-group"><label>Nombre</label><input type="text" id="uNombre" placeholder="Nombre Apellido"></div>
                        </div>
                        <div class="form-row">
                            <div class="form-group"><label>Contrase&ntilde;a (min 8)</label><input type="password" id="uPassword"></div>
                            <div class="form-group"><label>Rol</label><select id="uRol"><option value="user">Usuario</option><option value="admin">Administrador</option></select></div>
                        </div>
                        <div class="form-group"><label>Tel&eacute;fono (opcional)</label><input type="text" id="uTelefono"></div>
                        <button class="btn btn-primary" onclick="createUser()">Crear Usuario</button>
                        <button class="btn btn-secondary" onclick="toggleEl('addUserForm')">Cancelar</button>
                    </div>
                    <table>
                        <thead><tr><th>Email</th><th>Nombre</th><th>Rol</th><th>Estado</th><th>&Uacute;ltimo Login</th><th>IP</th><th></th></tr></thead>
                        <tbody id="usersBody"></tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- SISTEMA -->
        <div id="tab-system" class="tab-content hidden">
            <div class="stats">
                <div class="stat-card"><div class="label">Hora Servidor</div><div class="value green" id="sysClock" style="font-size:20px;font-variant-numeric:tabular-nums;">--:--:--</div></div>
                <div class="stat-card"><div class="label">Zona Horaria</div><div class="value blue" id="sysTz" style="font-size:16px;">-</div></div>
                <div class="stat-card"><div class="label">NTP Sincronizado</div><div class="value" id="sysNtp" style="font-size:18px;">-</div></div>
            </div>

            <div class="panel">
                <div class="panel-header"><span class="panel-title">Configuraci&oacute;n del Sistema</span></div>
                <div class="panel-body">
                    <div class="form-row">
                        <div class="form-group">
                            <label>Zona horaria del servidor</label>
                            <select id="sysTzSelect"></select>
                        </div>
                        <div class="form-group" style="align-self:end;">
                            <button class="btn btn-primary" onclick="changeTz()">Cambiar zona</button>
                        </div>
                    </div>
                    <p style="font-size:11px;color:#888;margin-top:4px;">Al cambiar la zona, las horas mostradas se actualizar&aacute;n en todo el panel. Requiere recargar.</p>
                </div>
            </div>

            <div class="panel">
                <div class="panel-header"><span class="panel-title">Informaci&oacute;n del Servidor</span></div>
                <div class="panel-body">
                    <table>
                        <tbody id="sysInfoBody"></tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- DDNS -->
        <div id="tab-ddns" class="tab-content hidden">
            <div class="panel">
                <div class="panel-header"><span class="panel-title">DNS Din&aacute;mico (DDNS)</span></div>
                <div class="panel-body">
                    <p style="color:#888;font-size:13px;margin-bottom:14px;">
                        Si este server est&aacute; en una oficina con IP p&uacute;blica din&aacute;mica, configura tu hostname de Dynu / DuckDNS / No-IP / Afraid para que se actualice autom&aacute;ticamente. As&iacute; siempre podr&aacute;s acceder por tu subdominio.
                    </p>

                    <div id="ddnsStatusBox" style="background:#f5f5f5;border-radius:8px;padding:12px;margin-bottom:14px;font-size:13px;">
                        <div>Estado: <strong id="ddnsStatusState">-</strong></div>
                        <div>IP p&uacute;blica: <strong id="ddnsLastIp">-</strong></div>
                        <div>&Uacute;ltimo intento: <span id="ddnsLastUpdate">-</span></div>
                        <div>&Uacute;ltimo OK: <span id="ddnsLastSuccess">-</span></div>
                        <div>Mensaje: <em id="ddnsLastStatus">-</em></div>
                    </div>

                    <div class="form-row">
                        <div class="form-group"><label>Habilitar DDNS</label>
                            <select id="ddnsEnabled"><option value="0">Desactivado</option><option value="1">Activado</option></select></div>
                        <div class="form-group"><label>Proveedor</label>
                            <select id="ddnsProvider">
                                <option value="dynu">Dynu</option>
                                <option value="duckdns">DuckDNS</option>
                                <option value="noip">No-IP</option>
                                <option value="afraid">Afraid.org (FreeDNS)</option>
                            </select></div>
                    </div>
                    <div class="form-group"><label>Hostname (p.ej. mioficina.dynu.net)</label>
                        <input type="text" id="ddnsHostname" placeholder="subdominio.dynu.net"></div>
                    <div class="form-row">
                        <div class="form-group"><label id="lblDdnsUser">Usuario</label>
                            <input type="text" id="ddnsUsername" placeholder="usuario Dynu / No-IP"></div>
                        <div class="form-group"><label id="lblDdnsPass">Contrase&ntilde;a / Token</label>
                            <input type="password" id="ddnsPassword" placeholder="dejar vac&iacute;o para no cambiar"></div>
                    </div>
                    <div class="form-group" style="max-width:240px;">
                        <label>Intervalo de actualizaci&oacute;n (segundos)</label>
                        <input type="number" id="ddnsInterval" min="60" value="300">
                    </div>

                    <button class="btn btn-primary" onclick="saveDdns()">Guardar</button>
                    <button class="btn btn-secondary" onclick="testDdns()">Actualizar ahora</button>

                    <p style="font-size:11px;color:#888;margin-top:14px;line-height:1.5;">
                        <strong>Dynu</strong> / <strong>No-IP</strong>: introduce usuario y password (o IP Update Password en Dynu).<br>
                        <strong>DuckDNS</strong>: deja Usuario vac&iacute;o y pon el token en Contrase&ntilde;a.<br>
                        <strong>Afraid.org</strong>: deja Usuario vac&iacute;o y pon el token en Contrase&ntilde;a.<br>
                        El server se actualizar&aacute; autom&aacute;ticamente cada <em>intervalo</em> segundos y cuando detecte cambio de IP.
                    </p>
                </div>
            </div>
        </div>

        <!-- LICENCIA -->
        <div id="tab-license" class="tab-content hidden">
            <div class="panel">
                <div class="panel-header"><span class="panel-title">Licencia Actual</span></div>
                <div class="panel-body">
                    <div id="licenseStatusBox" style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px;">
                        <div class="stat-card" style="background:linear-gradient(135deg,#69c350 0%,#3d8c27 100%);color:#fff;">
                            <div class="label" style="color:rgba(255,255,255,.8);">Plan</div>
                            <div class="value" style="color:#fff;font-size:26px;" id="licPlanName">-</div>
                            <div id="licPlanDesc" style="font-size:12px;color:rgba(255,255,255,.8);margin-top:2px;"></div>
                        </div>
                        <div class="stat-card">
                            <div class="label">Activa desde</div>
                            <div style="font-size:14px;color:#444;" id="licActivatedAt">-</div>
                            <div style="font-size:11px;color:#888;margin-top:2px;" id="licExpires"></div>
                        </div>
                    </div>

                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px;">
                        <div class="panel" style="margin:0;"><div class="panel-header"><span class="panel-title" style="font-size:13px;">&#9113; Impresoras</span></div>
                            <div class="panel-body"><div style="font-size:22px;font-weight:700;" id="licUsagePrinters">0 / 0</div>
                            <div class="quota-bar" style="margin-top:8px;"><div class="quota-fill ok" id="licBarPrinters" style="width:0%"></div></div></div>
                        </div>
                        <div class="panel" style="margin:0;"><div class="panel-header"><span class="panel-title" style="font-size:13px;">&#128101; Usuarios</span></div>
                            <div class="panel-body"><div style="font-size:22px;font-weight:700;" id="licUsageUsers">0 / 0</div>
                            <div class="quota-bar" style="margin-top:8px;"><div class="quota-fill ok" id="licBarUsers" style="width:0%"></div></div></div>
                        </div>
                    </div>

                    <div style="background:#f8f8f8;border-radius:8px;padding:12px;margin-bottom:14px;">
                        <div style="font-size:11px;color:#888;text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;">Hardware ID</div>
                        <div style="display:flex;align-items:center;gap:8px;">
                            <code id="licHwId" style="font-family:monospace;font-size:12px;flex:1;word-break:break-all;">-</code>
                            <button class="btn btn-secondary btn-sm" onclick="copyHwId()">Copiar</button>
                        </div>
                        <p style="font-size:11px;color:#888;margin-top:6px;">Identifica este server de forma &uacute;nica. Al pedir una licencia, env&iacute;a este ID.</p>
                    </div>
                </div>
            </div>

            <div class="panel">
                <div class="panel-header"><span class="panel-title">Activar Licencia</span></div>
                <div class="panel-body">
                    <p style="color:#888;font-size:13px;margin-bottom:12px;">
                        Introduce el c&oacute;digo de licencia que te enviamos (ej. <code>PRO-12345</code>).
                    </p>
                    <div style="display:flex;gap:8px;max-width:460px;">
                        <input type="text" id="licCode" placeholder="PRO-XXX o BASIC-XXX o ENTERPRISE-XXX" style="flex:1;padding:10px;border:1px solid #ddd;border-radius:6px;font-size:14px;">
                        <button class="btn btn-primary" onclick="activateLicense()">Activar</button>
                    </div>
                    <p style="font-size:11px;color:#888;margin-top:10px;">
                        &iquest;No tienes c&oacute;digo? <a href="mailto:info@dnns.es?subject=Licencia%20Print%20Server" style="color:#69c350;">Solic&iacute;talo</a> enviando tu Hardware ID.
                    </p>
                </div>
            </div>

            <div class="panel">
                <div class="panel-header"><span class="panel-title">Planes disponibles</span></div>
                <div class="panel-body">
                    <div id="licPlansGrid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;"></div>
                </div>
            </div>
        </div>

        <!-- AUDIT LOG -->
        <div id="tab-audit" class="tab-content hidden">
            <div class="panel">
                <div class="panel-header">
                    <span class="panel-title">Audit Log - Registro de auditor&iacute;a</span>
                    <button class="btn btn-secondary btn-sm" onclick="loadAudit()">Actualizar</button>
                </div>
                <div class="panel-body">
                    <p style="font-size:12px;color:#888;margin-bottom:10px;">&Uacute;ltimos 100 eventos. Se conservan 90 d&iacute;as.</p>
                    <table>
                        <thead><tr><th>Fecha</th><th>Usuario</th><th>Acci&oacute;n</th><th>Detalle</th><th>IP</th></tr></thead>
                        <tbody id="auditBody"></tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- DRIVERS -->
        <div id="tab-drivers" class="tab-content hidden">
            <div class="panel">
                <div class="panel-header"><span class="panel-title">Drivers de Impresoras</span></div>
                <div class="panel-body">
                    <p style="color:#888;font-size:13px;margin-bottom:14px;">
                        Descarga desde aqui los drivers para instalar en los PCs cliente. El administrador puede subir manualmente drivers o usar el boton "Buscar automatico".
                    </p>
                    <div id="driversContainer"></div>
                </div>
            </div>
        </div>

        <!-- UPLOAD DRIVER MODAL -->
        <div id="uploadDriverModal" class="modal hidden">
            <div class="modal-content" style="min-width:420px;max-width:500px;">
                <h3 style="margin-bottom:14px;font-size:16px;">A&ntilde;adir Driver</h3>
                <div class="form-group"><label>Impresora</label>
                    <input type="text" id="uploadDriverPrinterName" readonly style="background:#f5f5f5;"></div>

                <div style="display:flex;gap:6px;margin-bottom:14px;border-bottom:1px solid #eee;">
                    <div id="tabUploadUrl" class="driver-tab active" onclick="switchDriverTab('url')" style="padding:8px 14px;cursor:pointer;border-bottom:2px solid #69c350;font-size:13px;font-weight:500;">Desde URL (recomendado)</div>
                    <div id="tabUploadFile" class="driver-tab" onclick="switchDriverTab('file')" style="padding:8px 14px;cursor:pointer;border-bottom:2px solid transparent;font-size:13px;color:#888;">Subir archivo</div>
                </div>

                <div class="form-group"><label>Sistema Operativo</label>
                    <select id="uploadDriverOS">
                        <option value="windows">Windows</option>
                        <option value="mac">macOS</option>
                        <option value="linux">Linux</option>
                        <option value="generic">Gen&eacute;rico (multi-OS)</option>
                    </select></div>
                <div class="form-group"><label>Descripci&oacute;n (opcional)</label>
                    <input type="text" id="uploadDriverName" placeholder="Ej: Windows 10/11 64-bit v1.2.5"></div>

                <div id="uploadUrlSection">
                    <div class="form-group"><label>URL directa del driver</label>
                        <input type="text" id="uploadDriverUrl" placeholder="https://download.brother.com/...">
                        <p style="font-size:11px;color:#888;margin-top:4px;line-height:1.4;">
                            1) Pulsa <strong>&laquo;P&aacute;gina oficial&raquo;</strong> para ir al fabricante.<br>
                            2) Encuentra el driver correcto y acepta los t&eacute;rminos si los hay.<br>
                            3) Click derecho sobre el bot&oacute;n de descarga &rarr; <strong>Copiar direcci&oacute;n del enlace</strong>.<br>
                            4) Pega la URL aqu&iacute; y dale a <strong>Descargar</strong>.
                        </p>
                    </div>
                </div>

                <div id="uploadFileSection" class="hidden">
                    <div class="form-group"><label>Archivo</label>
                        <input type="file" id="uploadDriverFile">
                        <p style="font-size:11px;color:#888;margin-top:4px;">M&aacute;ximo 500 MB. Formatos: exe, dmg, pkg, deb, rpm, zip...</p>
                    </div>
                </div>

                <input type="hidden" id="uploadDriverPrinterId">
                <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:10px;">
                    <button class="btn btn-secondary" onclick="closeUploadDriver()">Cancelar</button>
                    <button class="btn btn-primary" id="uploadDriverBtn" onclick="doUploadDriver()">Descargar</button>
                </div>
            </div>
        </div>

        <!-- LOG -->
        <div id="tab-log" class="tab-content hidden">
            <div class="panel">
                <div class="panel-header"><span class="panel-title">Registro de Actividad</span></div>
                <div class="panel-body" id="logBody"></div>
            </div>
        </div>
    </div>
</div>

<!-- Mobile nav -->
<div class="mobile-nav">
    <div class="mobile-nav-item active" data-tab="dashboard">&#9632;<br>Home</div>
    <div class="mobile-nav-item" data-tab="printers">&#9113;<br>Print</div>
    <div class="mobile-nav-item" data-tab="queue">&#9776;<br>Cola</div>
    <div class="mobile-nav-item" data-tab="print">&#10064;<br>Enviar</div>
    <div class="mobile-nav-item" data-tab="log">&#9881;<br>Log</div>
</div>

<script>
const API = 'api.php';
let autoRefresh;

const IS_ADMIN = <?= $isAdmin ? 'true' : 'false' ?>;

function api(action, data = {}) {
    const fd = new FormData();
    fd.append('action', action);
    for (const [k, v] of Object.entries(data)) fd.append(k, v);
    return fetch(API, { method: 'POST', body: fd }).then(r => r.json()).then(d => {
        if (d && d.need_login) { openLogin(); return d; }
        return d;
    });
}
function apiGet(action) { return fetch(API + '?action=' + action).then(r => r.json()); }
function toast(msg, type = 'success') {
    const t = document.createElement('div');
    t.className = 'toast ' + type;
    t.textContent = msg;
    document.body.appendChild(t);
    setTimeout(() => t.remove(), 3000);
}
function toggleEl(id) { document.getElementById(id).classList.toggle('hidden'); }

// === AUTH ===
function doLogout() {
    api('auth_logout').then(() => { location.href = 'login.php'; });
}
function openQrLogin() {
    fetch(API + '?action=auth_qr_generate').then(r => r.json()).then(d => {
        if (!d.ok) { toast('Error generando QR', 'error'); return; }
        document.getElementById('qrImage').innerHTML = `<img src="${d.qr_img}" alt="QR" style="display:block;">`;
        document.getElementById('qrUrl').textContent = d.url;
        document.getElementById('qrModal').classList.remove('hidden');
    });
}
function closeQr() { document.getElementById('qrModal').classList.add('hidden'); }

// === USUARIOS ===
function loadUsers() {
    apiGet('list_users').then(d => {
        if (!d.ok) return;
        const me = <?= json_encode($currentUser) ?>;
        document.getElementById('usersBody').innerHTML = d.users.length ? d.users.map(u => `
            <tr>
                <td><strong>${u.email}</strong></td>
                <td>${u.nombre || '-'}</td>
                <td><span class="badge ${u.rol === 'admin' ? 'completado' : 'imprimiendo'}">${u.rol}</span></td>
                <td><span class="badge ${u.activo == 1 ? 'activa' : 'cancelado'}">${u.activo == 1 ? 'activo' : 'inactivo'}</span></td>
                <td style="font-size:11px;color:#888;">${u.last_login || 'nunca'}</td>
                <td style="font-size:11px;color:#888;">${u.last_ip || '-'}</td>
                <td>
                    <button class="btn btn-secondary btn-sm" onclick="editUser(${u.id},'${u.email}','${(u.nombre||'').replace(/'/g,'')}','${u.rol}',${u.activo})">Editar</button>
                    ${u.id !== me.id ? `<button class="btn btn-danger btn-sm" onclick="deleteUser(${u.id},'${u.email}')">X</button>` : ''}
                </td>
            </tr>`).join('') : '<tr><td colspan="7" style="text-align:center;color:#999;">Sin usuarios</td></tr>';
    });
}
function createUser() {
    const data = {
        email: document.getElementById('uEmail').value,
        nombre: document.getElementById('uNombre').value,
        password: document.getElementById('uPassword').value,
        rol: document.getElementById('uRol').value,
        telefono: document.getElementById('uTelefono').value,
    };
    api('create_user', data).then(d => {
        if (d.ok) { toast('Usuario creado'); toggleEl('addUserForm'); loadUsers();
            ['uEmail','uNombre','uPassword','uTelefono'].forEach(id => document.getElementById(id).value = '');
        } else toast(d.error, 'error');
    });
}
function editUser(id, email, nombre, rol, activo) {
    const newPass = prompt(`Editar ${email}\\n\\nDejar en blanco para no cambiar contrase\u00f1a.\\n\\nNueva contrase\u00f1a (o Enter para saltar):`);
    const data = { id };
    if (newPass !== null && newPass !== '') data.password = newPass;
    const toggleActive = confirm(`${email} esta ${activo==1?'ACTIVO':'INACTIVO'}. \u00bfToggle activo/inactivo?`);
    if (toggleActive) data.activo = activo == 1 ? 0 : 1;
    if (Object.keys(data).length === 1) { toast('Sin cambios'); return; }
    api('update_user', data).then(d => {
        if (d.ok) { toast('Usuario actualizado'); loadUsers(); }
        else toast(d.error, 'error');
    });
}
function deleteUser(id, email) {
    if (!confirm('Eliminar usuario ' + email + '?')) return;
    api('delete_user', { id }).then(d => {
        if (d.ok) { toast('Usuario eliminado'); loadUsers(); }
    });
}

// === AUDIT LOG ===
function loadAudit() {
    apiGet('list_audit_log').then(d => {
        if (!d.ok) return;
        document.getElementById('auditBody').innerHTML = d.log.length ? d.log.map(l => `
            <tr>
                <td style="font-size:11px;color:#666;">${l.created_at}</td>
                <td>${l.usuario_email || '-'}</td>
                <td><span class="badge ${l.action.includes('failed') ? 'error' : l.action.includes('delete') ? 'cancelado' : 'activa'}">${l.action}</span></td>
                <td style="font-size:12px;">${l.detalle || ''}</td>
                <td style="font-size:11px;color:#888;">${l.ip || ''}</td>
            </tr>`).join('') : '<tr><td colspan="5" style="text-align:center;color:#999;">Sin eventos</td></tr>';
    });
}

// === DDNS ===
function loadDdns() {
    apiGet('ddns_get').then(d => {
        if (!d.ok) return;
        const c = d.config;
        document.getElementById('ddnsEnabled').value = c.ddns_enabled || '0';
        document.getElementById('ddnsProvider').value = c.ddns_provider || 'dynu';
        document.getElementById('ddnsHostname').value = c.ddns_hostname || '';
        document.getElementById('ddnsUsername').value = c.ddns_username || '';
        document.getElementById('ddnsInterval').value = c.ddns_interval || 300;
        document.getElementById('ddnsPassword').placeholder = c.ddns_has_password ? '••••••• (guardado, deja vacio para no cambiar)' : 'contrase\u00f1a / token';
        document.getElementById('ddnsStatusState').textContent = c.ddns_enabled === '1' ? 'Activo' : 'Desactivado';
        document.getElementById('ddnsStatusState').style.color = c.ddns_enabled === '1' ? '#3d8c27' : '#888';
        document.getElementById('ddnsLastIp').textContent = c.ddns_last_ip || '-';
        document.getElementById('ddnsLastUpdate').textContent = c.ddns_last_update || 'nunca';
        document.getElementById('ddnsLastSuccess').textContent = c.ddns_last_success || 'nunca';
        document.getElementById('ddnsLastStatus').textContent = c.ddns_last_status || '-';
        updateDdnsLabels();
    });
}
function updateDdnsLabels() {
    const p = document.getElementById('ddnsProvider').value;
    const needsUser = (p === 'dynu' || p === 'noip');
    document.getElementById('lblDdnsUser').textContent = needsUser ? 'Usuario' : 'Usuario (no usado)';
    document.getElementById('ddnsUsername').disabled = !needsUser;
    document.getElementById('lblDdnsPass').textContent = needsUser ? 'Contrase\u00f1a' : 'Token';
}
document.addEventListener('change', e => {
    if (e.target && e.target.id === 'ddnsProvider') updateDdnsLabels();
});

function saveDdns() {
    const data = {
        enabled: document.getElementById('ddnsEnabled').value,
        provider: document.getElementById('ddnsProvider').value,
        hostname: document.getElementById('ddnsHostname').value.trim(),
        username: document.getElementById('ddnsUsername').value.trim(),
        password: document.getElementById('ddnsPassword').value,
        interval: document.getElementById('ddnsInterval').value,
    };
    if (data.enabled === '1' && !data.hostname) { toast('Hostname obligatorio', 'error'); return; }
    toast('Guardando...');
    api('ddns_save', data).then(d => {
        if (!d.ok) { toast(d.error || 'Error', 'error'); return; }
        toast('Guardado. ' + (d.test ? (d.test.ok ? 'Test OK' : 'Test KO: ' + (d.test.msg||'')) : ''));
        document.getElementById('ddnsPassword').value = '';
        loadDdns();
    });
}
function testDdns() {
    toast('Forzando actualizacion...');
    api('ddns_test').then(d => {
        toast(d.ok ? 'OK: ' + d.msg : 'Error: ' + d.msg, d.ok ? 'success' : 'error');
        loadDdns();
    });
}

// === SISTEMA ===
function fmtBytes(b) {
    if (b >= 1073741824) return (b/1073741824).toFixed(1) + ' GB';
    if (b >= 1048576) return (b/1048576).toFixed(0) + ' MB';
    if (b >= 1024) return (b/1024).toFixed(0) + ' KB';
    return b + ' B';
}
function loadSystem() {
    apiGet('system_info').then(d => {
        if (!d.ok) return;
        const pad = n => String(n).padStart(2, '0');
        const now = new Date(d.timestamp * 1000);
        document.getElementById('sysClock').textContent = `${pad(now.getHours())}:${pad(now.getMinutes())}:${pad(now.getSeconds())}`;
        document.getElementById('sysTz').textContent = d.timezone;
        const ntp = document.getElementById('sysNtp');
        ntp.textContent = d.ntp_sync ? 'S\u00ed' : 'No';
        ntp.style.color = d.ntp_sync ? '#3d8c27' : '#c0392b';

        // Info table
        const rows = [
            ['Hora actual', d.datetime],
            ['Zona horaria', d.timezone + ' (' + d.tz_name_short + ', UTC' + d.tz_offset + ')'],
            ['NTP sincronizado', d.ntp_sync ? 'S&iacute;' : 'No'],
            ['Hostname', d.hostname],
            ['Uptime', d.uptime || '-'],
            ['Load average (1/5/15 min)', d.load_avg ? d.load_avg.join(' / ') : '-'],
            ['PHP version', d.php_version],
            ['Memoria total', fmtBytes(d.mem_total)],
            ['Memoria libre', fmtBytes(d.mem_free) + ' (' + Math.round(d.mem_free / d.mem_total * 100) + '%)'],
            ['Disco total', fmtBytes(d.disk_total)],
            ['Disco libre', fmtBytes(d.disk_free) + ' (' + Math.round(d.disk_free / d.disk_total * 100) + '%)'],
        ];
        document.getElementById('sysInfoBody').innerHTML = rows.map(r =>
            `<tr><td style="font-weight:500;width:220px;">${r[0]}</td><td>${r[1]}</td></tr>`
        ).join('');
    });

    // Cargar lista de timezones una sola vez
    if (!window._tzList) {
        apiGet('list_timezones').then(d => {
            if (!d.ok) return;
            window._tzList = d.timezones;
            const sel = document.getElementById('sysTzSelect');
            sel.innerHTML = d.timezones.map(t => `<option value="${t}">${t}</option>`).join('');
            apiGet('system_info').then(s => { if (s.ok) sel.value = s.timezone; });
        });
    }
}
function changeTz() {
    const tz = document.getElementById('sysTzSelect').value;
    if (!confirm('Cambiar zona horaria a ' + tz + '?')) return;
    api('set_timezone', { timezone: tz }).then(d => {
        if (d.ok) { toast('Zona cambiada. Recargando...'); setTimeout(() => location.reload(), 1500); }
        else toast(d.output || 'Error', 'error');
    });
}

// === LICENCIA ===
function loadLicense() {
    apiGet('license_info').then(d => {
        if (!d.ok) return;
        const lic = d.licencia;
        const plan = d.plan;
        const usage = d.usage;
        document.getElementById('licPlanName').textContent = plan.nombre;
        document.getElementById('licPlanDesc').textContent = plan.descripcion || '';
        document.getElementById('licActivatedAt').textContent = lic.activated_at || '-';
        document.getElementById('licExpires').textContent = lic.expires_at ? 'Expira: ' + lic.expires_at : 'Sin expiracion';
        document.getElementById('licHwId').textContent = d.hardware_id;

        const maxP = parseInt(lic.max_impresoras);
        const maxU = parseInt(lic.max_usuarios);
        document.getElementById('licUsagePrinters').textContent = `${usage.impresoras} / ${maxP}`;
        document.getElementById('licUsageUsers').textContent = `${usage.usuarios} / ${maxU}`;
        const pctP = Math.min(100, Math.round(usage.impresoras / maxP * 100));
        const pctU = Math.min(100, Math.round(usage.usuarios / maxU * 100));
        const barP = document.getElementById('licBarPrinters');
        const barU = document.getElementById('licBarUsers');
        barP.style.width = pctP + '%';
        barU.style.width = pctU + '%';
        barP.className = 'quota-fill ' + (pctP >= 100 ? 'over' : pctP >= 80 ? 'warn' : 'ok');
        barU.className = 'quota-fill ' + (pctU >= 100 ? 'over' : pctU >= 80 ? 'warn' : 'ok');

        // Planes disponibles
        const plansDiv = document.getElementById('licPlansGrid');
        plansDiv.innerHTML = Object.entries(d.plans_available).map(([key, p]) => {
            const isCurrent = key === lic.tipo;
            return `<div style="padding:14px;border:2px solid ${isCurrent?'#69c350':'#e0e0e0'};border-radius:8px;${isCurrent?'background:#edf7ea;':''}">
                <div style="font-size:16px;font-weight:700;color:${isCurrent?'#3d8c27':'#2c2c2c'};">${p.nombre} ${isCurrent?'<span style="font-size:10px;background:#69c350;color:#fff;padding:2px 8px;border-radius:10px;margin-left:6px;">ACTUAL</span>':''}</div>
                <div style="font-size:13px;color:#888;margin-top:2px;">${p.descripcion}</div>
                ${isCurrent ? '' : `<button class="btn btn-primary btn-sm" style="margin-top:10px;" onclick="openRequestLicense('${key}','${p.nombre}')">Solicitar ${p.nombre}</button>`}
                <div style="font-size:12px;color:#666;margin-top:12px;">
                    &bull; Hasta ${p.max_impresoras} impresora${p.max_impresoras!=1?'s':''}<br>
                    &bull; Hasta ${p.max_usuarios} usuario${p.max_usuarios!=1?'s':''}
                </div>
            </div>`;
        }).join('');
    });
}

function copyHwId() {
    const hw = document.getElementById('licHwId').textContent;
    navigator.clipboard.writeText(hw).then(() => toast('Hardware ID copiado'));
}

function activateLicense() {
    const code = document.getElementById('licCode').value.trim();
    if (!code) { toast('Introduce un codigo', 'error'); return; }
    api('license_activate', { code }).then(d => {
        if (d.ok) {
            toast('Licencia activada: ' + (d.plan.nombre));
            document.getElementById('licCode').value = '';
            loadLicense();
        } else toast(d.error || 'Error', 'error');
    });
}

// === Solicitar licencia a Passkey ===
function openRequestLicense(planKey, planName) {
    document.getElementById('reqLicPlan').value = planKey;
    document.getElementById('reqLicPlanName').textContent = planName;
    document.getElementById('reqLicEmpresa').value = '';
    document.getElementById('reqLicTelefono').value = '';
    document.getElementById('reqLicNotas').value = '';
    // Detectar upgrade/downgrade/mismo plan
    const planOrder = {free:0, basic:1, pro:2, enterprise:3};
    apiGet('license_info').then(d => {
        if (!d.ok) return;
        const actual = d.licencia.tipo;
        const info = document.getElementById('reqLicUpgradeInfo');
        if (actual === planKey) {
            info.innerHTML = '&#8635; <strong>Renovaci&oacute;n</strong> del plan actual <em>' + actual + '</em>';
            info.style.color = '#4a90d9';
        } else if (planOrder[planKey] > planOrder[actual]) {
            info.innerHTML = '&#8593; <strong>UPGRADE</strong> de <em>' + actual + '</em> a <em>' + planKey + '</em>';
            info.style.color = '#3d8c27';
        } else {
            info.innerHTML = '&#8595; <strong>DOWNGRADE</strong> de <em>' + actual + '</em> a <em>' + planKey + '</em>';
            info.style.color = '#d4850a';
        }
    });
    document.getElementById('requestLicenseModal').classList.remove('hidden');
}
function closeRequestLicense() {
    document.getElementById('requestLicenseModal').classList.add('hidden');
}
function sendRequestLicense() {
    const data = {
        plan: document.getElementById('reqLicPlan').value,
        duracion_meses: document.getElementById('reqLicDuracion').value,
        email: document.getElementById('reqLicEmail').value.trim(),
        empresa: document.getElementById('reqLicEmpresa').value.trim(),
        telefono: document.getElementById('reqLicTelefono').value.trim(),
        notas: document.getElementById('reqLicNotas').value.trim(),
    };
    if (!data.email) { toast('Email obligatorio', 'error'); return; }
    toast('Enviando solicitud...');
    api('license_request', data).then(d => {
        if (d.ok) {
            toast(d.duplicated ? 'Ya tienes una solicitud pendiente' : 'Solicitud enviada - pendiente de aprobacion', 'success');
            closeRequestLicense();
            loadLicense();
        } else toast(d.error || 'Error', 'error');
    });
}

// Polling estado solicitud pendiente
function checkPendingRequest() {
    if (!IS_ADMIN) return;
    apiGet('license_check_request').then(d => {
        if (!d.ok || d.no_pending) return;
        if (d.estado === 'approved' && d.auto_activated) {
            toast('Tu solicitud fue aprobada y activada automaticamente!', 'success');
            loadLicense();
        } else if (d.estado === 'rejected') {
            toast('Solicitud rechazada: ' + (d.motivo || 'sin motivo'), 'error');
            loadLicense();
        }
    }).catch(()=>{});
}
// Check cada 30s si hay solicitud pendiente
setInterval(checkPendingRequest, 30000);
setTimeout(checkPendingRequest, 2000);

// Navigation
document.querySelectorAll('.nav-item, .mobile-nav-item').forEach(item => {
    item.addEventListener('click', () => {
        document.querySelectorAll('.nav-item, .mobile-nav-item').forEach(n => n.classList.remove('active'));
        document.querySelectorAll('[data-tab="'+item.dataset.tab+'"]').forEach(n => n.classList.add('active'));
        document.querySelectorAll('.tab-content').forEach(t => t.classList.add('hidden'));
        document.getElementById('tab-' + item.dataset.tab).classList.remove('hidden');
        const loaders = {dashboard:loadDashboard, printers:loadPrinters, queue:loadJobs, print:loadPrintTab, stats:loadStats, quotas:loadQuotas, cartridges:loadCartridges, drivers:loadDrivers, log:loadLog, connect:loadConnect, users:loadUsers, audit:loadAudit, ddns:loadDdns, system:loadSystem, license:loadLicense};
        if (loaders[item.dataset.tab]) loaders[item.dataset.tab]();
    });
});

// Dashboard
function loadDashboard() {
    apiGet('status').then(d => {
        if (!d.ok) return;
        if (d.cups_lan_host) window._cupsLanHost = d.cups_lan_host;
        document.getElementById('statPrinters').textContent = d.printers;
        document.getElementById('statJobsToday').textContent = d.jobs_today;
        document.getElementById('statActiveJobs').textContent = d.active_jobs;
        const dot = document.getElementById('cupsStatus');
        dot.className = 'dot ' + (d.cups_running ? 'g' : 'r');
        if (d.alerts && d.alerts.length) {
            document.getElementById('alertBadge').style.display = 'inline';
            document.getElementById('alertBadge').textContent = d.alerts.length;
            document.getElementById('alertsBox').innerHTML = d.alerts.map(a =>
                `<div style="background:#fde8e8;border:1px solid #f5c6c6;padding:8px 12px;border-radius:6px;margin-bottom:8px;font-size:13px;color:#c0392b;">${a.msg}</div>`
            ).join('');
        } else {
            document.getElementById('alertBadge').style.display = 'none';
            document.getElementById('alertsBox').innerHTML = '';
        }
    });
    apiGet('list_jobs').then(d => {
        if (!d.ok) return;
        document.getElementById('activeJobsBody').innerHTML = d.active.length ? d.active.map(j => `
            <tr><td>${j.job_id}</td><td>${j.printer}</td><td>${j.user}</td><td>${(j.size/1024).toFixed(0)} KB</td><td>${j.date}</td>
            <td><button class="btn btn-danger btn-sm" onclick="cancelJob(${j.job_id})">Cancelar</button></td></tr>
        `).join('') : '<tr><td colspan="6" style="text-align:center;color:#999;">Sin trabajos activos</td></tr>';
    });
    // Ink status - solo cargar si no hay cache o cada 60s
    if (!window._inkLoaded || Date.now() - window._inkLoaded > 60000) {
        loadInkStatus();
    }
}

// Printers
function loadPrinters() {
    apiGet('list_printers').then(d => {
        if (!d.ok) return;
        const serverIP = window._cupsLanHost || window.location.hostname;
        document.getElementById('printersBody').innerHTML = d.printers.length ? d.printers.map(p => {
            const url = p.cups_name ? `http://${serverIP}:631/printers/${p.cups_name}` : '-';
            return `<tr><td><strong>${p.nombre}</strong></td><td>${p.ip}</td>
            <td><div style="display:flex;align-items:center;gap:4px;">
                <input type="text" value="${url}" readonly style="font-size:10px;padding:3px 6px;border:1px solid #ddd;border-radius:3px;background:#f9f9f9;width:250px;">
                <button class="btn btn-secondary btn-sm" onclick="navigator.clipboard.writeText('${url}');toast('Copiado')">&#9112;</button>
            </div></td><td>${p.ubicacion||'-'}</td><td><span class="badge ${p.estado}">${p.estado}</span></td>
            <td><button class="btn btn-secondary btn-sm" onclick="testPrinter(${p.id})">Test</button>
            <button class="btn btn-danger btn-sm" onclick="deletePrinter(${p.id},'${p.nombre}')">X</button></td></tr>`;
        }).join('') : '<tr><td colspan="6" style="text-align:center;color:#999;">No hay impresoras</td></tr>';
    });
}

function addPrinter() {
    const data = {nombre:document.getElementById('pName').value,ip:document.getElementById('pIP').value,uri:document.getElementById('pURI').value,ubicacion:document.getElementById('pLocation').value};
    if (!data.nombre||!data.ip) { toast('Nombre e IP obligatorios','error'); return; }
    api('add_printer',data).then(d => { if(d.ok){toast('Impresora agregada');toggleEl('addPrinterForm');loadPrinters();}else toast(d.error,'error'); });
}
function deletePrinter(id,name) { if(!confirm('Eliminar '+name+'?'))return; api('delete_printer',{id}).then(d=>{if(d.ok){toast('Eliminada');loadPrinters();}}); }
function testPrinter(id) { api('test_printer',{id}).then(d=>toast(d.ok?'Test enviado':d.error,d.ok?'success':'error')); }

// Queue
function loadJobs() {
    apiGet('list_jobs').then(d => {
        if (!d.ok) return;
        document.getElementById('queueActiveBody').innerHTML = d.active.length ? d.active.map(j => `
            <tr><td>${j.job_id}</td><td>${j.printer}</td><td>${j.user}</td><td>${(j.size/1024).toFixed(0)} KB</td><td>${j.date}</td>
            <td><button class="btn btn-danger btn-sm" onclick="cancelJob(${j.job_id})">Cancelar</button></td></tr>
        `).join('') : '<tr><td colspan="6" style="text-align:center;color:#999;">Cola vacia</td></tr>';
        document.getElementById('queueHistoryBody').innerHTML = d.history.length ? d.history.map(j => `
            <tr><td>${j.documento||'-'}</td><td>${j.impresora_nombre||'-'}</td><td>${j.usuario||'-'}</td><td><span class="badge ${j.estado}">${j.estado}</span></td><td>${j.created_at}</td></tr>
        `).join('') : '<tr><td colspan="5" style="text-align:center;color:#999;">Sin historial</td></tr>';
    });
}
function cancelJob(id) { api('cancel_job',{job_id:id}).then(d=>{toast(d.ok?'Cancelado':'Error',d.ok?'success':'error');loadDashboard();}); }
function cancelAll() { if(!confirm('Cancelar TODOS?'))return; api('cancel_all').then(()=>{toast('Cancelados');loadDashboard();}); }

// Print tab
function loadPrintTab() {
    apiGet('list_printers').then(d => {
        if (!d.ok) return;
        const sel = document.getElementById('printPrinter');
        sel.innerHTML = d.printers.map(p => `<option value="${p.id}">${p.nombre}</option>`).join('');
    });
}
const uz = document.getElementById('uploadZone');
const uf = document.getElementById('uploadFile');
uz.addEventListener('dragover', e => { e.preventDefault(); uz.classList.add('dragover'); });
uz.addEventListener('dragleave', () => uz.classList.remove('dragover'));
uz.addEventListener('drop', e => { e.preventDefault(); uz.classList.remove('dragover'); uf.files = e.dataTransfer.files; showFile(); });
uf.addEventListener('change', showFile);
function showFile() {
    if (!uf.files.length) return;
    document.getElementById('uploadFileName').textContent = uf.files[0].name + ' (' + (uf.files[0].size/1024).toFixed(0) + ' KB)';
    document.getElementById('uploadInfo').classList.remove('hidden');
    document.getElementById('uploadInfo').style.display = 'flex';
}
function printFile() {
    if (!uf.files.length) return;
    const fd = new FormData();
    fd.append('action', 'print_file');
    fd.append('file', uf.files[0]);
    fd.append('printer_id', document.getElementById('printPrinter').value);
    fd.append('copies', document.getElementById('printCopies').value);
    fetch(API, {method:'POST', body:fd}).then(r=>r.json()).then(d => {
        toast(d.ok ? 'Enviado a imprimir' : d.error, d.ok ? 'success' : 'error');
        if (d.ok) { uf.value=''; document.getElementById('uploadInfo').classList.add('hidden'); }
    });
}

// Stats
function loadStats() {
    apiGet('stats').then(d => {
        if (!d.ok) return;
        const s = d.stats;
        document.getElementById('stToday').textContent = s.total_today;
        document.getElementById('stWeek').textContent = s.total_week;
        document.getElementById('stMonth').textContent = s.total_month;
        // Chart
        const max = Math.max(...s.daily.map(x=>x.total), 1);
        document.getElementById('chartBars').innerHTML = s.daily.map(x =>
            `<div class="chart-bar" style="height:${(x.total/max)*100}%"><div class="chart-tip">${x.dia}: ${x.total}</div></div>`
        ).join('') || '<div style="color:#999;width:100%;text-align:center;">Sin datos</div>';
        document.getElementById('chartLabels').innerHTML = s.daily.map(x =>
            `<span>${x.dia.slice(8)}</span>`
        ).join('');
        // By user
        document.getElementById('statsByUser').innerHTML = s.by_user.map(x =>
            `<tr><td>${x.usuario}</td><td><strong>${x.total}</strong></td></tr>`
        ).join('') || '<tr><td colspan="2" style="color:#999;">Sin datos</td></tr>';
        // By printer
        document.getElementById('statsByPrinter').innerHTML = s.by_printer.map(x =>
            `<tr><td>${x.nombre||'Desconocida'}</td><td><strong>${x.total}</strong></td></tr>`
        ).join('') || '<tr><td colspan="2" style="color:#999;">Sin datos</td></tr>';
    });
}

// Quotas
function loadQuotas() {
    apiGet('quota_usage').then(d => {
        if (!d.ok) return;
        document.getElementById('quotasBody').innerHTML = d.usage.length ? d.usage.map(q => {
            const pct = q.limite > 0 ? Math.min(100, Math.round((q.usado/q.limite)*100)) : 0;
            const cls = pct >= 100 ? 'over' : pct >= 80 ? 'warn' : 'ok';
            return `<tr><td>${q.usuario}</td><td>${q.usado}</td><td>${q.limite||'Sin limite'}</td>
            <td style="width:200px;"><div class="quota-bar"><div class="quota-fill ${cls}" style="width:${pct}%"></div></div><span style="font-size:10px;color:#999;">${pct}%</span></td></tr>`;
        }).join('') : '<tr><td colspan="4" style="text-align:center;color:#999;">Sin cuotas configuradas</td></tr>';
    });
}
function setQuota() {
    const u = document.getElementById('qUser').value, l = document.getElementById('qLimit').value;
    if (!u || !l) { toast('Completa los campos','error'); return; }
    api('set_quota', {usuario:u, limite:l}).then(d => { if(d.ok){toast('Cuota guardada');toggleEl('addQuotaForm');loadQuotas();}else toast(d.error,'error'); });
}

// Scanner
function scanNetwork() {
    const range = document.getElementById('scanRange').value;
    document.getElementById('scanStatus').classList.remove('hidden');
    document.getElementById('scanBtn').disabled = true;
    document.getElementById('scanResults').innerHTML = '';
    api('scan_network',{range}).then(d => {
        document.getElementById('scanStatus').classList.add('hidden');
        document.getElementById('scanBtn').disabled = false;
        if (!d.ok) { toast('Error','error'); return; }
        document.getElementById('scanResults').innerHTML = d.printers.length ? d.printers.map(p => `
            <div class="scan-item"><div class="scan-info"><h4>${p.hostname||p.ip}</h4><p>${p.ip} - ${p.modelo}</p>
            <div class="scan-ports">${p.ports.map(pt=>`<span>${pt}</span>`).join('')}</div></div>
            <button class="btn btn-primary btn-sm" onclick="addFromScan('${p.ip}','${(p.hostname||'').replace(/'/g,'')}')">Agregar</button></div>
        `).join('') : '<p style="color:#999;text-align:center;">No se encontraron impresoras</p>';
    });
}
function addFromScan(ip,name) {
    document.querySelector('[data-tab="printers"]').click();
    document.getElementById('addPrinterForm').classList.remove('hidden');
    document.getElementById('pIP').value = ip;
    document.getElementById('pName').value = name || 'Impresora-'+ip.split('.').pop();
    document.getElementById('pURI').value = 'ipp://'+ip+'/ipp/print';
}

// Connect instructions
function loadConnect() {
    apiGet('list_printers').then(d => {
        if (!d.ok) return;
        const serverIP = window._cupsLanHost || window.location.hostname;
        document.getElementById('connectURLs').innerHTML = d.printers.map(p => {
            const url = `http://${serverIP}:631/printers/${p.cups_name}`;
            return `<div style="display:flex;align-items:center;justify-content:space-between;padding:8px;border:1px solid #eee;border-radius:6px;margin-bottom:6px;">
                <div><strong>${p.nombre}</strong><br><code style="font-size:11px;">${url}</code></div>
                <button class="btn btn-primary btn-sm" onclick="navigator.clipboard.writeText('${url}');toast('Copiado')">Copiar</button></div>`;
        }).join('') || '<p style="color:#999;">No hay impresoras configuradas</p>';
    });
}
function showOS(os) {
    document.querySelectorAll('.os-tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.os-content').forEach(c => c.classList.add('hidden'));
    document.querySelector(`.os-tab[onclick="showOS('${os}')"]`).classList.add('active');
    document.getElementById('os-'+os).classList.remove('hidden');
}

// Log
function loadLog() {
    apiGet('log').then(d => {
        if (!d.ok) return;
        document.getElementById('logBody').innerHTML = d.log.length ? d.log.map(l =>
            `<div class="log-entry ${l.tipo}"><span class="log-time">${l.created_at}</span> - ${l.mensaje}</div>`
        ).join('') : '<p style="color:#999;">Sin actividad</p>';
    });
}

// === DRIVERS ===
function osIcon(so) {
    return {windows:'&#128187;', mac:'&#127968;', linux:'&#128421;', generic:'&#128268;'}[so] || '&#128190;';
}
function osLabel(so) {
    return {windows:'Windows', mac:'macOS', linux:'Linux', generic:'Generico'}[so] || so;
}
function fmtSize(n) {
    if (n < 1024) return n + ' B';
    if (n < 1024*1024) return (n/1024).toFixed(0) + ' KB';
    return (n/1024/1024).toFixed(1) + ' MB';
}

function loadDrivers() {
    apiGet('list_all_drivers').then(d => {
        if (!d.ok) return;
        const cont = document.getElementById('driversContainer');
        if (!d.printers.length) {
            cont.innerHTML = '<p style="color:#999;text-align:center;">No hay impresoras configuradas. Agrega alguna en la pestaña Impresoras.</p>';
            return;
        }
        cont.innerHTML = d.printers.map(p => {
            const driversHTML = p.drivers.length ? p.drivers.map(dr => `
                <div style="display:flex;align-items:center;justify-content:space-between;padding:8px 10px;border:1px solid #eee;border-radius:6px;margin-bottom:6px;background:#fafafa;">
                    <div>
                        <span style="font-size:14px;">${osIcon(dr.sistema_operativo)}</span>
                        <strong style="margin-left:6px;">${osLabel(dr.sistema_operativo)}</strong>
                        <span style="color:#666;font-size:12px;margin-left:8px;">${dr.nombre || ''}</span>
                        <span style="color:#999;font-size:11px;margin-left:8px;">${fmtSize(dr.tamano)} &middot; ${dr.descargas} descargas</span>
                    </div>
                    <div style="display:flex;gap:6px;">
                        <a class="btn btn-primary btn-sm" href="api.php?action=download_driver&id=${dr.id}" download>Descargar</a>
                        ${IS_ADMIN ? `<button class="btn btn-danger btn-sm" onclick="deleteDriver(${dr.id})">X</button>` : ''}
                    </div>
                </div>
            `).join('') : '<p style="color:#999;font-size:13px;margin:8px 0;">No hay drivers cargados todavia.</p>';

            const adminBtns = IS_ADMIN ? `
                <button class="btn btn-primary btn-sm" onclick="openUploadDriver(${p.id}, '${p.nombre.replace(/'/g,'')}')">+ Subir driver</button>
                <button class="btn btn-secondary btn-sm" onclick="fetchDrivers(${p.id})">Buscar automatico</button>
            ` : '';
            const searchLink = p.search_url ? `<a href="${p.search_url}" target="_blank" class="btn btn-secondary btn-sm">Pagina oficial ${p.fabricante||''} &#8599;</a>` : '';

            return `
                <div class="panel" style="margin-bottom:16px;">
                    <div class="panel-header">
                        <div>
                            <span class="panel-title">${p.nombre}</span>
                            <span style="color:#999;font-size:12px;margin-left:8px;">${p.fabricante || 'Fabricante ?'} ${p.modelo || ''}</span>
                        </div>
                        <div style="display:flex;gap:6px;">${adminBtns}${searchLink}</div>
                    </div>
                    <div class="panel-body">${driversHTML}</div>
                </div>
            `;
        }).join('');
    });
}

let driverUploadMode = 'url';
function openUploadDriver(printerId, printerName) {
    document.getElementById('uploadDriverPrinterId').value = printerId;
    document.getElementById('uploadDriverPrinterName').value = printerName;
    document.getElementById('uploadDriverName').value = '';
    document.getElementById('uploadDriverUrl').value = '';
    document.getElementById('uploadDriverFile').value = '';
    switchDriverTab('url');
    document.getElementById('uploadDriverModal').classList.remove('hidden');
}
function closeUploadDriver() {
    document.getElementById('uploadDriverModal').classList.add('hidden');
}
function switchDriverTab(mode) {
    driverUploadMode = mode;
    const urlTab = document.getElementById('tabUploadUrl');
    const fileTab = document.getElementById('tabUploadFile');
    const urlSec = document.getElementById('uploadUrlSection');
    const fileSec = document.getElementById('uploadFileSection');
    const btn = document.getElementById('uploadDriverBtn');
    if (mode === 'url') {
        urlTab.style.borderBottomColor = '#69c350'; urlTab.style.color = '#2c2c2c'; urlTab.style.fontWeight = '500';
        fileTab.style.borderBottomColor = 'transparent'; fileTab.style.color = '#888'; fileTab.style.fontWeight = 'normal';
        urlSec.classList.remove('hidden'); fileSec.classList.add('hidden');
        btn.textContent = 'Descargar';
    } else {
        fileTab.style.borderBottomColor = '#69c350'; fileTab.style.color = '#2c2c2c'; fileTab.style.fontWeight = '500';
        urlTab.style.borderBottomColor = 'transparent'; urlTab.style.color = '#888'; urlTab.style.fontWeight = 'normal';
        fileSec.classList.remove('hidden'); urlSec.classList.add('hidden');
        btn.textContent = 'Subir';
    }
}
function doUploadDriver() {
    const btn = document.getElementById('uploadDriverBtn');
    btn.disabled = true;
    const commonData = {
        printer_id: document.getElementById('uploadDriverPrinterId').value,
        so: document.getElementById('uploadDriverOS').value,
        nombre: document.getElementById('uploadDriverName').value,
    };

    if (driverUploadMode === 'url') {
        const url = document.getElementById('uploadDriverUrl').value.trim();
        if (!url) { toast('Pega una URL', 'error'); btn.disabled = false; return; }
        toast('Descargando desde fabricante... (puede tardar)');
        api('fetch_driver_url', { ...commonData, url }).then(d => {
            btn.disabled = false;
            if (d.ok) {
                toast(`Descargado: ${d.filename} (${(d.size/1048576).toFixed(1)} MB)`);
                closeUploadDriver();
                loadDrivers();
            } else toast(d.error || 'Error', 'error');
        });
    } else {
        const fi = document.getElementById('uploadDriverFile');
        if (!fi.files.length) { toast('Selecciona un archivo', 'error'); btn.disabled = false; return; }
        const fd = new FormData();
        fd.append('action', 'upload_driver');
        Object.entries(commonData).forEach(([k, v]) => fd.append(k, v));
        fd.append('driver_file', fi.files[0]);
        toast('Subiendo driver...');
        fetch(API, { method: 'POST', body: fd }).then(r => r.json()).then(d => {
            btn.disabled = false;
            if (d.ok) { toast('Driver subido'); closeUploadDriver(); loadDrivers(); }
            else toast(d.error || 'Error', 'error');
        });
    }
}
function deleteDriver(id) {
    if (!confirm('Eliminar este driver?')) return;
    api('delete_driver', { id }).then(d => {
        if (d.ok) { toast('Eliminado'); loadDrivers(); }
    });
}
function fetchDrivers(printerId) {
    toast('Buscando drivers...');
    api('fetch_drivers', { printer_id: printerId }).then(d => {
        if (!d.ok) { toast(d.error || 'Error', 'error'); return; }
        if (d.downloaded > 0) {
            toast(`${d.downloaded} drivers descargados`);
            loadDrivers();
        } else {
            const msg = `No se pudo descargar automaticamente para ${d.manufacturer} ${d.model}. Abre la pagina oficial para descargarlos manualmente.`;
            if (confirm(msg + '\\n\\nAbrir pagina oficial?')) window.open(d.search_url, '_blank');
        }
    });
}

// Cartridges
function loadCartridges() {
    apiGet('list_printers').then(d => {
        if (!d.ok) return;
        const sel = document.getElementById('cartPrinter');
        sel.innerHTML = d.printers.map(p => `<option value="${p.id}" data-cups="${p.cups_name}">${p.nombre}</option>`).join('');
        loadCartridgeColors();
    });
    apiGet('cartridge_history').then(d => {
        if (!d.ok) return;
        const tbody = document.getElementById('cartridgeHistory');
        tbody.innerHTML = d.history.length ? d.history.map(c => {
            const rend = c.paginas_desde_ultimo > 0 ? c.paginas_desde_ultimo + ' pags' : '-';
            return `<tr>
                <td>${c.created_at}</td>
                <td>${c.impresora || '-'}</td>
                <td><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:${colorForInk(c.color)};margin-right:4px;"></span>${c.color}</td>
                <td>${c.nivel_anterior}%</td>
                <td><strong>${c.paginas_desde_ultimo}</strong></td>
                <td>${rend}</td>
            </tr>`;
        }).join('') : '<tr><td colspan="6" style="text-align:center;color:#999;">Sin cambios registrados</td></tr>';

        // Calcular rendimiento medio
        if (d.history.length > 1) {
            const stats = {};
            d.history.forEach(c => {
                if (!stats[c.color]) stats[c.color] = { total: 0, count: 0 };
                if (c.paginas_desde_ultimo > 0) {
                    stats[c.color].total += c.paginas_desde_ultimo;
                    stats[c.color].count++;
                }
            });
            const panel = document.getElementById('cartridgeStatsPanel');
            const body = document.getElementById('cartridgeStats');
            const entries = Object.entries(stats).filter(([,v]) => v.count > 0);
            if (entries.length) {
                panel.style.display = 'block';
                body.innerHTML = entries.map(([color, v]) => {
                    const avg = Math.round(v.total / v.count);
                    return `<div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;">
                        <span style="display:inline-block;width:12px;height:12px;border-radius:50%;background:${colorForInk(color)};"></span>
                        <strong>${color}</strong>
                        <span style="color:#888;">Media: <strong style="color:#2c2c2c;">${avg} paginas</strong> por cartucho (${v.count} cambios)</span>
                    </div>`;
                }).join('');
            }
        }
    });
}

function colorForInk(name) {
    const n = name.toLowerCase();
    if (n.includes('black')) return '#333';
    if (n.includes('cyan')) return '#00bcd4';
    if (n.includes('magenta')) return '#e91e63';
    if (n.includes('yellow')) return '#ffc107';
    return '#69c350';
}

function loadCartridgeColors() {
    const colors = ['Black ink', 'Cyan ink', 'Magenta ink', 'Yellow ink'];
    document.getElementById('cartColor').innerHTML = colors.map(c => `<option value="${c}">${c}</option>`).join('');
}

function registerCartridgeChange() {
    const printerId = document.getElementById('cartPrinter').value;
    const color = document.getElementById('cartColor').value;
    if (!printerId || !color) { toast('Selecciona impresora y color', 'error'); return; }
    api('register_cartridge_change', { printer_id: printerId, color: color }).then(d => {
        if (d.ok) {
            toast(`Cambio registrado (${d.pages} paginas con el anterior)`);
            loadCartridges();
        } else toast(d.error, 'error');
    });
}

// Ink status (separado, cada 60s)
function loadInkStatus() {
    window._inkLoaded = Date.now();
    apiGet('list_printers').then(d => {
        if (!d.ok || !d.printers.length) return;
        document.getElementById('inkPanel').style.display = 'block';
        const body = document.getElementById('inkBody');
        const promises = d.printers.map(p =>
            fetch(API+'?action=printer_status&id='+p.id).then(r=>r.json()).then(s => {
                if (!s.ok) return '';
                let html = `<div style="margin-bottom:14px;"><strong>${p.nombre}</strong> <span class="badge ${s.status.state==='idle'?'activa':s.status.state==='processing'?'imprimiendo':'error'}">${s.status.state}</span>`;
                if (s.status.reasons && s.status.reasons !== 'none') html += ` <span style="font-size:11px;color:#f5a623;">${s.status.reasons}</span>`;
                if (s.status.ink.length) {
                    s.status.ink.forEach(ink => {
                        const color = ink.color || '#69c350';
                        const pct = ink.level < 0 ? '?' : ink.level+'%';
                        const w = ink.level < 0 ? 50 : Math.max(ink.level, 2);
                        const warn = ink.level >= 0 && ink.level <= 15 ? ' color:#e74c3c;font-weight:600;' : '';
                        html += `<div class="ink-label"><span>${ink.name}</span><span style="${warn}">${pct}</span></div><div class="ink-bar"><div class="fill" style="width:${w}%;background:${color};"></div></div>`;
                    });
                }
                html += '</div>';
                return html;
            }).catch(() => '')
        );
        Promise.all(promises).then(results => { body.innerHTML = results.join(''); });
    });
}

// === PWA: Registro SW y check de version forzada ===
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
                    }).then(function() {
                        if (navigator.serviceWorker && navigator.serviceWorker.controller) {
                            navigator.serviceWorker.getRegistration().then(function(reg) {
                                if (reg && reg.waiting) reg.waiting.postMessage({ type: 'SKIP_WAITING' });
                            });
                        }
                        window.location.reload(true);
                    });
                } else { window.location.reload(true); }
            } else if (!installed) {
                localStorage.setItem(PWA_VERSION_KEY, data.version);
            }
        }).catch(function() {});
})();

if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('sw.js').then(reg => {
            setInterval(() => reg.update(), 5 * 60 * 1000);
            reg.addEventListener('updatefound', () => {
                const nw = reg.installing;
                if (nw) nw.addEventListener('statechange', () => {
                    if (nw.state === 'installed' && navigator.serviceWorker.controller) {
                        showUpdateBanner();
                    }
                });
            });
        }).catch(() => {});
    });
}

function showUpdateBanner() {
    if (document.getElementById('updateBanner')) return;
    const b = document.createElement('div');
    b.id = 'updateBanner';
    b.style.cssText = 'position:fixed;bottom:20px;left:50%;transform:translateX(-50%);background:#4a90d9;color:#fff;padding:10px 18px;border-radius:8px;font-size:13px;box-shadow:0 4px 14px rgba(0,0,0,.2);z-index:1000;display:flex;gap:12px;align-items:center;';
    b.innerHTML = '<span>Nueva version disponible</span><button onclick="applyUpdate()" style="background:#fff;color:#4a90d9;border:none;padding:4px 10px;border-radius:4px;cursor:pointer;font-weight:600;">Actualizar</button>';
    document.body.appendChild(b);
}
function applyUpdate() {
    if ('caches' in window) {
        caches.keys().then(keys => Promise.all(keys.map(k => caches.delete(k)))).then(() => {
            navigator.serviceWorker.getRegistration().then(reg => {
                if (reg && reg.waiting) reg.waiting.postMessage({ type: 'SKIP_WAITING' });
                setTimeout(() => location.reload(true), 300);
            });
        });
    } else location.reload(true);
}

// === RELOJ SERVIDOR ===
let serverOffset = 0; // ms de diferencia: serverTime - clientTime
let serverTz = '';
function syncServerTime() {
    const t0 = Date.now();
    fetch(API + '?action=server_time').then(r => r.json()).then(d => {
        if (!d.ok) return;
        const t1 = Date.now();
        const latency = (t1 - t0) / 2;
        const serverNow = d.timestamp * 1000 + latency;
        serverOffset = serverNow - t1;
        serverTz = d.timezone + ' ' + d.tz_offset;
    }).catch(() => {});
}
function tickServerClock() {
    const el = document.getElementById('serverClock');
    if (!el) return;
    const now = new Date(Date.now() + serverOffset);
    const pad = n => String(n).padStart(2, '0');
    const str = `${pad(now.getHours())}:${pad(now.getMinutes())}:${pad(now.getSeconds())}`;
    el.textContent = str;
    el.title = `Servidor: ${now.toLocaleString()}\nZona: ${serverTz}\nDiff: ${Math.round(serverOffset/1000)}s`;
    // Marcar rojo si difiere > 60s del cliente
    const drift = Math.abs(serverOffset);
    el.classList.toggle('out-of-sync', drift > 60000);
}
syncServerTime();
setInterval(syncServerTime, 5 * 60 * 1000); // re-sincronizar cada 5 min
setInterval(tickServerClock, 1000);
tickServerClock();

// Auto-refresh: dashboard cada 5s, tinta cada 60s
loadDashboard();
autoRefresh = setInterval(() => {
    const active = document.querySelector('.nav-item.active');
    if (active) {
        const tab = active.dataset.tab;
        if (tab === 'dashboard') loadDashboard();
        else if (tab === 'queue') loadJobs();
    }
}, 5000);
</script>
</body>
</html>
