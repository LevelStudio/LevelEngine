<?php
declare(strict_types=1);

function le_password_check(string $password): void {
    if(strlen($password)<10||strlen($password)>72)throw new RuntimeException(le_text('Hasło musi mieć od 10 do 72 bajtów (polskie znaki mogą zajmować więcej niż jeden).'));
}

function le_url(string $url, string $fallback='#'): string {
    $url=trim($url);
    if($url===''||preg_match('/[\x00-\x20\x7f\\\\]/',$url))return $fallback;
    if(str_starts_with($url,'#'))return $url;
    if(str_starts_with($url,'/')&&!str_starts_with($url,'//'))return $url;
    return preg_match('~^https?://~i',$url)&&filter_var($url,FILTER_VALIDATE_URL)?$url:$fallback;
}
function le_origin(): string { return le_public_base(); }

function le_footer_links(string $raw): array {
    if(!is_array(json_decode($raw,false,512,JSON_THROW_ON_ERROR)))throw new RuntimeException(le_text('Linki stopki muszą być listą JSON.'));
    $links=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
    if(!is_array($links)||!array_is_list($links))throw new RuntimeException(le_text('Linki stopki muszą być listą JSON.'));
    foreach($links as $link)if(!is_array($link)||!is_string($link['label']??null)||trim($link['label'])===''||!is_string($link['url']??null)||le_url($link['url'],'')==='')throw new RuntimeException(le_text('Każdy link stopki wymaga nazwy i adresu http(s), / lub #.'));
    return $links;
}
function le_head(): void {
    $u=require_admin();if((int)$u['role_id']!==1){http_response_code(403);exit(le_text('Tylko Head Admin ma dostęp do tej operacji.'));}
}
function le_user_update(array $admin,int $id,int $role,string $status): void {
    if($id===(int)$admin['id'])throw new RuntimeException(le_text('Nie możesz zmienić własnej rangi/statusu tutaj.'));
    $pdo=db();$pdo->beginTransaction();
    try {
        // Lock the protected accounts in the same order for concurrent changes.
        $heads=$pdo->query('SELECT id,status FROM users WHERE role_id=1 ORDER BY id FOR UPDATE')->fetchAll();
        $q=$pdo->prepare('SELECT id,role_id,status FROM users WHERE id=? FOR UPDATE');$q->execute([$id]);$target=$q->fetch();
        if(!$target)throw new RuntimeException(le_text('Użytkownik nie istnieje.'));
        $q=$pdo->prepare('SELECT id,permissions FROM roles WHERE id=?');$q->execute([$role]);$newRole=$q->fetch();
        if(!$newRole)throw new RuntimeException(le_text('Ranga nie istnieje.'));
        if((int)$admin['role_id']!==1){
            if((int)$target['role_id']===1||$role===1)throw new RuntimeException(le_text('Tylko Head Admin może zarządzać kontami Head Adminów.'));
            $q=$pdo->prepare('SELECT permissions FROM roles WHERE id=?');$q->execute([$target['role_id']]);$old=json_decode((string)$q->fetchColumn(),true)?:[];
            foreach([$old,json_decode((string)$newRole['permissions'],true)?:[]] as $permissions)foreach($permissions as $key=>$value)if($value&&!has_permission($key,$admin))throw new RuntimeException(le_text('Nie możesz zarządzać kontem o wyższych uprawnieniach ani nadawać uprawnień, których nie masz.'));
        }
        $active=count(array_filter($heads,fn($u)=>$u['status']==='active'));
        if((int)$target['role_id']===1&&$target['status']==='active'&&($role!==1||$status!=='active')&&$active<=1)throw new RuntimeException(le_text('Nie można usunąć ostatniego aktywnego Head Admina.'));
        $pdo->prepare('UPDATE users SET role_id=?,status=?,session_version=session_version+1 WHERE id=?')->execute([$role,$status,$id]);$pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function safe_content(string $html): string {
    if(!class_exists('DOMDocument'))return nl2br(e(strip_tags($html)));
    $doc=new DOMDocument('1.0','UTF-8');$before=libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8"><div>'.$html.'</div>',LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);
    libxml_clear_errors();libxml_use_internal_errors($before);
    $allowed=['h1','h4','h5','h6','p','br','strong','em','u','s','ul','ol','li','a','h2','h3','blockquote','img','hr','code','pre','span','div'];
    $render=function(DOMNode $node)use(&$render,$allowed):string {
        if($node instanceof DOMText)return e($node->nodeValue);
        if(!($node instanceof DOMElement))return '';
        $tag=strtolower($node->tagName);
        if(in_array($tag,['script','style','iframe','object','embed','svg','math','template'],true))return '';
        $inner='';foreach($node->childNodes as $child)$inner.=$render($child);
        if(!in_array($tag,$allowed,true))return $inner;
        if($tag==='img'){
            $src=le_url($node->getAttribute('src'),'');
            if($src===''||!(str_starts_with($src,'/uploads/')||str_starts_with(strtolower($src),'https://')))return '';
            return '<img src="'.e($src).'" alt="'.e($node->getAttribute('alt')).'" loading="lazy" class="content-image">';
        }
        if($tag==='br'||$tag==='hr')return '<'.$tag.'>';
        $attrs='';if($tag==='a'){$href=le_url($node->getAttribute('href'),'');if($href!=='')$attrs=' href="'.e($href).'" target="_blank" rel="noopener noreferrer"';}
        if(in_array($tag,['p','div','span','h1','h2','h3','h4','h5','h6','blockquote','li'],true)&&preg_match('/(?:^|;)\s*text-align\s*:\s*(left|center|right|justify)\s*(?:;|$)/i',$node->getAttribute('style'),$alignment))$attrs.=' style="text-align:'.strtolower($alignment[1]).'"';
        return '<'.$tag.$attrs.'>'.$inner.'</'.$tag.'>';
    };
    $out='';$body=$doc->getElementsByTagName('body')->item(0);if($body){$wrapper=$body->firstChild;if($wrapper)foreach($wrapper->childNodes as $child)$out.=$render($child);}return $out;
}
function le_plugin_file(array $plugin,string $entry): ?string {
    $key=(string)($plugin['plugin_key']??'');if(!preg_match('/^[a-zA-Z0-9_-]{1,80}$/',$key))return null;
    $base=realpath(__DIR__.'/../plugins/'.$key);if(!$base)return null;
    $manifest=json_decode((string)($plugin['manifest_json']??'{}'),true)?:[];
    $value=$manifest[$entry]??($entry==='bootstrap'?($manifest['entry']??$manifest['main']??null):null);
    if(is_array($value))$value=$value['file']??$value['path']??$value['entry']??null;
    if(!is_string($value)||$value===''||str_contains($value,"\0")||str_contains($value,'\\')||preg_match('~(^|/)\.\.?(/|$)~',$value)||str_starts_with($value,'/'))return null;
    $file=realpath($base.'/'.$value);
    return $file&&is_file($file)&&str_starts_with($file,$base.DIRECTORY_SEPARATOR)&&strtolower(pathinfo($file,PATHINFO_EXTENSION))==='php'?$file:null;
}
function le_plugins_boot(): void {
    static $started=false;if($started)return;$started=true;
    foreach(db()->query('SELECT * FROM plugins WHERE enabled=1 ORDER BY id')->fetchAll() as $plugin){
        $file=le_plugin_file($plugin,'bootstrap');if(!$file)continue;
        try{(static function(string $file,array $plugin){require_once $file;})($file,$plugin);}catch(Throwable $e){levelengine_log('error','Błąd pluginu',['plugin'=>$plugin['plugin_key'],'error'=>$e->getMessage()]);}
    }
}
