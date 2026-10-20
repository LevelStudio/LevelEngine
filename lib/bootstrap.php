<?php
declare(strict_types=1);
require_once __DIR__.'/runtime.php';
le_maintenance_gate();
if(PHP_SAPI!=='cli'&&!str_starts_with((string)parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH),'/upgrade/')){
    le_request_lock();le_maintenance_gate();
}

$configFile = __DIR__ . '/../config.php';
if (!is_file($configFile)) { header('Location: /install/'); exit; }
$GLOBALS['lg_config'] = require $configFile;
require_once __DIR__.'/i18n.php';
le_i18n_init(false);
date_default_timezone_set((string)($GLOBALS['lg_config']['timezone'] ?? 'Europe/Berlin'));

function cfg(string $key, mixed $default = null): mixed { return $GLOBALS['lg_config'][$key] ?? $default; }

const LEVELENGINE_CMS_VERSION = '1.0.0';
const LEVELENGINE_BUILD = 2026101601;
const LEVELENGINE_REPOSITORY_URL = 'https://cms.levelgame.pl/repository/index.json';
require_once __DIR__.'/repository.php';
function e(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function redirect(string $path = '/'): never { header('Location: ' . $path); exit; }
function fail_page(string $message): never {
    http_response_code(500);
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>body{margin:0;background:#090a0d;color:#eee;font:16px system-ui;padding:40px}.box{max-width:760px;margin:10vh auto;padding:28px;border:1px solid #292d36;border-radius:20px;background:#111319}h1{margin-top:0;color:#ff8a1f}p{color:#aeb4c0;line-height:1.7}code{color:#fff}</style><div class="box"><h1>LevelEngine</h1><h2>Nie udało się uruchomić strony</h2><p>' . e($message) . '</p><p>Sprawdź dane MySQL w <code>config.php</code> lub przejdź do <code>/install/</code>.</p></div>'; exit;
}
function db(): PDO {
    static $pdo;
    if ($pdo instanceof PDO) return $pdo;
    $c = cfg('db', []);
    if (!extension_loaded('pdo_mysql')) fail_page('Brak rozszerzenia PDO MySQL w PHP. Włącz pdo_mysql na hostingu.');
    $dsn = 'mysql:host=' . ($c['host'] ?? 'localhost') . ';port=' . (int)($c['port'] ?? 3306) . ';dbname=' . ($c['name'] ?? '') . ';charset=utf8mb4';
    if(!empty($c['socket']))$dsn='mysql:host=.;unix_socket='.$c['socket'].';dbname='.($c['name']??'').';charset=utf8mb4';
    try {
        $pdo = new PDO($dsn, (string)($c['user'] ?? ''), (string)($c['pass'] ?? ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } catch (Throwable $e) {
        fail_page('Nie można połączyć się z bazą MySQL/MariaDB. Sprawdź host, nazwę bazy, użytkownika i hasło.');
    }
    $pdo->exec('SET time_zone='.$pdo->quote(date('P')));
    return $pdo;
}
function table_exists(PDO $pdo, string $table): bool {
    $q = $pdo->prepare('SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1');
    $q->execute([$table]); return (bool)$q->fetchColumn();
}
function column_exists(PDO $pdo, string $table, string $column): bool {
    $q = $pdo->prepare('SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1');
    $q->execute([$table, $column]); return (bool)$q->fetchColumn();
}
function add_column_if_missing(PDO $pdo, string $table, string $column, string $definition): void {
    if (!column_exists($pdo, $table, $column)) {
        try { $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}"); } catch (Throwable $e) { /* continue; a later compatibility check can still serve the page */ }
    }
}
function migrate_database(): void {
    if(!le_suite_schema_ready()){
        $path=(string)parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH);
        if(PHP_SAPI==='cli'||str_starts_with($path,'/upgrade'))return;
        http_response_code(503);header('Retry-After: 300');exit('Baza wymaga aktualizacji. Head Admin: otwórz /upgrade/.');
    }
}

require_once __DIR__.'/release.php';
migrate_database();

function levelengine_security_headers(): void {
    if (headers_sent()) return;
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    if ((le_https())) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}
levelengine_security_headers();

function request_id(): string {
    static $id=null;
    if ($id !== null) return $id;
    $incoming=(string)($_SERVER['HTTP_X_REQUEST_ID'] ?? '');
    $id=preg_match('/^[A-Za-z0-9._-]{8,80}$/',$incoming) ? $incoming : bin2hex(random_bytes(8));
    if (!headers_sent()) header('X-Request-ID: '.$id);
    return $id;
}
function levelengine_log(string $level,string $message,array $context=[]): void {
    $dir=__DIR__.'/../storage/logs';
    if(!is_dir($dir)) @mkdir($dir,0750,true);
    if(!is_dir($dir) || !is_writable($dir)) return;
    foreach($context as $k=>$v){ if(preg_match('/pass|password|secret|token|csrf|cookie/i',(string)$k)) $context[$k]='[redacted]'; }
    $row=['time'=>date('c'),'level'=>strtoupper($level),'request_id'=>request_id(),'message'=>$message,'context'=>$context];
    @file_put_contents($dir.'/levelengine-'.date('Y-m-d').'.log',json_encode($row,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL,FILE_APPEND|LOCK_EX);
}
function audit_log(string $action,string $targetType='',?string $targetId=null,array $details=[]): void {
    try {
        $u=current_user();
        $ip=(string)($_SERVER['REMOTE_ADDR'] ?? '');
        $ua=mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''),0,500);
        $q=db()->prepare('INSERT INTO audit_log(user_id,username,action,target_type,target_id,details_json,ip_address,user_agent,request_id) VALUES(?,?,?,?,?,?,?,?,?)');
        $q->execute([$u['id']??null,$u['username']??'System',$action,$targetType?:null,$targetId,$details?json_encode($details,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):null,$ip?:null,$ua?:null,request_id()]);
    } catch(Throwable $e) { levelengine_log('warning','Nie udało się zapisać Audit Log',['error'=>$e->getMessage(),'action'=>$action]); }
}

function start_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $https = le_https();
    ini_set('session.use_strict_mode','1');
    ini_set('session.use_only_cookies','1');
    session_name('levelengine');
    session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>$https,'httponly'=>true,'samesite'=>'Lax']);
    session_start();
}
function csrf(): string { start_session(); return $_SESSION['csrf'] ??= bin2hex(random_bytes(32)); }
function csrf_field(): string { return '<input type="hidden" name="csrf" value="'.e(csrf()).'">'; }
function csrf_ok(): bool { start_session(); return isset($_POST['csrf'],$_SESSION['csrf']) && hash_equals((string)$_SESSION['csrf'],(string)$_POST['csrf']); }
function flash(?string $message = null): ?string { start_session(); if ($message !== null) { $_SESSION['flash']=$message; return null; } $v=$_SESSION['flash']??null; unset($_SESSION['flash']); return $v; }
function setting(string $key, string $default=''): string {
    $pdo=db();
    if($pdo->inTransaction()){$q=$pdo->prepare('SELECT setting_value FROM settings WHERE setting_key=?');$q->execute([$key]);$v=$q->fetchColumn();return $v===false?$default:(string)$v;}
    $GLOBALS['le_settings_cache']??=$pdo->query('SELECT setting_key,setting_value FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
    return (string)($GLOBALS['le_settings_cache'][$key]??$default);
}
function set_setting(string $key,string $value): void { unset($GLOBALS['le_settings_cache']);db()->prepare('INSERT INTO settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)')->execute([$key,$value]); }
function valid_hex(string $color,string $fallback='#3b82f6'): string { return preg_match('/^#[0-9a-fA-F]{6}$/',$color)?strtolower($color):$fallback; }
function active_theme(): array { $key=preg_replace('/[^a-zA-Z0-9_-]/','',setting('active_theme','default')); $q=db()->prepare('SELECT * FROM themes WHERE theme_key=? LIMIT 1'); $q->execute([$key]); $t=$q->fetch(); if(!$t){$q=db()->query("SELECT * FROM themes WHERE theme_key='default' LIMIT 1");$t=$q->fetch();} return $t?:['theme_key'=>'default','path'=>'themes/default','name'=>'LevelEngine Default']; }
function theme_css_url(): string { $t=active_theme(); $path=(string)($t['path']??'themes/default'); $file=__DIR__.'/../'.trim($path,'/').'/style.css'; return is_file($file)?'/'.trim($path,'/').'/style.css':'/assets/css/app.css'; }

function slugify(string $s): string { $s=strtr($s,['ą'=>'a','ć'=>'c','ę'=>'e','ł'=>'l','ń'=>'n','ó'=>'o','ś'=>'s','ź'=>'z','ż'=>'z','Ą'=>'A','Ć'=>'C','Ę'=>'E','Ł'=>'L','Ń'=>'N','Ó'=>'O','Ś'=>'S','Ź'=>'Z','Ż'=>'Z']);$s=iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$s) ?: $s; $s=(string)preg_replace('/[^a-z0-9]+/','-',strtolower($s)); return trim($s,'-') ?: 'wpis'; }
function unique_slug(string $title,int $ignore=0,string $table='posts'): string { if(!in_array($table,['posts','pages'],true))throw new InvalidArgumentException('Nieprawidłowa tabela treści.');$base=slugify($title);$slug=$base;$i=2;while(true){$q=db()->prepare("SELECT id FROM `{$table}` WHERE slug=? AND id<>?");$q->execute([$slug,$ignore]);if(!$q->fetch())return $slug;$slug=$base.'-'.$i++;} }
function post_categories(): array { return ['news'=>le_text('News'),'contest'=>le_text('Konkursy'),'event'=>le_text('Wydarzenia'),'changelog'=>le_text('Changelog'),'notice'=>le_text('Ogłoszenia')]; }
function category_label(string $c): string { return post_categories()[$c] ?? ucfirst($c); }
function excerpt(string $html,int $len=180): string { $t=trim((string)preg_replace('/\s+/u',' ',strip_tags($html))); return mb_strlen($t)>$len?mb_substr($t,0,$len).'…':$t; }
function upload_image(array $file,string $folder='posts',int $max=5242880): array {
    if(!in_array($folder,['posts','avatars','gallery','media'],true))throw new InvalidArgumentException('Nieprawidłowy katalog obrazów.');
    if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK) throw new RuntimeException(le_text('Nie udało się przesłać pliku.'));
    if(($file['size']??0)>$max) throw new RuntimeException(le_text('Plik jest za duży. Maksymalnie 5 MB.'));
    if(!is_uploaded_file($file['tmp_name'])) throw new RuntimeException(le_text('Nieprawidłowy upload.'));
    $info=@getimagesize($file['tmp_name']);
    $types=[IMAGETYPE_JPEG=>'image/jpeg',IMAGETYPE_PNG=>'image/png',IMAGETYPE_WEBP=>'image/webp',IMAGETYPE_GIF=>'image/gif'];
    if(!$info || !isset($types[$info[2]])) throw new RuntimeException(le_text('Dozwolone formaty: JPG, PNG, WEBP, GIF.'));
    if($info[0]>10000||$info[1]>10000||$info[0]*$info[1]>25000000)throw new RuntimeException('Obraz przekracza limit 25 mln pikseli.');
    $uploadRoot=__DIR__.'/../uploads';if(!is_dir($uploadRoot)&&!mkdir($uploadRoot,0755,true))throw new RuntimeException('Nie można utworzyć katalogu uploadów.');
    $usage=0;foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($uploadRoot,FilesystemIterator::SKIP_DOTS)) as $f)if($f->isFile())$usage+=$f->getSize();if($usage+filesize($file['tmp_name'])>(int)cfg('upload_quota_bytes',1073741824))throw new RuntimeException('Przekroczono limit miejsca na obrazy.');
    if(extension_loaded('gd')){
        $limit=trim((string)ini_get('memory_limit'));$bytes=(int)$limit;
        if($bytes>0){$factor=match(strtolower(substr($limit,-1))){'g'=>1073741824,'m'=>1048576,'k'=>1024,default=>1};$bytes*=$factor;if($info[0]*$info[1]*5+16777216>$bytes-memory_get_usage(true))throw new RuntimeException(le_text('Obraz jest zbyt duży dla pamięci hostingu. Zmniejsz jego wymiary.'));}
        $decoded=match($info[2]){IMAGETYPE_JPEG=>@imagecreatefromjpeg($file['tmp_name']),IMAGETYPE_PNG=>@imagecreatefrompng($file['tmp_name']),IMAGETYPE_WEBP=>function_exists('imagecreatefromwebp')?@imagecreatefromwebp($file['tmp_name']):false,IMAGETYPE_GIF=>@imagecreatefromgif($file['tmp_name']),default=>false};
        if(!$decoded)throw new RuntimeException(le_text('Nie można odczytać obrazu. Plik jest uszkodzony lub ma nieobsługiwany format.'));imagedestroy($decoded);
    }
    $ext=[IMAGETYPE_JPEG=>'jpg',IMAGETYPE_PNG=>'png',IMAGETYPE_WEBP=>'webp',IMAGETYPE_GIF=>'gif'][$info[2]];
    $dir=__DIR__.'/../uploads/'.$folder;if(!is_dir($dir)&&!mkdir($dir,0755,true))throw new RuntimeException(le_text('Nie można utworzyć katalogu uploadów.'));
    $name=bin2hex(random_bytes(16)).'.'.$ext;$full=$dir.'/'.$name;if(!move_uploaded_file($file['tmp_name'],$full))throw new RuntimeException(le_text('Nie udało się zapisać obrazka.'));
    return ['path'=>'/uploads/'.$folder.'/'.$name,'mime'=>$types[$info[2]],'size'=>(int)$file['size']];
}
function current_user(): ?array {
    start_session();static $loaded=false,$user=null,$cachedKey='';$key=session_id().':'.(int)($_SESSION['user_id']??0).':'.(int)($_SESSION['session_version']??0);
    if($loaded&&$cachedKey===$key)return $user;$loaded=true;$cachedKey=$key;$user=null;if(empty($_SESSION['user_id']))return null;
    $q=db()->prepare('SELECT u.*,r.name role_name,r.color role_color,r.badge role_badge,r.can_access_admin,r.permissions,r.is_system FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=?');$q->execute([(int)$_SESSION['user_id']]);$user=$q->fetch()?:null;if($user&&(($user['status']??'')!=='active'||(int)($_SESSION['session_version']??1)!==(int)($user['session_version']??1))){$_SESSION=[];$user=null;}return $user;
}
function login_user(int $id): void { start_session();session_regenerate_id(true);$_SESSION['csrf']=bin2hex(random_bytes(32));$_SESSION['user_id']=$id;$q=db()->prepare('SELECT session_version FROM users WHERE id=?');$q->execute([$id]);$_SESSION['session_version']=(int)($q->fetchColumn()?:1);unset($_SESSION['twofa_pending'],$_SESSION['twofa_pending_until']); }
function logout_user(): void { start_session();$_SESSION=[];if(ini_get('session.use_cookies')){$p=session_get_cookie_params();setcookie(session_name(),'',time()-42000,$p['path'],$p['domain']??'',$p['secure'],$p['httponly']);}session_destroy(); }
function require_login(): array { $u=current_user();if(!$u)redirect('/login');if(($u['status']??'active')==='banned'){logout_user();http_response_code(403);exit(le_text('Konto zostało zablokowane.'));}return $u; }
function role_permissions(array $role): array { $p=json_decode((string)($role['permissions']??'{}'),true);return is_array($p)?$p:[]; }
function has_permission(string $permission,?array $user=null): bool { $u=$user?:current_user();if(!$u)return false;if((int)$u['role_id']===1)return true;return !empty(role_permissions($u)[$permission]); }
function require_admin(): array { $u=require_login();if(empty($u['can_access_admin'])){http_response_code(403);exit(le_text('Brak dostępu do panelu administracyjnego.'));}return $u; }
function require_permission(string $permission): array { $u=require_admin();if(!has_permission($permission,$u)){http_response_code(403);exit(le_text('Brak uprawnień.'));}return $u; }
function admin_url(string $tab='dashboard',array $extra=[]): string { return '/admin/?'.http_build_query(array_merge(['tab'=>$tab],$extra)); }

