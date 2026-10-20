<?php
declare(strict_types=1);
function le_send_account_token(int $id,string $purpose): void {
    if(!in_array($purpose,['reset','verify'],true))throw new InvalidArgumentException('Typ tokenu.');
    if(setting('smtp_host','')==='')return;
    $q=db()->prepare('SELECT id,email,status FROM users WHERE id=?');$q->execute([$id]);$u=$q->fetch();if(!$u||$u['status']!=='active')return;
    $raw=bin2hex(random_bytes(32));$ttl=$purpose==='reset'?1800:86400;$pdo=db();$pdo->beginTransaction();
    try{$pdo->prepare('DELETE FROM account_tokens WHERE user_id=? AND purpose=?')->execute([$id,$purpose]);$pdo->prepare('INSERT INTO account_tokens(token_hash,user_id,purpose,expires_at) VALUES(?,?,?,?)')->execute([hash('sha256',$raw),$id,$purpose,date('Y-m-d H:i:s',time()+$ttl)]);
        $path=$purpose==='reset'?'/account/reset':'/account/verify';le_enqueue('account:'.$purpose.':'.hash('sha256',$raw),'mail',['to'=>$u['email'],'subject'=>$purpose==='reset'?'Reset hasła':'Potwierdzenie adresu e-mail','body'=>"Otwórz link:\n".le_public_base().$path.'?token='.$raw."\nJeśli nie wysłałeś prośby, zignoruj wiadomość."]);$pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function le_consume_token(string $raw,string $purpose,?string $password=null): void {
    if(!preg_match('/^[a-f0-9]{64}$/D',$raw))throw new RuntimeException('Link jest nieprawidłowy lub wygasł.');
    if($purpose==='reset')le_password_check((string)$password);
    $pdo=db();$pdo->beginTransaction();
    try{$q=$pdo->prepare('SELECT t.*,u.status FROM account_tokens t JOIN users u ON u.id=t.user_id WHERE token_hash=? AND purpose=? AND expires_at>NOW() FOR UPDATE');$q->execute([hash('sha256',$raw),$purpose]);$token=$q->fetch();if(!$token||$token['status']!=='active')throw new RuntimeException('Link jest nieprawidłowy lub wygasł.');
        if($purpose==='reset')$pdo->prepare('UPDATE users SET password_hash=?,session_version=session_version+1 WHERE id=?')->execute([password_hash($password,PASSWORD_DEFAULT),$token['user_id']]);else $pdo->prepare('UPDATE users SET email_verified_at=NOW() WHERE id=?')->execute([$token['user_id']]);
        $pdo->prepare('DELETE FROM account_tokens WHERE user_id=? AND purpose=?')->execute([$token['user_id'],$purpose]);$pdo->commit();audit_log('account.'.$purpose,'user',(string)$token['user_id']);
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function le_account_security(array $u): void {
    $message='';$codes=[];
    if($_SERVER['REQUEST_METHOD']==='POST'){
        try{if(!csrf_ok())throw new RuntimeException('Sesja formularza wygasła.');engine_rate('account_security',(string)$u['id'],12);$action=(string)($_POST['action']??'');
            if($action==='verify_email'){le_send_account_token((int)$u['id'],'verify');$message='Jeśli poczta jest skonfigurowana, wiadomość została dodana do kolejki.';}
            else{
                if(!password_verify((string)($_POST['password']??''),$u['password_hash']))throw new RuntimeException('Nieprawidłowe hasło.');
                if($action==='begin'&&!$u['twofa_enabled']){$_SESSION['twofa_setup_secret']=totp_secret();$_SESSION['twofa_setup_until']=time()+600;}
                elseif($action==='enable'&&!$u['twofa_enabled']){
                    $secret=$_SESSION['twofa_setup_secret']??'';if(!$secret||($_SESSION['twofa_setup_until']??0)<time()||!totp_verify($secret,(string)($_POST['code']??'')))throw new RuntimeException('Nieprawidłowy kod lub konfiguracja wygasła.');
                    for($i=0;$i<10;$i++)$codes[]=bin2hex(random_bytes(6));
                    db()->prepare('UPDATE users SET twofa_secret=?,twofa_enabled=1,twofa_last_step=?,twofa_recovery=?,session_version=session_version+1 WHERE id=?')->execute(['v1:'.le_encrypt($secret),intdiv(time(),30),json_encode(array_map(fn($c)=>hash('sha256',$c),$codes)),$u['id']]);unset($_SESSION['twofa_setup_secret'],$_SESSION['twofa_setup_until']);login_user((int)$u['id']);audit_log('security.2fa_enabled','user',(string)$u['id']);$u['twofa_enabled']=1;$message='2FA włączone. Zapisz kody — pokazujemy je tylko raz.';
                }elseif($action==='disable'&&$u['twofa_enabled']){
                    if(!engine_twofa_accept((int)$u['id'],(string)($_POST['code']??'')))throw new RuntimeException('Nieprawidłowy kod.');db()->prepare('UPDATE users SET twofa_secret=NULL,twofa_enabled=0,twofa_recovery=NULL,session_version=session_version+1 WHERE id=?')->execute([$u['id']]);login_user((int)$u['id']);audit_log('security.2fa_disabled','user',(string)$u['id']);$u['twofa_enabled']=0;$message='2FA wyłączone.';
                }elseif($action==='sessions'){db()->prepare('UPDATE users SET session_version=session_version+1 WHERE id=?')->execute([$u['id']]);login_user((int)$u['id']);audit_log('security.sessions_revoked','user',(string)$u['id']);$message='Pozostałe sesje zostały wylogowane.';}
                else throw new RuntimeException('Nieprawidłowa operacja.');
            }
        }catch(Throwable $e){$message=$e->getMessage();}
    }
    $title='Bezpieczeństwo konta';require dirname(__DIR__).'/templates/default/header.php';
    echo '<main class="page container"><section class="card card-pad"><h1>'.e($title).'</h1><p>'.e(le_text($message)).'</p>';
    if($codes)echo '<pre>'.e(implode("\n",$codes)).'</pre>';
    echo '<p>2FA: '.($u['twofa_enabled']?'aktywne':'wyłączone').'</p>';
    $setup=$_SESSION['twofa_setup_secret']??'';
    if(!$u['twofa_enabled']&&$setup!=='')echo '<p>Klucz do aplikacji TOTP: <code>'.e($setup).'</code></p>';
    foreach(($u['twofa_enabled']?['disable'=>'Wyłącz 2FA']:($setup?['enable'=>'Potwierdź 2FA']:['begin'=>'Skonfiguruj 2FA']))+['sessions'=>'Wyloguj inne sesje'] as $action=>$label){
        echo '<form method="post">'.csrf_field().'<input type="hidden" name="action" value="'.$action.'"><label>Hasło<input class="input" type="password" name="password" required autocomplete="current-password"></label>';
        if(in_array($action,['enable','disable'],true))echo ('<label>'.e(le_text('Kod TOTP / odzyskiwania')).'<input class="input" name="code" required></label>');
        echo '<button class="btn">'.e(le_text($label)).'</button></form><hr>';
    }
    echo '<p>E-mail: '.(!empty($u['email_verified_at'])?'potwierdzony':'niepotwierdzony').'</p><form method="post">'.csrf_field().('<input type="hidden" name="action" value="verify_email"><button class="btn">'.e(le_text('Wyślij link potwierdzenia')).'</button></form></section></main>');require dirname(__DIR__).'/templates/default/footer.php';
}
function le_suite_route(): bool {
    $route=trim((string)parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH),'/');
    if($route==='preview'){
        $kind=(string)($_GET['kind']??'');le_content_permission($kind);$row=le_content_row($kind,(int)($_GET['id']??0));if(!$row){http_response_code(404);exit('Brak treści.');}
        header('Cache-Control: no-store');header('X-Robots-Tag: noindex, nofollow');$title=$row['title'];require dirname(__DIR__).'/templates/default/header.php';echo '<main'.($row['content_format']==='blocks'?'':' class="page container"').'><p class="container">'.e(le_text('Podgląd zapisanej treści')).'</p>'.($row['content_format']==='blocks'?'':'<h1>'.e($title).'</h1>').render_content($row['content'],$row['content_format']).'</main>';require dirname(__DIR__).'/templates/default/footer.php';return true;
    }
    if(preg_match('~^(pl|en|de)/(post|page)/([a-z0-9-]+)$~D',$route,$m)){
        $table=le_kind_table($m[2]);$q=db()->prepare("SELECT c.*,t.title translated_title,t.content translated_content,t.seo_description translated_description FROM content_translations t JOIN `$table` c ON c.id=t.object_id WHERE t.kind=? AND t.locale=? AND t.slug=? AND c.deleted_at IS NULL AND c.status='published'");$q->execute([$m[2],$m[1],$m[3]]);$row=$q->fetch();if(!$row){http_response_code(404);exit('Brak tłumaczenia.');}
        $GLOBALS['le_locale']=$m[1];$row['title']=$row['translated_title'];$row['content']=$row['translated_content'];$row['seo_description']=$row['translated_description'];$row['content_format']='html';$GLOBALS[$m[2]]=$row;$title=$row['title'];if($m[2]==='post'){$post=$row;require dirname(__DIR__).'/templates/default/post.php';}else{$page=$row;require dirname(__DIR__).'/templates/default/page.php';}return true;
    }
    if(!str_starts_with($route,'account/'))return false;
    header('Cache-Control: no-store');header('Referrer-Policy: no-referrer');header('X-Robots-Tag: noindex, nofollow');
    if($route==='account/security'){le_account_security(require_login());return true;}
    $message='';$token=(string)($_GET['token']??$_POST['token']??'');$action=substr($route,8);
    if(!in_array($action,['forgot','reset','verify','logout'],true))return false;
    if($_SERVER['REQUEST_METHOD']==='POST'){
        try{if(!csrf_ok())throw new RuntimeException('Sesja formularza wygasła.');engine_rate('account_'.$action,'',12);
            if($action==='logout'){logout_user();redirect('/');}
            if($action==='forgot'){
                $email=strtolower(trim((string)($_POST['email']??'')));engine_rate('reset_email',$email,4);$q=db()->prepare('SELECT id FROM users WHERE email=?');$q->execute([$email]);$id=$q->fetchColumn();
                if($id){try{le_send_account_token((int)$id,'reset');}catch(Throwable $e){levelengine_log('error','Nie można przygotować resetu hasła');}}
                $message='Jeśli konto istnieje i poczta jest skonfigurowana, otrzymasz link resetu.';
            }elseif($action==='reset'){le_consume_token($token,'reset',(string)($_POST['password']??''));logout_user();$message='Hasło zmienione. Możesz się zalogować.';}
            elseif($action==='verify'){le_consume_token($token,'verify');$message='Adres e-mail potwierdzony.';}
        }catch(Throwable $e){$message=$e->getMessage();}
    }
    $title=['forgot'=>'Reset hasła','reset'=>'Nowe hasło','verify'=>'Potwierdzenie e-maila','logout'=>'Wylogowanie'][$action];require dirname(__DIR__).'/templates/default/header.php';
    echo '<main class="page container"><section class="card card-pad"><h1>'.e($title).'</h1><p>'.e(le_text($message)).'</p><form method="post">'.csrf_field().'<input type="hidden" name="token" value="'.e($token).'">';
    if($action==='forgot')echo '<label>E-mail<input class="input" type="email" name="email" required></label>';
    if($action==='reset')echo '<label>Nowe hasło<input class="input" type="password" name="password" minlength="10" maxlength="72" required autocomplete="new-password"></label>';
    echo '<button class="btn primary">'.e($title).'</button></form><p><a href="/login">Logowanie</a></p></section></main>';require dirname(__DIR__).'/templates/default/footer.php';return true;
}
