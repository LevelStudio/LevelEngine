CREATE TABLE IF NOT EXISTS roles (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(80) NOT NULL UNIQUE,
 color VARCHAR(20) NOT NULL DEFAULT '#ffffff',
 badge VARCHAR(80) NULL,
 can_access_admin TINYINT(1) NOT NULL DEFAULT 0,
 is_system TINYINT(1) NOT NULL DEFAULT 0,
 permissions JSON NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Systemowe rangi muszą istnieć przed utworzeniem użytkowników.
INSERT IGNORE INTO roles (id,name,color,badge,can_access_admin,is_system,permissions) VALUES
(1,'Head Admin','#ff3333','★',1,1,'{"manage_roles":true,"manage_users":true,"manage_content":true,"manage_media":true,"manage_settings":true,"manage_gallery":true,"manage_pages":true,"manage_plugins":true,"manage_servers":true,"manage_themes":true,"view_audit_log":true}'),
(2,'Admin','#33cc33','◆',1,1,'{"manage_roles":false,"manage_users":true,"manage_content":true,"manage_media":true,"manage_settings":true,"manage_gallery":true,"manage_pages":true,"manage_plugins":false,"manage_servers":true,"manage_themes":true,"view_audit_log":false}'),
(3,'Moderator','#3399ff','●',1,1,'{"manage_roles":false,"manage_users":false,"manage_content":true,"manage_media":true,"manage_settings":false,"manage_gallery":true,"manage_pages":false,"manage_plugins":false,"manage_servers":false,"manage_themes":false,"view_audit_log":false}'),
(4,'Gracz','#ffffff','',0,1,'{}');

CREATE TABLE IF NOT EXISTS users (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 username VARCHAR(50) NOT NULL UNIQUE,
 display_name VARCHAR(80) NULL,
 email VARCHAR(190) NOT NULL UNIQUE,
 password_hash VARCHAR(255) NOT NULL,
 twofa_secret VARCHAR(255) NULL,
 twofa_enabled TINYINT(1) NOT NULL DEFAULT 0,
 twofa_last_step BIGINT NOT NULL DEFAULT 0,
 twofa_recovery LONGTEXT NULL,
 role_id INT UNSIGNED NOT NULL DEFAULT 4,
 avatar VARCHAR(255) NULL,
 bio TEXT NULL,
 signature VARCHAR(500) NULL,
 location VARCHAR(100) NULL,
 website VARCHAR(255) NULL,
 status ENUM('active','banned') NOT NULL DEFAULT 'active',
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 CONSTRAINT fk_users_role FOREIGN KEY(role_id) REFERENCES roles(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
 setting_key VARCHAR(100) PRIMARY KEY,
 setting_value LONGTEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS posts (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 title VARCHAR(255) NOT NULL,
 slug VARCHAR(255) NOT NULL UNIQUE,
 category VARCHAR(40) NOT NULL DEFAULT 'news',
 content MEDIUMTEXT NOT NULL,
 content_format VARCHAR(20) NOT NULL DEFAULT 'html',
 og_image VARCHAR(255) NULL,
 excerpt TEXT NULL,
 status VARCHAR(20) NOT NULL DEFAULT 'published',
 featured TINYINT(1) NOT NULL DEFAULT 0,
 thumbnail VARCHAR(255) NULL,
 seo_title VARCHAR(255) NULL,
 seo_description VARCHAR(500) NULL,
 author_id INT UNSIGNED NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 CONSTRAINT fk_posts_author FOREIGN KEY(author_id) REFERENCES users(id) ON DELETE SET NULL,
 INDEX idx_posts_category(category), INDEX idx_posts_status(status), INDEX idx_posts_created(created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pages (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 title VARCHAR(255) NOT NULL,
 slug VARCHAR(150) NOT NULL UNIQUE,
 content MEDIUMTEXT NOT NULL,
 content_format VARCHAR(20) NOT NULL DEFAULT 'html',
 og_image VARCHAR(255) NULL,
 seo_title VARCHAR(255) NULL,
 seo_description VARCHAR(500) NULL,
 show_in_menu TINYINT(1) NOT NULL DEFAULT 0,
 menu_order INT NOT NULL DEFAULT 0,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS media (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 path VARCHAR(255) NOT NULL,
 title VARCHAR(160) NULL,
 alt_text VARCHAR(255) NULL,
 mime VARCHAR(80) NULL,
 size_bytes INT UNSIGNED NOT NULL DEFAULT 0,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_media_created(created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS post_images (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 post_id INT UNSIGNED NULL,
 media_id INT UNSIGNED NULL,
 path VARCHAR(255) NOT NULL,
 alt_text VARCHAR(255) NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 INDEX(post_id), INDEX(media_id),
 CONSTRAINT fk_post_images_post FOREIGN KEY(post_id) REFERENCES posts(id) ON DELETE SET NULL,
 CONSTRAINT fk_post_images_media FOREIGN KEY(media_id) REFERENCES media(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gallery (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 title VARCHAR(160) NOT NULL,
 author VARCHAR(80) NOT NULL,
 game VARCHAR(20) NOT NULL DEFAULT 'MC',
 image VARCHAR(255) NOT NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS menus (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 label VARCHAR(80) NOT NULL,
 url VARCHAR(255) NOT NULL,
 icon VARCHAR(50) NULL,
 sort_order INT NOT NULL DEFAULT 0,
 visible TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 ip VARCHAR(45) NOT NULL,
 at INT UNSIGNED NOT NULL,
 INDEX idx_login_ip_at(ip,at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS plugins (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 plugin_key VARCHAR(100) NOT NULL UNIQUE,
 name VARCHAR(160) NOT NULL,
 version VARCHAR(40) NOT NULL DEFAULT '1.0.0',
 author VARCHAR(160) NULL,
 description TEXT NULL,
 path VARCHAR(255) NOT NULL,
 enabled TINYINT(1) NOT NULL DEFAULT 0,
 manifest_json LONGTEXT NULL,
 installed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS themes (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 theme_key VARCHAR(100) NOT NULL UNIQUE,
 name VARCHAR(160) NOT NULL,
 version VARCHAR(40) NOT NULL DEFAULT '1.0.0',
 author VARCHAR(160) NULL,
 description TEXT NULL,
 path VARCHAR(255) NOT NULL,
 is_system TINYINT(1) NOT NULL DEFAULT 0,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS servers (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, sort_order INT NOT NULL DEFAULT 0, enabled TINYINT(1) NOT NULL DEFAULT 1,
 game_key VARCHAR(40) NOT NULL DEFAULT 'custom', game_label VARCHAR(80) NOT NULL DEFAULT 'SERWER', name VARCHAR(160) NOT NULL,
 description VARCHAR(500) NULL, address VARCHAR(255) NOT NULL, port INT UNSIGNED NOT NULL DEFAULT 0, players INT UNSIGNED NOT NULL DEFAULT 0,
 max_players INT UNSIGNED NOT NULL DEFAULT 0, status VARCHAR(20) NOT NULL DEFAULT 'unknown', status_label VARCHAR(80) NULL, version VARCHAR(120) NULL,
 icon VARCHAR(80) NULL, banner VARCHAR(500) NULL, accent VARCHAR(7) NULL, site_url VARCHAR(500) NULL, discord_url VARCHAR(500) NULL,
 button_label VARCHAR(80) NOT NULL DEFAULT 'WEJDŹ', button_url VARCHAR(500) NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, INDEX idx_servers_visible_order (enabled,sort_order,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_log (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NULL, username VARCHAR(190) NULL, action VARCHAR(120) NOT NULL,
 target_type VARCHAR(120) NULL, target_id VARCHAR(190) NULL, details_json TEXT NULL, ip_address VARCHAR(45) NULL, user_agent VARCHAR(500) NULL,
 request_id VARCHAR(80) NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX idx_audit_created(created_at),
 INDEX idx_audit_user(user_id,created_at), INDEX idx_audit_action(action,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cms_migrations (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, migration_key VARCHAR(190) NOT NULL UNIQUE, version VARCHAR(40) NOT NULL, applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- LevelEngine 1.2.0






CREATE TABLE IF NOT EXISTS backups (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, filename VARCHAR(255) NOT NULL, size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
 created_by INT UNSIGNED NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX idx_backups_created(created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS auth_limits (bucket CHAR(64) PRIMARY KEY,window_id BIGINT NOT NULL,attempts INT NOT NULL DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS levelbridge_servers (server_id INT UNSIGNED PRIMARY KEY,key_hash CHAR(64) NOT NULL,last_seen BIGINT UNSIGNED NOT NULL DEFAULT 0,motd VARCHAR(500) NOT NULL DEFAULT '',plugin_version VARCHAR(40) NOT NULL DEFAULT '',CONSTRAINT fk_levelbridge_server FOREIGN KEY(server_id) REFERENCES servers(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
