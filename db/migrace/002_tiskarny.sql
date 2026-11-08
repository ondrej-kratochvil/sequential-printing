-- Migrace 002 (Milník D3): tiskárny uživatelů v DB, návrh na zveřejnění a schválení adminem.
-- Idempotentní (ADD COLUMN IF NOT EXISTS, MariaDB 10.0.2+).
--
-- Základní rozměry hlavy (platí od výšky 0) jsou sloupce xl_mm … yr_mm; printer_head_steps drží jen další schody
-- (od výšky z > 0). Starší záznamy bez těchto sloupců berou základ ze schodu z = 0 (src/TiskarnyDb.php).
ALTER TABLE printers
  ADD COLUMN IF NOT EXISTS xl_mm DECIMAL(8,2) NULL DEFAULT NULL AFTER posun_zprava_mm,
  ADD COLUMN IF NOT EXISTS xr_mm DECIMAL(8,2) NULL DEFAULT NULL AFTER xl_mm,
  ADD COLUMN IF NOT EXISTS yl_mm DECIMAL(8,2) NULL DEFAULT NULL AFTER xr_mm,
  ADD COLUMN IF NOT EXISTS yr_mm DECIMAL(8,2) NULL DEFAULT NULL AFTER yl_mm,
  ADD COLUMN IF NOT EXISTS navrzeno_at TIMESTAMP NULL DEFAULT NULL AFTER approved, -- návrh na zveřejnění čeká na admina
  ADD COLUMN IF NOT EXISTS poznamka TEXT NULL DEFAULT NULL AFTER vodici_tyce_z_mm; -- odkud hodnoty jsou (měření, dokumentace)
