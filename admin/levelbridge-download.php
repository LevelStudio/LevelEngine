<?php
declare(strict_types=1);
require __DIR__.'/../lib/bootstrap.php';start_session();require_permission('manage_servers');
header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
try{
    $package=levelbridge_package();$data=remote_get((string)($package['download_url']??''));
    if(!hash_equals($package['sha256'],hash('sha256',$data))||!str_starts_with($data,"PK\x03\x04"))throw new RuntimeException(le_text('Pobrany JAR ma nieprawidłową sumę SHA-256 lub format.'));
    header('Content-Type: application/java-archive');header('Content-Disposition: attachment; filename="LevelBridge-'.$package['version'].'.jar"');header('Content-Length: '.strlen($data));echo $data;exit;
}catch(Throwable $e){$_SESSION['levelbridge_download_error']=$e->getMessage();redirect(admin_url('servers'));}
