<?php
declare(strict_types=1);
require __DIR__.'/lib/bootstrap.php';
start_session();
if(le_suite_route())exit;
$route=trim((string)parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH),'/');
if($route===''||$route==='index.php'){
    if($page=le_studio_home()){$title=$page['title'];require __DIR__.'/templates/default/header.php';echo '<main>'.render_content($page['content'],$page['content_format']).'</main>';require __DIR__.'/templates/default/footer.php';exit;}
    $posts=db()->query("SELECT p.*,u.username FROM posts p LEFT JOIN users u ON u.id=p.author_id WHERE p.deleted_at IS NULL AND p.status='published' ORDER BY p.featured DESC,p.created_at DESC LIMIT 6")->fetchAll();
    $gallery=db()->query('SELECT * FROM gallery ORDER BY created_at DESC,id DESC LIMIT 8')->fetchAll();
    require __DIR__.'/templates/default/home.php'; exit;
}
if($route==='robots.txt'){header('Content-Type: text/plain; charset=utf-8');echo "User-agent: *\nAllow: /\nDisallow: /admin/\nDisallow: /upgrade/\nSitemap: /sitemap.xml\n";exit;}
if($route==='sitemap.xml'){header('Content-Type: application/xml; charset=utf-8');$base=le_origin();$urls=[$base.'/'];foreach(db()->query("SELECT slug,updated_at FROM posts WHERE deleted_at IS NULL AND status='published'") as $r)$urls[]=$base.'/post/'.rawurlencode($r['slug']);foreach(db()->query("SELECT slug,updated_at FROM pages WHERE deleted_at IS NULL AND status='published'") as $r)$urls[]=$base.'/page/'.rawurlencode($r['slug']);echo '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';foreach($urls as $u)echo '<url><loc>'.htmlspecialchars($u,ENT_XML1).'</loc></url>';echo '</urlset>';exit;}
if($route==='login'){
    $error='';
    if($_SERVER['REQUEST_METHOD']==='POST'){
        if(!csrf_ok()){$error=le_text('Sesja formularza wygasła. Odśwież stronę.');}else{
            $identity=trim((string)($_POST['identity']??''));try{engine_rate('login',strtolower($identity));}catch(RuntimeException $e){$error=$e->getMessage();require __DIR__.'/templates/default/login.php';exit;}$password=(string)($_POST['password']??'');
            $q=db()->prepare('SELECT * FROM users WHERE username=? OR email=? LIMIT 1');$q->execute([$identity,$identity]);$u=$q->fetch();
            if(!$u||!password_verify($password,(string)$u['password_hash'])){$error=le_text('Nieprawidłowy login/e-mail lub hasło.');}elseif(($u['status']??'active')==='banned'){$error=le_text('To konto jest zablokowane.');}elseif(!empty($u['twofa_enabled'])){session_regenerate_id(true);$_SESSION['twofa_pending']=(int)$u['id'];$_SESSION['twofa_pending_until']=time()+300;redirect('/2fa');}else{engine_rate_clear('login',strtolower($identity));login_user((int)$u['id']);redirect('/profile');}
        }
    }
    require __DIR__.'/templates/default/login.php'; exit;
}
if($route==='2fa'){
    $error='';$uid=(int)($_SESSION['twofa_pending']??0);if(!$uid||($_SESSION['twofa_pending_until']??0)<time()){unset($_SESSION['twofa_pending']);redirect('/login');}
    $q=db()->prepare('SELECT id,username,email,twofa_secret,twofa_enabled,status FROM users WHERE id=?');$q->execute([$uid]);$u=$q->fetch();if(!$u||empty($u['twofa_enabled'])||$u['status']!=='active'){unset($_SESSION['twofa_pending']);redirect('/login');}
    if($_SERVER['REQUEST_METHOD']==='POST'){try{engine_rate('2fa',(string)$uid,8);}catch(RuntimeException $e){$error=$e->getMessage();require __DIR__.'/templates/default/2fa.php';exit;}if(!csrf_ok())$error=le_text('Sesja formularza wygasła.');elseif(engine_twofa_accept($uid,(string)($_POST['code']??''))){unset($_SESSION['twofa_pending'],$_SESSION['twofa_pending_until']);engine_rate_clear('2fa',(string)$uid);engine_rate_clear('login',strtolower($u['username']));engine_rate_clear('login',strtolower($u['email']));login_user($uid);audit_log('auth.2fa_login','user',(string)$uid);redirect('/profile');}else{$error=le_text('Nieprawidłowy kod 2FA.');}}
    require __DIR__.'/templates/default/2fa.php';exit;
}
if($route==='register'){
    $error='';
    if($_SERVER['REQUEST_METHOD']==='POST')try{
        if(!csrf_ok())throw new RuntimeException(le_text('Sesja formularza wygasła. Odśwież stronę.'));
        $username=trim((string)($_POST['username']??''));$email=strtolower(trim((string)($_POST['email']??'')));$p1=(string)($_POST['password']??'');$p2=(string)($_POST['password2']??'');
        if(!preg_match('/^[A-Za-z0-9_]{3,50}$/D',$username))throw new RuntimeException(le_text('Login: 3–50 znaków, litery/cyfry/_.'));
        if(!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($email)>190)throw new RuntimeException(le_text('Podaj poprawny e-mail.'));
        le_password_check($p1);if($p1!==$p2)throw new RuntimeException(le_text('Hasła nie są takie same.'));
        engine_rate('register','',5);$q=db()->prepare('SELECT id FROM users WHERE username=? OR email=? LIMIT 1');$q->execute([$username,$email]);
        if($q->fetch())throw new RuntimeException(le_text('Login lub e-mail jest już zajęty.'));
        try{db()->prepare('INSERT INTO users(username,email,password_hash,role_id,avatar,status) VALUES(?,?,?,?,?,?)')->execute([$username,$email,password_hash($p1,PASSWORD_DEFAULT),4,'default.png','active']);}
        catch(PDOException $e){if($e->getCode()==='23000')throw new RuntimeException(le_text('Login lub e-mail jest już zajęty.'));throw $e;}
        $newUser=(int)db()->lastInsertId();login_user($newUser);
        try{le_send_account_token($newUser,'verify');}catch(Throwable $deliveryError){levelengine_log('error','Nie można przygotować potwierdzenia e-maila');}
        audit_log('auth.register','user',(string)$newUser);redirect('/profile');
    }catch(Throwable $e){$error=$e instanceof PDOException?le_text('Nie udało się utworzyć konta. Spróbuj ponownie.'):$e->getMessage();}
    require __DIR__.'/templates/default/register.php';exit;
}
if($route==='logout'){if(($_SERVER['REQUEST_METHOD']??'')==='POST'&&csrf_ok()){logout_user();redirect('/');}http_response_code(405);exit('Wylogowanie wymaga POST.');}
if($route==='profile'){
    $user=require_login();$flash=flash();if($_SERVER['REQUEST_METHOD']==='POST'&&!csrf_ok())$flash=le_text('Sesja formularza wygasła. Odśwież stronę.');
    if($_SERVER['REQUEST_METHOD']==='POST'&&csrf_ok()&&($_POST['action']??'')==='profile_update'){
        try{
            $sets=[];$vals=[];
            $displayName=trim((string)($_POST['display_name']??''));
            $bio=trim((string)($_POST['bio']??''));
            $signature=trim((string)($_POST['signature']??''));
            $location=trim((string)($_POST['location']??''));
            $website=trim((string)($_POST['website']??''));
            if(mb_strlen($displayName)>80)throw new RuntimeException(le_text('Nazwa wyświetlana może mieć maksymalnie 80 znaków.'));
            if(mb_strlen($bio)>1000)throw new RuntimeException(le_text('Opis „O mnie” może mieć maksymalnie 1000 znaków.'));
            if(mb_strlen($signature)>500)throw new RuntimeException(le_text('Podpis może mieć maksymalnie 500 znaków.'));
            if(mb_strlen($location)>100)throw new RuntimeException(le_text('Lokalizacja może mieć maksymalnie 100 znaków.'));
            if(mb_strlen($website)>255)throw new RuntimeException(le_text('Adres strony jest za długi.'));
            if($website!==''&&(!preg_match('~^https?://~i',$website)||le_url($website,'')===''))throw new RuntimeException(le_text('Podaj poprawny adres strony, np. https://example.com.'));
            foreach(['display_name'=>$displayName,'bio'=>$bio,'signature'=>$signature,'location'=>$location,'website'=>$website] as $column=>$value){$sets[]=$column.'=?';$vals[]=$value!==''?$value:null;}
            $p=(string)($_POST['password']??'');if($p!==''){if(!password_verify((string)($_POST['current_password']??''),(string)$user['password_hash']))throw new RuntimeException(le_text('Potwierdź zmianę obecnym hasłem.'));le_password_check($p);$sets[]='password_hash=?';$vals[]=password_hash($p,PASSWORD_DEFAULT);$sets[]='session_version=session_version+1';}
            if(isset($_FILES['avatar'])&&($_FILES['avatar']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE){$up=upload_image($_FILES['avatar'],'avatars',3145728);$sets[]='avatar=?';$vals[]=$up['path'];}
            if($sets){$vals[]=$user['id'];db()->prepare('UPDATE users SET '.implode(',',$sets).' WHERE id=?')->execute($vals);}if($p!=='')login_user((int)$user['id']);audit_log('account.updated','user',(string)$user['id']);flash(le_text('Profil został zapisany.'));redirect('/profile');
        }catch(Throwable $e){$flash=$e->getMessage();}
    }
    require __DIR__.'/templates/default/profile.php'; exit;
}
if(preg_match('~^plugin/([a-zA-Z0-9_-]{1,80})$~',$route,$pluginMatch)){
    $q=db()->prepare('SELECT * FROM plugins WHERE plugin_key=? AND enabled=1');$q->execute([$pluginMatch[1]]);$plugin=$q->fetch();$file=$plugin?le_menu_public_file($plugin):null;
    if(!$file){http_response_code(404);exit(le_text('Nie znaleziono pluginu.'));}
    ob_start();try{require $file;$pluginContent=ob_get_clean();}catch(Throwable $error){ob_end_clean();throw $error;}
    $title=(string)$plugin['name'];require __DIR__.'/templates/default/header.php';echo '<main class="page container">'.$pluginContent.'</main>';require __DIR__.'/templates/default/footer.php';exit;
}
if($route==='galeria'||$route==='gallery'){
    $total=(int)db()->query('SELECT COUNT(*) FROM gallery')->fetchColumn();$galleryPages=max(1,(int)ceil($total/24));
    $galleryPage=min($galleryPages,max(1,(int)($_GET['page']??1)));$offset=($galleryPage-1)*24;
    $gallery=db()->query('SELECT * FROM gallery ORDER BY created_at DESC,id DESC LIMIT 24 OFFSET '.$offset)->fetchAll();
    require __DIR__.'/templates/default/gallery.php';exit;
}
$catRoutes=['news'=>'news','konkursy'=>'contest','wydarzenia'=>'event','changelog'=>'changelog','ogloszenia'=>'notice'];
if(isset($catRoutes[$route])){$category=$catRoutes[$route];$q=db()->prepare("SELECT p.*,u.username FROM posts p LEFT JOIN users u ON u.id=p.author_id WHERE p.deleted_at IS NULL AND p.status='published' AND p.category=? ORDER BY p.featured DESC,p.created_at DESC");$q->execute([$category]);$posts=$q->fetchAll();$categoryName=category_label($category);require __DIR__.'/templates/default/category.php';exit;}
if(preg_match('~^post/([a-z0-9-]+)$~',$route,$m)){$q=db()->prepare("SELECT p.*,u.username FROM posts p LEFT JOIN users u ON u.id=p.author_id WHERE p.deleted_at IS NULL AND p.slug=? AND p.status='published' LIMIT 1");$q->execute([$m[1]]);$post=$q->fetch();if(!$post){http_response_code(404);exit(le_text('Nie znaleziono wpisu.'));}require __DIR__.'/templates/default/post.php';exit;}
if(preg_match('~^page/([a-z0-9-]+)$~',$route,$m)){$q=db()->prepare("SELECT * FROM pages WHERE deleted_at IS NULL AND status='published' AND slug=? LIMIT 1");$q->execute([$m[1]]);$page=$q->fetch();if(!$page){http_response_code(404);exit(le_text('Nie znaleziono strony.'));}require __DIR__.'/templates/default/page.php';exit;}
http_response_code(404);$title='404';require __DIR__.'/templates/default/header.php';echo '<main class="page" style="text-align:center"><h1>404</h1><p class="muted">'.e(le_text('Tej strony nie ma.')).'</p><a class="btn primary" href="/">'.e(le_text('Wróć na stronę')).'</a></main>';require __DIR__.'/templates/default/footer.php';
