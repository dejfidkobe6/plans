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
