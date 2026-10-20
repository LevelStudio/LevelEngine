<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
if(($_SERVER['REQUEST_METHOD']??'')!=='GET'){http_response_code(405);header('Allow: GET');echo '{"ok":false}';exit;}
try{
    require __DIR__.'/lib/bootstrap.php';
    $rows=[];foreach(levelbridge_servers(true) as $s)$rows[]=['id'=>(int)$s['id'],'status'=>$s['status'],'status_label'=>$s['status_label'],'players'=>(int)$s['players'],'max_players'=>(int)$s['max_players'],'version'=>(string)$s['version']];
    echo json_encode(['ok'=>true,'servers'=>$rows],JSON_INVALID_UTF8_SUBSTITUTE);
}catch(Throwable $e){http_response_code(503);echo '{"ok":false}';}
