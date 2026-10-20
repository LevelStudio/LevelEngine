<?php
declare(strict_types=1);
function le_kind_table(string $kind): string {
    if(!in_array($kind,['post','page'],true))throw new InvalidArgumentException('Nieprawidłowy typ treści.');
    return $kind==='post'?'posts':'pages';
}
function le_content_permission(string $kind): void { le_kind_table($kind);require_permission($kind==='post'?'manage_content':'manage_pages'); }
function le_content_row(string $kind,int $id,bool $lock=false): ?array {
    $table=le_kind_table($kind);$q=db()->prepare("SELECT * FROM `$table` WHERE id=?".($lock?' FOR UPDATE':''));$q->execute([$id]);return $q->fetch()?:null;
}
function le_content_duplicate(string $kind,int $id): int {
    le_content_permission($kind);$table=le_kind_table($kind);$pdo=db();$pdo->beginTransaction();
    try {
        $row=le_content_row($kind,$id,true);
        if(!$row||$row['deleted_at']!==null)throw new RuntimeException('Treść nie istnieje lub jest w koszu.');
        $title=mb_substr($row['title'],0,245).' — kopia';
        $keys=['content','content_format','seo_title','seo_description'];
        $keys=array_merge($keys,$kind==='post'?['category','excerpt','thumbnail']:[]);
        $values=array_intersect_key($row,array_flip($keys));
        $values+=['title'=>$title,'slug'=>unique_slug($title,0,$table),'status'=>'draft'];
        if($kind==='post')$values['author_id']=current_user()['id'];
        $columns=implode(',',array_keys($values));$marks=implode(',',array_fill(0,count($values),'?'));
        $pdo->prepare("INSERT INTO `$table`($columns) VALUES($marks)")->execute(array_values($values));
        $new=(int)$pdo->lastInsertId();$pdo->commit();
    }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
    audit_log('content.duplicated',$kind,(string)$new,['source'=>$id]);return $new;
}
function le_revision(string $kind,array $row): void {
    db()->prepare('INSERT INTO content_revisions(kind,object_id,snapshot,created_by) VALUES(?,?,?,?)')->execute([$kind,$row['id'],json_encode($row,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),current_user()['id']??null]);
}
function le_publication_notice(string $kind,array $row): void {
    if($row['status']!=='published'||$row['deleted_at']!==null)return;
    if(setting('discord_webhook','')==='')return;
    $key='publication:'.$kind.':'.$row['id'].':'.$row['edit_version'];
    le_enqueue($key,'discord',['title'=>$row['title'],'path'=>'/'.$kind.'/'.$row['slug']]);
}
function le_content_save(string $kind,array $fields): int {
    le_content_permission($kind);$table=le_kind_table($kind);$id=(int)($fields['id']??0);
    $title=trim((string)($fields['title']??''));$content=(string)($fields['content']??'');
    if($title===''||mb_strlen($title)>255||strlen($content)>2*1024*1024)throw new RuntimeException('Podaj tytuł do 255 znaków i treść do 2 MB.');
    $format=($fields['content_format']??'html')==='markdown'?'markdown':'html';
    if(($fields['content_format']??'')==='blocks'){if($kind!=='page')throw new RuntimeException('Kreator blokowy obsługuje podstrony.');$content=le_studio_json(le_studio_document($content));$format='blocks';}
    $status=(string)($fields['status']??'draft');if(!in_array($status,['draft','published','scheduled'],true))throw new RuntimeException('Nieprawidłowy status.');
    $publish=null;if($status==='scheduled'){
        $date=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',(string)($fields['publish_at']??''));
        $errors=DateTimeImmutable::getLastErrors();
        if(!$date||($errors&&($errors['warning_count']||$errors['error_count']))||$date->getTimestamp()<=time())throw new RuntimeException('Wybierz przyszły termin publikacji.');
        $publish=$date->format('Y-m-d H:i:s');
    }
    $slug=unique_slug((string)(($fields['slug']??'')?:$title),$id,$table);
    $values=['title'=>$title,'slug'=>$slug,'content'=>$format==='html'?safe_content($content):$content,'content_format'=>$format,'seo_title'=>mb_substr(trim((string)($fields['seo_title']??'')),0,255),'seo_description'=>mb_substr(trim((string)($fields['seo_description']??'')),0,500),'status'=>$status,'publish_at'=>$publish];
    if($kind==='post'){
        $cat=(string)($fields['category']??'news');if(!isset(post_categories()[$cat]))throw new RuntimeException('Nieprawidłowa kategoria.');
        $thumb=le_media_thumbnail((string)($fields['existing_thumbnail']??''),$id);
        if(isset($_FILES['thumbnail'])&&($_FILES['thumbnail']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE){require_permission('manage_media');$thumb=le_media_upload($_FILES['thumbnail'],$title)['path'];}
        $values+=['category'=>$cat,'excerpt'=>mb_substr(trim((string)($fields['excerpt']??'')),0,5000),'featured'=>!empty($fields['featured'])?1:0,'thumbnail'=>$thumb?:null];
    }else $values+=['show_in_menu'=>!empty($fields['show_in_menu'])?1:0,'menu_order'=>(int)($fields['menu_order']??0)];
    $pdo=db();$pdo->beginTransaction();
    try{
        $old=$id?le_content_row($kind,$id,true):null;
        if($id&&(!$old||$old['deleted_at']!==null))throw new RuntimeException('Treść nie istnieje lub jest w koszu.');
        if($old&&(int)($fields['edit_version']??0)!==(int)$old['edit_version'])throw new RuntimeException('Ktoś zmienił treść. Twoje dane pozostają w formularzu; otwórz aktualną wersję w drugim oknie i porównaj.');
        if($old){le_revision($kind,$old);$sets=implode(',',array_map(fn($k)=>"`$k`=?",array_keys($values)));$pdo->prepare("UPDATE `$table` SET $sets,edit_version=edit_version+1 WHERE id=?")->execute([...array_values($values),$id]);}
        else{if($kind==='post')$values['author_id']=current_user()['id'];$columns=implode(',',array_keys($values));$qs=implode(',',array_fill(0,count($values),'?'));$pdo->prepare("INSERT INTO `$table`($columns) VALUES($qs)")->execute(array_values($values));$id=(int)$pdo->lastInsertId();}
        $row=le_content_row($kind,$id);if($status==='published'&&(!$old||$old['status']!=='published'))le_publication_notice($kind,$row);
        $pdo->prepare('DELETE FROM content_autosaves WHERE user_id=? AND kind=? AND object_id=?')->execute([current_user()['id'],$kind,(int)($fields['id']??0)]);
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    if($kind==='page')le_menu_sync_page($id,(bool)$values['show_in_menu'],$title,$values['menu_order']);
    audit_log($old?'content.updated':'content.created',$kind,(string)$id,['version'=>$row['edit_version'],'status'=>$status]);return $id;
}
function le_content_restore(string $kind,int $id,int $revision,int $expected): void {
    le_content_permission($kind);$table=le_kind_table($kind);$pdo=db();$pdo->beginTransaction();
    try{
        $row=le_content_row($kind,$id,true);if(!$row||(int)$row['edit_version']!==$expected)throw new RuntimeException('Treść zmieniła się. Odśwież historię.');
        $q=$pdo->prepare('SELECT snapshot FROM content_revisions WHERE id=? AND kind=? AND object_id=?');$q->execute([$revision,$kind,$id]);$snapshot=$q->fetchColumn();if(!$snapshot)throw new RuntimeException('Brak wersji.');
        $old=json_decode($snapshot,true,512,JSON_THROW_ON_ERROR);le_revision($kind,$row);
        $allowed=['title','slug','content','content_format','seo_title','seo_description','category','excerpt','featured','thumbnail','show_in_menu','menu_order'];
        $old=array_intersect_key($old,array_flip($allowed));$sets=implode(',',array_map(fn($k)=>"`$k`=?",array_keys($old)));
        // A restored snapshot is always a draft; it cannot silently publish old content.
        $old['slug']=unique_slug((string)($old['slug']??$row['slug']),$id,$table);$sets=implode(',',array_map(fn($k)=>"`$k`=?",array_keys($old)));
        $pdo->prepare("UPDATE `$table` SET $sets,status='draft',publish_at=NULL,deleted_at=NULL,edit_version=edit_version+1 WHERE id=?")->execute([...array_values($old),$id]);$pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    audit_log('content.revision_restored',$kind,(string)$id,['revision'=>$revision]);
}
function le_content_trash(string $kind,int $id,bool $restore=false): void {
    le_content_permission($kind);$table=le_kind_table($kind);$pdo=db();$pdo->beginTransaction();
    try{$row=le_content_row($kind,$id,true);if(!$row)throw new RuntimeException('Brak treści.');le_revision($kind,$row);$pdo->prepare("UPDATE `$table` SET deleted_at=".($restore?'NULL':'NOW()').",status='draft',publish_at=NULL,edit_version=edit_version+1 WHERE id=?")->execute([$id]);$pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    audit_log($restore?'content.untrashed':'content.trashed',$kind,(string)$id);
}
function le_editor_suite_fields(string $kind,array $row): void {
    $id=(int)($row['id']??0);$version=(int)($row['edit_version']??1);
    echo '<input type="hidden" name="edit_version" value="'.$version.'"><input type="hidden" name="kind" value="'.e($kind).'">';
    echo '<label>Termin publikacji ('.e(date_default_timezone_get()).')<input class="input" type="datetime-local" name="publish_at" value="'.e(!empty($row['publish_at'])?date('Y-m-d\TH:i',strtotime($row['publish_at'])):'').'"></label>';
    if($kind==='page'){echo '<label>Status<select class="input" name="status">';foreach(['draft'=>'Szkic','published'=>'Opublikowana','scheduled'=>'Zaplanowana'] as $k=>$label)echo '<option value="'.$k.'" '.(($row['status']??'published')===$k?'selected':'').'>'.e(le_text($label)).'</option>';echo '</select></label>';}
    echo ('<p><span data-autosave-status role="status">'.e(le_text('Automatyczny zapis kopii roboczej')).'</span> <button class="btn" type="button" data-load-autosave>'.e(le_text('Odzyskaj kopię roboczą')).'</button></p>');
    if($id)echo '<p><a class="btn" href="/admin/suite.php?section=history&amp;kind='.e($kind).'&amp;id='.$id.('">'.e(le_text('Historia i tłumaczenia')).'</a> <a class="btn" target="_blank" rel="noopener" href="/preview?kind=').e($kind).'&amp;id='.$id.('">'.e(le_text('Podgląd zapisanej treści')).'</a></p>');
}
function le_translation_save(string $kind,int $id,string $locale,array $fields): void {
    le_content_permission($kind);$title=trim((string)($fields['title']??''));$content=(string)($fields['content']??'');
    if(!le_valid_locale($locale)||$title===''||mb_strlen($title)>255||strlen($content)>2000000)throw new RuntimeException('Nieprawidłowe tłumaczenie.');
    $slug=slugify(trim((string)($fields['slug']??''))?:$title);$description=mb_substr((string)($fields['seo_description']??''),0,500);$pdo=db();$pdo->beginTransaction();
    try{
        if(!le_content_row($kind,$id,true))throw new RuntimeException('Brak treści.');
        $q=$pdo->prepare('SELECT 1 FROM content_translations WHERE kind=? AND object_id=? AND locale=?');$q->execute([$kind,$id,$locale]);
        if($q->fetchColumn())$pdo->prepare('UPDATE content_translations SET title=?,slug=?,content=?,seo_description=? WHERE kind=? AND object_id=? AND locale=?')->execute([$title,$slug,safe_content($content),$description,$kind,$id,$locale]);
        else $pdo->prepare('INSERT INTO content_translations(kind,object_id,locale,title,slug,content,seo_description) VALUES(?,?,?,?,?,?,?)')->execute([$kind,$id,$locale,$title,$slug,safe_content($content),$description]);
        $pdo->commit();audit_log('content.translation_saved',$kind,(string)$id,['locale'=>$locale]);
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();if($e instanceof PDOException&&$e->getCode()==='23000')throw new RuntimeException('Ten adres tłumaczenia jest już zajęty.');throw $e;}
}
function le_apply_translation(): void {
    if(empty($GLOBALS['post'])&&empty($GLOBALS['page']))return;
    $kind=!empty($GLOBALS['post'])?'post':'page';$key=$kind==='post'?'post':'page';$row=$GLOBALS[$key];
    $q=db()->prepare('SELECT * FROM content_translations WHERE kind=? AND object_id=? AND locale=?');$q->execute([$kind,$row['id'],le_locale()]);$t=$q->fetch();
    if($t){foreach(['title','content','seo_description'] as $field)$GLOBALS[$key][$field]=$t[$field];$GLOBALS[$key]['content_format']='html';$GLOBALS['title']=$t['title'];}
}
function le_translation_links(): void {
    $key=!empty($GLOBALS['post'])?'post':(!empty($GLOBALS['page'])?'page':'');if($key==='')return;
    $q=db()->prepare('SELECT locale,slug FROM content_translations WHERE kind=? AND object_id=?');$q->execute([$key,$GLOBALS[$key]['id']]);
    foreach($q as $row)echo '<link rel="alternate" hreflang="'.e($row['locale']).'" href="'.e('/'.$row['locale'].'/'.$key.'/'.$row['slug']).'">';
}
