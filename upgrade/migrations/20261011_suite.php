<?php
return static function(PDO $pdo): void {
    $GLOBALS['le_install_root']=up_root();
    // The 2.4 manager deliberately skips the root manifest. Capture the exact
    // signed bytes and commit metadata only after its successful apply step.
    $archive=dirname(__DIR__,3).'/update.zip';
    if(is_file($archive)){
        $zip=new ZipArchive();if($zip->open($archive)===true){$raw=$zip->getFromName('manifest.json');$zip->close();
            if(is_string($raw)){$m=json_decode($raw,true);$target=(string)($m['version']??'');$root=up_root();
                register_shutdown_function(static function()use($pdo,$raw,$target,$root):void{
                    try{$q=$pdo->prepare('SELECT setting_value FROM settings WHERE setting_key=?');$q->execute(['engine_version']);
                        if($q->fetchColumn()===$target&&!is_link($root.'/manifest.json'))le_atomic_write($root.'/manifest.json',$raw,0644);
                    }catch(Throwable $e){error_log('Metadata upgrade: '.$e->getMessage());}
                });
            }
        }
    }
    require_once dirname(__DIR__,2).'/lib/suite-schema.php';
    le_schema_install($pdo);
    require_once dirname(__DIR__,2).'/lib/runtime.php';require_once dirname(__DIR__,2).'/lib/integrations.php';le_vault_init();
    foreach($pdo->query("SELECT id,twofa_secret FROM users WHERE twofa_secret IS NOT NULL AND twofa_secret<>''")->fetchAll(PDO::FETCH_ASSOC) as $u){if(!str_starts_with($u['twofa_secret'],'v1:'))$pdo->prepare('UPDATE users SET twofa_secret=? WHERE id=?')->execute(['v1:'.le_encrypt($u['twofa_secret']),$u['id']]);}
};
