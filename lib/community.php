<?php
declare(strict_types=1);
function le_community_type(string $key): string {
    $map=['level-applications'=>'application','level-events'=>'event','level-polls'=>'poll','level-shop'=>'shop'];
    if(!isset($map[$key]))throw new InvalidArgumentException('Nieznany moduł społeczności.');return $map[$key];
}
function le_community_submit(string $key,int $id,array $fields): void {
    $type=le_community_type($key);$u=require_login();
    if(!csrf_ok())throw new RuntimeException('Sesja formularza wygasła.');engine_rate('community',(string)$u['id'],20);
    $pdo=db();$pdo->beginTransaction();
    try{
        $q=$pdo->prepare('SELECT * FROM community_items WHERE id=? AND plugin_key=? AND enabled=1 FOR UPDATE');$q->execute([$id,$key]);$item=$q->fetch();
        if(!$item||($item['starts_at']&&strtotime($item['starts_at'])>time())||($item['ends_at']&&strtotime($item['ends_at'])<=time()))throw new RuntimeException('Ta pozycja jest obecnie niedostępna.');
        $q=$pdo->prepare('SELECT id FROM community_entries WHERE item_id=? AND user_id=?');$q->execute([$id,$u['id']]);if($q->fetchColumn())throw new RuntimeException('Twoje zgłoszenie zostało już zapisane.');
        if($item['capacity']>0){$q=$pdo->prepare("SELECT COUNT(*) FROM community_entries WHERE item_id=? AND state<>'cancelled'");$q->execute([$id]);if((int)$q->fetchColumn()>=(int)$item['capacity'])throw new RuntimeException('Brak wolnych miejsc.');}
        $response=trim((string)($fields['response']??''));
        if($type==='poll'){$options=json_decode($item['options_json']??'[]',true)?:[];if(!in_array($response,$options,true))throw new RuntimeException('Wybierz jedną z dostępnych odpowiedzi.');}
        if($type==='application'&&($response===''||mb_strlen($response)>4000))throw new RuntimeException('Zgłoszenie musi zawierać od 1 do 4000 znaków.');
        if(in_array($type,['event','shop'],true))$response='';
        $state=$type==='poll'?'recorded':($type==='event'?'registered':'pending');
        $pdo->prepare('INSERT INTO community_entries(item_id,user_id,response,state,price_minor,currency) VALUES(?,?,?,?,?,?)')->execute([$id,$u['id'],$response,$state,$item['price_minor'],$item['currency']]);$entry=(int)$pdo->lastInsertId();$pdo->commit();
        audit_log('community.'.$type,$key,(string)$entry);
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function le_community_public(string $key): void {
    $type=le_community_type($key);$message='';
    if($_SERVER['REQUEST_METHOD']==='POST')try{le_community_submit($key,(int)($_POST['item_id']??0),$_POST);$message='Zapisano. Dziękujemy!';}catch(Throwable $e){$message=$e->getMessage();}
    $q=db()->prepare('SELECT * FROM community_items WHERE plugin_key=? AND enabled=1 ORDER BY id DESC LIMIT 100');$q->execute([$key]);
    echo '<p role="status">'.e(le_text($message)).'</p>';if($type==='shop')echo '<p>Zamówienia obsługiwane ręcznie przez administrację. Formularz nie pobiera płatności ani nie wykonuje komend na serwerze.</p>';
    foreach($q as $item){
        echo '<section class="card card-pad" style="margin:16px 0"><h2>'.e($item['title']).'</h2><p>'.nl2br(e($item['description'])).'</p>';
        if($item['starts_at']||$item['ends_at'])echo '<p>'.e(($item['starts_at']??'').' — '.($item['ends_at']??'')).' ('.e(date_default_timezone_get()).')</p>';
        if($type==='shop')echo '<p>'.number_format($item['price_minor']/100,2,',',' ').' '.e($item['currency']).'</p>';
        $u=current_user();if(!$u){echo '<a href="/login">Zaloguj się, aby wziąć udział</a></section>';continue;}
        $query=db()->prepare('SELECT * FROM community_entries WHERE item_id=? AND user_id=?');$query->execute([$item['id'],$u['id']]);$existing=$query->fetch();
        if($existing){echo '<p>Twój status: '.e($existing['state']).'</p>';}
        else{
            echo '<form method="post">'.csrf_field().'<input type="hidden" name="item_id" value="'.$item['id'].'">';
            if($type==='application')echo '<textarea class="input" name="response" maxlength="4000" required aria-label="Treść zgłoszenia"></textarea>';
            if($type==='poll')foreach(json_decode($item['options_json']??'[]',true)?:[] as $option)echo '<label><input type="radio" name="response" required value="'.e($option).'"> '.e($option).'</label><br>';
            echo '<button class="btn primary">'.(['application'=>'Wyślij zgłoszenie','event'=>'Zapisz się','poll'=>'Głosuj','shop'=>'Złóż zamówienie'][$type]).'</button></form>';
        }
        if($type==='poll'){$results=db()->prepare('SELECT response,COUNT(*) votes FROM community_entries WHERE item_id=? GROUP BY response');$results->execute([$item['id']]);echo '<ul>';foreach($results as $result)echo '<li>'.e($result['response']).': '.(int)$result['votes'].'</li>';echo '</ul>';}
        echo '</section>';
    }
}
function le_community_admin(string $key): void {
    require_permission('manage_plugins');$type=le_community_type($key);$message='';
    if($_SERVER['REQUEST_METHOD']==='POST')try{
        if(!csrf_ok())throw new RuntimeException('Sesja formularza wygasła.');$action=(string)($_POST['action']??'');
        if($action==='item_save'){
            $title=trim((string)($_POST['title']??''));$description=trim((string)($_POST['description']??''));$id=(int)($_POST['id']??0);
            if($title===''||mb_strlen($title)>160||mb_strlen($description)>4000)throw new RuntimeException('Sprawdź tytuł i opis.');
            $options=[];if($type==='poll'){$options=array_values(array_unique(array_filter(array_map('trim',explode("\n",(string)($_POST['options']??''))))));if(count($options)<2||count($options)>20)throw new RuntimeException('Podaj od 2 do 20 opcji.');foreach($options as $opt)if(mb_strlen($opt)>160)throw new RuntimeException('Opcja jest za długa.');}
            $dates=[];foreach(['starts_at','ends_at'] as $field){$value=trim((string)($_POST[$field]??''));$d=$value!==''?DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$value):null;if($value!==''&&(!$d||$d->format('Y-m-d\TH:i')!==$value))throw new RuntimeException('Nieprawidłowy termin.');$dates[]=$d?$d->format('Y-m-d H:i:s'):null;}
            if($dates[0]&&$dates[1]&&$dates[1]<=$dates[0])throw new RuntimeException('Koniec musi nastąpić po początku.');
            $capacity=filter_var($_POST['capacity']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>100000]]);if($capacity===false)throw new RuntimeException('Nieprawidłowy limit miejsc.');
            $price=(string)($_POST['price']??'0');if(!preg_match('/^\d{1,6}(?:[.,]\d{1,2})?$/D',$price))throw new RuntimeException('Nieprawidłowa cena.');$parts=explode('.',str_replace(',','.',$price));$minor=(int)$parts[0]*100+(int)str_pad($parts[1]??'0',2,'0');
            $currency=(string)($_POST['currency']??'PLN');if(!in_array($currency,['PLN','EUR','USD'],true))throw new RuntimeException('Nieprawidłowa waluta.');
            $values=[$title,$description,json_encode($options),...$dates,$capacity,$minor,$currency,!empty($_POST['enabled'])?1:0];
            if($id){$check=db()->prepare('SELECT COUNT(*) FROM community_entries e JOIN community_items i ON i.id=e.item_id WHERE i.id=? AND i.plugin_key=?');$check->execute([$id,$key]);if($type==='poll'&&(int)$check->fetchColumn()>0)throw new RuntimeException('Głosowanie z oddanymi głosami nie może być edytowane.');db()->prepare('UPDATE community_items SET title=?,description=?,options_json=?,starts_at=?,ends_at=?,capacity=?,price_minor=?,currency=?,enabled=? WHERE id=? AND plugin_key=?')->execute([...$values,$id,$key]);}
            else db()->prepare('INSERT INTO community_items(title,description,options_json,starts_at,ends_at,capacity,price_minor,currency,enabled,plugin_key) VALUES(?,?,?,?,?,?,?,?,?,?)')->execute([...$values,$key]);
            audit_log('community.item_saved',$key,(string)$id);$message='Zapisano.';
        }elseif($action==='entry_state'){
            $state=(string)($_POST['state']??'');$states=['application'=>['pending','accepted','rejected'],'event'=>['registered','cancelled'],'poll'=>['recorded'],'shop'=>['pending','confirmed','fulfilled','cancelled']][$type];if(!in_array($state,$states,true))throw new RuntimeException('Nieprawidłowy status.');
            db()->prepare('UPDATE community_entries e JOIN community_items i ON i.id=e.item_id SET e.state=? WHERE e.id=? AND i.plugin_key=?')->execute([$state,(int)($_POST['entry_id']??0),$key]);audit_log('community.entry_updated',$key,(string)($_POST['entry_id']??0),['state'=>$state]);$message='Status zapisany.';
        }else throw new RuntimeException('Nieprawidłowa operacja.');
    }catch(Throwable $e){$message=$e->getMessage();}
    $title='Zarządzanie modułem';require dirname(__DIR__).'/templates/default/header.php';echo '<main class="page container"><p><a href="/admin/?tab=plugins">← Pluginy</a></p><h1>'.e($key).'</h1><p>'.e(le_text($message)).'</p>';
    $edit=null;if(isset($_GET['edit'])){$q=db()->prepare('SELECT * FROM community_items WHERE id=? AND plugin_key=?');$q->execute([(int)$_GET['edit'],$key]);$edit=$q->fetch()?:null;}
    echo '<form method="post" class="card card-pad">'.csrf_field().'<input type="hidden" name="action" value="item_save"><input type="hidden" name="id" value="'.(int)($edit['id']??0).'">';
    foreach(['title'=>'Tytuł','description'=>'Opis','capacity'=>'Limit uczestników / zamówień (0 = bez limitu)'] as $name=>$label)echo '<label>'.e(le_text($label)).'<input class="input" name="'.$name.'" value="'.e($edit[$name]??($name==='capacity'?'0':'')).'"></label>';
    if($type==='poll')echo ('<label>'.e(le_text('Opcje (każda w osobnej linii)')).'<textarea class="input" name="options">').e(implode("\n",json_decode($edit['options_json']??'[]',true)?:[])).'</textarea></label>';
    foreach(['starts_at'=>'Początek','ends_at'=>'Koniec'] as $name=>$label)echo '<label>'.e(le_text($label)).'<input class="input" type="datetime-local" name="'.$name.'" value="'.e(!empty($edit[$name])?date('Y-m-d\TH:i',strtotime($edit[$name])):'').'"></label>';
    if($type==='shop')echo ('<label>'.e(le_text('Cena')).'<input class="input" name="price" value="').number_format(($edit['price_minor']??0)/100,2,'.','').('"></label><label>'.e(le_text('Waluta')).'<select class="input" name="currency"><option>PLN</option><option>EUR</option><option>USD</option></select></label>');
    echo '<label><input type="checkbox" name="enabled" value="1" '.(($edit['enabled']??1)?'checked':'').'> Aktywne</label><button class="btn primary">Zapisz</button></form>';
    $q=db()->prepare('SELECT * FROM community_items WHERE plugin_key=? ORDER BY id DESC LIMIT 100');$q->execute([$key]);foreach($q as $row)echo '<p>'.e($row['title']).' <a href="?plugin='.e($key).'&amp;edit='.$row['id'].'">Edytuj</a></p>';
    $q=db()->prepare('SELECT e.*,i.title,u.username FROM community_entries e JOIN community_items i ON i.id=e.item_id JOIN users u ON u.id=e.user_id WHERE i.plugin_key=? ORDER BY e.id DESC LIMIT 200');$q->execute([$key]);echo ('<h2>'.e(le_text('Zgłoszenia / zamówienia')).'</h2>');
    foreach($q as $row){echo '<section class="card card-pad"><p>'.e($row['title'].' · '.$row['username'].' · '.$row['state']).'</p><p>'.nl2br(e($row['response'])).'</p><form method="post">'.csrf_field().'<input type="hidden" name="action" value="entry_state"><input type="hidden" name="entry_id" value="'.$row['id'].'"><select name="state" class="input">';foreach(['application'=>['pending','accepted','rejected'],'event'=>['registered','cancelled'],'poll'=>['recorded'],'shop'=>['pending','confirmed','fulfilled','cancelled']][$type] as $s)echo '<option '.($row['state']===$s?'selected':'').'>'.e($s).'</option>';echo ('</select><button class="btn">'.e(le_text('Zapisz status')).'</button></form></section>');}
    echo '</main>';require dirname(__DIR__).'/templates/default/footer.php';
}
