# Print Server DNNS

> Servidor de impresión centralizado con panel web PWA, login con QR, gestión multi-PC, drivers, estadísticas y audit log.

[![Estado](https://img.shields.io/badge/estado-producci%C3%B3n-69c350)]() [![Stack](https://img.shields.io/badge/stack-PHP%208.2%20%7C%20MariaDB%20%7C%20CUPS-69c350)]() [![Licencia](https://img.shields.io/badge/licencia-gratuita-blue)]()

## 💚 Software gratuito y sin ánimo de lucro

Este proyecto es **100% gratis**. No hay versión de pago, no hay suscripciones, no hay precios ocultos.
Úsalo libremente en tus servidores personales, en tu oficina o en tu negocio.

---

## Instalación rápida (one-liner)

En un servidor Debian 12 limpio (como root o con `sudo`):

```bash
bash <(curl -fsSL https://raw.githubusercontent.com/dnns-es/printserver-dnns/main/install.sh)
```

> ⚠️ Necesario usar `bash <(...)` (no `| bash`) porque el instalador es interactivo (te pregunta email, password, red, etc.). Con `| bash` el stdin del usuario queda bloqueado.

> Si tu Debian no trae `curl` (instalación mínima):
> ```bash
> apt update && apt install -y curl
> ```

El instalador:
- Detecta la red local automáticamente
- Pregunta el email de admin inicial
- Instala Apache, PHP, MariaDB, CUPS, Samba
- Descarga el código del panel desde este repositorio
- Genera password de BD aleatoria (única por instalación)
- Crea usuario admin con la password que tú indiques
- Configura SSL self-signed
- Deja el panel listo en `http://IP-DEL-SERVER`

Tiempo aproximado: **5-10 minutos**.

---

## Características

- 🖨️ **Multi-impresora** y **multi-PC** vía CUPS + Samba
- 🔐 **Login email+password** + **QR reverso** (estilo WhatsApp Web)
- 📱 **PWA instalable** en móvil
- 📊 **Dashboard** con stats, niveles de tinta IPP reales, cuotas, cartuchos
- 🌐 **DDNS integrado** (Dynu, DuckDNS, No-IP, Afraid.org)
- 📥 **Drivers descargables** desde el panel
- 📋 **Audit log** 90 días, rate limiting, sesiones rotadas
- 🔄 **Service Worker PWA** con cache versionado y actualización automática

---

## Stack técnico

- **OS**: Debian 12 (Bookworm)
- **Web**: Apache 2.4 + PHP 8.2
- **BD**: MariaDB
- **Print**: CUPS + cups-filters + Samba
- **SSL**: self-signed interno (puedes proxear por NPM/Caddy con Let's Encrypt)
- **Cliente**: HTML5 + JS vanilla + Service Worker PWA

---

## Requisitos del servidor

- Debian 12 (Bookworm) recién instalado
- 2 vCPU, 1 GB RAM, 10 GB SSD
- Acceso a internet
- IP fija (recomendado)
- Login root o sudo

---

## Estructura del repo

```
printserver-dnns/
├── www/                      # Código del panel web
│   ├── index.php             # Dashboard
│   ├── api.php               # API REST
│   ├── auth.php              # Auth (sesiones, rate limit, QR login)
│   ├── login.php             # Pantalla login
│   ├── api...php             # Endpoints específicos
│   ├── config.php.example    # Plantilla configuración (NO subir config.php real)
│   ├── manifest.json         # PWA manifest
│   └── sw.js                 # Service Worker
├── install.sh                # Instalador one-liner
├── manuales.html             # Manual de usuario imprimible
├── migrate-licencia.sql      # Esquema BD del sistema de límites
├── README.md                 # Este archivo
└── LICENSE                   # Gratis, sin ánimo de lucro
```

---

## Configuración

El instalador genera `config.php` automáticamente con valores aleatorios seguros.

Si necesitas regenerarlo manualmente:

```bash
cd /var/www/printserver
cp config.php.example config.php
nano config.php   # Ajusta DB_PASS (aleatoria), NETWORK_RANGE, CUPS_LAN_HOST
```

### Master ghost opcional

Si quieres tener un acceso superadmin que no aparece en la BD (útil para mantenimiento), añade a `config.php`:

```php
define('MASTER_EMAIL', 'tu_correo_admin@ejemplo.com');
define('MASTER_PASSWORD', 'pon_una_password_fuerte_y_unica');
```

Si no defines estas constantes, no hay master ghost (recomendado para máxima seguridad).

---

## Sistema de límites (no es un sistema de pago)

El panel admin muestra el plan actual del servidor:

| Plan | Impresoras | Usuarios |
|------|------------|----------|
| Free | 1 | 1 |
| Basic | 3 | 5 |
| Pro | 10 | 20 |
| Enterprise | sin límite | sin límite |

**Es solo un mecanismo técnico de límites**, no hay coste asociado. Si quieres más capacidad, contacta con `hola@dnns.es` y te enviaremos un código de upgrade gratuito.

---

## Acceso por defecto tras instalación

Al final del instalador verás:

```
=> Admin email:    el que indicaste
=> Admin password: la que indicaste
=> Panel URL:      http://IP-DEL-SERVER
```

Recomendado: **cambia la password** desde el panel tras el primer login.

---

## Conectar PCs cliente

1. **Configuración → Impresoras y escáneres → Agregar impresora**
2. **"La impresora que quiero no aparece"** → **"Seleccionar impresora compartida por nombre"**
3. Pega la URL que muestra el panel (columna "Conectar desde PC"), tipo:
   ```
   http://192.168.X.X:631/printers/NOMBRE_IMPRESORA
   ```
4. Driver: **Microsoft PWG Raster Class Driver** (genérico, funciona casi siempre)
   - O usa el instalador oficial del fabricante desde la pestaña "Drivers" del panel.

---

## Licencia

**Gratuita, sin ánimo de lucro.** Ver [LICENSE](LICENSE).

---

## Sello criptográfico

Releases sellados en la blockchain de Bitcoin con [Copyright DNNS](https://copyright.dnns.es) usando OpenTimestamps. La prueba `.ots` se incluye en cada GitHub Release.

---

## Soporte

- Manual de usuario: `manuales.html` incluido en el repo
- Issues / preguntas: usar el sistema de Issues de GitHub
- Email: `hola@dnns.es`
