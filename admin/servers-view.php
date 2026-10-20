<?php need('manage_servers'); $bridgeOnce=$_SESSION['levelbridge_once']??null;unset($_SESSION['levelbridge_once']); ?>
<section class="panel">
<?php if(isset($_SESSION['levelbridge_download_error'])): ?><div class="toast danger"><?=e($_SESSION['levelbridge_download_error'])?></div><?php unset($_SESSION['levelbridge_download_error']);endif; ?>
<div class="panel-head"><div><h2><?=e(le_text('Serwery i LevelBridge'))?></h2><p><?=e(le_text('Połącz Paper z witryną i pokazuj aktualny status oraz liczbę graczy.'))?></p></div><a class="btn primary" href="/admin/levelbridge-download.php"><?=e(le_text('Pobierz LevelBridge z repo'))?></a></div>
<p class="hint"><?=e(le_text('1. Dodaj serwer. 2. Wygeneruj klucz. 3. Pobierz JAR i umieść w plugins/ serwera Paper. 4. Wklej konfigurację do plugins/LevelBridge/config.yml i wykonaj /levelbridge reload.'))?></p>
<?php if(is_array($bridgeOnce)): ?><div class="toast success"><?=e(le_text('Nowy klucz jest pokazany tylko raz. Skopiuj konfigurację do pluginu. Poprzedni klucz już nie działa.'))?></div><textarea class="input editor" readonly rows="7" aria-label="<?=e(le_text('Konfiguracja LevelBridge'))?>"><?=e(levelbridge_yaml((int)$bridgeOnce['id'],(string)$bridgeOnce['key']))?></textarea><?php endif; ?>
<div class="server-admin-list">
<?php foreach($servers as $s): ?>
<article class="panel server-admin-card">
<div class="panel-head"><div><h3><?=e($s['name'])?> <small>#<?=(int)$s['id']?></small></h3><p><?=e($s['status_label'])?> · <?=(int)$s['players']?> / <?=(int)$s['max_players']?> graczy</p></div></div>
<form method="post" class="form-grid"><?=csrf_field()?><input type="hidden" name="action" value="server_save"><input type="hidden" name="server_id" value="<?=(int)$s['id']?>">
<div class="two"><label>Etykieta<input class="input" name="server_label" value="<?=e($s['game_label'])?>"></label><label>Nazwa<input class="input" name="server_name" value="<?=e($s['name'])?>" required></label></div>
<label>Opis<input class="input" name="server_desc" value="<?=e($s['description'])?>"></label>
<div class="two"><label>Adres serwera<input class="input" name="server_address" value="<?=e($s['address'])?>" required></label><label>Port (0 = domyślny lub port w adresie)<input class="input" type="number" name="server_port" min="0" max="65535" value="<?=(int)$s['port']?>"></label></div>
<div class="two"><label>Strona serwera<input class="input" name="server_site" value="<?=e($s['site_url'])?>" placeholder="https://..."></label><label>Discord<input class="input" name="server_discord" value="<?=e($s['discord_url'])?>" placeholder="https://discord.gg/..."></label></div>
<label class="check"><input type="checkbox" name="server_enabled" value="1" <?=!empty($s['enabled'])?'checked':''?>> Widoczny na stronie</label>
<div class="actions"><button class="btn primary"><?=e(le_text('Zapisz serwer'))?></button><button class="btn danger" name="action" value="server_delete" onclick="return confirm(<?=e(json_encode(le_text('Usunąć serwer i jego klucz LevelBridge?'),JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP))?>)"><?=e(le_text('Usuń'))?></button></div></form>
<hr><h3>LevelBridge</h3>
<?php if(!empty($s['bridge_key_hash'])): ?>
<p>Ostatni kontakt: <?=!empty($s['bridge_seen'])?e(date('d.m.Y H:i:s',(int)$s['bridge_seen'])):le_text('brak — oczekiwanie na plugin')?>. Po 75 sekundach bez kontaktu status zmienia się na offline.</p>
<p class="hint">Plugin: <?=e($s['bridge_version']?:'—')?> · Minecraft: <?=e($s['version']?:'—')?></p>
<?php if(!empty($s['bridge_motd'])): ?><p>MOTD: <?=e($s['bridge_motd'])?></p><?php endif; ?>
<?php else: ?><p class="muted"><?=e(le_text('Serwer nie ma jeszcze klucza połączenia.'))?></p><?php endif; ?>
<div class="actions"><form method="post"><?=csrf_field()?><input type="hidden" name="server_id" value="<?=(int)$s['id']?>"><button class="btn" name="action" value="bridge_key" <?php if(!empty($s['bridge_key_hash'])): ?>onclick="return confirm(<?=e(json_encode(le_text('Zmienić klucz? Wklej nową konfigurację do pluginu; stary klucz przestanie działać.'),JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP))?>)"<?php endif; ?>><?=!empty($s['bridge_key_hash'])?le_text('Wygeneruj nowy klucz'):le_text('Połącz z LevelBridge')?></button></form>
<?php if(!empty($s['bridge_key_hash'])): ?><form method="post"><?=csrf_field()?><input type="hidden" name="server_id" value="<?=(int)$s['id']?>"><button class="btn danger" name="action" value="bridge_revoke" onclick="return confirm(<?=e(json_encode(le_text('Odłączyć plugin i unieważnić klucz?'),JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP))?>)"><?=e(le_text('Odłącz LevelBridge'))?></button></form><?php endif; ?></div>
</article><?php endforeach; ?>
<?php if(!$servers): ?><p><?=e(le_text('Dodaj pierwszy serwer poniżej.'))?></p><?php endif; ?></div>
<hr><h2><?=e(le_text('Dodaj serwer'))?></h2><form method="post" class="form-grid"><?=csrf_field()?><input type="hidden" name="action" value="server_save"><input type="hidden" name="server_id" value="0">
<div class="two"><label><?=e(le_text('Etykieta'))?><input class="input" name="server_label" value="MINECRAFT"></label><label><?=e(le_text('Nazwa'))?><input class="input" name="server_name" required></label></div><label><?=e(le_text('Opis'))?><input class="input" name="server_desc"></label>
<div class="two"><label><?=e(le_text('Adres serwera'))?><input class="input" name="server_address" required placeholder="mc.levelgame.pl"></label><label><?=e(le_text('Port (0 = domyślny)'))?><input class="input" name="server_port" type="number" min="0" max="65535" value="0"></label></div>
<div class="two"><label><?=e(le_text('Strona serwera'))?><input class="input" name="server_site" placeholder="https://..."></label><label>Discord<input class="input" name="server_discord" placeholder="https://discord.gg/..."></label></div>
<label class="check"><input type="checkbox" name="server_enabled" value="1" checked> <?=e(le_text('Widoczny na stronie'))?></label><button class="btn primary"><?=e(le_text('Dodaj serwer'))?></button></form>
</section>