/* LevelEngine 1.2 utilities */
function base32_decode_le(string $s): string { $a='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';$s=strtoupper(preg_replace('/[^A-Z2-7]/i','',$s));$bits='';foreach(str_split($s) as $c){$p=strpos($a,$c);if($p===false)continue;$bits.=str_pad(decbin($p),5,'0',STR_PAD_LEFT);} $out='';for($i=0;$i+8<=strlen($bits);$i+=8)$out.=chr(bindec(substr($bits,$i,8)));return $out; }
function base32_encode_le(string $data): string { $a='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';$bits='';foreach(str_split($data) as $c)$bits.=str_pad(decbin(ord($c)),8,'0',STR_PAD_LEFT);$out='';for($i=0;$i<strlen($bits);$i+=5){$chunk=substr($bits,$i,5);$chunk=str_pad($chunk,5,'0');$out.=$a[bindec($chunk)];}return $out; }
function totp_secret(): string { return base32_encode_le(random_bytes(20)); }
function totp_code(string $secret,?int $time=null): string { if(str_starts_with($secret,'v1:'))$secret=le_decrypt(substr($secret,3)); $counter=intdiv($time??time(),30);$bin=pack('N*',0).pack('N*',$counter);$hash=hash_hmac('sha1',$bin,base32_decode_le($secret),true);$off=ord($hash[19])&15;$n=((ord($hash[$off])&127)<<24)|(ord($hash[$off+1])<<16)|(ord($hash[$off+2])<<8)|ord($hash[$off+3]);return str_pad((string)($n%1000000),6,'0',STR_PAD_LEFT); }
function totp_verify(string $secret,string $code): bool { $code=preg_replace('/\D/','',$code);if(strlen($code)!==6)return false;for($i=-1;$i<=1;$i++)if(hash_equals(totp_code($secret,time()+$i*30),$code))return true;return false; }
function markdown_to_html(string $md): string { $md=str_replace(["\r\n","\r"],"\n",$md);$out=[];$inList=false;foreach(explode("\n",$md) as $line){$line=e($line);if(preg_match('/^(#{1,6})\s+(.+)$/',$line,$m)){if($inList){$out[]='</ul>';$inList=false;}$n=strlen($m[1]);$out[]="<h$n>".$m[2]."</h$n>";continue;}if(preg_match('/^[-*]\s+(.+)$/',$line,$m)){if(!$inList){$out[]='<ul>';$inList=true;}$out[]='<li>'.$m[1].'</li>';continue;}if($inList){$out[]='</ul>';$inList=false;}if(trim($line)===''){$out[]='';continue;}$line=preg_replace_callback('~!\[([^\]\r\n]*)\]\((/uploads/(?:media|posts|gallery|avatars)/[a-zA-Z0-9._-]+\.(?:jpg|jpeg|png|gif|webp))\)~i',fn($m)=>'<img src="'.e($m[2]).'" alt="'.e(html_entity_decode($m[1],ENT_QUOTES|ENT_HTML5,'UTF-8')).'">',$line);$line=preg_replace('/\*\*(.+?)\*\*/','<strong>$1</strong>',$line);$line=preg_replace('/\*(.+?)\*/','<em>$1</em>',$line);$line=preg_replace('/`([^`]+)`/','<code>$1</code>',$line);$line=preg_replace_callback('/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/',fn($m)=>'<a href="'.e(html_entity_decode($m[2],ENT_QUOTES|ENT_HTML5,'UTF-8')).'" rel="noopener noreferrer">'.$m[1].'</a>',$line);$out[]='<p>'.$line.'</p>';}if($inList)$out[]='</ul>';return implode("\n",$out); }
function render_content(string $content,string $format='html'): string { if($format==='blocks')return le_studio_render($content);return safe_content($format==='markdown'?markdown_to_html($content):$content); }
function diagnostics(): array { $root=dirname(__DIR__);return [
 ['PHP >= 8.1',version_compare(PHP_VERSION,'8.1','>='),PHP_VERSION],['PDO MySQL',extension_loaded('pdo_mysql'),'pdo_mysql'],['GD',extension_loaded('gd'),'miniatury'],['mbstring',extension_loaded('mbstring'),'teksty'],['DOM',class_exists('DOMDocument'),'HTML'],['cURL',extension_loaded('curl'),'integracje'],['ZipArchive',class_exists('ZipArchive'),'ZIP / backupy / instalatory'],['OpenSSL',extension_loaded('openssl'),'2FA i kryptografia'],['uploads/ zapisywalny',is_writable($root.'/uploads'),$root.'/uploads'],['upgrade/ zapisywalny',is_writable($root.'/upgrade'),$root.'/upgrade'],['HTTPS',le_https(),'Zalecane dla panelu i 2FA']]; }

require_once __DIR__.'/common.php';
require_once __DIR__.'/media.php';
require_once __DIR__.'/editor.php';
require_once __DIR__.'/menu.php';
require_once __DIR__.'/levelbridge.php';
require_once __DIR__.'/suite.php';
require_once __DIR__.'/packages.php';
le_i18n_site_default();
if(!is_file(__DIR__.'/../storage/safe-mode')&&!str_starts_with((string)parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH),'/upgrade'))le_plugins_boot();
