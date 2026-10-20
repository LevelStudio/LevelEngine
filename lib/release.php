<?php
declare(strict_types=1);
function engine_sql_statements(string $sql): array {
    $result=[];$buf='';$quote=null;$escape=false;$lineComment=false;$block=false;$n=strlen($sql);
    for($i=0;$i<$n;$i++){
        $c=$sql[$i];$next=$sql[$i+1]??'';
        if($lineComment){if($c==="\n"){$lineComment=false;$buf.="\n";}continue;}
        if($block){if($c==='*'&&$next==='/'){$block=false;$i++;$buf.=' ';}continue;}
        if($quote!==null){$buf.=$c;if($escape){$escape=false;continue;}if($c==='\\'){$escape=true;continue;}if($c===$quote){if($next===$quote){$buf.=$next;$i++;}else $quote=null;}continue;}
        if(($c==='-'&&$next==='-'&&ctype_space($sql[$i+2]??' '))||$c==='#'){$lineComment=true;continue;}
        if($c==='/'&&$next==='*'){$block=true;$i++;continue;}
        if(in_array($c,["'",'"','`'],true)){$quote=$c;$buf.=$c;continue;}
        if($c===';'){if(trim($buf)!=='')$result[]=trim($buf);$buf='';}else $buf.=$c;
    }
    if($quote!==null||$block)throw new RuntimeException(le_text('Niekompletny plik SQL.'));
    if(trim($buf)!=='')$result[]=trim($buf);return $result;
}
function engine_upload_path(string $url): ?string {
    if(!preg_match('~^/uploads/(?:avatars|posts|media|gallery)/[a-zA-Z0-9._-]+$~',$url))return null;
    $base=realpath(__DIR__.'/../uploads');$file=realpath(__DIR__.'/..'.$url);
    return $base&&$file&&is_file($file)&&str_starts_with($file,$base.DIRECTORY_SEPARATOR)?$file:null;
}
function engine_backup_create(): string {
    $dir=__DIR__.'/../backups';if(!is_dir($dir)&&!mkdir($dir,0700,true))throw new RuntimeException('Nie można utworzyć katalogu backupów.');
    $name='levelengine-'.date('Ymd-His').'-'.bin2hex(random_bytes(8)).'.sql';le_backup_database(db(),$dir.'/'.$name);return $name;
}
function engine_rate(string $scope,string $identity,int $max=10): void {
    $pdo=db();$window=intdiv(time(),900);$ip=(string)($_SERVER['REMOTE_ADDR']??'cli');
    $identities=['ip:'.$ip=>max($max*5,30)];if($identity!=='')$identities['account:'.strtolower($identity)]=$max;else $identities['ip:'.$ip]=$max;
    foreach($identities as $identityKey=>$limit){
        $key=hash('sha256',$scope.'|'.$identityKey);$pdo->prepare('INSERT INTO auth_limits(bucket,window_id,attempts) VALUES(?,?,1) ON DUPLICATE KEY UPDATE attempts=IF(window_id=VALUES(window_id),attempts+1,1),window_id=VALUES(window_id)')->execute([$key,$window]);
        $q=$pdo->prepare('SELECT attempts FROM auth_limits WHERE bucket=?');$q->execute([$key]);if((int)$q->fetchColumn()>$limit){http_response_code(429);throw new RuntimeException('Za dużo prób. Spróbuj ponownie za 15 minut.');}
    }
}
function engine_rate_clear(string $scope,string $identity): void {
    db()->prepare('DELETE FROM auth_limits WHERE bucket=?')->execute([hash('sha256',$scope.'|account:'.strtolower($identity))]);
}
function engine_twofa_accept(int $id,string $code): bool {
    $pdo=db();$pdo->beginTransaction();
    try{
        $q=$pdo->prepare('SELECT twofa_secret,twofa_enabled,twofa_last_step,twofa_recovery,status FROM users WHERE id=? FOR UPDATE');$q->execute([$id]);$u=$q->fetch();if(!$u||!$u['twofa_enabled']||$u['status']!=='active'){$pdo->rollBack();return false;}
        if(preg_match('/^[0-9]{6}$/',$code)){
            for($offset=-1;$offset<=1;$offset++){$step=intdiv(time(),30)+$offset;if($step>(int)$u['twofa_last_step']&&hash_equals(totp_code((string)$u['twofa_secret'],$step*30),$code)){$pdo->prepare('UPDATE users SET twofa_last_step=? WHERE id=?')->execute([$step,$id]);$pdo->commit();return true;}}
        }else{
            $hash=hash('sha256',strtolower(trim($code)));$codes=json_decode((string)($u['twofa_recovery']??'[]'),true)?:[];
            foreach($codes as $k=>$stored)if(hash_equals($stored,$hash)){unset($codes[$k]);$pdo->prepare('UPDATE users SET twofa_recovery=? WHERE id=?')->execute([json_encode(array_values($codes)),$id]);$pdo->commit();return true;}
        }
        $pdo->rollBack();return false;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
