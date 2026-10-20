<?php
declare(strict_types=1);
function le_studio_home_revision(): string {
    $values=[];
    foreach(['hero_badge','hero_header','hero_sub','home_news_title','home_news_subtitle','home_news_button','home_news_url','home_servers_title','home_servers_subtitle','home_gallery_title','home_gallery_subtitle','home_primary_label','home_primary_url','home_secondary_label','home_secondary_url','home_sections'] as $key)$values[$key]=setting($key,'');
    return hash('sha256',json_encode($values,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
}
function le_studio_save_home(array $input): void {
    require_permission('manage_settings');
    $keys=['hero_badge','hero_header','hero_sub','home_news_title','home_news_subtitle','home_news_button','home_news_url','home_servers_title','home_servers_subtitle','home_gallery_title','home_gallery_subtitle','home_primary_label','home_primary_url','home_secondary_label','home_secondary_url'];
    $values=[];foreach($keys as $key){$value=$input[$key]??'';if(!is_string($value)||mb_strlen($value)>5000)throw new RuntimeException(le_text('Nieprawidłowe lub zbyt długie pole.'));$value=trim($value);if(str_ends_with($key,'_url')&&le_url($value,'')==='')throw new RuntimeException(le_text('Nieprawidłowy link.'));$values[$key]=$value;}
    $values['home_sections']=json_encode(['servers'=>!empty($input['home_servers']),'news'=>!empty($input['home_news']),'gallery'=>!empty($input['home_gallery'])],JSON_THROW_ON_ERROR);
    $pdo=db();$pdo->beginTransaction();try{
        $pdo->prepare("INSERT IGNORE INTO settings(setting_key,setting_value) VALUES('studio_home_lock','1')")->execute();
        $pdo->query("SELECT setting_value FROM settings WHERE setting_key='studio_home_lock' FOR UPDATE")->fetchColumn();
        if(!hash_equals(le_studio_home_revision(),(string)($input['home_revision']??'')))throw new RuntimeException(le_text('Strona główna zmieniła się w innym oknie. Otwórz aktualną wersję i porównaj zmiany.'));
        foreach($values as $key=>$value)set_setting($key,$value);$pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    audit_log('studio.home_saved','settings',null);
}
