<?php
declare(strict_types=1);
function le_media_upload(array $file,string $title='',string $alt=''): array {
    $title=trim($title);$alt=trim($alt);
    if(mb_strlen($title)>160||mb_strlen($alt)>255)throw new RuntimeException(le_text('Tytuł może mieć do 160 znaków, a opis ALT do 255.'));
    if($title==='')$title=mb_substr(pathinfo(basename(str_replace('\\','/',(string)($file['name']??'Obrazek'))),PATHINFO_FILENAME),0,160);
    $up=upload_image($file,'media');
    try{
        le_image_derivative($up['path']);
        $dimensions=@getimagesize(__DIR__.'/..'.$up['path']);
        db()->prepare('INSERT INTO media(path,title,alt_text,mime,size_bytes,width,height) VALUES(?,?,?,?,?,?,?)')->execute([$up['path'],$title,$alt,$up['mime'],$up['size'],(int)($dimensions[0]??0),(int)($dimensions[1]??0)]);
        return ['id'=>(int)db()->lastInsertId(),'path'=>$up['path'],'title'=>$title,'alt_text'=>$alt,'mime'=>$up['mime'],'size_bytes'=>$up['size']];
    }catch(Throwable $e){$path=engine_upload_path($up['path']);if($path&&is_file($path)){unlink($path);$thumb=substr($path,0,strrpos($path,'.')).'.thumb.webp';if(is_file($thumb))unlink($thumb);}throw $e;}
}
function le_media_list(string $search='',int $offset=0): array {
    $search=mb_substr(trim($search),0,160);$offset=max(0,min(100000,$offset));
    $where='';$args=[];
    if($search!==''){$where=' WHERE title LIKE ? OR alt_text LIKE ?';$args=['%'.$search.'%','%'.$search.'%'];}
    $q=db()->prepare('SELECT id,path,title,alt_text,mime,size_bytes,created_at FROM media'.$where.' ORDER BY created_at DESC,id DESC LIMIT 41 OFFSET '.$offset);
    $q->execute($args);$items=$q->fetchAll();$more=count($items)>40;
    return ['items'=>array_slice($items,0,40),'has_more'=>$more,'next_offset'=>$offset+40];
}
function le_media_thumbnail(string $path,int $postId=0): string {
    if($path==='')return '';
    // Preserve an existing thumbnail even if it predates the media library.
    if($postId>0){$q=db()->prepare('SELECT thumbnail FROM posts WHERE id=?');$q->execute([$postId]);if($q->fetchColumn()===$path)return $path;}
    if(!preg_match('~^/uploads/(?:media|posts|gallery|avatars)/[a-zA-Z0-9._-]+\.(?:jpg|jpeg|png|gif|webp)$~i',$path)||!engine_upload_path($path))throw new RuntimeException(le_text('Wybierz miniaturę z biblioteki mediów.'));
    $q=db()->prepare('SELECT id FROM media WHERE path=?');$q->execute([$path]);
    if(!$q->fetchColumn())throw new RuntimeException(le_text('Miniatura nie istnieje w bibliotece.'));return $path;
}
function le_media_usage(string $path): int {
    $like='%'.str_replace(['=','%','_'],['==','=%','=_'],$path).'%';$total=0;
    $queries=[
        ["SELECT COUNT(*) FROM posts WHERE thumbnail=? OR og_image=? OR content LIKE ? ESCAPE '='",[$path,$path,$like]],
        ["SELECT COUNT(*) FROM pages WHERE og_image=? OR content LIKE ? ESCAPE '='",[$path,$like]],
        ['SELECT COUNT(*) FROM gallery WHERE image=?',[$path]],
        ['SELECT COUNT(*) FROM post_images WHERE path=?',[$path]],
        ["SELECT COUNT(*) FROM content_translations WHERE content LIKE ? ESCAPE '='",[$like]],
        ["SELECT COUNT(*) FROM content_revisions WHERE JSON_UNQUOTE(JSON_EXTRACT(snapshot,'$.content')) LIKE ? ESCAPE '=' OR JSON_UNQUOTE(JSON_EXTRACT(snapshot,'$.thumbnail'))=? OR JSON_UNQUOTE(JSON_EXTRACT(snapshot,'$.og_image'))=?",[$like,$path,$path]],
        ["SELECT COUNT(*) FROM content_autosaves WHERE JSON_UNQUOTE(JSON_EXTRACT(snapshot,'$.content')) LIKE ? ESCAPE '=' OR JSON_UNQUOTE(JSON_EXTRACT(snapshot,'$.existing_thumbnail'))=?",[$like,$path]],
    ];
    foreach($queries as [$sql,$args]){$q=db()->prepare($sql);$q->execute($args);$total+=(int)$q->fetchColumn();}
    return $total;
}
function le_media_delete(int $id): void {
    $q=db()->prepare('SELECT path FROM media WHERE id=?');$q->execute([$id]);$m=$q->fetch();
    if(!$m)throw new RuntimeException(le_text('Obrazek nie istnieje.'));
    if(le_media_usage($m['path'])>0)throw new RuntimeException(le_text('Obrazek jest używany w treści, miniaturze lub galerii. Najpierw usuń go z tych miejsc.'));
    $file=engine_upload_path($m['path']);
    if($file&&is_file($file)&&!unlink($file))throw new RuntimeException(le_text('Nie udało się usunąć pliku. Sprawdź prawa zapisu.'));
    if($file){$thumb=substr($file,0,strrpos($file,'.')).'.thumb.webp';if(is_file($thumb)&&!unlink($thumb))throw new RuntimeException('Nie można usunąć miniatury.');}
    db()->prepare('DELETE FROM media WHERE id=?')->execute([$id]);
}
