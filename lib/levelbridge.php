<?php
declare(strict_types=1);

const LEVELBRIDGE_TIMEOUT = 75;
function levelbridge_status(array $server, ?int $now=null): array {
    if (empty($server['bridge_key_hash'])) return $server;
    $seen=(int)($server['bridge_seen']??0);
    $fresh=$seen>0 && ($now??time())-$seen<=LEVELBRIDGE_TIMEOUT;
    $online=$fresh && ($server['status']??'')==='online';
    $server['status']=$online?'online':($seen>0?'offline':'unknown');
    $server['status_label']=$online?'ONLINE':($seen>0?'OFFLINE':'OCZEKIWANIE');
    if(!$online)$server['players']=0;
    return $server;
}
function levelbridge_server(int $id): array {
    $q=db()->prepare('SELECT s.*,b.key_hash bridge_key_hash,b.last_seen bridge_seen,b.motd bridge_motd,b.plugin_version bridge_version FROM servers s LEFT JOIN levelbridge_servers b ON b.server_id=s.id WHERE s.id=?');
    $q->execute([$id]);$s=$q->fetch();
    if(!$s)throw new RuntimeException(le_text('Serwer nie istnieje.'));return levelbridge_status($s);
}
function levelbridge_servers(bool $public=false): array {
    $rows=db()->query('SELECT s.*,b.key_hash bridge_key_hash,b.last_seen bridge_seen,b.motd bridge_motd,b.plugin_version bridge_version FROM servers s LEFT JOIN levelbridge_servers b ON b.server_id=s.id '.($public?'WHERE s.enabled=1 ':'').'ORDER BY s.sort_order ASC,s.id ASC')->fetchAll();
    return array_map('levelbridge_status',$rows);
}
function levelbridge_payload(string $raw): array {
    if(strlen($raw)>8192)throw new InvalidArgumentException(le_text('Za duża wiadomość.'));
    try{$p=json_decode($raw,true,16,JSON_THROW_ON_ERROR);}catch(JsonException $e){throw new InvalidArgumentException(le_text('Nieprawidłowy JSON.'));}
    if(!is_array($p)||($p['protocol']??null)!==1||!is_int($p['server_id']??null)||$p['server_id']<1||!is_bool($p['online']??null))throw new InvalidArgumentException(le_text('Nieprawidłowy protokół, ID lub status.'));
    foreach(['players','max_players'] as $field)if(!is_int($p[$field]??null)||$p[$field]<0||$p[$field]>100000)throw new InvalidArgumentException(le_text('Nieprawidłowa liczba graczy.'));
    foreach(['version'=>120,'motd'=>500,'plugin_version'=>40] as $field=>$limit)if(!is_string($p[$field]??null)||mb_strlen($p[$field])>$limit||str_contains($p[$field],"\0"))throw new InvalidArgumentException(le_text('Nieprawidłowe pole ').$field.'.');
    if(!$p['online'])$p['players']=0;
    return $p;
}
function levelbridge_accept(array $p,string $key): void {
    $pdo=db();$pdo->beginTransaction();
    try{
        $q=$pdo->prepare('SELECT key_hash FROM levelbridge_servers WHERE server_id=? FOR UPDATE');$q->execute([$p['server_id']]);$hash=$q->fetchColumn();
        if(!is_string($hash)||!preg_match('/^[a-f0-9]{64}$/',$key)||!hash_equals($hash,hash('sha256',$key)))throw new UnexpectedValueException('Nieprawidłowy klucz lub ID serwera.');
        $pdo->prepare('UPDATE servers SET status=?,status_label=?,players=?,max_players=?,version=? WHERE id=?')->execute([$p['online']?'online':'offline',$p['online']?'ONLINE':'OFFLINE',$p['players'],$p['max_players'],$p['version'],$p['server_id']]);
        $pdo->prepare('UPDATE levelbridge_servers SET last_seen=?,motd=?,plugin_version=? WHERE server_id=?')->execute([time(),$p['motd'],$p['plugin_version'],$p['server_id']]);
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function levelbridge_yaml(int $id,string $key): string {
    return 'endpoint: '.json_encode(le_origin().'/levelbridge-api.php',JSON_UNESCAPED_SLASHES)."\nserver-id: $id\napi-key: \"$key\"\ninterval-seconds: 15\nallow-insecure-localhost: false\n";
}
function levelbridge_package(): array {
    $catalog=marketplace_catalog(true,true);
    if($catalog['source']!=='remote')throw new RuntimeException(le_text('Repozytorium cms.levelgame.pl jest niedostępne. Spróbuj ponownie później.'));
    foreach($catalog['packages'] as $p){
        if(($p['id']??'')!=='levelbridge'||($p['type']??'')!=='paper-plugin')continue;
        if(!preg_match('/^[a-f0-9]{64}$/',(string)($p['sha256']??''))||!preg_match('/^\d+\.\d+\.\d+(?:[-.][A-Za-z0-9.-]+)?$/',(string)($p['version']??'')))throw new RuntimeException(le_text('Niekompletny wpis LevelBridge w repozytorium.'));
        if(!empty($p['min_cms'])&&version_compare(LEVELENGINE_CMS_VERSION,(string)$p['min_cms'],'<'))throw new RuntimeException(le_text('Plugin wymaga nowszej wersji CMS.'));
        return $p;
    }
    throw new RuntimeException(le_text('LevelBridge nie został jeszcze opublikowany w repozytorium. Dodaj JAR w LevelPromo → Repository Manager jako plugin Paper z ID levelbridge.'));
}
