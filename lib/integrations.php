<?php
declare(strict_types=1);
function le_vault_key(): string {
    $file=($GLOBALS['le_install_root']??dirname(__DIR__)).'/storage/vault.key';
    if(!is_file($file))throw new RuntimeException('Brak klucza magazynu sekretów. Skonfiguruj integracje.');
    $key=file_get_contents($file);if(strlen($key)!==32)throw new RuntimeException('Uszkodzony klucz sekretów.');return $key;
}
function le_vault_init(): void {
    $file=($GLOBALS['le_install_root']??dirname(__DIR__)).'/storage/vault.key';if(is_file($file))return;
    $h=fopen($file,'xb');if(!$h)throw new RuntimeException('Nie można utworzyć klucza sekretów.');try{le_write_all($h,random_bytes(32));}finally{fclose($h);}@chmod($file,0600);
}
function le_encrypt(string $plain): string {
    $iv=random_bytes(12);$tag='';$cipher=openssl_encrypt($plain,'aes-256-gcm',le_vault_key(),OPENSSL_RAW_DATA,$iv,$tag);
    if($cipher===false)throw new RuntimeException('Nie można zabezpieczyć sekretu.');return base64_encode($iv.$tag.$cipher);
}
function le_decrypt(string $sealed): string {
    $data=base64_decode($sealed,true);if($data===false||strlen($data)<28)throw new RuntimeException('Nieprawidłowy sekret.');
    $plain=openssl_decrypt(substr($data,28),'aes-256-gcm',le_vault_key(),OPENSSL_RAW_DATA,substr($data,0,12),substr($data,12,16));
    if($plain===false)throw new RuntimeException('Klucz sekretów nie pasuje do danych.');return $plain;
}
function le_enqueue(string $key,string $kind,array $payload): void {
    if(!in_array($kind,['mail','discord'],true))throw new InvalidArgumentException('Typ kolejki.');
    db()->prepare('INSERT IGNORE INTO delivery_queue(event_key,kind,payload) VALUES(?,?,?)')->execute([$key,$kind,le_encrypt(json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE))]);
}
function le_smtp_send(string $recipient,string $subject,string $body): void {
    $host=setting('smtp_host','');$port=(int)setting('smtp_port','587');$mode=setting('smtp_mode','starttls');$from=setting('smtp_from','');
    if(!preg_match('/^[a-zA-Z0-9.-]+$/',$host)||$port<1||$port>65535||!filter_var($recipient,FILTER_VALIDATE_EMAIL)||!filter_var($from,FILTER_VALIDATE_EMAIL)||preg_match('/[\r\n]/',$recipient.$from.$subject))throw new RuntimeException('Nieprawidłowa konfiguracja SMTP lub odbiorca.');
    if(!in_array($mode,['starttls','tls'],true))throw new RuntimeException('SMTP wymaga TLS.');
    $context=stream_context_create(['ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'peer_name'=>$host,'allow_self_signed'=>false]]);
    $socket=stream_socket_client(($mode==='tls'?'tls':'tcp').'://'.$host.':'.$port,$errno,$error,10,STREAM_CLIENT_CONNECT,$context);
    if(!$socket)throw new RuntimeException('Nie można połączyć z SMTP.');stream_set_timeout($socket,15);
    $read=function(array $codes)use($socket):void{
        $last='';$size=0;do{$line=fgets($socket,2048);if($line===false)throw new RuntimeException('SMTP: brak odpowiedzi.');$size+=strlen($line);if($size>32768)throw new RuntimeException('SMTP: zbyt długa odpowiedź.');$last=$line;}while(strlen($line)>3&&$line[3]==='-');
        if(!in_array((int)substr($last,0,3),$codes,true))throw new RuntimeException('SMTP odrzucił operację (kod '.(int)substr($last,0,3).').');
    };
    $send=function(string $command,array $codes)use($socket,$read):void{le_write_all($socket,$command."\r\n");$read($codes);};
    try{
        $read([220]);$send('EHLO levelengine.local',[250]);
        if($mode==='starttls'){$send('STARTTLS',[220]);if(stream_socket_enable_crypto($socket,true,STREAM_CRYPTO_METHOD_TLS_CLIENT)!==true)throw new RuntimeException('SMTP: błąd TLS.');$send('EHLO levelengine.local',[250]);}
        $user=setting('smtp_user','');$sealed=setting('smtp_password','');
        if($user!==''){$send('AUTH LOGIN',[334]);$send(base64_encode($user),[334]);$send(base64_encode($sealed!==''?le_decrypt($sealed):''),[235]);}
        $send('MAIL FROM:<'.$from.'>',[250]);$send('RCPT TO:<'.$recipient.'>',[250,251]);$send('DATA',[354]);
        $headers='From: <'.$from.">\r\nTo: <".$recipient.">\r\nSubject: =?UTF-8?B?".base64_encode($subject)."?=\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\nDate: ".date(DATE_RFC2822)."\r\nMessage-ID: <".bin2hex(random_bytes(16))."@levelengine.local>\r\n\r\n";
        $send($headers.rtrim(chunk_split(base64_encode($body),76,"\r\n"))."\r\n.",[250]);$send('QUIT',[221]);
    }finally{fclose($socket);}
}
function le_discord_send(array $payload): void {
    $sealed=setting('discord_webhook','');if($sealed==='')throw new RuntimeException('Discord nie jest skonfigurowany.');$url=le_decrypt($sealed);
    if(!preg_match('~^https://discord\.com/api/webhooks/[0-9]+/[A-Za-z0-9_-]+$~D',$url))throw new RuntimeException('Nieprawidłowy webhook Discord.');
    $content=mb_substr((string)($payload['title']??''),0,250)."\n".le_public_base().(string)($payload['path']??'/');
    $ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode(['content'=>$content,'allowed_mentions'=>['parse'=>[]]],JSON_THROW_ON_ERROR),CURLOPT_HTTPHEADER=>['Content-Type: application/json'],CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS]);
    $result=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);if($result===false||$code<200||$code>=300)throw new RuntimeException('Discord: niepotwierdzona dostawa (HTTP '.$code.').');
}
function le_worker(): array {
    if(is_file(dirname(__DIR__).'/storage/maintenance.json'))throw new RuntimeException('Trwa konserwacja.');
    $pdo=db();$lockName='le_worker_'.substr(hash('sha256',(string)(cfg('db',[])['name']??'')),0,40);$q=$pdo->prepare('SELECT GET_LOCK(?,0)');$q->execute([$lockName]);$lock=$q->fetchColumn();if(!$lock)return ['busy'=>true];
    $stats=['published'=>0,'sent'=>0,'failed'=>0];
    try{
        foreach(['post'=>'posts','page'=>'pages'] as $kind=>$table){
            $ids=$pdo->query("SELECT id FROM `$table` WHERE status='scheduled' AND deleted_at IS NULL AND publish_at<=NOW() LIMIT 100")->fetchAll(PDO::FETCH_COLUMN);
            foreach($ids as $id){$pdo->beginTransaction();try{$row=le_content_row($kind,(int)$id,true);if($row['status']==='scheduled'&&$row['deleted_at']===null&&strtotime($row['publish_at'])<=time()){$pdo->prepare("UPDATE `$table` SET status='published',edit_version=edit_version+1 WHERE id=?")->execute([$id]);$row=le_content_row($kind,(int)$id);le_publication_notice($kind,$row);$stats['published']++;}$pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}}
        }
        // Interrupted delivery is marked uncertain, never silently retried (remote APIs lack idempotency).
        $pdo->exec("UPDATE delivery_queue SET state='uncertain',last_error='Przerwano wysyłkę; sprawdź odbiorcę przed ponowieniem.' WHERE state='sending' AND claimed_at<DATE_SUB(NOW(),INTERVAL 5 MINUTE)");
        $rows=$pdo->query("SELECT * FROM delivery_queue WHERE state='pending' AND available_at<=NOW() ORDER BY id LIMIT 20")->fetchAll();
        foreach($rows as $row){
            $pdo->prepare("UPDATE delivery_queue SET state='sending',claimed_at=NOW(),attempts=attempts+1 WHERE id=?")->execute([$row['id']]);
            try{$p=json_decode(le_decrypt($row['payload']),true,512,JSON_THROW_ON_ERROR);if($row['kind']==='mail')le_smtp_send($p['to'],$p['subject'],$p['body']);else le_discord_send($p);$pdo->prepare("UPDATE delivery_queue SET state='sent',payload='',last_error=NULL WHERE id=?")->execute([$row['id']]);$stats['sent']++;}
            catch(Throwable $e){$pdo->prepare("UPDATE delivery_queue SET state='uncertain',last_error=? WHERE id=?")->execute([mb_substr($e->getMessage(),0,500),$row['id']]);$stats['failed']++;}
        }
        // Store minute samples even while a server is offline; max one observation per minute.
        foreach(levelbridge_servers() as $s){$pdo->prepare('INSERT IGNORE INTO server_history(server_id,bucket,samples,online_samples,players_sum,players_peak) VALUES(?,?,1,?,?,?)')->execute([$s['id'],intdiv(time(),60)*60,$s['status']==='online'?1:0,$s['players'],$s['players']]);}
        $pdo->exec('DELETE FROM server_history WHERE bucket<UNIX_TIMESTAMP()-7776000');
        $pdo->exec('DELETE FROM auth_limits WHERE window_id<FLOOR(UNIX_TIMESTAMP()/900)-2');
        $pdo->exec('DELETE FROM account_tokens WHERE expires_at<NOW()');
        $pdo->exec('DELETE FROM content_autosaves WHERE updated_at<DATE_SUB(NOW(),INTERVAL 30 DAY)');
        $pdo->exec("DELETE FROM delivery_queue WHERE state='sent' AND created_at<DATE_SUB(NOW(),INTERVAL 30 DAY)");
        set_setting('last_worker_at',date('c'));audit_log('worker.completed','system',null,$stats);return $stats;
    }finally{$q=$pdo->prepare('SELECT RELEASE_LOCK(?)');$q->execute([$lockName]);}
}
