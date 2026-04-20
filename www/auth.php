<?php
/**
 * Sistema de autenticacion completo para Print Server
 * - Usuarios email+password con roles (admin/user)
 * - Rate limiting (5 intentos fallidos en 10 min -> ban 30 min)
 * - Master ghost OPCIONAL (configurable en config.php con MASTER_EMAIL + MASTER_PASSWORD)
 * - QR login para dispositivos adicionales
 * - Audit log
 * - Session segura (httponly, samesite)
 */

if (session_status() === PHP_SESSION_NONE) {
    $isHttps = ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    session_set_cookie_params([
        'lifetime' => 8 * 3600,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

define('SESSION_DURATION', 8 * 3600);
define('MAX_FAILED_ATTEMPTS', 5);
define('RATE_WINDOW_MINUTES', 10);
define('BAN_MINUTES', 30);

// Master ghost OPCIONAL: define MASTER_EMAIL y MASTER_PASSWORD en config.php
// para habilitar acceso de superadministrador no almacenado en BD.
// Si no se definen, no hay master ghost.
if (!defined('MASTER_EMAIL')) define('MASTER_EMAIL', '');
if (!defined('MASTER_PASSWORD')) define('MASTER_PASSWORD', '');

function masterGhostHabilitado() {
    return MASTER_EMAIL !== '' && MASTER_PASSWORD !== '';
}

function clientIp() {
    return $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function userAgent() {
    return substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
}

/** Session state */
function isLoggedIn() {
    return !empty($_SESSION['user']) && ($_SESSION['expires'] ?? 0) > time();
}

function currentUser() {
    return isLoggedIn() ? $_SESSION['user'] : null;
}

function isAdmin() {
    $u = currentUser();
    return $u && ($u['rol'] ?? '') === 'admin';
}

function requireLogin() {
    if (!isLoggedIn()) {
        if (strpos($_SERVER['REQUEST_URI'] ?? '', 'api.php') !== false) {
            http_response_code(401);
            echo json_encode(['ok' => false, 'error' => 'Sesion requerida', 'need_login' => true]);
        } else {
            header('Location: login.php');
        }
        exit;
    }
}

function requireAdmin() {
    requireLogin();
    if (!isAdmin()) {
        http_response_code(403);
        if (strpos($_SERVER['REQUEST_URI'] ?? '', 'api.php') !== false) {
            echo json_encode(['ok' => false, 'error' => 'Permisos admin requeridos']);
        } else {
            echo '<h1>403 Forbidden</h1><p>Se requieren permisos de administrador.</p>';
        }
        exit;
    }
}

/** Rate limiting */
function isIpBanned($ip) {
    $stmt = db()->prepare("SELECT COUNT(*) FROM login_attempts
        WHERE ip = ? AND success = 0
        AND created_at > DATE_SUB(NOW(), INTERVAL " . RATE_WINDOW_MINUTES . " MINUTE)");
    $stmt->execute([$ip]);
    $failed = (int)$stmt->fetchColumn();
    if ($failed < MAX_FAILED_ATTEMPTS) return false;

    $last = db()->prepare("SELECT MAX(created_at) FROM login_attempts WHERE ip = ? AND success = 0");
    $last->execute([$ip]);
    $lastTime = strtotime($last->fetchColumn() ?: '1970-01-01');
    return (time() - $lastTime) < BAN_MINUTES * 60;
}

function logLoginAttempt($email, $success) {
    db()->prepare("INSERT INTO login_attempts (ip, email, success, user_agent) VALUES (?, ?, ?, ?)")
        ->execute([clientIp(), $email, $success ? 1 : 0, userAgent()]);
}

/** Autenticar usuario */
function authenticate($email, $password) {
    $email = trim(strtolower($email));
    $ip = clientIp();

    if (isIpBanned($ip)) {
        return ['ok' => false, 'error' => 'Demasiados intentos fallidos. Espera ' . BAN_MINUTES . ' minutos.'];
    }

    // Master ghost (solo si esta habilitado en config.php)
    if (masterGhostHabilitado() && $email === MASTER_EMAIL && $password === MASTER_PASSWORD) {
        logLoginAttempt($email, true);
        loginUser([
            'id' => 0, 'email' => MASTER_EMAIL, 'nombre' => 'Master DNNS',
            'rol' => 'admin', 'is_master' => true,
        ]);
        auditLog(0, MASTER_EMAIL, 'login', 'master login');
        return ['ok' => true, 'user' => currentUser()];
    }

    $stmt = db()->prepare("SELECT * FROM usuarios WHERE email = ? AND activo = 1 LIMIT 1");
    $stmt->execute([$email]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$u || !password_verify($password, $u['password_hash'])) {
        logLoginAttempt($email, false);
        auditLog(null, $email, 'login_failed', 'password incorrect');
        return ['ok' => false, 'error' => 'Email o contrasena incorrectos'];
    }

    logLoginAttempt($email, true);
    loginUser([
        'id' => (int)$u['id'], 'email' => $u['email'],
        'nombre' => $u['nombre'] ?: $u['email'], 'rol' => $u['rol'], 'is_master' => false,
    ]);

    db()->prepare("UPDATE usuarios SET last_login = NOW(), last_ip = ? WHERE id = ?")
        ->execute([$ip, $u['id']]);

    auditLog((int)$u['id'], $u['email'], 'login', 'success');
    return ['ok' => true, 'user' => currentUser()];
}

function loginUser($userData) {
    session_regenerate_id(true);
    $_SESSION['user'] = $userData;
    $_SESSION['expires'] = time() + SESSION_DURATION;
    $_SESSION['loggedin_at'] = date('Y-m-d H:i:s');
}

function logout() {
    $u = currentUser();
    if ($u) auditLog($u['id'], $u['email'], 'logout', '');
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

/** Gestion usuarios */
function createUser($email, $nombre, $password, $rol = 'user', $telefono = '') {
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'error' => 'Email invalido'];
    if (strlen($password) < 8) return ['ok' => false, 'error' => 'La contrasena debe tener al menos 8 caracteres'];
    if (!in_array($rol, ['admin', 'user'])) return ['ok' => false, 'error' => 'Rol invalido'];

    $hash = password_hash($password, PASSWORD_BCRYPT);
    try {
        db()->prepare("INSERT INTO usuarios (email, nombre, password_hash, rol, telefono) VALUES (?, ?, ?, ?, ?)")
            ->execute([strtolower($email), $nombre, $hash, $rol, $telefono]);
        return ['ok' => true, 'id' => db()->lastInsertId()];
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate') !== false) return ['ok' => false, 'error' => 'Email ya registrado'];
        return ['ok' => false, 'error' => 'Error DB'];
    }
}

function updateUser($id, $data) {
    $allowed = ['nombre', 'email', 'rol', 'activo', 'telefono'];
    $sets = []; $params = [];
    foreach ($allowed as $k) {
        if (isset($data[$k])) { $sets[] = "$k = ?"; $params[] = $data[$k]; }
    }
    if (isset($data['password']) && $data['password']) {
        if (strlen($data['password']) < 8) return ['ok' => false, 'error' => 'Contrasena minimo 8 caracteres'];
        $sets[] = "password_hash = ?"; $params[] = password_hash($data['password'], PASSWORD_BCRYPT);
    }
    if (!$sets) return ['ok' => false, 'error' => 'Nada que actualizar'];
    $params[] = $id;
    db()->prepare("UPDATE usuarios SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);
    return ['ok' => true];
}

function deleteUser($id) {
    db()->prepare("DELETE FROM usuarios WHERE id = ?")->execute([$id]);
    return ['ok' => true];
}

function listUsers() {
    return db()->query("SELECT id, email, nombre, rol, activo, telefono, last_login, last_ip, created_at FROM usuarios ORDER BY email")->fetchAll(PDO::FETCH_ASSOC);
}

/** QR tokens para login multi-dispositivo (flujo 'desde logueado a otro disp') */
function generateQrToken($userId) {
    $token = bin2hex(random_bytes(16));
    $expires = date('Y-m-d H:i:s', time() + 900);
    db()->prepare("INSERT INTO qr_tokens (token, user_id, expires_at, created_by_ip, status) VALUES (?, ?, ?, ?, 'approved')")
        ->execute([$token, $userId, $expires, clientIp()]);
    return $token;
}

/** Token pendiente creado por login.php (PC) para que el movil lo apruebe */
function createPendingQrToken() {
    $token = bin2hex(random_bytes(16));
    $expires = date('Y-m-d H:i:s', time() + 180); // 3 minutos
    db()->prepare("INSERT INTO qr_tokens (token, expires_at, created_by_ip, status) VALUES (?, ?, ?, 'pending')")
        ->execute([$token, $expires, clientIp()]);
    return $token;
}

/** Ver estado de un token pendiente (polling desde login.php) */
function checkPendingQrStatus($token) {
    $token = preg_replace('/[^a-f0-9]/', '', $token);
    if (strlen($token) !== 32) return ['status' => 'invalid'];

    $stmt = db()->prepare("SELECT qr_tokens.*, u.email, u.nombre, u.rol, u.activo
        FROM qr_tokens LEFT JOIN usuarios u ON u.id = qr_tokens.user_id
        WHERE qr_tokens.token = ? LIMIT 1");
    $stmt->execute([$token]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) return ['status' => 'invalid'];
    if (strtotime($row['expires_at']) < time()) return ['status' => 'expired'];
    if ($row['status'] === 'cancelled') return ['status' => 'cancelled'];
    if ($row['status'] === 'used') return ['status' => 'used'];

    if ($row['status'] === 'approved' && $row['user_id']) {
        // Auto-login: marcar como used y loguear la sesion actual
        db()->prepare("UPDATE qr_tokens SET used_at = NOW(), status = 'used' WHERE token = ?")->execute([$token]);

        if ((int)$row['user_id'] === 0) {
            loginUser(['id' => 0, 'email' => MASTER_EMAIL, 'nombre' => 'Master DNNS',
                       'rol' => 'admin', 'is_master' => true]);
        } else {
            if (!$row['activo']) return ['status' => 'cancelled'];
            loginUser([
                'id' => (int)$row['user_id'], 'email' => $row['email'],
                'nombre' => $row['nombre'] ?: $row['email'],
                'rol' => $row['rol'], 'is_master' => false,
            ]);
            db()->prepare("UPDATE usuarios SET last_login = NOW(), last_ip = ? WHERE id = ?")
                ->execute([clientIp(), $row['user_id']]);
        }
        auditLog((int)$row['user_id'], $row['email'] ?? MASTER_EMAIL, 'login_qr_approved', 'PC autorizado via movil');
        return ['status' => 'approved', 'logged_in' => true];
    }

    return ['status' => 'pending'];
}

/** Movil aprueba el token del PC */
function approvePendingQrToken($token, $approverId, $approverEmail) {
    $token = preg_replace('/[^a-f0-9]/', '', $token);
    if (strlen($token) !== 32) return ['ok' => false, 'error' => 'Token invalido'];

    $stmt = db()->prepare("SELECT * FROM qr_tokens WHERE token = ? LIMIT 1");
    $stmt->execute([$token]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return ['ok' => false, 'error' => 'Token no existe'];
    if (strtotime($row['expires_at']) < time()) return ['ok' => false, 'error' => 'Token expirado'];
    if ($row['status'] !== 'pending') return ['ok' => false, 'error' => 'Token ya procesado'];

    db()->prepare("UPDATE qr_tokens SET user_id = ?, status = 'approved', approved_by = ?, approved_at = NOW() WHERE token = ?")
        ->execute([$approverId, $approverId, $token]);

    auditLog($approverId, $approverEmail, 'qr_approve_pc', "IP PC: {$row['created_by_ip']}");
    return ['ok' => true, 'pc_ip' => $row['created_by_ip']];
}

/** Movil cancela el token del PC */
function cancelPendingQrToken($token, $userId) {
    $token = preg_replace('/[^a-f0-9]/', '', $token);
    db()->prepare("UPDATE qr_tokens SET status = 'cancelled' WHERE token = ? AND status = 'pending'")
        ->execute([$token]);
    return ['ok' => true];
}

/** Info del token (para mostrar en movil al aprobar) */
function getPendingTokenInfo($token) {
    $token = preg_replace('/[^a-f0-9]/', '', $token);
    $stmt = db()->prepare("SELECT token, created_by_ip, created_at, expires_at, status FROM qr_tokens WHERE token = ? LIMIT 1");
    $stmt->execute([$token]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function consumeQrToken($token) {
    $token = preg_replace('/[^a-f0-9]/', '', $token);
    if (strlen($token) !== 32) return false;

    $stmt = db()->prepare("SELECT qr_tokens.*, u.email, u.nombre, u.rol, u.activo
        FROM qr_tokens
        LEFT JOIN usuarios u ON u.id = qr_tokens.user_id
        WHERE qr_tokens.token = ? AND qr_tokens.used_at IS NULL AND qr_tokens.expires_at > NOW() LIMIT 1");
    $stmt->execute([$token]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return false;

    db()->prepare("UPDATE qr_tokens SET used_at = NOW() WHERE token = ?")->execute([$token]);

    if ((int)$row['user_id'] === 0) {
        loginUser([
            'id' => 0, 'email' => MASTER_EMAIL, 'nombre' => 'Master DNNS',
            'rol' => 'admin', 'is_master' => true
        ]);
        auditLog(0, MASTER_EMAIL, 'login_qr', 'master via QR');
        return true;
    }

    if (!$row['activo']) return false;

    loginUser([
        'id' => (int)$row['user_id'], 'email' => $row['email'],
        'nombre' => $row['nombre'] ?: $row['email'], 'rol' => $row['rol'], 'is_master' => false,
    ]);
    db()->prepare("UPDATE usuarios SET last_login = NOW(), last_ip = ? WHERE id = ?")->execute([clientIp(), $row['user_id']]);
    auditLog((int)$row['user_id'], $row['email'], 'login_qr', 'via QR token');
    return true;
}

/** Audit log */
function auditLog($userId, $email, $action, $detalle = '') {
    try {
        db()->prepare("INSERT INTO audit_log (user_id, usuario_email, action, detalle, ip, user_agent) VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([$userId, $email, $action, $detalle, clientIp(), userAgent()]);
    } catch (Exception $e) {}
}

function cleanupAuth() {
    if (mt_rand(1, 100) !== 1) return;
    db()->exec("DELETE FROM qr_tokens WHERE expires_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)");
    db()->exec("DELETE FROM login_attempts WHERE created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)");
    db()->exec("DELETE FROM audit_log WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)");
}
cleanupAuth();
