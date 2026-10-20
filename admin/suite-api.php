<?php
declare(strict_types=1);
require __DIR__.'/../lib/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
try{
    $u=current_user();
    if(!$u){http_response_code(401);throw new RuntimeException(le_text('Zaloguj się ponownie.'));}
    $kind=(string)($_REQUEST['kind']??'');
    if(!in_array($kind,['post','page'],true))throw new RuntimeException(le_text('Nieprawidłowy typ treści.'));
    if(empty($u['can_access_admin'])||!has_permission($kind==='post'?'manage_content':'manage_pages',$u)){http_response_code(403);throw new RuntimeException(le_text('Brak uprawnień.'));}
    $id=(int)($_REQUEST['id']??0);
    if($id){$row=le_content_row($kind,$id);if(!$row||$row['deleted_at']!==null)throw new RuntimeException('Brak treści.');}
    if($_SERVER['REQUEST_METHOD']==='POST'){
        if(!csrf_ok())throw new RuntimeException('Sesja formularza wygasła.');engine_rate('autosave',(string)$u['id'],200);
        if($id&&(int)($_POST['edit_version']??0)!==(int)$row['edit_version'])throw new RuntimeException('Treść zmieniła się w innym oknie. Odśwież edytor przed zapisem kopii.');
        $fields=[];foreach($_POST as $key=>$v)if(is_string($v)&&!in_array($key,['csrf','action'],true))$fields[$key]=$v;
        $json=json_encode($fields,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);if(strlen($json)>2200000)throw new RuntimeException('Kopia jest zbyt duża.');
        db()->prepare('INSERT INTO content_autosaves(user_id,kind,object_id,snapshot) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE snapshot=VALUES(snapshot),updated_at=NOW()')->execute([$u['id'],$kind,$id,$json]);echo '{"ok":true}';
    }elseif($_SERVER['REQUEST_METHOD']==='GET'){
        $q=db()->prepare('SELECT snapshot FROM content_autosaves WHERE user_id=? AND kind=? AND object_id=?');$q->execute([$u['id'],$kind,$id]);$snapshot=$q->fetchColumn();echo json_encode(['ok'=>true,'snapshot'=>$snapshot?json_decode($snapshot,true):null]);
    }else{http_response_code(405);echo '{"ok":false}';}
}catch(Throwable $e){if(http_response_code()<400)http_response_code(400);echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);}
