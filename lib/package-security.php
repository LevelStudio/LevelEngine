<?php
declare(strict_types=1);
function le_verify_package(ZipArchive $zip,bool $allowUnsigned=false): array {
    if($zip->numFiles>3000)throw new RuntimeException('Za dużo plików w paczce.');
    $total=0;$paths=[];
    for($i=0;$i<$zip->numFiles;$i++){
        $stat=$zip->statIndex($i);if(!$stat)throw new RuntimeException('Nie można odczytać ZIP.');
        $path=(string)$stat['name'];$total+=(int)$stat['size'];
        if((int)$stat['size']>10*1024*1024||$total>100*1024*1024)throw new RuntimeException('Rozpakowana paczka jest za duża.');
        if(str_contains($path,'\\')||str_contains($path,':')||str_starts_with($path,'/')||preg_match('~(^|/)\.\.?(/|$)|[\x00-\x1f]~',$path))throw new RuntimeException('Niebezpieczna ścieżka ZIP.');
        if(isset($paths[strtolower($path)]))throw new RuntimeException('Powtórzona ścieżka ZIP.');$paths[strtolower($path)]=true;
        $ops=0;$attributes=0;if($zip->getExternalAttributesIndex($i,$ops,$attributes)&&(($attributes>>16)&0170000)===0120000)throw new RuntimeException('Dowiązania w ZIP są zabronione.');
    }
    $raw=$zip->getFromName('manifest.json');$prefix='';
    if($raw===false){for($i=0;$i<$zip->numFiles;$i++){$name=(string)$zip->getNameIndex($i);if(preg_match('~^[a-zA-Z0-9_-]+/manifest\.json$~D',$name)){$prefix=dirname($name).'/';$raw=$zip->getFromIndex($i);break;}}}
    if($raw===false)throw new RuntimeException('Brak manifestu.');$manifest=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
    if(!is_array($manifest)||array_is_list($manifest))throw new RuntimeException('Manifest musi być obiektem JSON.');
    $sigRaw=$zip->getFromName($prefix.'package-signature.json');
    if($sigRaw===false){
        if(!$allowUnsigned)throw new RuntimeException('Paczka nie ma podpisu. Head Admin może jawnie zaakceptować zaufaną paczkę bez podpisu.');
        if(isset($manifest['files']))le_package_file_hashes($zip,$manifest['files'],$prefix);
        return $manifest;
    }
    $sig=json_decode($sigRaw,true,512,JSON_THROW_ON_ERROR);$signature=base64_decode((string)($sig['signature']??''),true);
    if(($sig['algorithm']??'')!=='RSA-SHA256')throw new RuntimeException('Nieobsługiwany podpis paczki.');
    $keyFile=__DIR__.'/release-public.pem';$key=is_file($keyFile)?file_get_contents($keyFile):'';
    $extra=cfg('release_public_keys',[]);if(isset($extra[$sig['key_id']??'']))$key=$extra[$sig['key_id']];
    if(!is_string($key)||$key===''||$signature===false||!hash_equals((string)($sig['key_id']??''),hash('sha256',$key))||openssl_verify($raw,$signature,$key,OPENSSL_ALGO_SHA256)!==1)throw new RuntimeException('Podpis paczki jest nieprawidłowy lub klucz nie jest zaufany.');
    $hashes=$manifest['files']??null;if(!is_array($hashes)||count($hashes)>3000)throw new RuntimeException('Brak podpisanej listy plików.');
    $seen=[];
    for($i=0;$i<$zip->numFiles;$i++){
        $name=(string)$zip->getNameIndex($i);if(str_ends_with($name,'/'))continue;
        if(!str_starts_with($name,$prefix))throw new RuntimeException('Plik poza paczką.');$rel=substr($name,strlen($prefix));
        if(in_array($rel,['manifest.json','package-signature.json'],true))continue;
        if(isset($seen[strtolower($rel)]))throw new RuntimeException('Powtórzony plik paczki.');$seen[strtolower($rel)]=true;
        if(!isset($hashes[$rel])||!preg_match('/^[a-f0-9]{64}$/D',(string)$hashes[$rel])||!hash_equals($hashes[$rel],hash('sha256',(string)$zip->getFromIndex($i))))throw new RuntimeException('Plik nie zgadza się z podpisanym manifestem: '.$rel);
    }
    if(count($seen)!==count($hashes))throw new RuntimeException('Podpisana paczka jest niepełna.');return $manifest;
}
function le_package_file_hashes(ZipArchive $zip,mixed $hashes,string $prefix=''): void {
    if(!is_array($hashes)||count($hashes)>3000)throw new RuntimeException('Nieprawidłowa lista plików paczki.');$seen=[];
    for($i=0;$i<$zip->numFiles;$i++){
        $name=(string)$zip->getNameIndex($i);if(str_ends_with($name,'/'))continue;
        if(!str_starts_with($name,$prefix))throw new RuntimeException('Plik poza paczką.');$rel=substr($name,strlen($prefix));
        if(in_array($rel,['manifest.json','package-signature.json'],true))continue;
        $bytes=$zip->getFromIndex($i);
        if($bytes===false||!isset($hashes[$rel])||!is_string($hashes[$rel])||!preg_match('/^[a-f0-9]{64}$/D',$hashes[$rel])||!hash_equals($hashes[$rel],hash('sha256',$bytes)))throw new RuntimeException('Plik nie zgadza się z manifestem: '.$rel);
        $seen[$rel]=true;
    }
    if(count($seen)!==count($hashes))throw new RuntimeException('Paczka jest niepełna.');
}
function le_package_requirements(array $manifest,?string $self=null): void {
    if(!empty($manifest['min_cms'])&&version_compare(LEVELENGINE_CMS_VERSION,(string)$manifest['min_cms'],'<'))throw new RuntimeException('Paczka wymaga nowszego CMS.');
    if(!empty($manifest['max_cms'])&&version_compare(LEVELENGINE_CMS_VERSION,(string)$manifest['max_cms'],'>'))throw new RuntimeException('Paczka nie obsługuje tej wersji CMS.');
    if(!empty($manifest['min_php'])&&version_compare(PHP_VERSION,(string)$manifest['min_php'],'<'))throw new RuntimeException('Paczka wymaga nowszego PHP.');
    foreach(($manifest['requires']??[]) as $key=>$minimum){if(!preg_match('/^[a-zA-Z0-9_-]{1,80}$/D',(string)$key)||!is_string($minimum))throw new RuntimeException('Nieprawidłowa zależność.');$q=db()->prepare('SELECT version,enabled FROM plugins WHERE plugin_key=?');$q->execute([$key]);$dep=$q->fetch();if(!$dep||!$dep['enabled']||version_compare($dep['version'],$minimum,'<'))throw new RuntimeException('Brak aktywnej zależności '.$key.' >= '.$minimum);}
}
function le_plugin_compatibility(int $id): void {
    $q=db()->prepare('SELECT * FROM plugins WHERE id=?');$q->execute([$id]);$plugin=$q->fetch();if(!$plugin)throw new RuntimeException('Brak pluginu.');
    if(!$plugin['enabled'])le_package_requirements(json_decode($plugin['manifest_json'],true)?:[],$plugin['plugin_key']);
    else foreach(db()->query('SELECT plugin_key,manifest_json FROM plugins WHERE enabled=1') as $row){$m=json_decode($row['manifest_json'],true)?:[];if(isset($m['requires'][$plugin['plugin_key']]))throw new RuntimeException('Najpierw wyłącz zależny plugin '.$row['plugin_key']);}
}
function le_plugin_api_guard(string $permission='',bool $mutation=false): array {
    $u=require_login();if($permission!==''&&!has_permission($permission,$u)){http_response_code(403);exit('Brak uprawnień.');}
    if($mutation&&($_SERVER['REQUEST_METHOD']!=='POST'||!csrf_ok())){http_response_code(403);exit('Nieprawidłowe żądanie.');}return $u;
}
function le_plugin_can_delete(int $id): void {
    $q=db()->prepare('SELECT plugin_key FROM plugins WHERE id=?');$q->execute([$id]);$key=$q->fetchColumn();if(!$key)return;
    foreach(db()->query('SELECT plugin_key,manifest_json FROM plugins') as $row){$m=json_decode($row['manifest_json'],true)?:[];if(isset($m['requires'][$key]))throw new RuntimeException('Plugin '.$row['plugin_key'].' wymaga tego rozszerzenia.');}
}
function engine_install_package(array $upload,string $type='plugin',bool $local=false,?array $expected=null): void {
    require_permission($type==='theme'?'manage_themes':'manage_plugins');
    if(($upload['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||(!$local&&!is_uploaded_file($upload['tmp_name'])))throw new RuntimeException('Nieprawidłowy upload.');
    if(filesize($upload['tmp_name'])>25*1024*1024)throw new RuntimeException('Paczka przekracza 25 MB.');
    $zip=new ZipArchive();if($zip->open($upload['tmp_name'],ZipArchive::CHECKCONS)!==true)throw new RuntimeException('Nieprawidłowy ZIP.');
    try{$allow=($expected!==null&&$local)||(!empty($_POST['trusted_unsigned'])&&(int)(current_user()['role_id']??0)===1);$manifest=le_verify_package($zip,$allow);le_package_requirements($manifest);}
    finally{$zip->close();}
    $key=(string)($manifest['id']??'');if(!preg_match('/^[a-zA-Z0-9_-]{1,80}$/D',$key))throw new RuntimeException('Nieprawidłowe ID.');
    $table=$type==='theme'?'themes':'plugins';$column=$type==='theme'?'theme_key':'plugin_key';$q=db()->prepare("SELECT * FROM `$table` WHERE `$column`=?");$q->execute([$key]);$old=$q->fetch();
    if(!$old){engine_install_package_legacy($upload,$type,$local,$expected);return;}
    if(!empty($old['is_system'])||($type==='theme'&&$key==='default'))throw new RuntimeException('Nie można aktualizować motywu systemowego.');
    if(version_compare((string)($manifest['version']??'0'),$old['version'],'<='))throw new RuntimeException('Paczka nie jest nowsza.');
    if($type==='plugin'&&$old['enabled'])throw new RuntimeException('Wyłącz plugin przed aktualizacją.');
    $root=dirname(__DIR__);$dest=$root.'/'.$table.'/'.$key;$backup=$root.'/backups/packages';if(!is_dir($backup)&&!mkdir($backup,0700,true))throw new RuntimeException('Nie można utworzyć backupu rozszerzenia.');
    $saved=$backup.'/'.$key.'-'.date('YmdHis').'-'.bin2hex(random_bytes(4));if(!is_dir($dest)||is_link($dest)||!rename($dest,$saved))throw new RuntimeException('Nie można zachować starego rozszerzenia.');
    db()->beginTransaction();
    try{db()->prepare("DELETE FROM `$table` WHERE `$column`=?")->execute([$key]);engine_install_package_legacy($upload,$type,$local,$expected);db()->commit();audit_log('package.updated',$type,$key,['from'=>$old['version'],'to'=>$manifest['version']]);}
    catch(Throwable $e){if(db()->inTransaction())db()->rollBack();if(is_dir($dest))le_safe_tree_delete($dest,$root.'/'.$table);if(!rename($saved,$dest))throw new RuntimeException('Nie udało się przywrócić rozszerzenia. Kopia: '.$saved);throw $e;}
}
