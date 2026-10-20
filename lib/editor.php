<?php
declare(strict_types=1);
function le_editor_toolbar(string $mode='full'): void {
    $commands=['bold'=>le_text('Pogrubienie'),'italic'=>le_text('Kursywa'),'underline'=>le_text('Podkreślenie'),'strikeThrough'=>le_text('Przekreślenie'),'justifyLeft'=>le_text('Do lewej'),'justifyCenter'=>le_text('Wyśrodkuj'),'justifyRight'=>le_text('Do prawej')];
    if($mode==='full')$commands+=['justifyFull'=>le_text('Wyjustuj'),'h2'=>le_text('Nagłówek H2'),'h3'=>le_text('Nagłówek H3'),'p'=>le_text('Akapit'),'insertUnorderedList'=>le_text('Lista punktowana'),'insertOrderedList'=>le_text('Lista numerowana'),'blockquote'=>le_text('Cytat'),'code'=>le_text('Kod'),'link'=>le_text('Link'),'removeFormat'=>le_text('Usuń formatowanie'),'undo'=>le_text('Cofnij'),'redo'=>le_text('Ponów')];
    echo '<div class="editor-toolbar" role="toolbar" aria-label="'.e(le_text('Formatowanie tekstu')).'">';
    foreach($commands as $cmd=>$label)echo '<button type="button" data-editor-cmd="'.e($cmd).'" title="'.e($label).'">'.e($label).'</button>';
    if($mode==='full'&&has_permission('manage_media'))echo '<button type="button" id="insert-image" data-media-open="content">'.e(le_text('Dodaj obrazek')).'</button>';
    if($mode==='full')echo '<button type="button" data-editor-source>'.e(le_text('Kod / wizualny')).'</button>';
    echo '</div>';
}
function le_profile_content(string $text): string {
    $text=nl2br(e($text));
    $tags=['b'=>'strong','i'=>'em','u'=>'u','s'=>'s','left'=>'div','center'=>'div','right'=>'div'];
    foreach($tags as $code=>$tag){
        $attr=in_array($code,['left','center','right'],true)?' style="text-align:'.$code.'"':'';
        $text=preg_replace('~\['.$code.'\](.*?)\[/'.$code.'\]~si','<'.$tag.$attr.'>$1</'.$tag.'>',$text);
    }
    return $text;
}
