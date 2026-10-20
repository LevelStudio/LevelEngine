<?php
declare(strict_types=1);
require __DIR__.'/../lib/bootstrap.php';
start_session();require_permission('manage_plugins');
$key=(string)($_GET['plugin']??'');$q=db()->prepare('SELECT * FROM plugins WHERE plugin_key=? AND enabled=1');$q->execute([$key]);$plugin=$q->fetch();
$file=$plugin?le_plugin_file($plugin,'admin'):null;
if(!$file){http_response_code(404);exit(le_text('Plugin nie jest aktywny lub nie ma panelu konfiguracji.'));}
// The plugin owns its form handling; execute before output so redirects work.
require $file;
