<?php
declare(strict_types=1);
function le_backup_database(PDO $pdo,string $file): array {
    if($pdo->inTransaction())throw new RuntimeException('Backup nie może rozpocząć się w innej transakcji.');
    $tables=[];foreach($pdo->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM) as $row){if($row[1]!=='BASE TABLE')throw new RuntimeException('Backup CMS nie obsługuje widoków. Użyj narzędzi hostingu.');$tables[]=$row[0];}
    if($pdo->query('SHOW TRIGGERS')->fetch())throw new RuntimeException('Backup CMS nie obsługuje triggerów. Użyj narzędzi hostingu.');
    if($pdo->query("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' AND ENGINE<>'InnoDB' LIMIT 1")->fetchColumn())throw new RuntimeException('Spójna kopia wymaga tabel InnoDB. Użyj narzędzi hostingu.');
    if(str_contains((string)$pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn(),'NO_BACKSLASH_ESCAPES'))throw new RuntimeException('Ten tryb SQL wymaga kopii wykonanej narzędziami hostingu.');
    $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
    $h=fopen($file,'xb');if(!$h){$pdo->rollBack();throw new RuntimeException('Nie można zapisać kopii.');}
    $previous=$pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);$rows=0;
    try{
        le_write_all($h,"-- LevelEngine consistent backup v1\nSET FOREIGN_KEY_CHECKS=0;\nSET NAMES utf8mb4;\n");
        foreach($tables as $table){
            $quoted='`'.str_replace('`','``',$table).'`';$create=$pdo->query('SHOW CREATE TABLE '.$quoted)->fetch(PDO::FETCH_NUM);
            le_write_all($h,'DROP TABLE IF EXISTS '.$quoted.";\n".$create[1].";\n");
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,false);$stmt=$pdo->query('SELECT * FROM '.$quoted);
            while($row=$stmt->fetch(PDO::FETCH_ASSOC)){$columns=array_map(fn($k)=>'`'.str_replace('`','``',$k).'`',array_keys($row));$values=array_map(fn($v)=>$v===null?'NULL':$pdo->quote((string)$v),array_values($row));le_write_all($h,'INSERT INTO '.$quoted.' ('.implode(',',$columns).') VALUES ('.implode(',',$values).");\n");$rows++;}
            $stmt->closeCursor();$pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,$previous);
        }
        le_write_all($h,"SET FOREIGN_KEY_CHECKS=1;\n");if(!fflush($h))throw new RuntimeException('Niepełny zapis kopii.');if(function_exists('fsync'))fsync($h);fclose($h);$h=null;$pdo->commit();
        $meta=['format'=>1,'cms_version'=>function_exists('setting')?setting('engine_version','unknown'):'unknown','release_build'=>function_exists('setting')?(int)setting('release_build','0'):0,'sha256'=>hash_file('sha256',$file),'bytes'=>filesize($file),'tables'=>$tables,'rows'=>$rows,'created_at'=>date('c')];le_atomic_write($file.'.meta.json',json_encode($meta,JSON_THROW_ON_ERROR));le_backup_validate($file);return $meta;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();if(is_resource($h))fclose($h);if(is_file($file))unlink($file);throw $e;}
    finally{$pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,$previous);}
}
function le_backup_statements(string $file): Generator {
    $h=fopen($file,'rb');if(!$h)throw new RuntimeException('Brak kopii.');$buffer='';$quote=null;$escaped=false;$comment=false;$lineStart=true;
    try{
        while(($chunk=fread($h,65536))!==false&&$chunk!==''){
            $n=strlen($chunk);for($i=0;$i<$n;$i++){
                $c=$chunk[$i];
                if($comment){if($c==="\n"){$comment=false;$lineStart=true;}continue;}
                if($quote!==null){$buffer.=$c;if($escaped){$escaped=false;continue;}if($c==='\\'){$escaped=true;continue;}if($c===$quote)$quote=null;continue;}
                if($lineStart&&$c==='-'&&($chunk[$i+1]??'')==='-'){$comment=true;continue;}
                if(in_array($c,["'",'"','`'],true)){$quote=$c;$buffer.=$c;$lineStart=false;continue;}
                if($c===';'){if(trim($buffer)!=='')yield trim($buffer);$buffer='';$lineStart=true;}
                else{$buffer.=$c;if($c==="\n")$lineStart=true;elseif(!ctype_space($c))$lineStart=false;}
                if(strlen($buffer)>16*1024*1024)throw new RuntimeException('Instrukcja SQL przekracza 16 MB.');
            }
        }
        if($chunk===false||$quote!==null||trim($buffer)!=='')throw new RuntimeException('Niekompletna kopia SQL.');
    }finally{fclose($h);}
}
function le_backup_validate(string $file): array {
    $meta=json_decode((string)file_get_contents($file.'.meta.json'),true,512,JSON_THROW_ON_ERROR);
    if(($meta['format']??0)!==1||!is_array($meta['tables']??null)||!hash_equals((string)($meta['sha256']??''),hash_file('sha256',$file))||(int)$meta['bytes']!==filesize($file))throw new RuntimeException('Kopia nie przeszła kontroli integralności.');
    $creates=[];foreach(le_backup_statements($file) as $statement){
        if(preg_match('/^CREATE TABLE `((?:``|[^`])+)` /s',$statement,$m))$creates[]=str_replace('``','`',$m[1]);
        elseif(!preg_match('/^(?:SET (?:FOREIGN_KEY_CHECKS=[01]|NAMES utf8mb4)$|DROP TABLE IF EXISTS `|INSERT INTO `)/s',$statement))throw new RuntimeException('Nieobsługiwana instrukcja w kopii.');
    }
    $expected=$meta['tables'];sort($expected);sort($creates);if($creates!==$expected)throw new RuntimeException('Lista tabel w kopii jest niepełna.');return $meta;
}
function le_backup_restore(PDO $pdo,string $file): void {
    le_backup_validate($file);$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    try{
        $tables=$pdo->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM);
        foreach($tables as $row)if($row[1]!=='BASE TABLE')throw new RuntimeException('Nie można przywrócić bazy z widokami.');
        foreach($tables as $row)$pdo->exec('DROP TABLE IF EXISTS `'.str_replace('`','``',$row[0]).'`');
        foreach(le_backup_statements($file) as $statement)$pdo->exec($statement);
    }finally{$pdo->exec('SET FOREIGN_KEY_CHECKS=1');}
}
