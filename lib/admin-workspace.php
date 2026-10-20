<?php
declare(strict_types=1);

function le_admin_navigation(array $user): array {
    $groups = [
        'Przestrzeń pracy' => [['dashboard','⌂','Przegląd',null],['posts','✦','Wpisy','manage_content'],['pages','▤','Podstrony','manage_pages'],['media','▧','Biblioteka mediów','manage_media'],['gallery','▣','Galeria','manage_gallery']],
        'Twój serwis' => [['studio','✧','LevelEngine Studio','__studio__'],['designstudio','◐','Wygląd strony','manage_themes'],['menu','☷','Nawigacja','manage_settings'],['servers','◉','Serwery i LevelBridge','manage_servers'],['users','♙','Użytkownicy','manage_users'],['roles','◆','Rangi i uprawnienia','manage_roles']],
        'Narzędzia' => [['plugins','⬡','Pluginy','manage_plugins'],['themes','◈','Motywy','manage_themes'],['marketplace','▦','Marketplace','__market__'],['settings','⚙','Ustawienia','manage_settings'],['audit','◷','Dziennik aktywności','view_audit_log'],['backups','⛁','Kopie zapasowe','__head__'],['system','◎','Stan systemu','__head__'],['upgrades','↻','Aktualizacje','__head__'],['security','◇','Bezpieczeństwo konta',null]],
    ];
    foreach ($groups as $group => &$items) {
        $items = array_values(array_filter($items, static function(array $item) use ($user): bool {
            return match ($item[3]) {
                null => true,
                '__head__' => (int)$user['role_id'] === 1,
                '__studio__' => has_permission('manage_pages',$user)||has_permission('manage_settings',$user),
                '__market__' => has_permission('manage_plugins',$user)||has_permission('manage_themes',$user),
                default => has_permission($item[3],$user),
            };
        }));
    }
    unset($items);
    return array_filter($groups);
}

