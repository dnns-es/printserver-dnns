#!/bin/bash
# Print Server DNNS - Instalador one-liner
# Uso: bash <(curl -fsSL https://print.dnns.es/install.sh)

set -e

REPO_URL="${REPO_URL:-https://github.com/dnns-es/printserver-dnns}"
REPO_BRANCH="${REPO_BRANCH:-main}"
DB_NAME="printserver"
DB_USER="printapp"
# Password BD generada aleatoriamente al instalar
DB_PASS="$(openssl rand -base64 24 | tr -d '=+/\n' | head -c 32)"
WEB_ROOT="/var/www/printserver"
# Clave SSH opcional para acceso remoto del mantenedor (vacia = no se inyecta)
SSH_KEY_OFICINA="${SSH_KEY_OFICINA:-}"

# --- Colores (usar $'...' para que interprete escapes correctamente) ---
G=$'\e[0;32m'; Y=$'\e[1;33m'; R=$'\e[0;31m'; B=$'\e[0;34m'; N=$'\e[0m'
msg()  { printf '%s==>%s %s\n' "$G" "$N" "$*"; }
warn() { printf '%s!!!%s %s\n' "$Y" "$N" "$*"; }
err()  { printf '%sXXX%s %s\n' "$R" "$N" "$*"; exit 1; }
ask()  { local v; read -p "$(printf '%s?%s %s [%s]: ' "$B" "$N" "$1" "$2")" v; echo "${v:-$2}"; }
askp() { local v; read -sp "$(printf '%s?%s %s: ' "$B" "$N" "$1")" v; echo "$v"; }

# --- Root check ---
[ "$(id -u)" = "0" ] || err "Ejecuta como root"

# --- Detectar entorno (CT/VM) ---
IS_LXC=0
[ -f /proc/1/environ ] && grep -q container=lxc /proc/1/environ && IS_LXC=1
[ "$IS_LXC" = "1" ] && msg "Detectado LXC container"

# --- Detectar IP y red ---
DETECTED_IP=$(ip -4 addr show scope global | grep inet | awk '{print $2}' | cut -d/ -f1 | head -1)
DETECTED_NET=$(ip -4 route | awk '/scope link/ {print $1; exit}')

# --- Banner ---
printf '%s\n' "$G"
echo "========================================================="
echo "         PRINT SERVER DNNS - Instalador"
echo "========================================================="
printf '%s' "$N"
cat <<'BANNER'
Este script instala:
 - CUPS + Apache + PHP + MariaDB + Samba
 - Panel web con login, QR, DDNS, drivers, estadisticas
 - Cron DDNS + security headers + SSL self-signed
 - PWA instalable desde movil
 - Europe/Madrid timezone + www-data sudoers

BANNER
echo "Detectado:  IP = $DETECTED_IP  Red = $DETECTED_NET  LXC = $IS_LXC"

