-- Volitelný seed: výchozí tiskárna + 2 schody profilu hlavy.
-- Pozn.: uživatelé/admin se řeší až v další fázi.

INSERT INTO printers
  (name, approved, bed_x_mm, bed_y_mm, bed_z_mm, posun_zprava_mm, vodici_tyce_y_mm, vodici_tyce_z_mm)
VALUES
  ('PRUSA MINI (výchozí)', 1, 180.00, 180.00, 180.00, 1.00, 17.40, 21.00);

SET @printer_id = LAST_INSERT_ID();

INSERT INTO printer_head_steps
  (printer_id, z_mm, xl_mm, xr_mm, yl_mm, yr_mm)
VALUES
  (@printer_id, 0.00, 36.50, 12.00, 15.50, 15.50),
  (@printer_id, 50.00, 10.00, 10.00, 10.00, 10.00);

