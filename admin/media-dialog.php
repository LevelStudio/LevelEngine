<?php if(has_permission('manage_media',$admin)): ?>
<dialog id="media-dialog" class="media-dialog" aria-labelledby="media-dialog-title" data-endpoint="/admin/media-api.php" data-csrf="<?=e(csrf())?>">
<div class="media-dialog-head"><div><h2 id="media-dialog-title"><?=e(le_text('Dodaj obrazek do Newsa'))?></h2><p><?=e(le_text('Wgraj plik na tę stronę lub wybierz obrazek z biblioteki.'))?></p></div><button type="button" class="btn" data-media-close aria-label="<?=e(le_text('Zamknij bibliotekę mediów'))?>">✕</button></div>
<div class="media-uploader" data-media-drop><label class="media-file-label"><?=e(le_text('Wybierz zdjęcia z komputera'))?><input type="file" data-media-files accept="image/jpeg,image/png,image/webp,image/gif" multiple></label><p><?=e(le_text('Możesz też przeciągnąć pliki tutaj. JPG, PNG, WebP i GIF — do 5 MB każdy.'))?></p></div>
<div class="media-search-row"><label><?=e(le_text('Szukaj w bibliotece'))?><input class="input" type="search" data-media-search placeholder="<?=e(le_text('Tytuł lub opis obrazka'))?>"></label><label><?=e(le_text('Opis obrazka (ALT)'))?><input class="input" data-media-alt maxlength="255" placeholder="<?=e(le_text('Co przedstawia obrazek?'))?>"></label></div>
<p data-media-message role="status" aria-live="polite"></p><div class="media-picker-grid" data-media-grid></div>
<button class="btn" type="button" data-media-more hidden><?=e(le_text('Pokaż więcej'))?></button>
<div class="media-dialog-actions"><span data-media-selected><?=e(le_text('Wybierz obrazek.'))?></span><button class="btn" type="button" data-media-close><?=e(le_text('Anuluj'))?></button><button class="btn primary" type="button" data-media-insert disabled><?=e(le_text('Wstaw do treści'))?></button></div>
</dialog>
<?php endif; ?>