# --- Preguntas ---
HOSTNAME_VAL=$(ask "Hostname del server" "printserver")
ADMIN_EMAIL=$(ask "Email del admin" "admin@empresa.es")
ADMIN_NAME=$(ask "Nombre del admin" "Administrador")
ADMIN_PASS=$(askp "Password admin (minimo 8)")
echo
[ ${#ADMIN_PASS} -ge 8 ] || err "Password menor de 8 caracteres"
CUPS_LAN_HOST=$(ask "IP LAN del server (para URL IPP en PCs clientes)" "$DETECTED_IP")
NETWORK_RANGE=$(ask "Red local para escaneo de impresoras" "$DETECTED_NET")
TIMEZONE=$(ask "Zona horaria" "Europe/Madrid")
INSTALL_SSH_KEY=$(ask "Inyectar clave SSH oficina (y/n)" "y")

echo
msg "Resumen:"
echo "   hostname: $HOSTNAME_VAL"
echo "   admin:    $ADMIN_EMAIL ($ADMIN_NAME)"
echo "   IP LAN:   $CUPS_LAN_HOST"
echo "   red:      $NETWORK_RANGE"
echo "   timezone: $TIMEZONE"
CONFIRM=$(ask "Continuar? (y/n)" "y")
[ "$CONFIRM" = "y" ] || exit 0

# --- Zona horaria + hostname ---
msg "Configurando zona horaria y hostname..."
timedatectl set-timezone "$TIMEZONE" 2>/dev/null || true
hostnamectl set-hostname "$HOSTNAME_VAL" 2>/dev/null || echo "$HOSTNAME_VAL" > /etc/hostname
grep -q "$HOSTNAME_VAL" /etc/hosts || echo "127.0.0.1 $HOSTNAME_VAL $HOSTNAME_VAL.local" >> /etc/hosts

# --- Paquetes ---
msg "Actualizando apt..."
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq

msg "Instalando paquetes (puede tardar)..."
apt-get install -y -qq \
  cups cups-client cups-bsd cups-filters printer-driver-all \
  apache2 php php-cli php-curl php-mysql php-json php-mbstring libapache2-mod-php \
  mariadb-server mariadb-client \
  samba \
  nmap snmp iputils-ping curl sudo wget \
  avahi-daemon avahi-utils \
  imagemagick \
  cron

# --- Systemd overrides para LXC ---
if [ "$IS_LXC" = "1" ]; then
    msg "Aplicando overrides systemd (LXC)..."
    for svc in apache2 mariadb smbd; do
        mkdir -p /etc/systemd/system/${svc}.service.d
        cat > /etc/systemd/system/${svc}.service.d/override.conf <<EOF
[Service]
PrivateTmp=false
ProtectSystem=false
ProtectHome=false
NoNewPrivileges=false
EOF
    done
    systemctl daemon-reload
    systemctl mask colord 2>/dev/null || true
fi

# --- MariaDB ---
msg "Arrancando MariaDB..."
systemctl enable mariadb --now 2>/dev/null || mysqld_safe &
sleep 3

msg "Creando base de datos..."
mysql <<SQL
CREATE DATABASE IF NOT EXISTS ${DB_NAME} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE ${DB_NAME};

CREATE TABLE IF NOT EXISTS impresoras (id INT AUTO_INCREMENT PRIMARY KEY, nombre VARCHAR(100) NOT NULL, ip VARCHAR(45), uri VARCHAR(255), driver VARCHAR(100) DEFAULT 'everywhere', ubicacion VARCHAR(100), cups_name VARCHAR(100), fabricante VARCHAR(50), modelo VARCHAR(100), estado ENUM('activa','inactiva','error') DEFAULT 'activa', created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP);

CREATE TABLE IF NOT EXISTS trabajos (id INT AUTO_INCREMENT PRIMARY KEY, cups_job_id INT, impresora_id INT, usuario VARCHAR(100), equipo VARCHAR(100), documento VARCHAR(255), paginas INT DEFAULT 0, copias INT DEFAULT 1, estado ENUM('pendiente','imprimiendo','completado','error','cancelado') DEFAULT 'pendiente', created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, FOREIGN KEY (impresora_id) REFERENCES impresoras(id) ON DELETE SET NULL);

CREATE TABLE IF NOT EXISTS log_actividad (id INT AUTO_INCREMENT PRIMARY KEY, tipo ENUM('info','warning','error') DEFAULT 'info', mensaje TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP);

CREATE TABLE IF NOT EXISTS cuotas (id INT AUTO_INCREMENT PRIMARY KEY, usuario VARCHAR(100) NOT NULL UNIQUE, limite_mensual INT DEFAULT 100, created_at DATETIME DEFAULT CURRENT_TIMESTAMP);

CREATE TABLE IF NOT EXISTS cartuchos (id INT AUTO_INCREMENT PRIMARY KEY, impresora_id INT, color VARCHAR(50) NOT NULL, nivel_anterior INT DEFAULT 0, nivel_nuevo INT DEFAULT 100, paginas_desde_ultimo INT DEFAULT 0, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (impresora_id) REFERENCES impresoras(id) ON DELETE CASCADE);

CREATE TABLE IF NOT EXISTS ink_snapshots (id INT AUTO_INCREMENT PRIMARY KEY, impresora_id INT, color VARCHAR(50) NOT NULL, nivel INT DEFAULT 0, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (impresora_id) REFERENCES impresoras(id) ON DELETE CASCADE, INDEX idx_snapshot (impresora_id, color, created_at));

CREATE TABLE IF NOT EXISTS config (clave VARCHAR(50) PRIMARY KEY, valor TEXT, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP);

CREATE TABLE IF NOT EXISTS drivers (id INT AUTO_INCREMENT PRIMARY KEY, impresora_id INT, sistema_operativo ENUM('windows','mac','linux','generic') DEFAULT 'generic', nombre VARCHAR(255), archivo VARCHAR(255), tamano BIGINT DEFAULT 0, version VARCHAR(50), url_origen TEXT, fabricante VARCHAR(50), descargas INT DEFAULT 0, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (impresora_id) REFERENCES impresoras(id) ON DELETE CASCADE);

CREATE TABLE IF NOT EXISTS usuarios (id INT AUTO_INCREMENT PRIMARY KEY, email VARCHAR(150) NOT NULL UNIQUE, nombre VARCHAR(100), password_hash VARCHAR(255) NOT NULL, rol ENUM('admin','user') DEFAULT 'user', activo TINYINT(1) DEFAULT 1, telefono VARCHAR(30), last_login DATETIME, last_ip VARCHAR(45), created_at DATETIME DEFAULT CURRENT_TIMESTAMP);

CREATE TABLE IF NOT EXISTS login_attempts (id INT AUTO_INCREMENT PRIMARY KEY, ip VARCHAR(45), email VARCHAR(150), success TINYINT(1) DEFAULT 0, user_agent VARCHAR(255), created_at DATETIME DEFAULT CURRENT_TIMESTAMP, INDEX idx_ip_time (ip, created_at));

CREATE TABLE IF NOT EXISTS audit_log (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT, usuario_email VARCHAR(150), action VARCHAR(50), detalle TEXT, ip VARCHAR(45), user_agent VARCHAR(255), created_at DATETIME DEFAULT CURRENT_TIMESTAMP, INDEX idx_user_time (user_id, created_at));

CREATE TABLE IF NOT EXISTS qr_tokens (token VARCHAR(64) PRIMARY KEY, user_id INT NULL, expires_at DATETIME, used_at DATETIME NULL, created_by_ip VARCHAR(45), status ENUM('pending','approved','used','cancelled') DEFAULT 'pending', approved_by INT NULL, approved_at DATETIME NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, INDEX idx_expires (expires_at));

CREATE TABLE IF NOT EXISTS licencia (id INT AUTO_INCREMENT PRIMARY KEY, tipo ENUM('free','basic','pro','enterprise') NOT NULL DEFAULT 'free', max_impresoras INT NOT NULL DEFAULT 1, max_usuarios INT NOT NULL DEFAULT 1, hw_id VARCHAR(64) DEFAULT NULL, activation_code VARCHAR(64) DEFAULT NULL, cliente VARCHAR(200) DEFAULT NULL, activated_at DATETIME DEFAULT CURRENT_TIMESTAMP, expires_at DATETIME DEFAULT NULL, INDEX idx_tipo (tipo), INDEX idx_activation (activation_code));

GRANT ALL PRIVILEGES ON ${DB_NAME}.* TO '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
FLUSH PRIVILEGES;
SQL

# --- Descargar codigo del panel desde GitHub ---
TARBALL_URL="${REPO_URL}/archive/refs/heads/${REPO_BRANCH}.tar.gz"
msg "Descargando codigo del panel desde $TARBALL_URL..."
mkdir -p "$WEB_ROOT" /tmp/ps-install
curl -fsSL "$TARBALL_URL" -o /tmp/ps-install/repo.tar.gz || err "No se pudo descargar el codigo"
# Extraer y copiar SOLO la carpeta www/ al WEB_ROOT
tar xzf /tmp/ps-install/repo.tar.gz -C /tmp/ps-install --no-same-owner 2>&1 | grep -v "Cannot change ownership" || true
EXTRACTED_DIR=$(find /tmp/ps-install -maxdepth 1 -type d -name "printserver-dnns-*" | head -1)
[ -d "$EXTRACTED_DIR/www" ] || err "Estructura inesperada del repo"
cp -r "$EXTRACTED_DIR/www/." "$WEB_ROOT/"
# Documentacion adicional
[ -f "$EXTRACTED_DIR/manuales.html" ] && cp "$EXTRACTED_DIR/manuales.html" "$WEB_ROOT/"
[ -f "$EXTRACTED_DIR/migrate-licencia.sql" ] && cp "$EXTRACTED_DIR/migrate-licencia.sql" "$WEB_ROOT/"

# --- Ajustar config.php ---
msg "Configurando config.php..."
cat > "$WEB_ROOT/config.php" <<PHP
<?php
define('DB_HOST', 'localhost');
define('DB_NAME', '${DB_NAME}');
define('DB_USER', '${DB_USER}');
define('DB_PASS', '${DB_PASS}');
define('APP_NAME', 'Print Server');
define('CUPS_HOST', 'localhost');
define('CUPS_PORT', 631);
define('NETWORK_RANGE', '${NETWORK_RANGE}');
define('CUPS_LAN_HOST', '${CUPS_LAN_HOST}');

function db() {
    static \$pdo = null;
    if (\$pdo === null) {
        \$pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }
    return \$pdo;
}
function cupsExec(\$cmd) {
    \$output = []; \$ret = 0; exec(\$cmd . ' 2>&1', \$output, \$ret);
    return ['output' => implode("\n", \$output), 'code' => \$ret];
}
function logActivity(\$tipo, \$mensaje) {
    db()->prepare("INSERT INTO log_actividad (tipo, mensaje) VALUES (?, ?)")->execute([\$tipo, \$mensaje]);
}
function syncCupsJobs() {
    \$res = cupsExec('lpstat -W all -o 2>/dev/null');
    if (\$res['output']) {
        foreach (explode("\n", trim(\$res['output'])) as \$line) {
            if (preg_match('/^(\S+)-(\d+)\s+(.+?)\s{2,}(\d+)\s+(.+)\$/', \$line, \$m)) {
                \$printerName = \$m[1]; \$jobId = (int)\$m[2]; \$user = trim(\$m[3]);
                \$exists = db()->prepare("SELECT id FROM trabajos WHERE cups_job_id = ?");
                \$exists->execute([\$jobId]);
                if (!\$exists->fetch()) {
                    \$pStmt = db()->prepare("SELECT id FROM impresoras WHERE cups_name = ?");
                    \$pStmt->execute([\$printerName]);
                    \$printer = \$pStmt->fetch(PDO::FETCH_ASSOC);
                    \$printerId = \$printer ? \$printer['id'] : null;
                    \$fecha = date('Y-m-d H:i:s');
                    if (preg_match('/[A-Z][a-z]{2}\s+[A-Z][a-z]{2}\s+\d+\s+\d{2}:\d{2}:\d{2}\s+\d{4}/', \$m[5])) {
                        \$ts = strtotime(\$m[5]);
                        if (\$ts) \$fecha = date('Y-m-d H:i:s', \$ts);
                    }
                    \$ins = db()->prepare("INSERT INTO trabajos (cups_job_id, impresora_id, usuario, documento, paginas, estado, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    \$ins->execute([\$jobId, \$printerId, \$user, 'Trabajo #'.\$jobId, 0, 'imprimiendo', \$fecha]);
                }
            }
        }
    }
    \$active = cupsExec('lpstat -o -W not-completed 2>/dev/null');
    \$activeIds = [];
    if (\$active['output']) {
        foreach (explode("\n", trim(\$active['output'])) as \$line) {
            if (preg_match('/^\S+-(\d+)\s/', \$line, \$m)) \$activeIds[] = (int)\$m[1];
        }
    }
    \$pending = db()->query("SELECT id, cups_job_id FROM trabajos WHERE estado IN ('pendiente','imprimiendo')");
    foreach (\$pending->fetchAll(PDO::FETCH_ASSOC) as \$job) {
        if (!in_array((int)\$job['cups_job_id'], \$activeIds)) {
            db()->prepare("UPDATE trabajos SET estado = 'completado', updated_at = NOW() WHERE id = ?")->execute([\$job['id']]);
        }
    }
}
PHP

# --- Crear admin ---
msg "Creando usuario admin ${ADMIN_EMAIL}..."
php -r "
require_once '$WEB_ROOT/config.php';
\$hash = password_hash('$ADMIN_PASS', PASSWORD_BCRYPT);
db()->prepare('INSERT INTO usuarios (email, nombre, password_hash, rol) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), rol = VALUES(rol)')->execute(['$ADMIN_EMAIL', '$ADMIN_NAME', \$hash, 'admin']);
echo 'OK\n';
"

# --- CUPS config ---
msg "Configurando CUPS..."
sed -i "s/^Listen localhost:631/Listen 0.0.0.0:631/" /etc/cups/cupsd.conf || true
sed -i "/<Location \/>/{n;s/Order allow,deny/Order allow,deny\n  Allow from $NETWORK_RANGE/}" /etc/cups/cupsd.conf || true
cupsctl --share-printers --remote-any --remote-admin 2>/dev/null || true

# --- Generar iconos PWA ---
if command -v convert >/dev/null 2>&1; then
    msg "Generando iconos PWA..."
    cd "$WEB_ROOT"
    [ -f icon-192.png ] || convert -size 192x192 xc:'#69c350' -gravity center -font DejaVu-Sans-Bold -pointsize 90 -fill white -annotate +0+0 'P' icon-192.png 2>/dev/null || true
    [ -f icon-512.png ] || convert -size 512x512 xc:'#69c350' -gravity center -font DejaVu-Sans-Bold -pointsize 240 -fill white -annotate +0+0 'P' icon-512.png 2>/dev/null || true
fi

# --- SSL self-signed ---
msg "Generando cert SSL self-signed..."
cat > /tmp/ps-cert.cnf <<CFG
[req]
distinguished_name = req_dn
x509_extensions = v3_req
prompt = no
[req_dn]
C = ES
CN = $HOSTNAME_VAL.local
[v3_req]
basicConstraints = CA:FALSE
keyUsage = digitalSignature, keyEncipherment
extendedKeyUsage = serverAuth, clientAuth
subjectAltName = @alt
[alt]
DNS.1 = $HOSTNAME_VAL.local
DNS.2 = $HOSTNAME_VAL
IP.1 = $CUPS_LAN_HOST
IP.2 = 127.0.0.1
CFG
openssl req -x509 -nodes -newkey rsa:2048 -keyout /etc/ssl/private/printserver.key -out /etc/ssl/certs/printserver.crt -days 3650 -config /tmp/ps-cert.cnf 2>/dev/null
chmod 600 /etc/ssl/private/printserver.key

# --- Apache vhost ---
msg "Configurando Apache..."
a2enmod ssl headers rewrite >/dev/null 2>&1

cat > /etc/apache2/sites-available/printserver.conf <<VHOST
<VirtualHost *:80 *:8080>
    ServerName $HOSTNAME_VAL.local
    ServerAlias $CUPS_LAN_HOST
    DocumentRoot $WEB_ROOT
    <Directory $WEB_ROOT>
        AllowOverride All
        Require all granted
    </Directory>
    Header always set X-Content-Type-Options "nosniff"
    Header always set X-Frame-Options "SAMEORIGIN"
    Header always set Referrer-Policy "strict-origin-when-cross-origin"
    <FilesMatch "\.(json|webmanifest)\$">
        Header set Cache-Control "no-cache"
    </FilesMatch>
    <FilesMatch "\.(log|sql|bak)\$">
        Require all denied
    </FilesMatch>
    ErrorLog \${APACHE_LOG_DIR}/printserver-error.log
    CustomLog \${APACHE_LOG_DIR}/printserver-access.log combined
</VirtualHost>

<VirtualHost *:443>
    ServerName $HOSTNAME_VAL.local
    ServerAlias $CUPS_LAN_HOST
    DocumentRoot $WEB_ROOT
    <Directory $WEB_ROOT>
        AllowOverride All
        Require all granted
    </Directory>
    SSLEngine on
    SSLCertificateFile /etc/ssl/certs/printserver.crt
    SSLCertificateKeyFile /etc/ssl/private/printserver.key
    SSLProtocol -all +TLSv1.2 +TLSv1.3
    Header always set Strict-Transport-Security "max-age=31536000"
    Header always set X-Content-Type-Options "nosniff"
    Header always set X-Frame-Options "SAMEORIGIN"
    ErrorLog \${APACHE_LOG_DIR}/printserver-ssl-error.log
    CustomLog \${APACHE_LOG_DIR}/printserver-ssl-access.log combined
</VirtualHost>
VHOST

# Puertos
grep -q "^Listen 8080" /etc/apache2/ports.conf || echo "Listen 8080" >> /etc/apache2/ports.conf

a2dissite 000-default.conf 2>/dev/null || true
a2ensite printserver.conf >/dev/null

# PHP config (usar primer match en vez de glob en redireccion que bash no expande)
PHP_APACHE_INI=$(ls /etc/php/*/apache2/conf.d/ 2>/dev/null | head -1)
PHP_APACHE_DIR=$(dirname "$(find /etc/php -path '*/apache2/conf.d' | head -1)/x" 2>/dev/null)
if [ -d "$PHP_APACHE_DIR" ]; then
cat > "$PHP_APACHE_DIR/99-printserver.ini" <<PHP
upload_max_filesize = 500M
post_max_size = 500M
max_execution_time = 300
memory_limit = 512M
date.timezone = $TIMEZONE
PHP
fi
# Tambien para CLI
PHP_CLI_DIR=$(dirname "$(find /etc/php -path '*/cli/conf.d' | head -1)/x" 2>/dev/null)
if [ -d "$PHP_CLI_DIR" ]; then
cat > "$PHP_CLI_DIR/99-printserver.ini" <<PHP
date.timezone = $TIMEZONE
PHP
fi

# --- Permisos ---
msg "Permisos y sudoers..."
chown -R www-data:www-data "$WEB_ROOT"
usermod -aG lpadmin,lp www-data 2>/dev/null || true

cat > /etc/sudoers.d/printserver <<'SUDOERS'
www-data ALL=(ALL) NOPASSWD: /usr/bin/nmap, /usr/sbin/lpadmin, /usr/bin/lpstat, /usr/bin/lp, /usr/bin/cancel, /usr/sbin/cupsctl, /usr/bin/timedatectl
SUDOERS
chmod 440 /etc/sudoers.d/printserver

# --- Cron DDNS ---
msg "Configurando cron DDNS..."
(crontab -l 2>/dev/null | grep -v ddns-cron; echo '* * * * * /usr/bin/php /var/www/printserver/ddns-cron.php >> /var/log/ddns.log 2>&1') | crontab -
touch /var/log/ddns.log
chmod 644 /var/log/ddns.log

# --- SSH key ---
if [ "$INSTALL_SSH_KEY" = "y" ]; then
    msg "Inyectando clave SSH oficina..."
    mkdir -p /root/.ssh
    # Solo inyectar si SSH_KEY_OFICINA viene por env y no esta ya presente
    [ -n "$SSH_KEY_OFICINA" ] && grep -qF "$SSH_KEY_OFICINA" /root/.ssh/authorized_keys 2>/dev/null || \
        [ -n "$SSH_KEY_OFICINA" ] && echo "$SSH_KEY_OFICINA" >> /root/.ssh/authorized_keys
    chmod 600 /root/.ssh/authorized_keys
fi

# --- Servicios ---
msg "Arrancando servicios..."
systemctl enable cups apache2 mariadb smbd avahi-daemon cron 2>/dev/null
systemctl restart apache2 cups
systemctl start smbd avahi-daemon 2>/dev/null || true


# ==========================================================
# AGENTE RMM OPCIONAL (acceso remoto SSH para soporte tecnico)
# ==========================================================
echo ""
printf '%s---------------------------------------------------------%s\n' "$B" "$N"
printf '%s OPCIONAL - Agente de mantenimiento remoto (DNNS RMM) %s\n' "$B" "$N"
printf '%s---------------------------------------------------------%s\n' "$B" "$N"
echo ""
echo "  Si lo activas, el equipo creara un tunel SSH inverso seguro"
echo "  hacia rmm.dnns.es para que el equipo de DNNS pueda asistirte"
echo "  en mantenimiento, actualizaciones o resolucion de problemas."
echo ""
echo "  - Es completamente OPCIONAL."
echo "  - Solo el operador autorizado de DNNS puede conectar."
echo "  - Puedes desinstalarlo en cualquier momento."
echo "  - Servicio gratuito como el resto del software."
echo ""
WANT_RMM=$(ask "Instalar agente DNNS RMM?" "N")
if [[ "$WANT_RMM" =~ ^[YySs]$ ]]; then
  msg "Descargando e instalando agente DNNS RMM..."
  # Pasar metadata del Print Server al agente para registrar info completa.
  # Usamos bash <(...) para que el agente pueda preguntar interactivo si falta algo
  # (el modo pipe | bash bloquea stdin y el agente no podria preguntar).
  if PASSKEY_HOST=rmm.dnns.es \
     RMM_HOST=rmm.dnns.es \
     PRODUCTO=printserver \
     ADMIN_EMAIL="$ADMIN_EMAIL" \
     ADMIN_NAME="$ADMIN_NAME" \
     bash <(curl -fsSL https://raw.githubusercontent.com/dnns-es/dnns-rmm-agent/main/install.sh); then
    msg "  [OK] Agente RMM instalado"
  else
    warn "  [!] El agente RMM no se pudo instalar (continuamos sin el)"
  fi
else
  msg "Agente RMM no instalado"
fi


# --- Verificación ---
msg "Verificando..."
sleep 2
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" http://localhost/)
if [ "$HTTP_CODE" = "302" ] || [ "$HTTP_CODE" = "200" ]; then
    echo -e "${G}"
    cat <<DONE
=========================================================
                INSTALACION COMPLETA
=========================================================${N}
 Panel:    http://$CUPS_LAN_HOST/
 HTTPS:    https://$CUPS_LAN_HOST/  (cert self-signed)
 Admin:    $ADMIN_EMAIL
 DB:       $DB_NAME (user: $DB_USER)

 Siguientes pasos:
  1. Accede al panel y logueate como $ADMIN_EMAIL
  2. Pesta\u00f1a 'Escanear Red' \u2192 descubre tus impresoras
  3. Pesta\u00f1a 'DDNS' \u2192 configura tu subdominio dinamico
  4. Si hay NPM: proxy print.tudominio.es \u2192 $CUPS_LAN_HOST:8080

 Log de CUPS:      journalctl -u cups -f
 Log de Apache:    tail -f /var/log/apache2/printserver-*.log
 Log de DDNS:      tail -f /var/log/ddns.log
DONE
else
    warn "Panel devuelve HTTP $HTTP_CODE - revisa /var/log/apache2/printserver-error.log"
fi
