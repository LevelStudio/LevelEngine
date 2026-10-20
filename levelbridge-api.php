<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
function bridge_reply(int $code,array $data): never {http_response_code($code);echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);exit;}
if(($_SERVER['REQUEST_METHOD']??'')!=='POST'){header('Allow: POST');bridge_reply(405,['ok'=>false,'error'=>'Wymagane POST.']);}
$auth=(string)($_SERVER['HTTP_AUTHORIZATION']??$_SERVER['REDIRECT_HTTP_AUTHORIZATION']??'');
if(!preg_match('/^Bearer ([a-f0-9]{64})$/',$auth,$m))bridge_reply(401,['ok'=>false,'error'=>'Wymagany klucz LevelBridge.']);
if((int)($_SERVER['CONTENT_LENGTH']??0)>8192)bridge_reply(413,['ok'=>false,'error'=>'Za duża wiadomość.']);
if(strtolower(trim(explode(';',(string)($_SERVER['CONTENT_TYPE']??''))[0]))!=='application/json')bridge_reply(415,['ok'=>false,'error'=>'Wymagane application/json.']);
try{
    require __DIR__.'/lib/bootstrap.php';
    $raw=file_get_contents('php://input',false,null,0,8193);
    $payload=levelbridge_payload($raw===false?'':$raw);
    levelbridge_accept($payload,$m[1]);
    bridge_reply(200,['ok'=>true,'protocol'=>1,'timeout_seconds'=>LEVELBRIDGE_TIMEOUT]);
}catch(UnexpectedValueException $e){bridge_reply(401,['ok'=>false,'error'=>$e->getMessage()]);}
catch(InvalidArgumentException $e){bridge_reply(400,['ok'=>false,'error'=>$e->getMessage()]);}
catch(Throwable $e){error_log('LevelBridge: '.get_class($e));bridge_reply(503,['ok'=>false,'error'=>'CMS nie może zapisać statusu. Sprawdź bazę i migrację.']);}
