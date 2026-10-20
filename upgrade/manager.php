<?php
declare(strict_types=1);
require dirname(__DIR__).'/lib/bootstrap.php';le_head();require dirname(__DIR__).'/lib/updater.php';
header('Cache-Control: no-store');header('X-Robots-Tag: noindex, nofollow');$u=current_user();$message='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    $lock=null;$dbLocked=false;
    try{
        if(!csrf_ok())throw new RuntimeException('Sesja formularza wygasła.');
        $lock=fopen(up_storage().'/upgrade.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('Inna aktualizacja jest w toku.');
        $lockName='le_upgrade_'.substr(hash('sha256',(string)(cfg('db',[])['name']??'')),0,40);$q=db()->prepare('SELECT GET_LOCK(?,0)');$q->execute([$lockName]);$dbLocked=(bool)$q->fetchColumn();if(!$dbLocked)throw new RuntimeException('Inna aktualizacja używa bazy.');
        $action=(string)($_POST['action']??'');
        if($action==='prepare'){
            if(is_file(up_root().'/storage/maintenance.json'))throw new RuntimeException('Najpierw zakończ odzyskiwanie.');
            $file=$_FILES['package']??[];if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||($file['size']??0)>50*1024*1024||!is_uploaded_file($file['tmp_name']))throw new RuntimeException('Wybierz ZIP do 50 MB.');
            $work=up_storage().'/'.bin2hex(random_bytes(8));mkdir($work,0700,true);if(!move_uploaded_file($file['tmp_name'],$work.'/update.zip'))throw new RuntimeException('Nie można zapisać paczki.');
            $allow=!empty($_POST['trusted_unsigned']);$prepared=up_extract($work.'/update.zip',$work.'/payload',$allow);if(($prepared['manifest']['package_mode']??'')==='renumber'&&empty($_POST['confirm_renumber']))throw new RuntimeException('Potwierdź zmianę numeracji LevelEngine 3.0.0 → 1.0.0.');$_SESSION['suite_upgrade_work']=$work;$_SESSION['suite_upgrade_unsigned']=$allow;$_SESSION['suite_upgrade_hash']=hash_file('sha256',$work.'/update.zip');$message='Paczka zweryfikowana: '.$prepared['manifest']['version'];
        }elseif($action==='apply'){
            $work=(string)($_SESSION['suite_upgrade_work']??'');$base=realpath(up_storage());$resolved=realpath($work);if(!$resolved||dirname($resolved)!==$base||!hash_equals((string)($_SESSION['suite_upgrade_hash']??''),hash_file('sha256',$work.'/update.zip')))throw new RuntimeException('Paczka wygasła lub została zmieniona.');
            $prepared=up_extract($work.'/update.zip',$work.'/payload',(bool)($_SESSION['suite_upgrade_unsigned']??false));$stamp=up_apply_suite($work,$prepared);unset($_SESSION['suite_upgrade_work'],$_SESSION['suite_upgrade_unsigned'],$_SESSION['suite_upgrade_hash']);$message='Aktualizacja zakończona. Backup: '.$stamp;
        }elseif($action==='recover'){$stamp=(string)($_POST['backup']??'');up_recover_suite($stamp);$message='Przywrócono zgodne pliki i bazę. Zaloguj się ponownie.';}
        elseif($action==='safe_mode'){$marker=up_root().'/storage/safe-mode';if(!empty($_POST['enabled']))le_atomic_write($marker,'1');elseif(is_file($marker))unlink($marker);$message='Tryb awaryjny zmieniony.';}
        elseif($action==='clear_preparing'){
            $journal=json_decode((string)file_get_contents(up_storage().'/journal.json'),true)?:[];
            if(($journal['state']??'')!=='preparing')throw new RuntimeException('Nie można zakończyć konserwacji po rozpoczęciu zmian. Użyj odzyskiwania.');
            if(is_file(up_root().'/storage/maintenance.json'))unlink(up_root().'/storage/maintenance.json');up_journal(['state'=>'cancelled']);$message='Anulowano przygotowanie. Rdzeń i baza nie były zmieniane.';
        }else throw new RuntimeException('Nieprawidłowa operacja.');
    }catch(Throwable $e){$message=$e->getMessage();}
    finally{if($dbLocked){$q=db()->prepare('SELECT RELEASE_LOCK(?)');$q->execute([$lockName]);}if(is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}}
}
$journal=is_file(up_storage().'/journal.json')?json_decode((string)file_get_contents(up_storage().'/journal.json'),true):[];
?><!doctype html><html lang="<?=e(le_locale())?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Aktualizacje LevelEngine</title><link rel="stylesheet" href="/assets/css/app.css"></head><body><main class="page container"><h1><?=e(le_text('Aktualizacje i odzyskiwanie'))?></h1><p>Operacje zatwierdzasz aktywną sesją Head Admina — bez ponownego wpisywania hasła.</p><p>Obecna wersja: <?=e(setting('engine_version')).' · build '.e(setting('release_build','0'))?></p><p role="status"><?=e(le_text($message))?></p><p><a href="/admin/">← Panel</a></p>
<section class="card card-pad"><h2><?=e(le_text('1. Sprawdź paczkę'))?></h2><form method="post" enctype="multipart/form-data"><?=csrf_field()?><input type="hidden" name="action" value="prepare"><input class="input" type="file" name="package" accept=".zip" required><label><input type="checkbox" name="trusted_unsigned" value="1"> Świadomie ufam paczce bez podpisu wydawcy</label><label><input type="checkbox" name="confirm_renumber" value="1"> Potwierdzam zmianę numeracji 3.0.0 → 1.0.0 (jeśli dotyczy)</label><button class="btn primary"><?=e(le_text('Sprawdź ZIP'))?></button></form></section>
<?php if(!empty($_SESSION['suite_upgrade_work'])):?><section class="card card-pad"><h2><?=e(le_text('2. Wykonaj kopię i aktualizuj'))?></h2><p>Strona przejdzie w konserwację. Zachowaj też niezależny backup hostingu.</p><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="apply"><button class="btn primary">Aktualizuj</button></form></section><?php endif;?>
<section class="card card-pad"><h2><?=e(le_text('Stan procesu'))?></h2><pre style="white-space:pre-wrap"><?=e(json_encode($journal,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE))?></pre><?php if(($journal['state']??'')==='preparing'):?><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="clear_preparing"><button class="btn"><?=e(le_text('Anuluj przygotowanie'))?></button></form><?php endif;?></section>
<section class="card card-pad"><h2><?=e(le_text('Przywróć pliki i bazę'))?></h2><p>Operacja usuwa dane zapisane po wykonaniu wybranej kopii. Nie przywracamy samych plików do niezgodnej bazy.</p><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="recover"><select class="input" name="backup"><?php foreach(array_reverse(glob(up_root().'/upgrade/backups/*/manifest.json')?:[]) as $file):$meta=json_decode((string)file_get_contents($file),true)?:[];if(($meta['format']??0)!==2)continue;?><option value="<?=e(basename(dirname($file)))?>"><?=e(basename(dirname($file)).' · '.$meta['from'].' → '.$meta['to'])?></option><?php endforeach;?></select><button class="btn danger"><?=e(le_text('Przywróć zgodną kopię'))?></button></form></section>
<section class="card card-pad"><h2><?=e(le_text('Tryb awaryjny'))?></h2><p>Wyłącza uruchamianie pluginów. Ten panel działa również, gdy plugin uszkodził stronę.</p><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="safe_mode"><label><input type="checkbox" name="enabled" <?=is_file(up_root().'/storage/safe-mode')?'checked':''?>> Włącz tryb awaryjny</label><button class="btn">Zapisz</button></form></section>
</main></body></html>
