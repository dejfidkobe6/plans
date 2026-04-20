<?php
// ============================================================
// BESIX Plans – automatické DB migrace
// Spouští se při každém API requestu (pouze pending migrace).
// Přidej nové migrace na konec pole $MIGRATIONS.
// ============================================================

$MIGRATIONS = [

    '001_plan_canvas_data' => "
        CREATE TABLE IF NOT EXISTS plan_canvas_data (
            project_id    INT          NOT NULL,
            state_json    LONGTEXT,
            profese_json  MEDIUMTEXT,
            annot_counter INT          NOT NULL DEFAULT 1,
            updated_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
                                       ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (project_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",

    '002_plan_backgrounds' => "
        CREATE TABLE IF NOT EXISTS plan_backgrounds (
            project_id    INT          NOT NULL,
            level_id      VARCHAR(64)  NOT NULL,
            image_data    LONGTEXT,
            original_data LONGTEXT,
            updated_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
                                       ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (project_id, level_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",

    '003_remember_tokens' => "
        CREATE TABLE IF NOT EXISTS remember_tokens (
            id         INT AUTO_INCREMENT PRIMARY KEY,
            user_id    INT NOT NULL,
            token_hash CHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            INDEX idx_tok (token_hash),
            INDEX idx_uid (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",

];

// ---------------------------------------------------------------
// Runner – volá se jednou za request, přeskočí již hotové migrace
// ---------------------------------------------------------------
function runMigrations(PDO $db): void {
    global $MIGRATIONS;

    // Vytvoř tabulku migrací pokud neexistuje (nekritické – bez ní přeskočíme)
    try {
        $db->exec("
            CREATE TABLE IF NOT EXISTS _migrations (
                name       VARCHAR(128) NOT NULL PRIMARY KEY,
                applied_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (\Throwable $e) {
        error_log('Cannot create _migrations table: ' . $e->getMessage());
        return; // Bez tracking tabulky migrace přeskočíme
    }

    // Načti již aplikované migrace
    try {
        $done = $db->query("SELECT name FROM _migrations")->fetchAll(PDO::FETCH_COLUMN);
    } catch (\Throwable $e) {
        return;
    }
    $done = array_flip($done);

    foreach ($MIGRATIONS as $name => $sql) {
        if (isset($done[$name])) continue; // přeskočit

        try {
            $db->exec(trim($sql));
            $db->prepare("INSERT IGNORE INTO _migrations (name) VALUES (?)")
               ->execute([$name]);
        } catch (\Throwable $e) {
            // Loguj ale nespadni – nechceme zablokovat celou API kvůli migraci
            error_log("Migration [{$name}] failed: " . $e->getMessage());
        }
    }
}
