-- MariaDB 11.x
-- Schéma pro uživatele, tiskárny (včetně schvalování) a profil hlavy po výškách („schody“).

CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  email VARCHAR(255) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  is_admin TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS api_keys (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(100) NOT NULL,
  key_prefix CHAR(12) NOT NULL,
  key_hash CHAR(64) NOT NULL, -- sha256 hex
  rate_limit_per_min INT UNSIGNED NOT NULL DEFAULT 60,
  last_used_at TIMESTAMP NULL DEFAULT NULL,
  revoked_at TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_api_keys_hash (key_hash),
  KEY idx_api_keys_user (user_id),
  CONSTRAINT fk_api_keys_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Jednoduchý rate-limit po minutách (okno = unix timestamp zaokrouhlený dolů na minutu).
CREATE TABLE IF NOT EXISTS api_key_rate_limits (
  api_key_id BIGINT UNSIGNED NOT NULL,
  window_start_ts INT UNSIGNED NOT NULL,
  cnt INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (api_key_id, window_start_ts),
  CONSTRAINT fk_api_key_rate_limits_key FOREIGN KEY (api_key_id) REFERENCES api_keys(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tiskárna: buď systémová (created_by_user_id NULL), nebo uživatelská.
-- approved=1 znamená veřejně viditelná.
CREATE TABLE IF NOT EXISTS printers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(150) NOT NULL,
  created_by_user_id BIGINT UNSIGNED NULL DEFAULT NULL,
  approved TINYINT(1) NOT NULL DEFAULT 0,
  replaces_printer_id BIGINT UNSIGNED NULL DEFAULT NULL, -- když někdo navrhne úpravu, vznikne nový záznam, který nahrazuje původní

  bed_x_mm DECIMAL(8,2) NOT NULL,
  bed_y_mm DECIMAL(8,2) NOT NULL,
  bed_z_mm DECIMAL(8,2) NOT NULL,
  posun_zprava_mm DECIMAL(8,2) NOT NULL DEFAULT 0,

  vodici_tyce_y_mm DECIMAL(8,2) NOT NULL DEFAULT 0,
  vodici_tyce_z_mm DECIMAL(8,2) NOT NULL DEFAULT 0,

  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  KEY idx_printers_approved (approved),
  KEY idx_printers_created_by (created_by_user_id),
  KEY idx_printers_replaces (replaces_printer_id),
  CONSTRAINT fk_printers_created_by FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_printers_replaces FOREIGN KEY (replaces_printer_id) REFERENCES printers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Profil hlavy po výškách (schody).
-- Vyhodnocení ve výpočtu: pro objekt výšky H použiješ nejbližší z <= H.
CREATE TABLE IF NOT EXISTS printer_head_steps (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  printer_id BIGINT UNSIGNED NOT NULL,
  z_mm DECIMAL(8,2) NOT NULL,
  xl_mm DECIMAL(8,2) NOT NULL,
  xr_mm DECIMAL(8,2) NOT NULL,
  yl_mm DECIMAL(8,2) NOT NULL,
  yr_mm DECIMAL(8,2) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_printer_head_steps (printer_id, z_mm),
  KEY idx_printer_head_steps_printer (printer_id),
  CONSTRAINT fk_printer_head_steps_printer FOREIGN KEY (printer_id) REFERENCES printers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

