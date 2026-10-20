<?php
declare(strict_types=1);

function le_https(): bool {
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return true;
    $trusted = $GLOBALS['lg_config']['trusted_proxies'] ?? [];
    return in_array($_SERVER['REMOTE_ADDR'] ?? '', $trusted, true)
        && strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}
function le_atomic_write(string $file, string $data, int $mode=0600): void {
    $tmp=$file.'.'.bin2hex(random_bytes(8)).'.tmp';
    try {
        $h=fopen($tmp,'xb'); if(!$h)throw new RuntimeException('Nie można utworzyć pliku tymczasowego.');
        try { le_write_all($h,$data); if(!fflush($h))throw new RuntimeException('Nie można zapisać pliku.'); if(function_exists('fsync'))fsync($h); }
        finally { fclose($h); }
        @chmod($tmp,$mode);
        if(!rename($tmp,$file))throw new RuntimeException('Nie można zatwierdzić pliku.');
    } finally { if(is_file($tmp))unlink($tmp); }
}
function le_write_all($h,string $data): void {
    $offset=0; $size=strlen($data);
    while($offset<$size){$n=fwrite($h,substr($data,$offset));if($n===false||$n===0)throw new RuntimeException('Niepełny zapis: sprawdź miejsce na dysku.');$offset+=$n;}
}
function le_maintenance_gate(): void {
    $marker=dirname(__DIR__).'/storage/maintenance.json';
    if(!is_file($marker))return;
    $path=(string)parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH);
    if(PHP_SAPI==='cli'||$path==='/upgrade/'||$path==='/upgrade/index.php'||$path==='/upgrade/manager.php')return;
    http_response_code(503); header('Retry-After: 300');header('Cache-Control: no-store');
    exit('Trwa konserwacja strony. Spróbuj ponownie za kilka minut.');
}
function le_public_base(): string {
    $url=trim((string)($GLOBALS['lg_config']['public_url']??''));
    if(function_exists('setting')){$url=setting('public_url',$url);if($url!=='')return rtrim($url,'/');}
    if($url!=='')return rtrim($url,'/');
    throw new RuntimeException('Ustaw publiczny adres strony w Centrum → Integracje.');
}
function le_safe_tree_delete(string $directory,string $allowedBase): void {
    $base=realpath($allowedBase);$path=realpath($directory);
    if(!$base||!$path||!str_starts_with($path,$base.DIRECTORY_SEPARATOR)||is_link($directory))throw new RuntimeException('Niebezpieczny katalog.');
    foreach(new DirectoryIterator($path) as $f){if($f->isDot())continue;$p=$f->getPathname();if($f->isLink()||$f->isFile()){if(!unlink($p))throw new RuntimeException('Nie można usunąć pliku.');}else le_safe_tree_delete($p,$base);}
    if(!rmdir($path))throw new RuntimeException('Nie można usunąć katalogu.');
}
function le_suite_schema_ready(): bool {
    try{$q=db()->prepare('SELECT setting_value FROM settings WHERE setting_key=?');$q->execute(['suite_schema']);return $q->fetchColumn()==='10';}catch(Throwable $e){return false;}
}
function le_request_unlock(): void {
    $h=$GLOBALS['le_request_lock']??null;if(is_resource($h)){flock($h,LOCK_UN);fclose($h);}unset($GLOBALS['le_request_lock']);
}
function le_request_lock(bool $exclusive=false): void {
    le_request_unlock();$dir=dirname(__DIR__).'/storage';if(!is_dir($dir))return;
    $h=fopen($dir.'/requests.lock','c');if(!$h)throw new RuntimeException('Nie można zablokować operacji.');
    $until=microtime(true)+15;
    while(!flock($h,($exclusive?LOCK_EX:LOCK_SH)|LOCK_NB)){
        if(microtime(true)>$until){fclose($h);throw new RuntimeException('Trwa inna operacja konserwacji. Spróbuj ponownie.');}usleep(100000);
    }
    $GLOBALS['le_request_lock']=$h;
}
