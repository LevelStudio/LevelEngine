<?php
return function(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS levelbridge_servers (server_id INT UNSIGNED PRIMARY KEY,key_hash CHAR(64) NOT NULL,last_seen BIGINT UNSIGNED NOT NULL DEFAULT 0,motd VARCHAR(500) NOT NULL DEFAULT '',plugin_version VARCHAR(40) NOT NULL DEFAULT '',CONSTRAINT fk_levelbridge_server FOREIGN KEY(server_id) REFERENCES servers(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
};
