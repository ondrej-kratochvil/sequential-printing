-- Migrace 001 (Milník D2): pokusy o přihlášení / registraci (omezení počtu) a projekty uživatelů.
-- Idempotentní: jde spustit opakovaně (bin/migrace.php spouští všechny soubory db/migrace/*.sql popořadě).

-- Pokusy o přihlášení a registraci (omezení počtu pokusů podle e-mailu a IP). Staré záznamy maže aplikace.
CREATE TABLE IF NOT EXISTS login_attempts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  druh VARCHAR(20) NOT NULL, -- prihlaseni | registrace
  email VARCHAR(255) NOT NULL DEFAULT '',
  ip VARCHAR(45) NOT NULL DEFAULT '',
  uspech TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_login_attempts_email (druh, email, created_at),
  KEY idx_login_attempts_ip (druh, ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Projekt = uložený vstup formuláře (objekty + nastavení) ve stejném tvaru jako export vstupu z UI (JSON).
CREATE TABLE IF NOT EXISTS projects (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(150) NOT NULL,
  data MEDIUMTEXT NOT NULL, -- JSON {"version": 1, "objekty": [...], "nastaveni": {...}}
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_projects_user (user_id, updated_at),
  CONSTRAINT fk_projects_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
