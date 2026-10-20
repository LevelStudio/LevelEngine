<?php
declare(strict_types=1);
require __DIR__.'/../lib/bootstrap.php';
require __DIR__.'/../lib/admin-workspace.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');header('X-Robots-Tag: noindex, nofollow');
$user=current_user();
if (!$user || empty($user['can_access_admin'])) {http_response_code(403);echo '{"ok":false}';exit;}
if (($_SERVER['REQUEST_METHOD']??'GET')!=='GET') {http_response_code(405);header('Allow: GET');echo '{"ok":false}';exit;}
try {echo json_encode(['ok'=>true,'items'=>le_admin_search($user,(string)($_GET['q']??''))],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);}
catch(Throwable $error){http_response_code(500);levelengine_log('error','Wyszukiwanie panelu nie powiodło się');echo json_encode(['ok'=>false,'error'=>le_text('Nie udało się wyszukać. Spróbuj ponownie.')]);}
