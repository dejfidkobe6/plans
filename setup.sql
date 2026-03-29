-- ============================================================
-- BESIX Platform – setup pro plans.besix.cz
-- Spusť pouze jednou na sdílené DB!
-- ============================================================

-- 1. Sessions tabulka (pokud ještě neexistuje z board.besix.cz)
CREATE TABLE IF NOT EXISTS sessions (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED    NOT NULL,
    token      CHAR(64)        NOT NULL,
    expires_at DATETIME        NOT NULL,
    created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_token (token),
    KEY idx_user (user_id),
    KEY idx_expires (expires_at),
    CONSTRAINT fk_sess_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Registrace plans aplikace
INSERT INTO apps (app_key, app_name)
SELECT 'plans', 'BeSix Plans'
WHERE NOT EXISTS (SELECT 1 FROM apps WHERE app_key = 'plans');

-- 3. Ověření (mělo by vrátit řádek s plans)
SELECT * FROM apps WHERE app_key = 'plans';
