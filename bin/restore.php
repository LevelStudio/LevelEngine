<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/lib/bootstrap.php';
try{
    if(($argv[1]??'')!=='--confirm-data-loss')throw new RuntimeException('Użycie: php bin/restore.php --confirm-data-loss nazwa.sql. Przywraca bazę, usuwa późniejsze dane. Wymaga zgodnej wersji CMS i zachowanego storage/vault.key.');
    $name=(string)($argv[2]??'');if(!preg_match('/^[A-Za-z0-9_.-]+\.sql$/D',$name))throw new RuntimeException('Nieprawidłowa nazwa kopii.');
    $file=dirname(__DIR__).'/backups/'.$name;$meta=le_backup_validate($file);
    if(($meta['cms_version']??'')!==setting('engine_version'))throw new RuntimeException('Najpierw przywróć zgodne pliki rdzenia CMS.');
    le_request_lock(true);le_atomic_write(dirname(__DIR__).'/storage/maintenance.json',json_encode(['since'=>date('c'),'reason'=>'cli_restore']));
    $rescue=dirname(__DIR__).'/backups/rescue-'.date('Ymd-His').'-'.bin2hex(random_bytes(3)).'.sql';
    le_backup_database(db(),$rescue);fwrite(STDOUT,'Kopia przed przywróceniem: '.basename($rescue).PHP_EOL);
    le_backup_restore(db(),$file);db()->exec('UPDATE users SET session_version=session_version+1');
    unlink(dirname(__DIR__).'/storage/maintenance.json');echo 'Przywrócono bazę. Zaloguj się ponownie.'.PHP_EOL;
}catch(Throwable $e){fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);}
