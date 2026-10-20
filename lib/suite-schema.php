<?php
declare(strict_types=1);
// This file is called only by the installer and an explicit upgrade migration.
function le_schema_install(PDO $pdo): void {
    $add=function(string $table,string $column,string $definition)use($pdo):void{
        $q=$pdo->prepare('SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');$q->execute([$table,$column]);
        if(!$q->fetchColumn())$pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    };
    foreach(['session_version'=>'INT UNSIGNED NOT NULL DEFAULT 1','email_verified_at'=>'DATETIME NULL'] as $c=>$d)$add('users',$c,$d);
    foreach(['posts','pages'] as $t){
        foreach(['edit_version'=>'INT UNSIGNED NOT NULL DEFAULT 1','deleted_at'=>'DATETIME NULL','publish_at'=>'DATETIME NULL'] as $c=>$d)$add($t,$c,$d);
    }
    $pdo->exec('ALTER TABLE users MODIFY twofa_secret VARCHAR(255) NULL');
    $add('pages','status',"VARCHAR(20) NOT NULL DEFAULT 'published'");
    $add('media','width','INT UNSIGNED NOT NULL DEFAULT 0');$add('media','height','INT UNSIGNED NOT NULL DEFAULT 0');
    $sql=[
      "CREATE TABLE IF NOT EXISTS content_revisions(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,kind VARCHAR(10) NOT NULL,object_id INT UNSIGNED NOT NULL,snapshot LONGTEXT NOT NULL,created_by INT UNSIGNED NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX(kind,object_id,id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
      "CREATE TABLE IF NOT EXISTS content_autosaves(user_id INT UNSIGNED NOT NULL,kind VARCHAR(10) NOT NULL,object_id INT UNSIGNED NOT NULL,snapshot LONGTEXT NOT NULL,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(user_id,kind,object_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
      "CREATE TABLE IF NOT EXISTS content_translations(kind VARCHAR(10) NOT NULL,object_id INT UNSIGNED NOT NULL,locale CHAR(2) NOT NULL,title VARCHAR(255) NOT NULL,slug VARCHAR(255) NOT NULL,content MEDIUMTEXT NOT NULL,seo_description VARCHAR(500) NULL,PRIMARY KEY(kind,object_id,locale),UNIQUE KEY(kind,locale,slug)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
      "CREATE TABLE IF NOT EXISTS account_tokens(token_hash CHAR(64) PRIMARY KEY,user_id INT UNSIGNED NOT NULL,purpose VARCHAR(20) NOT NULL,expires_at DATETIME NOT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX(user_id,purpose)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
      "CREATE TABLE IF NOT EXISTS delivery_queue(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,event_key VARCHAR(190) NOT NULL UNIQUE,kind VARCHAR(20) NOT NULL,payload LONGTEXT NOT NULL,state VARCHAR(20) NOT NULL DEFAULT 'pending',attempts INT NOT NULL DEFAULT 0,available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,claimed_at DATETIME NULL,last_error VARCHAR(500) NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX(state,available_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
      "CREATE TABLE IF NOT EXISTS server_history(server_id INT UNSIGNED NOT NULL,bucket BIGINT UNSIGNED NOT NULL,samples INT UNSIGNED NOT NULL,online_samples INT UNSIGNED NOT NULL,players_sum BIGINT UNSIGNED NOT NULL,players_peak INT UNSIGNED NOT NULL,PRIMARY KEY(server_id,bucket)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
      "CREATE TABLE IF NOT EXISTS community_items(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,plugin_key VARCHAR(80) NOT NULL,title VARCHAR(160) NOT NULL,description TEXT NULL,options_json TEXT NULL,starts_at DATETIME NULL,ends_at DATETIME NULL,capacity INT UNSIGNED NOT NULL DEFAULT 0,price_minor INT UNSIGNED NOT NULL DEFAULT 0,currency CHAR(3) NOT NULL DEFAULT 'PLN',enabled TINYINT NOT NULL DEFAULT 1,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX(plugin_key,enabled)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
      "CREATE TABLE IF NOT EXISTS community_entries(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,item_id INT UNSIGNED NOT NULL,user_id INT UNSIGNED NOT NULL,response TEXT NULL,state VARCHAR(30) NOT NULL DEFAULT 'pending',price_minor INT UNSIGNED NOT NULL DEFAULT 0,currency CHAR(3) NOT NULL DEFAULT 'PLN',created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,UNIQUE KEY(item_id,user_id),INDEX(user_id,id),CONSTRAINT suite_entry_item FOREIGN KEY(item_id) REFERENCES community_items(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    ];foreach($sql as $statement)$pdo->exec($statement);
    // Existing role customizations are kept. Only report the previous moderator defaults.
    $q=$pdo->query('SELECT id,name,permissions FROM roles WHERE id=3');$r=$q->fetch(PDO::FETCH_ASSOC);
    if($r){$permissions=json_decode($r['permissions'],true)?:[];if(!empty($permissions['manage_servers'])||!empty($permissions['manage_themes']))$pdo->prepare("INSERT IGNORE INTO settings(setting_key,setting_value) VALUES('role_review_required','1')")->execute();}
    $pdo->prepare("INSERT INTO settings(setting_key,setting_value) VALUES('suite_schema','10') ON DUPLICATE KEY UPDATE setting_value='10'")->execute();
}
