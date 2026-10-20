<?php
declare(strict_types=1);
function le_menu_config(): array {
    $saved=setting('navigation_menu','');
    if($saved!==''){$items=json_decode($saved,true);return is_array($items)&&array_is_list($items)?$items:[];}
    $defaults=[['home','Start','/'],['news','News','/news/'],['contests','Konkursy','/konkursy/'],['events','Wydarzenia','/wydarzenia/'],['changelog','Changelog','/changelog/'],['gallery','Galeria','/galeria/']];
    $items=[];foreach($defaults as $i=>$row)$items[]=['id'=>$row[0],'label'=>$row[1],'type'=>'url','target'=>$row[2],'order'=>$i,'enabled'=>true,'new_tab'=>false,'builtin'=>true];
    foreach(db()->query("SELECT id,title FROM pages WHERE deleted_at IS NULL AND status='published' AND show_in_menu=1 ORDER BY menu_order ASC,title ASC")->fetchAll() as $page)$items[]=['id'=>'page-'.(int)$page['id'],'label'=>$page['title'],'type'=>'page','target'=>(int)$page['id'],'order'=>count($items),'enabled'=>true,'new_tab'=>false,'auto_page'=>true];
    return $items;
}
function le_menu_revision(array $items): string {return hash('sha256',json_encode($items,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));}
function le_menu_store(array $items,string $revision): void {
    $pdo=db();$pdo->beginTransaction();
    try{
        $pdo->prepare("INSERT IGNORE INTO settings(setting_key,setting_value) VALUES('navigation_menu','')")->execute();
        $pdo->query("SELECT setting_value FROM settings WHERE setting_key='navigation_menu' FOR UPDATE")->fetchColumn();
        if(!hash_equals(le_menu_revision(le_menu_config()),$revision))throw new RuntimeException(le_text('Menu zmieniło się w innym oknie. Odśwież stronę.'));
        set_setting('navigation_menu',json_encode(array_values($items),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));$pdo->commit();
    }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
}
function le_menu_public_file(array $plugin): ?string {return le_plugin_file($plugin,'public')??le_plugin_file($plugin,'frontend');}
function le_menu_target(array $item): ?string {
    $type=$item['type']??'';$target=$item['target']??'';
    if($type==='url')return le_url((string)$target,'')?:null;
    if($type==='page'){$q=db()->prepare("SELECT slug FROM pages WHERE deleted_at IS NULL AND status='published' AND id=?");$q->execute([(int)$target]);$slug=$q->fetchColumn();return $slug!==false?'/page/'.rawurlencode((string)$slug):null;}
    if($type==='plugin'){$q=db()->prepare('SELECT * FROM plugins WHERE plugin_key=? AND enabled=1');$q->execute([(string)$target]);$plugin=$q->fetch();return $plugin&&le_menu_public_file($plugin)?'/plugin/'.rawurlencode((string)$target):null;}
    return null;
}
function le_menu_save(array $fields): void {
    $items=le_menu_config();$id=(string)($fields['menu_id']??'');$label=trim((string)($fields['label']??''));$type=(string)($fields['target_type']??'url');
    if($label===''||mb_strlen($label)>80)throw new RuntimeException(le_text('Nazwa menu jest wymagana i może mieć do 80 znaków.'));
    if(!in_array($type,['url','page','plugin'],true))throw new RuntimeException(le_text('Nieprawidłowy cel menu.'));
    $target=$type==='page'?(int)($fields['target_page']??0):trim((string)($fields[$type==='plugin'?'target_plugin':'target_url']??''));
    if(is_string($target)&&strlen($target)>2048)throw new RuntimeException(le_text('Nieprawidłowy cel menu.'));
    $item=['id'=>$id?:bin2hex(random_bytes(8)),'label'=>$label,'type'=>$type,'target'=>$target,'order'=>(int)($fields['menu_order']??count($items)),'enabled'=>!empty($fields['enabled']),'new_tab'=>!empty($fields['new_tab'])];
    if(!le_menu_target($item))throw new RuntimeException(le_text('Wybierz istniejącą podstronę, aktywny plugin ze stroną publiczną lub poprawny adres URL.'));
    if($id!==''){$found=false;foreach($items as $i=>$old)if(($old['id']??'')===$id){$item['builtin']=!empty($old['builtin'])&&$label===$old['label'];$items[$i]=$item;$found=true;break;}if(!$found)throw new RuntimeException(le_text('Pozycja menu nie istnieje.'));}
    else {if(count($items)>=50)throw new RuntimeException(le_text('Menu może zawierać maksymalnie 50 pozycji.'));$items[]=$item;}
    usort($items,fn($a,$b)=>(($a['order']??0)<=>($b['order']??0)));le_menu_store($items,(string)($fields['menu_revision']??''));
}
function le_menu_delete(string $id,string $revision): void {
    $items=le_menu_config();$remaining=array_values(array_filter($items,fn($item)=>($item['id']??'')!==$id));
    if(count($remaining)===count($items))throw new RuntimeException(le_text('Pozycja menu nie istnieje.'));
    le_menu_store($remaining,$revision);
}
function le_menu_sync_page(int $id,bool $visible,string $title='',int $order=0): void {
    if(setting('navigation_menu','')==='')return;
    $pdo=db();$pdo->beginTransaction();
    try{
    $pdo->query("SELECT setting_value FROM settings WHERE setting_key='navigation_menu' FOR UPDATE")->fetchColumn();
    $items=le_menu_config();$matched=false;$changed=false;
    foreach($items as $i=>$item){
        if(($item['type']??'')!=='page'||(int)($item['target']??0)!==$id)continue;
        $matched=true;if(empty($item['auto_page']))continue;
        if(!$visible)unset($items[$i]);else{$items[$i]['label']=$title;$items[$i]['order']=$order;}$changed=true;
    }
    if(!$matched&&$visible){$items[]=['id'=>'page-'.$id,'label'=>$title,'type'=>'page','target'=>$id,'order'=>$order,'enabled'=>true,'new_tab'=>false,'auto_page'=>true];$changed=true;}
    if($changed)set_setting('navigation_menu',json_encode(array_values($items),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    $pdo->commit();
    }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
}
function le_render_menu(): void {
    $items=le_menu_config();usort($items,fn($a,$b)=>(($a['order']??0)<=>($b['order']??0)));
    foreach($items as $item){if(empty($item['enabled']))continue;$url=le_menu_target($item);if(!$url)continue;
        $label=!empty($item['builtin'])?le_text((string)$item['label']):(string)$item['label'];
        $translations=json_decode(setting('menu_translations','{}'),true)?:[];$label=$translations[$item['id']][le_locale()]??$label;
        echo '<a href="'.e($url).'"'.(!empty($item['new_tab'])?' target="_blank" rel="noopener noreferrer"':'').'>'.e($label).'</a>';
    }
}
