<?php
declare(strict_types=1);

function le_repository_url(string $url): bool {
    $parts=parse_url($url);
    return $parts && strtolower($parts['scheme']??'')==='https' && strtolower($parts['host']??'')==='cms.levelgame.pl'
        && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['fragment'])
        && (!isset($parts['port']) || $parts['port']===443) && !preg_match('/[\x00-\x20\x7f\\\\]/',$url);
}
function remote_get(string $url,int $maxBytes=20971520): string {
    if(!le_repository_url($url))throw new RuntimeException(le_text('Paczki muszą pochodzić z oficjalnego repozytorium HTTPS cms.levelgame.pl.'));
    if(!function_exists('curl_init'))throw new RuntimeException(le_text('Marketplace wymaga rozszerzenia cURL.'));
    $ips=gethostbynamel('cms.levelgame.pl');if(!$ips)throw new RuntimeException(le_text('Nie można ustalić adresu repozytorium.'));
    foreach($ips as $ip)if(!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE))throw new RuntimeException(le_text('Repozytorium wskazuje niepubliczny adres.'));
    $data='';$overflow=false;$ch=curl_init($url);
    curl_setopt_array($ch,[CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_RESOLVE=>['cms.levelgame.pl:443:'.$ips[0]],CURLOPT_USERAGENT=>'LevelEngineCMS/'.LEVELENGINE_CMS_VERSION.' FINAL',CURLOPT_WRITEFUNCTION=>static function($ch,string $chunk)use(&$data,&$overflow,$maxBytes){if(strlen($data)+strlen($chunk)>$maxBytes){$overflow=true;return 0;}$data.=$chunk;return strlen($chunk);}]);
    $ok=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$errno=curl_errno($ch);curl_close($ch);
    if($overflow)throw new RuntimeException(le_text('Odpowiedź repozytorium jest zbyt duża.'));
    if($ok===false){
        // libcurl uses code 60; its named PHP constant is not exposed in every build.
        if($errno===60)throw new RuntimeException(le_text('Nie można zweryfikować certyfikatu repozytorium. Sprawdź certyfikaty CA i zegar hostingu.'));
        if($errno===CURLE_OPERATION_TIMEDOUT)throw new RuntimeException(le_text('Repozytorium nie odpowiedziało w wymaganym czasie. Spróbuj ponownie.'));
        throw new RuntimeException(le_text('Nie można połączyć się z repozytorium. Sprawdź dostęp HTTPS i DNS na hostingu.'));
    }
    if($code<200||$code>=300)throw new RuntimeException(le_text('Repozytorium zwróciło błąd HTTP').' '.$code.'.');return $data;
}
function le_repository_packages(array $items): array {
    if(!array_is_list($items)||count($items)>1000)throw new RuntimeException(le_text('Nieprawidłowy katalog repozytorium.'));
    $seen=[];$out=[];
    foreach($items as $item){
        if(!is_array($item)||!preg_match('/^[a-zA-Z0-9_-]{1,80}$/D',(string)($item['id']??''))||isset($seen[$item['id']])||!in_array($item['type']??'',['theme','plugin','paper-plugin'],true))throw new RuntimeException(le_text('Katalog zawiera nieprawidłową lub powtórzoną paczkę.'));
        $seen[$item['id']]=true;
        foreach(['name'=>160,'version'=>80,'author'=>160,'description'=>2000,'status'=>40] as $key=>$limit){$value=$item[$key]??($key==='name'?$item['id']:($key==='version'?'0.0.0':''));if(!is_string($value)||mb_strlen($value)>$limit)throw new RuntimeException(le_text('Nieprawidłowe dane paczki w repozytorium.'));$item[$key]=$value;}
        if(!preg_match('/^\d+\.\d+\.\d+(?:[-.][A-Za-z0-9.-]+)?$/D',$item['version']))throw new RuntimeException(le_text('Nieprawidłowa wersja paczki.'));
        if(!empty($item['download_url'])&&(!is_string($item['download_url'])||!le_repository_url($item['download_url'])))throw new RuntimeException(le_text('Katalog zawiera adres spoza oficjalnego repozytorium.'));
        if(!empty($item['sha256'])&&(!is_string($item['sha256'])||!preg_match('/^[a-f0-9]{64}$/D',$item['sha256'])))throw new RuntimeException(le_text('Nieprawidłowa suma kontrolna w katalogu.'));
        foreach(['min_cms','max_cms','min_php'] as $key)if(isset($item[$key])&&(!is_string($item[$key])||!preg_match('/^\d+\.\d+(?:\.\d+)?(?:[-.][A-Za-z0-9.-]+)?$/D',$item[$key])))throw new RuntimeException(le_text('Nieprawidłowe wymagania paczki.'));
        $out[]=$item;
    }
    return $out;
}
function marketplace_catalog(bool $remote=true,bool $refresh=false): array {
    $file=__DIR__.'/../storage/cache/repository.json';$cached=null;$error='';
    if(is_file($file))try{$cached=json_decode((string)file_get_contents($file),true,64,JSON_THROW_ON_ERROR);$cached['packages']=le_repository_packages($cached['packages']??[]);if(!is_int($cached['fetched_at']??null)||$cached['fetched_at']>time()+60)$cached=null;}catch(Throwable $e){$cached=null;}
    if($remote&&!$refresh&&$cached&&$cached['fetched_at']>time()-300)return $cached+['source'=>'cached','error'=>''];
    if($remote)try{
        $doc=json_decode(remote_get(LEVELENGINE_REPOSITORY_URL,1048576),true,64,JSON_THROW_ON_ERROR);
        if(!is_array($doc)||($doc['schema']??0)!==1||!is_array($doc['packages']??null))throw new RuntimeException(le_text('Nieobsługiwany format repozytorium.'));
        $result=['packages'=>le_repository_packages($doc['packages']),'generated_at'=>is_string($doc['generated_at']??null)?mb_substr($doc['generated_at'],0,80):null,'fetched_at'=>time()];
        $dir=dirname($file);try{if(!is_dir($dir)&&!mkdir($dir,0700,true))throw new RuntimeException('cache');le_atomic_write($file,json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));}catch(Throwable $e){levelengine_log('warning','Nie można zapisać pamięci repozytorium');}
        return $result+['source'=>'remote','error'=>''];
    }catch(Throwable $e){$error=$e->getMessage();$GLOBALS['marketplace_error']=$error;}
    if($remote&&$cached&&$cached['fetched_at']>time()-86400)return $cached+['source'=>'stale','error'=>$error];
    $fallback=__DIR__.'/../marketplace/catalog.json';try{$items=le_repository_packages(json_decode((string)file_get_contents($fallback),true,64,JSON_THROW_ON_ERROR));}catch(Throwable $e){$items=[];}
    return ['source'=>'fallback','packages'=>$items,'generated_at'=>null,'fetched_at'=>null,'error'=>$error];
}
function marketplace_find(string $id): ?array {
    // An installation always checks the live catalog; cached metadata is for browsing.
    $catalog=marketplace_catalog(true,true);
    if($catalog['source']!=='remote')throw new RuntimeException(le_text('Instalacja wymaga połączenia z aktualnym repozytorium. Odśwież katalog i spróbuj ponownie.'));
    foreach($catalog['packages'] as $item)if($item['id']===$id)return $item;return null;
}
function marketplace_install(array $item): string {
    $type=(string)($item['type']??'');$id=(string)($item['id']??'');$sha=(string)($item['sha256']??'');
    if(!in_array($type,['plugin','theme'],true)||!preg_match('/^[a-zA-Z0-9_-]{1,80}$/D',$id)||!preg_match('/^[a-f0-9]{64}$/D',$sha)||empty($item['version']))throw new RuntimeException(le_text('Niekompletna paczka repozytorium.'));
    require_permission($type==='theme'?'manage_themes':'manage_plugins');le_package_requirements($item);
    return marketplace_install_bytes($item,remote_get((string)($item['download_url']??'')));
}
function marketplace_install_bytes(array $item,string $data): string {
    if(!preg_match('/^[a-f0-9]{64}$/D',(string)($item['sha256']??''))||!hash_equals((string)$item['sha256'],hash('sha256',$data)))throw new RuntimeException(le_text('SHA-256 paczki nie zgadza się z repozytorium.'));
    $tmp=tempnam(sys_get_temp_dir(),'lemp_');if(!$tmp)throw new RuntimeException(le_text('Nie można utworzyć pliku tymczasowego.'));
    try{if(file_put_contents($tmp,$data)===false)throw new RuntimeException(le_text('Nie można zapisać paczki.'));engine_install_package(['error'=>UPLOAD_ERR_OK,'tmp_name'=>$tmp],(string)$item['type'],true,$item);return (string)$item['id'];}finally{@unlink($tmp);}
}
