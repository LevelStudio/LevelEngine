<?php
declare(strict_types=1);

// Block documents remain in pages.content, so revisions, scheduling and backups work unchanged.
function le_studio_types(): array {
    return array_map('le_text',['hero'=>'Baner główny','text'=>'Tekst','features'=>'Oferta / kolumny','pricing'=>'Cennik','team'=>'Zespół','testimonials'=>'Opinie','faq'=>'FAQ','gallery'=>'Galeria','posts'=>'Aktualności','cta'=>'Wezwanie do działania','stats'=>'Liczby i osiągnięcia','timeline'=>'Etapy współpracy','logos'=>'Partnerzy','contact'=>'Kontakt']);
}
function le_studio_string(mixed $value,int $max=10000): string {
    if(!is_string($value)||mb_strlen($value)>$max)throw new RuntimeException('Nieprawidłowe lub zbyt długie pole bloku.');
    return trim($value);
}
function le_studio_url(mixed $value,bool $image=false): string {
    $url=le_studio_string($value,2048);if($url==='')return '';
    if(preg_match('/[\x00-\x20\x7f\\\\]/',$url))throw new RuntimeException('Nieprawidłowy adres w bloku.');
    if(!$image&&preg_match('/^#[a-zA-Z0-9_.:-]*$/D',$url))return $url;
    if(!$image&&str_starts_with($url,'mailto:')&&filter_var(substr($url,7),FILTER_VALIDATE_EMAIL))return $url;
    if(!$image&&preg_match('/^tel:\+?[0-9()\-]{3,30}$/D',$url))return $url;
    if(str_starts_with($url,'/')&&!str_starts_with($url,'//'))return $url;
    $parts=parse_url($url);
    if(!$parts||!in_array(strtolower($parts['scheme']??''),['https','http'],true)||empty($parts['host'])||isset($parts['user'])||isset($parts['pass']))throw new RuntimeException('Użyj adresu http(s) lub ścieżki zaczynającej się od /.');
    return $url;
}
function le_studio_document(string $raw): array {
    if(strlen($raw)>1500000)throw new RuntimeException('Układ przekracza 1,5 MB.');
    $doc=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
    if(!is_array($doc)||($doc['schema']??0)!==1||!is_array($doc['blocks']??null)||!array_is_list($doc['blocks'])||count($doc['blocks'])>60)throw new RuntimeException('Nieprawidłowy układ lub więcej niż 60 bloków.');
    $blocks=[];$seen=[];$anchors=[];
    foreach($doc['blocks'] as $b){
        if(!is_array($b)||!isset(le_studio_types()[$b['type']??'']))throw new RuntimeException('Nieznany typ bloku.');
        $id=(string)($b['id']??'');if(!preg_match('/^[a-zA-Z0-9_-]{1,64}$/D',$id)||isset($seen[$id]))throw new RuntimeException('Nieprawidłowy lub powtórzony identyfikator bloku.');$seen[$id]=true;
        $v=['id'=>$id,'type'=>$b['type'],'tone'=>in_array($b['tone']??'',['plain','soft','accent'],true)?$b['tone']:'plain','align'=>($b['align']??'')==='center'?'center':'left'];
        $v['anchor']=le_studio_string($b['anchor']??'',64);
        if($v['anchor']!==''&&(!preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/D',$v['anchor'])||str_starts_with($v['anchor'],'block-')||isset($anchors[$v['anchor']])))throw new RuntimeException('Kotwica musi być unikalna, zaczynać się literą i zawierać tylko litery, cyfry, - lub _. Prefiks block- jest zarezerwowany.');
        if($v['anchor']!=='')$anchors[$v['anchor']]=true;
        if(isset($b['hidden'])&&!is_bool($b['hidden']))throw new RuntimeException('Nieprawidłowa widoczność bloku.');
        $v['hidden']=$b['hidden']??false;
        $v['columns']=$b['columns']??'auto';if(!in_array($v['columns'],['auto','1','2','3','4'],true))throw new RuntimeException('Nieprawidłowa liczba kolumn.');
        $v['spacing']=$b['spacing']??'normal';if(!in_array($v['spacing'],['compact','normal','spacious'],true))throw new RuntimeException('Nieprawidłowe odstępy sekcji.');
        foreach(['eyebrow','title','text','button','alt'] as $k)$v[$k]=le_studio_string($b[$k]??'',$k==='text'?20000:300);
        $v['url']=le_studio_url($b['url']??'');$v['image']=le_studio_url($b['image']??'',true);
        $items=$b['items']??[];if(!is_array($items)||!array_is_list($items)||count($items)>24)throw new RuntimeException('Maksymalnie 24 elementy w bloku.');$v['items']=[];
        foreach($items as $item){if(!is_array($item))throw new RuntimeException('Nieprawidłowy element.');$row=[];foreach(['title','text','meta','alt'] as $k)$row[$k]=le_studio_string($item[$k]??'',$k==='text'?5000:300);$row['image']=le_studio_url($item['image']??'',true);$row['url']=le_studio_url($item['url']??'');$v['items'][]=$row;}
        $blocks[]=$v;
    }
    return ['schema'=>1,'blocks'=>$blocks];
}
function le_studio_json(array $doc): string {return json_encode($doc,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
function le_studio_import(string $raw): array {
    if(strlen($raw)>1500000)throw new RuntimeException('Układ przekracza 1,5 MB.');
    $data=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
    if(!is_array($data)||($data['format']??'')!=='levelengine-layout'||($data['version']??0)!==1||!is_array($data['document']??null))throw new RuntimeException('Nieprawidłowy plik układu.');
    return le_studio_document(le_studio_json($data['document']));
}
function le_studio_render(string $raw): string {
    try{$doc=le_studio_document($raw);}catch(Throwable $e){levelengine_log('error','Nieprawidłowy dokument Studio');return '<p>Nie można wyświetlić tego układu.</p>';}
    $out='<div class="le-site">';
    foreach($doc['blocks'] as $b){
        if($b['hidden'])continue;
        $out.='<section id="'.e($b['anchor']?:'block-'.$b['id']).'" class="le-block le-spacing-'.e($b['spacing']).' le-'.e($b['type']).' le-columns-'.e($b['columns']).' le-tone-'.e($b['tone']).' le-align-'.e($b['align']).'"><div class="le-wrap">';
        if($b['eyebrow']!=='')$out.='<p class="le-eyebrow">'.e($b['eyebrow']).'</p>';
        $heading=$b['type']==='hero'?'h1':'h2';if($b['title']!=='')$out.="<$heading>".e($b['title'])."</$heading>";
        if($b['text']!=='')$out.='<p class="le-intro">'.nl2br(e($b['text'])).'</p>';
        if($b['image']!=='')$out.='<img class="le-cover" src="'.e($b['image']).'" alt="'.e($b['alt']).'" loading="lazy">';
        if($b['type']==='posts'){
            $posts=db()->query("SELECT title,slug,excerpt,thumbnail FROM posts WHERE deleted_at IS NULL AND status='published' ORDER BY featured DESC,created_at DESC LIMIT 6")->fetchAll();
            $out.='<div class="le-grid">';foreach($posts as $p){$out.='<article class="le-tile">';if($p['thumbnail'])$out.='<img src="'.e(le_studio_url($p['thumbnail'],true)).'" alt="" loading="lazy">';$out.='<h3><a href="/post/'.e($p['slug']).'">'.e($p['title']).'</a></h3><p>'.e($p['excerpt']??'').'</p></article>';}$out.=($posts?'':'<p>'.e(le_text('Nowe publikacje pojawią się tutaj.')).'</p>').'</div>';
        }elseif($b['items']){
            $out.='<div class="'.($b['type']==='faq'?'le-faq':'le-grid').'">';
            foreach($b['items'] as $item){
                if($b['type']==='faq'){$out.='<details><summary>'.e($item['title']).'</summary><p>'.nl2br(e($item['text'])).'</p></details>';continue;}
                $out.='<article class="le-tile">';if($item['image']!=='')$out.='<img src="'.e($item['image']).'" alt="'.e($item['alt']).'" loading="lazy">';
                if($item['meta']!=='')$out.='<p class="le-meta">'.e($item['meta']).'</p>';
                $out.='<h3>'.e($item['title']).'</h3><p>'.nl2br(e($item['text'])).'</p>';
                if($item['url']!=='')$out.='<a class="le-link" href="'.e($item['url']).'">'.e(le_text($b['type']==='contact'?'Skontaktuj się →':'Dowiedz się więcej →')).'</a>';$out.='</article>';
            }$out.='</div>';
        }
        if($b['button']!==''&&$b['url']!=='')$out.='<a class="le-button" href="'.e($b['url']).'">'.e($b['button']).'</a>';
        $out.='</div></section>';
    }return $out.'</div>';
}
function le_studio_design(array $input): array {
    $out=[];foreach(['accent'=>'#7c5cff','background'=>'#10121a','surface'=>'#1a1d29','text'=>'#f4f5fb','muted'=>'#b1b7cc'] as $k=>$default){$v=$input[$k]??$default;if(!is_string($v)||!preg_match('/^#[0-9a-fA-F]{6}$/D',$v))throw new RuntimeException('Nieprawidłowy kolor.');$out[$k]=$v;}
    $defaults=['font'=>'modern','width'=>'normal','radius'=>'soft'];
    foreach(['font'=>['modern','editorial','system'],'width'=>['compact','normal','wide'],'radius'=>['square','soft','round']] as $k=>$allowed){$v=$input[$k]??$defaults[$k];if(!in_array($v,$allowed,true))throw new RuntimeException('Nieprawidłowa opcja wyglądu.');$out[$k]=$v;}
    return $out;
}
function le_studio_saved_design(): array {return le_studio_design(json_decode(setting('studio_design','{}'),true)?:[]);}
function le_studio_css(array $d): string {
    $d=le_studio_design($d);$font=['modern'=>'Inter, sans-serif','editorial'=>'Georgia, serif','system'=>'system-ui, sans-serif'][$d['font']];$w=['compact'=>'960px','normal'=>'1180px','wide'=>'1400px'][$d['width']];$r=['square'=>'0px','soft'=>'16px','round'=>'28px'][$d['radius']];
    return ':root{--le-accent:'.$d['accent'].';--le-bg:'.$d['background'].';--le-surface:'.$d['surface'].';--le-text:'.$d['text'].';--le-muted:'.$d['muted'].';--le-font:'.$font.';--le-width:'.$w.';--le-radius:'.$r.'}body.le-styled{background:var(--le-bg);color:var(--le-text);font-family:var(--le-font)}body.le-styled .nav,body.le-styled footer{background:var(--le-surface);color:var(--le-text)}body.le-styled .btn.primary{background:var(--le-accent)}';
}
function le_studio_kit_names(): array {return array_map('le_text',['company'=>'Firma','blog'=>'Blog','portal'=>'Portal','community'=>'Społeczność']);}
function le_studio_kit(string $key): array {
    if(!isset(le_studio_kit_names()[$key]))throw new RuntimeException('Nieznany zestaw.');
    $block=fn($id,$type,$title,$text='',$items=[])=>['id'=>$id,'type'=>$type,'title'=>$title,'text'=>$text,'items'=>$items,'tone'=>'plain','align'=>'left'];
    $item=fn($t,$v,$m='')=>['title'=>$t,'text'=>$v,'meta'=>$m];
    $hero=['company'=>['Pomysły zmieniamy w rezultaty.','Poznaj naszą ofertę i ludzi, z którymi zrealizujesz następny projekt.'],'blog'=>['Miejsce na dobre historie.','Odkrycia, inspiracje i przemyślenia. Rozgość się.'],'portal'=>['Wiedzieć więcej. Rozumieć lepiej.','Aktualności, analizy i tematy, do których warto wracać.'],'community'=>['Dobre rzeczy robimy razem.','Poznaj ludzi, dziel się pomysłami i znajdź swoje miejsce.']][$key];
    $blocks=[$block('welcome','hero',$hero[0],$hero[1])];$blocks[0]+=['eyebrow'=>strtoupper(le_studio_kit_names()[$key]),'button'=>$key==='company'?'Poznaj ofertę':'Zobacz aktualności','url'=>$key==='company'?'/page/oferta':'/news/'];$blocks[0]['tone']='accent';
    if($key==='company'){$blocks[]=$block('services','features','Co możemy dla Ciebie zrobić?','',[$item('Strategia','Dobry plan dopasowany do Twoich celów.'),$item('Realizacja','Od pomysłu do gotowego rozwiązania.'),$item('Wsparcie','Pomoc również po zakończeniu projektu.')]);$blocks[]=$block('opinions','testimonials','Współpraca, która daje efekty','',[$item('Tu dodaj opinię klienta','Zastąp ten tekst prawdziwą opinią, za zgodą jej autora.','Przykładowa sekcja')]);}
    if($key==='blog')$blocks[]=$block('about','text','Cześć, dobrze Cię widzieć!','To przestrzeń na Twój głos. Napisz, o czym tworzysz i dlaczego warto zostać na dłużej.');
    if($key==='portal')$blocks[]=$block('topics','features','Wybierz swój temat','',[$item('Wiadomości','Najważniejsze informacje w jednym miejscu.'),$item('Opinie','Różne perspektywy i autorskie komentarze.'),$item('Poradniki','Wiedza, którą można wykorzystać.')]);
    if($key==='community')$blocks[]=$block('values','features','Znajdź coś dla siebie','',[$item('Ludzie','Poznaj innych i wymieniaj doświadczenia.'),$item('Wspólne inicjatywy','Twórz i rozwijaj pomysły z innymi.'),$item('Dyskusje','Miejsce na pytania i ciekawe rozmowy.')]);
    $blocks[]=$block('latest','posts','Najnowsze publikacje');$blocks[]=$block('questions','faq','Warto wiedzieć','',[$item('Jak się z nami skontaktować?','Dane kontaktowe znajdziesz na podstronie Kontakt.'),$item('Gdzie znajdę nowości?','Najnowsze wpisy pojawiają się w sekcji aktualności.')]);
    $doc=['schema'=>1,'blocks'=>$blocks];return le_studio_document(le_studio_json($doc));
}
function le_studio_create_kit(string $key): array {
    require_permission('manage_pages');$home=le_studio_kit($key);$pdo=db();$pdo->beginTransaction();$ids=[];
    try{
        foreach([['Start — '.le_studio_kit_names()[$key],'start-'.$key,$home],[$key==='company'?'Oferta':'O nas',$key==='company'?'oferta':'o-nas',['schema'=>1,'blocks'=>[['id'=>'intro','type'=>'text','title'=>$key==='company'?'Nasza oferta':'Poznaj nas','text'=>'Uzupełnij tę stronę własną treścią.']]]],['Kontakt','kontakt',['schema'=>1,'blocks'=>[['id'=>'contact','type'=>'text','title'=>'Porozmawiajmy','text'=>'Wpisz tutaj swoje dane kontaktowe i godziny dostępności.']]]]] as [$title,$base,$doc]){
            $slug=unique_slug($base,0,'pages');$pdo->prepare("INSERT INTO pages(title,slug,content,content_format,status,show_in_menu,menu_order) VALUES(?,?,?,'blocks','draft',0,0)")->execute([$title,$slug,le_studio_json(le_studio_document(le_studio_json($doc)))]);$ids[]=(int)$pdo->lastInsertId();
        }
        // Point the generated hero at the generated page, including any slug collision suffix.
        if($key==='company'){$q=$pdo->prepare('SELECT slug FROM pages WHERE id=?');$q->execute([$ids[1]]);$home['blocks'][0]['url']='/page/'.$q->fetchColumn();$pdo->prepare('UPDATE pages SET content=? WHERE id=?')->execute([le_studio_json($home),$ids[0]]);}
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    audit_log('studio.kit_created','page',(string)$ids[0],['kit'=>$key,'pages'=>$ids]);return $ids;
}
function le_studio_preview(string $raw,array $design): string {
    return '<!doctype html><html lang="'.e(function_exists('le_locale')?le_locale():'pl').'"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/assets/css/studio-site.css?v=100-final"><style>'.le_studio_css($design).'</style></head><body class="le-styled">'.le_studio_render($raw).'</body></html>';
}
function le_studio_home(): ?array {
    $id=(int)setting('studio_home_page','0');if(!$id)return null;$q=db()->prepare("SELECT * FROM pages WHERE id=? AND status='published' AND deleted_at IS NULL");$q->execute([$id]);return $q->fetch()?:null;
}
