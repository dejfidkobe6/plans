-- ============================================================
-- BESIX Platform – setup pro plans.besix.cz
-- Spusť pouze jednou na sdílené DB (besixcz)!
-- ============================================================

-- Registrace plans aplikace do tabulky apps
INSERT INTO apps (app_key, app_name)
SELECT 'plans', 'BeSix Plans'
WHERE NOT EXISTS (SELECT 1 FROM apps WHERE app_key = 'plans');

-- Ověření – měl bys vidět řádek plans | BeSix Plans
SELECT * FROM apps WHERE app_key = 'plans';

-- ============================================================
-- Canvas data – ukládání stavu plátna (anotace, vrstvy, profese)
-- ============================================================
CREATE TABLE IF NOT EXISTS plan_canvas_data (
  project_id    INT          NOT NULL,
  state_json    LONGTEXT,
  profese_json  MEDIUMTEXT,
  annot_counter INT          NOT NULL DEFAULT 1,
  updated_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
