<?php
declare(strict_types=1);
require __DIR__.'/lib/bootstrap.php';
$key=(string)($_GET['plugin']??'');$q=db()->prepare('SELECT * FROM plugins WHERE plugin_key=? AND enabled=1');$q->execute([$key]);$plugin=$q->fetch();
$file=$plugin?le_plugin_file($plugin,'api'):null;
if(!$file){http_response_code(404);exit(le_text('Endpoint nie istnieje.'));}
// API entry points must authenticate every request themselves (token or session).
require $file;
