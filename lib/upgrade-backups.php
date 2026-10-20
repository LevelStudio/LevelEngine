<?php
declare(strict_types=1);
// Only timestamped Upgrade Manager backups are eligible; never follow symlinks.
function up_prune_backups(string $directory,string $protected=''): array {
    if(is_link($directory))throw new RuntimeException(le_text('Niebezpieczny katalog kopii zapasowych.'));
    $base=realpath($directory);if($base===false)return ['removed'=>0,'kept'=>0];
    $candidates=[];
    foreach(scandir($base)?:[] as $name){
        if(!preg_match('/^\d{8}_\d{6}_[a-f0-9]{6}$/D',$name))continue;
        $path=$base.DIRECTORY_SEPARATOR.$name;
        if(is_link($path)||!is_dir($path))continue;
        $resolved=realpath($path);
        if(!$resolved||dirname($resolved)!==$base)continue;
        $candidates[$name]=$resolved;
    }
    krsort($candidates,SORT_STRING);$keep=[];
    if(isset($candidates[$protected]))$keep[$protected]=true;
    foreach($candidates as $name=>$path){if(count($keep)>=2)break;$keep[$name]=true;}
    // Validate every target before beginning recursive removal.
    $remove=array_diff_key($candidates,$keep);
    foreach($remove as $path)if(dirname((string)realpath($path))!==$base||is_link($path))throw new RuntimeException(le_text('Niebezpieczny katalog kopii zapasowych.'));
    $delete=function(string $path)use(&$delete):void {
        if(is_link($path)||is_file($path)){if(!unlink($path))throw new RuntimeException(le_text('Nie można usunąć starej kopii zapasowej.'));return;}
        foreach(scandir($path)?:[] as $name)if($name!=='.'&&$name!=='..')$delete($path.DIRECTORY_SEPARATOR.$name);
        if(!rmdir($path))throw new RuntimeException(le_text('Nie można usunąć starej kopii zapasowej.'));
    };
    foreach($remove as $path)$delete($path);
    return ['removed'=>count($remove),'kept'=>count($keep)];
}