function le_admin_dashboard(array $user): array {
    $pdo = db();
    $data = ['metrics'=>[], 'recent'=>[], 'schedule'=>[], 'activity'=>[], 'servers'=>[], 'checks'=>[], 'series'=>[]];
    if (has_permission('manage_content',$user)) {
        $counts = $pdo->query("SELECT COALESCE(SUM(status='published'),0) published,COALESCE(SUM(status='draft'),0) drafts,COALESCE(SUM(status='scheduled'),0) scheduled FROM posts WHERE deleted_at IS NULL")->fetch();
        $data['metrics'][] = ['label'=>'Opublikowane wpisy','value'=>(int)$counts['published'],'hint'=>'Treści dostępne dla czytelników','icon'=>'✦','tone'=>'blue','tab'=>'posts'];
        $data['metrics'][] = ['label'=>'Szkice i plany','value'=>(int)$counts['drafts']+(int)$counts['scheduled'],'hint'=>le_text('Szkice').': '.$counts['drafts'].' · '.le_text('Zaplanowane').': '.$counts['scheduled'],'icon'=>'▤','tone'=>'violet','tab'=>'posts'];
        $data['recent'] = $pdo->query('SELECT p.id,p.title,p.status,p.updated_at,u.username FROM posts p LEFT JOIN users u ON u.id=p.author_id WHERE p.deleted_at IS NULL ORDER BY p.updated_at DESC,p.id DESC LIMIT 5')->fetchAll();
    }
    if (has_permission('manage_users',$user)) {
        $data['metrics'][] = ['label'=>'Społeczność','value'=>(int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(),'hint'=>'Zarejestrowane konta','icon'=>'♙','tone'=>'green','tab'=>'users'];
    } elseif (has_permission('manage_pages',$user)) {
        $data['metrics'][] = ['label'=>'Podstrony','value'=>(int)$pdo->query('SELECT COUNT(*) FROM pages WHERE deleted_at IS NULL')->fetchColumn(),'hint'=>'Strony tekstowe i projekty Studio','icon'=>'▤','tone'=>'green','tab'=>'pages'];
    }
    if (has_permission('manage_media',$user)) {
        $data['metrics'][] = ['label'=>'Biblioteka mediów','value'=>(int)$pdo->query('SELECT COUNT(*) FROM media')->fetchColumn(),'hint'=>'Obrazy gotowe do użycia','icon'=>'▧','tone'=>'amber','tab'=>'media'];
    }
    $days = (($_GET['period']??'14')==='30') ? 30 : 14;
    $since = (new DateTimeImmutable('today'))->modify('-'.($days-1).' days');
    for ($i=0;$i<$days;$i++) $data['series'][$since->modify('+'.$i.' days')->format('Y-m-d')] = ['posts'=>0,'users'=>0];
    foreach (['posts'=>'manage_content','users'=>'manage_users'] as $table=>$permission) {
        if (!has_permission($permission,$user)) continue;
        $q=$pdo->prepare("SELECT DATE(created_at) day,COUNT(*) total FROM `$table` WHERE created_at>=?".($table==='posts'?' AND deleted_at IS NULL':'').' GROUP BY DATE(created_at)');
        $q->execute([$since->format('Y-m-d H:i:s')]);
        foreach ($q as $row) if (isset($data['series'][$row['day']])) $data['series'][$row['day']][$table]=(int)$row['total'];
    }
    foreach (['post'=>'manage_content','page'=>'manage_pages'] as $kind=>$permission) {
        if (!has_permission($permission,$user)) continue;
        $table=le_kind_table($kind);$q=$pdo->query("SELECT id,title,publish_at FROM `$table` WHERE status='scheduled' AND deleted_at IS NULL ORDER BY publish_at ASC LIMIT 5");
        foreach ($q as $row) $data['schedule'][]=$row+['kind'=>$kind];
    }
    usort($data['schedule'],fn($a,$b)=>strcmp((string)$a['publish_at'],(string)$b['publish_at']));$data['schedule']=array_slice($data['schedule'],0,5);
    if (has_permission('view_audit_log',$user)) $data['activity']=$pdo->query('SELECT username,action,created_at FROM audit_log ORDER BY id DESC LIMIT 5')->fetchAll();
    if (has_permission('manage_servers',$user)) $data['servers']=levelbridge_servers();
    if ((int)$user['role_id']===1) {
        $data['checks'][]=['ok'=>!empty($user['twofa_enabled']),'label'=>'Dodatkowa ochrona konta','text'=>!empty($user['twofa_enabled'])?'Uwierzytelnianie 2FA jest aktywne.':'Włącz 2FA, aby lepiej chronić konto administratora.','url'=>'/account/security'];
        $backup=$pdo->query('SELECT created_at FROM backups ORDER BY created_at DESC LIMIT 1')->fetchColumn();
        $fresh=$backup && strtotime($backup)>time()-7*86400;
        $data['checks'][]=['ok'=>(bool)$fresh,'label'=>'Kopia zapasowa','text'=>$backup?le_text('Ostatnia kopia:').' '.date('d.m.Y H:i',strtotime($backup)):'Utwórz pierwszą kopię przed większymi zmianami.','url'=>admin_url('backups')];
        $queue=(int)$pdo->query("SELECT COUNT(*) FROM delivery_queue WHERE state IN ('failed','uncertain')")->fetchColumn();
        $data['checks'][]=['ok'=>$queue===0,'label'=>'Kolejka integracji','text'=>$queue?$queue.' zadań wymaga sprawdzenia.':'Brak zadań wymagających interwencji.','url'=>'/admin/suite.php?section=queue'];
    }
    if ((int)$user['role_id']===1) {
        $last=setting('last_worker_at','');$working=$last!==''&&strtotime($last)>time()-600;
        $data['checks'][]=['ok'=>$working,'label'=>'Harmonogram publikacji','text'=>$working?'Harmonogram był aktywny w ostatnich 10 minutach.':'Skonfiguruj uruchamianie workera co minutę na hostingu.','url'=>'/admin/suite.php?section=overview'];
    }
    return $data;
}

function le_admin_list(string $tab): array {
    $type=$tab==='users'?'users':le_kind_table($tab==='posts'?'post':'page');
    $query=mb_substr(trim((string)($_GET['q']??'')),0,80);
    $status=(string)($_GET['status']??'');
    $allowed=$type==='users'?['active','banned']:['draft','published','scheduled'];
    if (!in_array($status,$allowed,true)) $status='';
    $where=[];$params=[];
    if ($type!=='users') $where[]='t.deleted_at IS NULL';
    if ($query!=='') { $where[]=$type==='users'?'(t.username LIKE ? OR t.email LIKE ?)':'(t.title LIKE ? OR t.slug LIKE ?)';$term='%'.strtr($query,['='=>'==','%'=>'=%','_'=>'=_']).'%';$where[count($where)-1]=str_replace('LIKE ?','LIKE ? ESCAPE \'=\'',$where[count($where)-1]);$params=[$term,$term]; }
    if ($status!=='') {$where[]='t.status=?';$params[]=$status;}
    $clause=$where?' WHERE '.implode(' AND ',$where):'';
    $q=db()->prepare("SELECT COUNT(*) FROM `$type` t".$clause);$q->execute($params);$total=(int)$q->fetchColumn();
    $pages=max(1,(int)ceil($total/25));$page=min($pages,max(1,(int)($_GET['page']??1)));$offset=($page-1)*25;
    $select=match($type){'users'=>'t.*,r.name role_name,r.color role_color','posts'=>'t.*,u.username',default=>'t.*'};
    $join=match($type){'users'=>' JOIN roles r ON r.id=t.role_id','posts'=>' LEFT JOIN users u ON u.id=t.author_id',default=>''};
    $q=db()->prepare("SELECT $select FROM `$type` t$join$clause ORDER BY t.created_at DESC,t.id DESC LIMIT 25 OFFSET $offset");$q->execute($params);
    return ['rows'=>$q->fetchAll(),'total'=>$total,'pages'=>$pages,'page'=>$page,'q'=>$query,'status'=>$status];
}

function le_admin_status(string $status): string {
    return le_text(['published'=>'Opublikowany','draft'=>'Szkic','scheduled'=>'Zaplanowany','active'=>'Aktywne','banned'=>'Zablokowane'][$status]??$status);
}

function le_admin_search(array $user,string $term): array {
    $results=[];$term=mb_substr(trim($term),0,80);if(mb_strlen($term)<2)return [];
    foreach (le_admin_navigation($user) as $group=>$items) foreach ($items as $item) {
        $label=le_text($item[2]);if(mb_stripos($label,$term)!==false)$results[]=['title'=>$label,'detail'=>le_text($group),'url'=>admin_url($item[0])];
    }
    $like='%'.strtr($term,['='=>'==','%'=>'=%','_'=>'=_']).'%';
    foreach (['post'=>'manage_content','page'=>'manage_pages'] as $kind=>$permission) {
        if (!has_permission($permission,$user)) continue;$table=le_kind_table($kind);
        $q=db()->prepare("SELECT id,title,status FROM `$table` WHERE deleted_at IS NULL AND title LIKE ? ESCAPE '=' ORDER BY updated_at DESC LIMIT 5");$q->execute([$like]);
        foreach ($q as $row) $results[]=['title'=>$row['title'],'detail'=>le_text($kind==='post'?'Wpis':'Podstrona').' · '.le_admin_status($row['status']),'url'=>admin_url($kind.'_edit',['id'=>$row['id']])];
    }
    if (has_permission('manage_users',$user)) {
        $q=db()->prepare("SELECT username FROM users WHERE username LIKE ? ESCAPE '=' ORDER BY username LIMIT 5");$q->execute([$like]);
        foreach ($q as $row) $results[]=['title'=>$row['username'],'detail'=>le_text('Użytkownik'),'url'=>admin_url('users',['q'=>$row['username']])];
    }
    return array_slice($results,0,20);
}
