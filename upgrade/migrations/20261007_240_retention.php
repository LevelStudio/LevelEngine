<?php
declare(strict_types=1);
// Older managers execute migrations before replacing themselves. Prune only
// at shutdown after version metadata confirms the entire update succeeded.
return static function(PDO $pdo): void {
    $root=up_root();
    register_shutdown_function(static function()use($pdo,$root):void {
        $lock=null;
        try {
            $lock=fopen($root.'/upgrade/runtime/upgrade.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))return;
            $q=$pdo->prepare('SELECT setting_value FROM settings WHERE setting_key=?');$q->execute(['engine_version']);
            if($q->fetchColumn()!=='2.4.0')return;
            $q->execute(['last_upgrade_backup']);$stamp=(string)$q->fetchColumn();
            if(!preg_match('/^\d{8}_\d{6}_[a-f0-9]{6}$/D',$stamp))return;
            $path=$root.'/upgrade/backups/'.$stamp.'/manifest.json';
            $meta=is_file($path)?json_decode((string)file_get_contents($path),true):null;
            if(!is_array($meta)||($meta['to']??'')!=='2.4.0')return;
            if(!function_exists('up_prune_backups'))require_once $root.'/lib/upgrade-backups.php';
            up_prune_backups($root.'/upgrade/backups',$stamp);
        }catch(Throwable $error){error_log('LevelEngine backup cleanup: '.$error->getMessage());}
        finally{if(is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}}
    });
};
