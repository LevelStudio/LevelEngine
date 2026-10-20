<?php
declare(strict_types=1);
function up_root(): string {return dirname(__DIR__);}
function up_storage(): string {
    $dir=up_root().'/upgrade/runtime';if(!is_dir($dir)&&!mkdir($dir,0700,true))throw new RuntimeException('Nie można utworzyć katalogu aktualizacji.');return $dir;
}
function up_path(string $path): string {
    if($path===''||preg_match('~[\\\\:\x00-\x20]|^/|(^|/)\.\.?(/|$)~',$path))throw new RuntimeException('Niebezpieczna ścieżka paczki.');return $path;
}
function up_destination(string $root,string $rel): string {
    up_path($rel);$base=realpath($root);if(!$base)throw new RuntimeException('Brak katalogu docelowego.');$parts=explode('/',$rel);$at=$base;
    foreach($parts as $part){$at.=DIRECTORY_SEPARATOR.$part;if(is_link($at))throw new RuntimeException('Dowiązanie w katalogu docelowym.');if(file_exists($at)){$resolved=realpath($at);if(!$resolved||!str_starts_with($resolved,$base.DIRECTORY_SEPARATOR))throw new RuntimeException('Ścieżka poza katalogiem CMS.');}}
    return $at;
}
function up_allowed(string $path): bool {
    if(in_array($path,['.htaccess','index.php','levelbridge-api.php','plugin-api.php','server-status.php','config.example.php','manifest.json','install/schema.sql','install/.htaccess','backups/.htaccess','uploads/.htaccess','plugins/.htaccess'],true))return true;
    if(preg_match('~^README[^/]*\.md$~D',$path))return true;
    return (bool)preg_match('~^(?:admin|lib|assets|lang|templates|themes/default|bin|marketplace)/[A-Za-z0-9._/-]+$|^upgrade/(?:index\.php|manager\.php|manifest\.json|\.htaccess|README\.md|migrations/[A-Za-z0-9._-]+\.php)$~D',$path);
}
function up_extract(string $archive,string $directory,bool $unsigned=false): array {
    $zip=new ZipArchive();if($zip->open($archive,ZipArchive::CHECKCONS)!==true)throw new RuntimeException('Nieprawidłowy ZIP.');
    try{
        if($zip->numFiles>3000)throw new RuntimeException('Za dużo plików.');$total=0;$seen=[];
        for($i=0;$i<$zip->numFiles;$i++){$name=up_path((string)$zip->getNameIndex($i));$stat=$zip->statIndex($i);$total+=(int)$stat['size'];if($total>100*1024*1024||$stat['size']>10*1024*1024||isset($seen[strtolower($name)]))throw new RuntimeException('Za duża paczka lub zduplikowany plik.');$seen[strtolower($name)]=1;$os=0;$attrs=0;$zip->getExternalAttributesIndex($i,$os,$attrs);if((($attrs>>16)&0170000)===0120000)throw new RuntimeException('Dowiązania nie są dozwolone.');}
        $manifest=le_verify_package($zip,$unsigned);
        if(($manifest['engine']??'')!=='LevelEngineCMS'||($manifest['package_type']??'')!=='upgrade'||!preg_match('/^\d+\.\d+\.\d+(?:[-.][A-Za-z0-9.-]+)?$/D',(string)($manifest['version']??'')))throw new RuntimeException('Wymagana paczka UPGRADE dla LevelEngineCMS.');
        $renumber=($manifest['engine']??'')==='LevelEngineCMS'&&($manifest['package_mode']??'')==='renumber'&&($manifest['renumber_from']??'')==='3.0.0'&&($manifest['version']??'')==='1.0.0'&&setting('engine_version','0')==='3.0.0'&&($manifest['min_version']??'')==='3.0.0'&&($manifest['max_version']??'')==='3.0.0';
        $maintenance=($manifest['package_mode']??'')==='maintenance'&&($manifest['version']??'')===setting('engine_version','0')&&($manifest['min_version']??'')===setting('engine_version','0')&&($manifest['max_version']??'')===setting('engine_version','0')&&is_int($manifest['release_build']??null)&&$manifest['release_build']>(int)setting('release_build','0');
        if(version_compare(PHP_VERSION,(string)($manifest['min_php']??'8.1'),'<')||version_compare(setting('engine_version','0'),(string)($manifest['min_version']??'2.4.0'),'<')||(version_compare($manifest['version'],setting('engine_version','0'),'<=')&&!$renumber&&!$maintenance)||(!empty($manifest['max_version'])&&version_compare(setting('engine_version','0'),$manifest['max_version'],'>')))throw new RuntimeException('Paczka nie pasuje do aktualnej wersji CMS/PHP.');
        if(!is_dir($directory)&&!mkdir($directory,0700,true))throw new RuntimeException('Nie można utworzyć katalogu paczki.');$files=[];
        for($i=0;$i<$zip->numFiles;$i++){
            $name=(string)$zip->getNameIndex($i);if(str_ends_with($name,'/')||$name==='package-signature.json')continue;
            if(!up_allowed($name))throw new RuntimeException('Paczka próbuje nadpisać plik spoza rdzenia: '.$name);
            $dest=up_destination($directory,$name);if(!is_dir(dirname($dest))&&!mkdir(dirname($dest),0700,true))throw new RuntimeException('Nie można utworzyć katalogu.');$bytes=$zip->getFromIndex($i);if($bytes===false)throw new RuntimeException('Nie można odczytać pliku.');le_atomic_write($dest,$bytes,0644);$files[$name]=hash('sha256',$bytes);
        }
        return ['manifest'=>$manifest,'files'=>$files];
    }finally{$zip->close();}
}
function up_journal(array $journal): void {le_atomic_write(up_storage().'/journal.json',json_encode($journal,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));}
function up_backup_files(array $files,string $backup): array {
    $map=[];foreach($files as $rel=>$hash){$dest=up_destination(up_root(),$rel);if(is_file($dest)){$bak=up_destination($backup.'/files',$rel);if(!is_dir(dirname($bak))&&!mkdir(dirname($bak),0700,true))throw new RuntimeException('Nie można utworzyć backupu.');if(!copy($dest,$bak))throw new RuntimeException('Nie można skopiować do backupu: '.$rel);$map[$rel]=hash_file('sha256',$bak);}else $map[$rel]=null;}
    return $map;
}
function up_restore_files(string $backup,array $map): void {
    foreach($map as $rel=>$hash)if($hash!==null){$src=up_destination($backup.'/files',$rel);if(!is_file($src)||!hash_equals($hash,hash_file('sha256',$src)))throw new RuntimeException('Uszkodzony backup pliku: '.$rel);}
    foreach($map as $rel=>$hash){$dest=up_destination(up_root(),$rel);if($hash===null){if(is_file($dest)&&!unlink($dest))throw new RuntimeException('Nie można usunąć nowego pliku: '.$rel);}else{if(!is_dir(dirname($dest))&&!mkdir(dirname($dest),0755,true))throw new RuntimeException('Nie można utworzyć katalogu.');le_atomic_write($dest,(string)file_get_contents(up_destination($backup.'/files',$rel)),0644);if(function_exists('opcache_invalidate'))opcache_invalidate($dest,true);}}
}
function up_run_migrations(string $payload,PDO $pdo,string $from,string $to): array {
    $ran=[];$dir=$payload.'/upgrade/migrations';$files=glob($dir.'/*.php')?:[];sort($files,SORT_STRING);
    foreach($files as $file){$key=basename($file);$q=$pdo->prepare('SELECT 1 FROM cms_migrations WHERE migration_key=?');$q->execute([$key]);if($q->fetchColumn())continue;$fn=require $file;if(!is_callable($fn))throw new RuntimeException('Nieprawidłowa migracja: '.$key);$fn($pdo);$pdo->prepare('INSERT INTO cms_migrations(migration_key,version) VALUES(?,?)')->execute([$key,$to]);$ran[]=$key;}
    return $ran;
}
function up_apply_suite(string $work,array $prepared): string {
    le_request_lock(true);
    $manifest=$prepared['manifest'];$files=$prepared['files'];$stamp=date('Ymd_His').'_'.bin2hex(random_bytes(3));$backup=up_root().'/upgrade/backups/'.$stamp;
    if(!mkdir($backup.'/files',0700,true))throw new RuntimeException('Nie można utworzyć katalogu backupu.');
    $free=disk_free_space(up_root());$needed=0;foreach($files as $rel=>$hash)$needed+=filesize(up_destination($work.'/payload',$rel));if($free!==false&&$free<$needed*3+10485760)throw new RuntimeException('Za mało miejsca na aktualizację i backup.');
    $journal=['state'=>'preparing','backup'=>$stamp,'from'=>setting('engine_version',''),'from_build'=>(int)setting('release_build','0'),'to'=>$manifest['version'],'to_build'=>(int)($manifest['release_build']??0),'applied'=>[]];up_journal($journal);
    le_atomic_write(up_root().'/storage/maintenance.json',json_encode(['since'=>date('c'),'backup'=>$stamp]));
    try{
        $database=le_backup_database(db(),$backup.'/database.sql');$map=up_backup_files($files,$backup);
        $meta=['format'=>2,'from'=>$journal['from'],'to'=>$journal['to'],'from_build'=>$journal['from_build'],'to_build'=>$journal['to_build'],'files'=>$map,'database'=>$database,'created_at'=>date('c')];le_atomic_write($backup.'/manifest.json',json_encode($meta,JSON_THROW_ON_ERROR));
        $journal['state']='backup_ready';up_journal($journal);
        up_run_migrations($work.'/payload',db(),$journal['from'],$journal['to']);$journal['state']='copying';up_journal($journal);
        foreach($files as $rel=>$hash){$src=up_destination($work.'/payload',$rel);if(!hash_equals($hash,hash_file('sha256',$src)))throw new RuntimeException('Zmieniona paczka.');$dest=up_destination(up_root(),$rel);if(!is_dir(dirname($dest))&&!mkdir(dirname($dest),0755,true))throw new RuntimeException('Nie można utworzyć katalogu.');le_atomic_write($dest,(string)file_get_contents($src),0644);if(!hash_equals($hash,hash_file('sha256',$dest)))throw new RuntimeException('Niepełny zapis: '.$rel);if(function_exists('opcache_invalidate'))opcache_invalidate($dest,true);$journal['applied'][]=$rel;up_journal($journal);}
        $journal['state']='checking';up_journal($journal);
        if(!le_suite_schema_ready()||!is_file(up_root().'/index.php'))throw new RuntimeException('Kontrola po aktualizacji nie powiodła się.');
        foreach($files as $rel=>$hash)if(!hash_equals($hash,hash_file('sha256',up_destination(up_root(),$rel))))throw new RuntimeException('Niespójny rdzeń.');
        set_setting('release_build',(string)($manifest['release_build']??0));set_setting('engine_version',$manifest['version']);set_setting('public_version',$manifest['public_version']??$manifest['version']);set_setting('last_upgrade_backup',$stamp);
        $journal['state']='complete';up_journal($journal);unlink(up_root().'/storage/maintenance.json');audit_log('upgrade.completed','core',$manifest['version']);
        require_once __DIR__.'/upgrade-backups.php';try{up_prune_backups(up_root().'/upgrade/backups',$stamp);}catch(Throwable $e){levelengine_log('warning','Nie udało się usunąć starych kopii');}return $stamp;
    }catch(Throwable $e){
        $journal['state']=is_file($backup.'/manifest.json')?'recovery_required':'preparing';$journal['error']=$e->getMessage();up_journal($journal);
        throw new RuntimeException('Aktualizacja przerwana. Strona jest w konserwacji. Wybierz odzyskiwanie: '.$e->getMessage());
    }
}
function up_recover_suite(string $stamp): void {
    le_request_lock(true);
    if(!preg_match('/^\d{8}_\d{6}_[a-f0-9]{6}$/D',$stamp))throw new RuntimeException('Nieprawidłowy backup.');$backup=up_root().'/upgrade/backups/'.$stamp;
    $meta=json_decode((string)file_get_contents($backup.'/manifest.json'),true,512,JSON_THROW_ON_ERROR);if(($meta['format']??0)!==2)throw new RuntimeException('Backup pochodzi ze starego managera. Użyj niezależnej kopii hostingu.');
    le_backup_validate($backup.'/database.sql');foreach($meta['files'] as $rel=>$hash)if($hash!==null&&(!is_file(up_destination($backup.'/files',$rel))||!hash_equals($hash,hash_file('sha256',up_destination($backup.'/files',$rel)))))throw new RuntimeException('Uszkodzony backup plików.');
    le_atomic_write(up_root().'/storage/maintenance.json',json_encode(['since'=>date('c'),'backup'=>$stamp]));
    le_backup_restore(db(),$backup.'/database.sql');up_restore_files($backup,$meta['files']);
    if(column_exists(db(),'users','session_version'))db()->exec('UPDATE users SET session_version=session_version+1');
    set_setting('release_build',(string)($meta['from_build']??0));set_setting('engine_version',$meta['from']);set_setting('public_version',$meta['from']);up_journal(['state'=>'recovered','backup'=>$stamp]);unlink(up_root().'/storage/maintenance.json');audit_log('upgrade.recovered','core',$meta['from']);
}
