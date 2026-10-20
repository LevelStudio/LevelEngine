<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
function media_reply(int $code,array $data): never {http_response_code($code);echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);exit;}
try{
    require __DIR__.'/../lib/bootstrap.php';start_session();$u=current_user();
    if(!$u)media_reply(401,['ok'=>false,'error'=>le_text('Zaloguj się ponownie.')]);
    if(($u['status']??'')!=='active'||empty($u['can_access_admin'])||!has_permission('manage_media',$u))media_reply(403,['ok'=>false,'error'=>le_text('Brak uprawnienia do zarządzania mediami.')]);
    $method=$_SERVER['REQUEST_METHOD']??'';
    if($method==='GET')media_reply(200,['ok'=>true]+le_media_list((string)($_GET['q']??''),(int)($_GET['offset']??0)));
    if($method!=='POST'){header('Allow: GET, POST');media_reply(405,['ok'=>false,'error'=>le_text('Niedozwolona metoda.')]);}
    if(!csrf_ok())media_reply(403,['ok'=>false,'error'=>le_text('Sesja formularza wygasła. Odśwież stronę.')]);
    try{$item=le_media_upload($_FILES['image']??[],(string)($_POST['title']??''),(string)($_POST['alt_text']??''));}
    catch(RuntimeException $e){media_reply(400,['ok'=>false,'error'=>$e->getMessage()]);}
    media_reply(201,['ok'=>true,'item'=>$item]);
}catch(Throwable $e){error_log(le_text('Media Uploader: ').get_class($e));media_reply(503,['ok'=>false,'error'=>le_text('Nie udało się zapisać obrazka. Sprawdź bazę i prawa katalogu uploads/media.')]);}
