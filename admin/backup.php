<?php
declare(strict_types=1);
require __DIR__.'/../lib/bootstrap.php';
le_head();$q=db()->prepare('SELECT filename FROM backups WHERE id=?');$q->execute([(int)($_GET['id']??0)]);$name=$q->fetchColumn();
if(!$name||!preg_match('/^levelengine-[A-Za-z0-9-]+\.sql$/',$name)){http_response_code(404);exit(le_text('Brak backupu.'));}
$base=realpath(__DIR__.'/../backups');$file=realpath($base.'/'.$name);
if(!$file||!is_file($file)||!str_starts_with($file,$base.DIRECTORY_SEPARATOR)){http_response_code(404);exit(le_text('Brak pliku.'));}
header('Content-Type: application/sql');header('Content-Disposition: attachment; filename="'.$name.'"');header('Content-Length: '.filesize($file));header('Cache-Control: no-store');readfile($file);
