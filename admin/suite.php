<?php
declare(strict_types=1);
require __DIR__.'/../lib/bootstrap.php';
$admin=require_admin();header('Cache-Control: no-store');header('X-Robots-Tag: noindex, nofollow');
$section=(string)($_GET['section']??'overview');$sections=['overview','history','trash','integrations','queue','servers','menu','health','backups'];if(!in_array($section,$sections,true))$section='overview';
$kind=(string)($_GET['kind']??'post');$id=(int)($_GET['id']??0);$message='';
if($section==='history')le_content_permission($kind);
if(in_array($section,['integrations','queue','health','backups'],true))le_head();
if($section==='servers')require_permission('manage_servers');
if($section==='menu')require_permission('manage_settings');
if($_SERVER['REQUEST_METHOD']==='POST')try{
    if(!csrf_ok())throw new RuntimeException('Sesja formularza wygasła.');$action=(string)($_POST['action']??'');
    if($action==='revision')le_content_restore((string)$_POST['kind'],(int)$_POST['id'],(int)$_POST['revision'],(int)$_POST['edit_version']);
    elseif($action==='untrash')le_content_trash((string)$_POST['kind'],(int)$_POST['id'],true);
    elseif($action==='translation'){
        $k=(string)$_POST['kind'];le_content_permission($k);$oid=(int)$_POST['id'];$locale=le_valid_locale($_POST['locale']??null);$title=trim((string)$_POST['title']);$content=(string)$_POST['content'];
        if(!$locale||$title===''||mb_strlen($title)>255||strlen($content)>2000000||!le_content_row($k,$oid))throw new RuntimeException('Nieprawidłowe tłumaczenie.');
        le_translation_save($k,$oid,$locale,$_POST);
    }elseif($action==='integrations'){
        le_head();if(!password_verify((string)($_POST['password']??''),$admin['password_hash']))throw new RuntimeException('Potwierdź hasłem.');le_vault_init();
        $url=rtrim(trim((string)($_POST['public_url']??'')),'/');$parts=parse_url($url);
        if(!$parts||!filter_var($url,FILTER_VALIDATE_URL)||isset($parts['user'])||isset($parts['pass'])||isset($parts['query'])||isset($parts['fragment'])||!in_array($parts['scheme']??'',['https','http'],true)||(!($parts['scheme']==='https')&&!in_array($parts['host']??'',['localhost','127.0.0.1'],true)))throw new RuntimeException('Publiczny adres musi używać HTTPS (HTTP tylko localhost).');
        $host=trim((string)($_POST['smtp_host']??''));$from=trim((string)($_POST['smtp_from']??''));$mode=(string)($_POST['smtp_mode']??'starttls');$port=(int)($_POST['smtp_port']??587);
        if($host!==''&&(!preg_match('/^[a-zA-Z0-9.-]+$/D',$host)||!filter_var($from,FILTER_VALIDATE_EMAIL)||$port<1||$port>65535||!in_array($mode,['starttls','tls'],true)))throw new RuntimeException('Sprawdź konfigurację SMTP.');
        $webhook=trim((string)($_POST['discord_webhook']??''));if($webhook!==''&&!preg_match('~^https://discord\.com/api/webhooks/[0-9]+/[A-Za-z0-9_-]+$~D',$webhook))throw new RuntimeException('Nieprawidłowy webhook.');
        foreach(['public_url'=>$url,'smtp_host'=>$host,'smtp_port'=>(string)$port,'smtp_mode'=>$mode,'smtp_from'=>$from,'smtp_user'=>trim((string)($_POST['smtp_user']??''))] as $k=>$v)set_setting($k,$v);
        if((string)($_POST['smtp_password']??'')!=='')set_setting('smtp_password',le_encrypt((string)$_POST['smtp_password']));
        if($webhook!=='')set_setting('discord_webhook',le_encrypt($webhook));
        if(!empty($_POST['clear_discord']))set_setting('discord_webhook','');
        audit_log('integrations.updated','system');
    }elseif($action==='test_mail'){
        le_head();if(!password_verify((string)($_POST['password']??''),$admin['password_hash']))throw new RuntimeException('Potwierdź hasłem.');le_enqueue('smtp-test:'.bin2hex(random_bytes(12)),'mail',['to'=>$admin['email'],'subject'=>'Test LevelEngine CMS','body'=>'Konfiguracja poczty działa.']);
    }elseif($action==='worker'){le_head();$message=json_encode(le_worker(),JSON_UNESCAPED_UNICODE);}
    elseif($action==='retry_delivery'){
        le_head();$retry=(int)($_POST['delivery_id']??0);db()->prepare("UPDATE delivery_queue SET state='pending',available_at=NOW(),last_error=NULL WHERE id=? AND state='uncertain'")->execute([$retry]);audit_log('queue.manual_retry','delivery',(string)$retry);
    }elseif($action==='menu_translations'){
        require_permission('manage_settings');$data=[];$allowed=array_column(le_menu_config(),'id');
        foreach($_POST['labels']??[] as $mid=>$labels){if(!in_array($mid,$allowed,true)||!is_array($labels))continue;foreach($labels as $locale=>$label)if(le_valid_locale($locale)&&is_string($label)&&trim($label)!==''){if(mb_strlen($label)>80)throw new RuntimeException('Etykieta menu jest za długa.');$data[$mid][$locale]=trim($label);}}
        set_setting('menu_translations',json_encode($data,JSON_UNESCAPED_UNICODE));audit_log('menu.translations_updated','menu');
    }elseif($action==='review_moderator'){
        le_head();$q=db()->query('SELECT permissions FROM roles WHERE id=3');$p=json_decode((string)$q->fetchColumn(),true)?:[];$p['manage_servers']=false;$p['manage_themes']=false;db()->prepare('UPDATE roles SET permissions=? WHERE id=3')->execute([json_encode($p)]);set_setting('role_review_required','0');audit_log('roles.moderator_permissions_reviewed','role','3');
    }elseif($action==='backup_restore'){
        le_head();engine_rate('backup_restore',(string)$admin['id'],6);
        if(!password_verify((string)($_POST['password']??''),$admin['password_hash']))throw new RuntimeException('Potwierdź hasłem.');
        $q=db()->prepare('SELECT filename FROM backups WHERE id=?');$q->execute([(int)($_POST['backup_id']??0)]);$name=$q->fetchColumn();
        if(!$name||!preg_match('/^levelengine-[A-Za-z0-9-]+\.sql$/D',$name))throw new RuntimeException('Brak kopii.');
        $file=__DIR__.'/../backups/'.$name;$backupMeta=le_backup_validate($file);if(($backupMeta['cms_version']??'')!==LEVELENGINE_CMS_VERSION)throw new RuntimeException('Kopia bazy pochodzi z innej wersji rdzenia. Użyj pełnego rollbacku.');
        le_request_lock(true);le_atomic_write(__DIR__.'/../storage/maintenance.json',json_encode(['since'=>date('c'),'reason'=>'restore_database']));
        try{
            $rescue=engine_backup_create();le_backup_restore(db(),$file);
            db()->exec('UPDATE users SET session_version=session_version+1');
            unlink(__DIR__.'/../storage/maintenance.json');audit_log('backup.restored','backup',$name,['rescue'=>$rescue]);logout_user();redirect('/login');
        }catch(Throwable $restoreError){throw new RuntimeException('Przywracanie przerwane. Konserwacja pozostaje aktywna. Kopia ratunkowa: '.($rescue??'brak').'. '.$restoreError->getMessage());}
    }elseif($action==='safe_mode'){
        le_head();$file=__DIR__.'/../storage/safe-mode';if(!empty($_POST['enabled']))le_atomic_write($file,'1');elseif(is_file($file))unlink($file);audit_log('system.safe_mode','system',null,['enabled'=>!empty($_POST['enabled'])]);
    }else throw new RuntimeException('Nieprawidłowa operacja.');
    if($message==='')$message='Zapisano.';
}catch(Throwable $e){$message=$e->getMessage();}
$title='Centrum LevelEngine';require __DIR__.'/../templates/default/header.php';
echo '<main class="page container"><p><a href="/admin/">← Panel</a></p><h1>'.e($title).'</h1><nav style="display:flex;gap:8px;flex-wrap:wrap">';
$labels=['overview'=>'Przegląd','trash'=>'Kosz','integrations'=>'Integracje','queue'=>'Kolejka','servers'=>'Historia serwerów','menu'=>'Tłumaczenia menu','health'=>'Diagnostyka','backups'=>'Przywracanie bazy'];
foreach($labels as $key=>$label){if(in_array($key,['integrations','queue','health','backups'],true)&&$admin['role_id']!=1)continue;if($key==='servers'&&!has_permission('manage_servers'))continue;if($key==='menu'&&!has_permission('manage_settings'))continue;echo '<a class="btn" href="?section='.$key.'">'.e(le_text($label)).'</a>';}
echo '</nav><p role="status">'.e(le_text($message)).'</p>';
if($section==='overview'){
    echo ('<section class="card card-pad"><h2>'.e(le_text('Nowe możliwości')).'</h2><p>Historia i tłumaczenia są dostępne w edytorze wpisu lub podstrony. Terminy publikacji i wiadomości obsługuje harmonogram.</p><p>Ostatnie wykonanie harmonogramu: ').e(setting('last_worker_at','jeszcze nie uruchomiono')).'</p><p>Tryb awaryjny: '.(is_file(__DIR__.'/../storage/safe-mode')?'aktywny':'wyłączony').'</p></section>';
    if($admin['role_id']==1&&setting('role_review_required','')==='1')echo '<section class="card card-pad"><h2>Sprawdź uprawnienia Moderatora</h2><p>Poprzednia instalacja nadała zarządzanie serwerami i motywami. Możesz zachować obecne ustawienia lub odebrać te dwa uprawnienia.</p><form method="post">'.csrf_field().'<input type="hidden" name="action" value="review_moderator"><button class="btn">Odbierz te uprawnienia Moderatorowi</button></form><a href="/admin/?tab=roles">Edytuj rangi</a></section>';
}
if($section==='history'){
    $row=le_content_row($kind,$id);if(!$row){echo ('<p>'.e(le_text('Brak treści.')).'</p>');}else{
        echo '<h2>'.e($row['title']).'</h2><p><a href="'.e(admin_url($kind.'_edit',['id'=>$id])).('">'.e(le_text('Edytuj treść')).'</a></p>');
        $q=db()->prepare('SELECT id,snapshot,created_at FROM content_revisions WHERE kind=? AND object_id=? ORDER BY id DESC LIMIT 100');$q->execute([$kind,$id]);
        foreach($q as $rev){$snap=json_decode($rev['snapshot'],true)?:[];echo '<details class="card card-pad"><summary>'.e($rev['created_at'].' · '.($snap['title']??'')).'</summary><pre style="white-space:pre-wrap;overflow-wrap:anywhere">'.e($snap['content']??'').'</pre><form method="post">'.csrf_field().'<input type="hidden" name="action" value="revision"><input type="hidden" name="kind" value="'.e($kind).'"><input type="hidden" name="id" value="'.$id.'"><input type="hidden" name="edit_version" value="'.$row['edit_version'].'"><input type="hidden" name="revision" value="'.$rev['id'].('"><button class="btn">'.e(le_text('Przywróć jako szkic')).'</button></form></details>');}
        $locale=le_valid_locale($_GET['locale']??null)??'en';$q=db()->prepare('SELECT * FROM content_translations WHERE kind=? AND object_id=? AND locale=?');$q->execute([$kind,$id,$locale]);$t=$q->fetch()?:[];
        echo ('<h2>'.e(le_text('Tłumaczenie treści')).'</h2><p>');foreach(['pl','en','de'] as $l)echo '<a class="btn" href="?section=history&amp;kind='.e($kind).'&amp;id='.$id.'&amp;locale='.$l.'">'.$l.'</a> ';echo '</p><form method="post" class="card card-pad">'.csrf_field().'<input type="hidden" name="action" value="translation"><input type="hidden" name="kind" value="'.e($kind).'"><input type="hidden" name="id" value="'.$id.'"><input type="hidden" name="locale" value="'.$locale.'">';
        foreach(['title'=>'Tytuł','slug'=>'Adres','seo_description'=>'Opis SEO'] as $name=>$label)echo '<label>'.e(le_text($label)).'<input class="input" name="'.$name.'" value="'.e($t[$name]??'').'"></label>';
        echo ('<label>'.e(le_text('Treść HTML')).'<textarea class="input" name="content" rows="12">').e($t['content']??'').('</textarea></label><button class="btn primary">'.e(le_text('Zapisz tłumaczenie')).'</button></form>');
    }
}
if($section==='trash')foreach(['post'=>'posts','page'=>'pages'] as $k=>$table){if(!has_permission($k==='post'?'manage_content':'manage_pages'))continue;echo '<h2>'.($k==='post'?'Wpisy':'Podstrony').'</h2>';foreach(db()->query("SELECT id,title,deleted_at FROM `$table` WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC LIMIT 100") as $row)echo '<section class="card card-pad">'.e($row['title']).'<form method="post">'.csrf_field().'<input type="hidden" name="action" value="untrash"><input type="hidden" name="kind" value="'.$k.'"><input type="hidden" name="id" value="'.$row['id'].('"><button class="btn">'.e(le_text('Przywróć jako szkic')).'</button></form></section>');}
if($section==='integrations'){
    echo '<form method="post" class="card card-pad">'.csrf_field().('<input type="hidden" name="action" value="integrations"><h2>'.e(le_text('Poczta i Discord')).'</h2>');
    foreach(['public_url'=>'Publiczny adres strony','smtp_host'=>'Host SMTP','smtp_port'=>'Port SMTP','smtp_from'=>'Adres nadawcy','smtp_user'=>'Login SMTP'] as $name=>$label)echo '<label>'.e(le_text($label)).'<input class="input" name="'.$name.'" value="'.e(setting($name,$name==='smtp_port'?'587':'')).'"></label>';
    echo ('<label>'.e(le_text('Szyfrowanie')).'<select class="input" name="smtp_mode"><option value="starttls">STARTTLS</option><option value="tls" ').(setting('smtp_mode')==='tls'?'selected':'').('>TLS</option></select></label><label>'.e(le_text('Nowe hasło SMTP (puste = zachowaj)')).'<input class="input" type="password" name="smtp_password" autocomplete="new-password"></label><label>'.e(le_text('Nowy webhook Discord (puste = zachowaj)')).'<input class="input" type="password" name="discord_webhook" autocomplete="off"></label><label><input type="checkbox" name="clear_discord"> Wyłącz Discord</label><label>'.e(le_text('Twoje hasło')).'<input class="input" type="password" name="password" required></label><button class="btn primary">'.e(le_text('Zapisz integracje')).'</button></form>');
    echo '<form method="post" class="card card-pad">'.csrf_field().('<input type="hidden" name="action" value="test_mail"><label>'.e(le_text('Twoje hasło')).'<input class="input" type="password" name="password" required></label><button class="btn">'.e(le_text('Dodaj test poczty do kolejki')).'</button></form>');
}
if($section==='queue'){
    echo '<form method="post">'.csrf_field().('<input type="hidden" name="action" value="worker"><button class="btn primary">'.e(le_text('Uruchom harmonogram i kolejkę')).'</button></form><p>Przy niepewnym wyniku wysyłki sprawdź odbiorcę przed ponowieniem. Serwer zewnętrzny mógł już przyjąć wiadomość.</p>');
    foreach(db()->query('SELECT id,kind,state,attempts,last_error,created_at FROM delivery_queue ORDER BY id DESC LIMIT 100') as $row){echo '<section class="card card-pad"><p>#'.$row['id'].' '.e($row['kind'].' · '.$row['state'].' · '.$row['created_at']).'</p><p>'.e($row['last_error']).'</p>';if($row['state']==='uncertain')echo '<form method="post">'.csrf_field().'<input type="hidden" name="action" value="retry_delivery"><input type="hidden" name="delivery_id" value="'.$row['id'].('"><button class="btn">'.e(le_text('Świadomie ponów wysyłkę')).'</button></form>');echo '</section>';}
}
if($section==='servers'){
    foreach(levelbridge_servers() as $server){$q=db()->prepare('SELECT FLOOR(bucket/3600)*3600 hour,SUM(samples) samples,SUM(online_samples) online,SUM(players_sum) players,MAX(players_peak) peak FROM server_history WHERE server_id=? AND bucket>=UNIX_TIMESTAMP()-604800 GROUP BY hour ORDER BY hour DESC LIMIT 168');$q->execute([$server['id']]);echo '<section class="card card-pad"><h2>'.e($server['name']).('</h2><table><tr><th>'.e(le_text('Godzina')).'</th><th>'.e(le_text('Dostępność')).'</th><th>'.e(le_text('Śr. gracze')).'</th><th>'.e(le_text('Maks.')).'</th></tr>');foreach($q as $r)echo '<tr><td>'.e(date('d.m H:i',(int)$r['hour'])).'</td><td>'.round(100*$r['online']/$r['samples'],1).'%</td><td>'.round($r['players']/$r['samples'],1).'</td><td>'.$r['peak'].'</td></tr>';echo '</table><p>Obserwacje harmonogramu, retencja 90 dni. Przerwy w harmonogramie nie oznaczają awarii serwera.</p></section>';}
}
if($section==='menu'){
    $data=json_decode(setting('menu_translations','{}'),true)?:[];echo '<form method="post" class="card card-pad">'.csrf_field().'<input type="hidden" name="action" value="menu_translations">';
    foreach(le_menu_config() as $item){echo '<fieldset><legend>'.e($item['label']).'</legend>';foreach(['pl','en','de'] as $l)echo '<label>'.$l.'<input class="input" name="labels['.e($item['id']).']['.$l.']" value="'.e($data[$item['id']][$l]??'').'"></label>';echo '</fieldset>';}
    echo ('<button class="btn primary">'.e(le_text('Zapisz etykiety')).'</button></form>');
}
if($section==='backups'){
    echo ('<section class="card card-pad"><h2>'.e(le_text('Przywracanie bazy danych')).'</h2><p>Przywracanie usuwa późniejsze zmiany danych. Przed operacją powstanie kopia ratunkowa. Korzystaj z kopii tej samej wersji CMS. Pełne przywracanie plików i bazy wykonuje Upgrade Manager.</p>');
    foreach(db()->query('SELECT id,filename,created_at FROM backups ORDER BY id DESC LIMIT 100') as $row){
        if(!is_file(__DIR__.'/../backups/'.$row['filename'].'.meta.json'))continue;
        echo '<form method="post">'.csrf_field().'<input type="hidden" name="action" value="backup_restore"><input type="hidden" name="backup_id" value="'.$row['id'].'"><p>'.e($row['filename'].' · '.$row['created_at']).('</p><label>'.e(le_text('Twoje hasło')).'<input class="input" type="password" name="password" required></label><button class="btn danger">'.e(le_text('Przywróć tę bazę')).'</button></form><hr>');
    }
    echo '</section>';
}
if($section==='health'){
    echo ('<section class="card card-pad"><h2>'.e(le_text('Kontrola środowiska')).'</h2><ul>');foreach(diagnostics() as [$label,$ok,$detail])echo '<li>'.e(le_text($label)).' — '.($ok?'OK':'BRAK').' '.e($detail).'</li>';
    foreach(['mbstring','dom','curl','gd','openssl','zip'] as $ext)echo '<li>'.e($ext).' — '.(extension_loaded($ext)?'OK':'BRAK').'</li>';
    foreach(['storage','backups','uploads','upgrade/runtime','upgrade/backups'] as $dir)echo '<li>'.e($dir).' zapis — '.(is_writable(__DIR__.'/../'.$dir)?'OK':'BRAK').'</li>';
    echo '<li>Wersja schematu: '.e(setting('suite_schema','brak')).'</li><li>Harmonogram: '.e(setting('last_worker_at','brak')).'</li></ul><p>Konfigurację blokowania prywatnych katalogów sprawdź również po stronie serwera WWW.</p></section>';
    echo '<form method="post">'.csrf_field().'<input type="hidden" name="action" value="safe_mode"><label><input type="checkbox" name="enabled" '.(is_file(__DIR__.'/../storage/safe-mode')?'checked':'').'> Wyłącz wykonywanie pluginów (tryb awaryjny)</label><button class="btn">Zapisz</button></form>';
}
echo '</main>';require __DIR__.'/../templates/default/footer.php';
