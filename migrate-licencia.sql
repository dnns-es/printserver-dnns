-- Fecha: 19/04/2026
-- Migración: crear tabla `licencia` que falta en install.sh del Print Server
-- Causa del bug: api.php?action=license_info devuelve HTTP 500 silencioso
--                porque getCurrentLicense() hace SELECT * FROM licencia
--                y la tabla nunca se creó.
--
-- USO: mariadb -u USER -pPASS NOMBRE_BD < migrate-licencia.sql

CREATE TABLE IF NOT EXISTS licencia (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  tipo              ENUM('free','basic','pro','enterprise') NOT NULL DEFAULT 'free',
  max_impresoras    INT NOT NULL DEFAULT 1,
  max_usuarios      INT NOT NULL DEFAULT 1,
  hw_id             VARCHAR(64) DEFAULT NULL,
  activation_code   VARCHAR(64) DEFAULT NULL,
  cliente           VARCHAR(200) DEFAULT NULL,
  activated_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
  expires_at        DATETIME DEFAULT NULL,

  INDEX idx_tipo (tipo),
  INDEX idx_activation (activation_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
