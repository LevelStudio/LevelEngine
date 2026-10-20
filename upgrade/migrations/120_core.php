<?php
return function(PDO $pdo): void {
    $queries=[
      "ALTER TABLE users ADD COLUMN twofa_secret VARCHAR(64) NULL",
      "ALTER TABLE users ADD COLUMN twofa_enabled TINYINT(1) NOT NULL DEFAULT 0",
      "ALTER TABLE posts ADD COLUMN content_format VARCHAR(20) NOT NULL DEFAULT 'html'",
      "ALTER TABLE posts ADD COLUMN og_image VARCHAR(255) NULL",
      "ALTER TABLE pages ADD COLUMN content_format VARCHAR(20) NOT NULL DEFAULT 'html'",
      "ALTER TABLE pages ADD COLUMN og_image VARCHAR(255) NULL"
    ];
    foreach($queries as $sql){try{$pdo->exec($sql);}catch(PDOException $e){if(!str_contains($e->getMessage(),'Duplicate column'))throw $e;}}
    $pdo->exec("CREATE TABLE IF NOT EXISTS backups (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, filename VARCHAR(255) NOT NULL, size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0, created_by INT UNSIGNED NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX idx_backups_created(created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
};
