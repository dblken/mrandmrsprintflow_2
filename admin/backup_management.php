<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_role('Admin');
if (file_exists(__DIR__ . '/../config.php')) require_once __DIR__ . '/../config.php';
$base_path = defined('BASE_PATH') ? BASE_PATH : '/printflow';
$backup_dir = rtrim(sys_get_temp_dir(), "\\/") . DIRECTORY_SEPARATOR . 'printflow-database-backups';
$backup_key = 'printflow_database_backup_download';
$backup_ttl = 900;
$user_id = (int)(get_user_id() ?? 0);

function pf_backup_cleanup(string $dir, int $ttl): void {
    if (!is_dir($dir)) return;
    $cutoff = time() - max(60, $ttl);
    foreach (glob($dir . DIRECTORY_SEPARATOR . 'printflow_backup_*') ?: [] as $file) if (is_file($file) && (int)@filemtime($file) < $cutoff) @unlink($file);
}
function pf_backup_config(): array {
    global $db_config;
    if (isset($db_config) && is_array($db_config)) return $db_config;
    $env = static function (array $names): string { foreach ($names as $name) { $value = function_exists('printflow_env') ? printflow_env($name) : false; if ($value !== false && trim((string)$value) !== '') return (string)$value; } return ''; };
    return ['host'=>$env(['DB_HOST','PRINTFLOW_DB_HOST']), 'user'=>$env(['DB_USER','PRINTFLOW_DB_USER']), 'pass'=>$env(['DB_PASSWORD','PRINTFLOW_DB_PASSWORD','PRINTFLOW_DB_PASS']), 'name'=>$env(['DB_NAME','PRINTFLOW_DB_NAME']), 'port'=>$env(['DB_PORT','PRINTFLOW_DB_PORT']) ?: '3306'];
}
function pf_backup_dump(string $dir): array {
    $cfg = pf_backup_config();
    foreach (['host','user','pass','name','port'] as $key) if (trim((string)($cfg[$key] ?? '')) === '') return ['ok'=>false,'message'=>'Database backup is not available because the database configuration is incomplete.'];
    if (!preg_match('/^[A-Za-z0-9_$-]+$/', (string)$cfg['name'])) return ['ok'=>false,'message'=>'Database backup is not available for the current database configuration.'];
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) return ['ok'=>false,'message'=>'The temporary backup location is not available.'];
    $defaults = tempnam($dir, 'printflow_backup_client_'); $dump = tempnam($dir, 'printflow_backup_');
    if ($defaults === false || $dump === false) { if (is_string($defaults)) @unlink($defaults); if (is_string($dump)) @unlink($dump); return ['ok'=>false,'message'=>'The server could not prepare a temporary backup file.']; }
    $credentials = "[client]\n" . 'host=' . $cfg['host'] . "\n" . 'port=' . (int)$cfg['port'] . "\n" . 'user=' . $cfg['user'] . "\n" . 'password=' . $cfg['pass'] . "\n";
    if (@file_put_contents($defaults, $credentials, LOCK_EX) === false) { @unlink($defaults); @unlink($dump); return ['ok'=>false,'message'=>'The server could not prepare the database backup.']; }
    @chmod($defaults, 0600); $configured = trim((string)(function_exists('printflow_env') ? printflow_env('PRINTFLOW_MYSQLDUMP_PATH') : ''));
    $candidates = array_merge($configured !== '' ? [$configured] : [], ['/usr/bin/mysqldump','/usr/local/bin/mysqldump','/opt/lampp/bin/mysqldump','mysqldump']); $found = false;
    foreach (array_unique($candidates) as $candidate) {
        if ($candidate !== 'mysqldump' && (!is_file($candidate) || !is_executable($candidate))) continue; $found = true;
        $command = [$candidate,'--defaults-extra-file='.$defaults,'--single-transaction','--quick','--routines','--triggers','--events','--hex-blob','--databases',(string)$cfg['name']];
        $proc = @proc_open($command,[0=>['pipe','r'],1=>['file',$dump,'wb'],2=>['pipe','w']],$pipes); if (!is_resource($proc)) continue;
        if (isset($pipes[0]) && is_resource($pipes[0])) fclose($pipes[0]); if (isset($pipes[2]) && is_resource($pipes[2])) { stream_get_contents($pipes[2]); fclose($pipes[2]); }
        $exit = proc_close($proc); if ($exit === 0 && is_file($dump) && (int)@filesize($dump) > 0) { @unlink($defaults); return ['ok'=>true,'path'=>$dump]; }
        error_log('[printflow_backup] mysqldump failed with exit code ' . (int)$exit); break;
    }
    @unlink($defaults); @unlink($dump); return ['ok'=>false,'message'=>$found ? 'The database backup could not be generated. No database records were changed.' : 'Database backup is unavailable because mysqldump is not installed or configured on this server.'];
}

pf_backup_cleanup($backup_dir, $backup_ttl); $status=''; $message='';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_database_backup'])) {
    if (!verify_csrf_token((string)($_POST['csrf_token'] ?? ''))) { $status='error'; $message='Your security token is invalid or expired. Please try again.'; }
    else { $result=pf_backup_dump($backup_dir); if (!empty($result['ok'])) { $_SESSION[$backup_key]=['id'=>bin2hex(random_bytes(24)),'path'=>$result['path'],'name'=>'printflow_database_backup_'.date('Y-m-d_H-i-s').'.sql','created_at'=>time()]; log_activity($user_id,'Database backup generated','Database-only backup created successfully.'); header('Location: '.$base_path.'/admin/backup_management.php?status=created'); exit; } log_activity($user_id,'Database backup generation failed','Database-only backup generation failed.'); $status='error'; $message=(string)$result['message']; }
}
if (isset($_GET['download']) && hash_equals((string)($_SESSION[$backup_key]['id'] ?? ''),(string)$_GET['download'])) {
    $pending=$_SESSION[$backup_key]; $path=(string)($pending['path'] ?? ''); $expected=realpath($backup_dir); $real=is_file($path) ? realpath($path) : false;
    if ((int)($pending['created_at'] ?? 0)>0 && time()-(int)$pending['created_at'] <= $backup_ttl && $expected !== false && $real !== false && str_starts_with($real,rtrim($expected,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)) { log_activity($user_id,'Database backup downloaded','Database-only backup downloaded successfully.'); unset($_SESSION[$backup_key]); header('Content-Type: application/sql; charset=binary'); header('Content-Disposition: attachment; filename="'.basename((string)$pending['name']).'"'); header('Content-Length: '.(string)filesize($real)); header('X-Content-Type-Options: nosniff'); readfile($real); @unlink($real); exit; }
    unset($_SESSION[$backup_key]); $status='error'; $message='The backup download has expired. Please generate a new backup.';
}
if (($_GET['status'] ?? '') === 'created' && isset($_SESSION[$backup_key])) { $status='success'; $message='Backup created successfully. Download it below before it expires.'; }
$page_title='Backup Management - PrintFlow';
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title><?php echo htmlspecialchars($page_title,ENT_QUOTES,'UTF-8'); ?></title><?php include __DIR__.'/../includes/admin_style.php'; ?><style>
.backup-page{max-width:920px;margin:0 auto;padding:32px}.backup-card{background:#fff;border:1px solid #e5e7eb;border-radius:16px;padding:28px;box-shadow:0 8px 24px rgba(15,23,42,.05)}.backup-card h1{margin:0 0 8px;font-size:24px;color:#0f172a}.backup-card h2{margin:0 0 10px;font-size:18px;color:#0f172a}.backup-card p{color:#64748b;line-height:1.6}.backup-notice{margin:22px 0;padding:16px 18px;border-radius:10px;background:#fff7ed;border:1px solid #fed7aa;color:#9a3412}.backup-status{margin:18px 0;padding:14px 16px;border-radius:10px;font-weight:600}.backup-status.success{color:#166534;background:#dcfce7;border:1px solid #bbf7d0}.backup-status.error{color:#991b1b;background:#fee2e2;border:1px solid #fecaca}.backup-actions{display:flex;flex-wrap:wrap;gap:12px;align-items:center;margin-top:24px}.backup-button,.backup-download{display:inline-flex;align-items:center;justify-content:center;min-height:42px;padding:0 18px;border-radius:8px;font-weight:700;text-decoration:none;cursor:pointer}.backup-button{border:0;background:#0f3b46;color:#fff}.backup-download{border:1px solid #0f9d8f;color:#087f74;background:#fff}.backup-meta{color:#64748b;font-size:13px}@media(max-width:700px){.backup-page{padding:20px 16px}.backup-card{padding:22px 18px}.backup-actions>*{width:100%}}
</style></head><body><div class="dashboard-container"><?php include __DIR__.'/../includes/admin_sidebar.php'; ?><main class="main-content"><section class="backup-page"><div class="backup-card"><h1>Backup Management</h1><p>Create a database-only backup for controlled manual administration.</p><div class="backup-notice"><strong>Database Backup Only</strong><br>This backup contains PrintFlow database records. Uploaded government IDs, custom designs, payment proofs, product images, profile images, chat images, and other server files are not included.</div><?php if($message!==''): ?><div class="backup-status <?php echo $status==='success'?'success':'error'; ?>" role="status"><?php echo htmlspecialchars($message,ENT_QUOTES,'UTF-8'); ?></div><?php endif; ?><h2>Database Backup</h2><p>Backup generation uses the server's verified <code>mysqldump</code> utility when available. The temporary backup is stored outside the public web root and is removed after download or expiration.</p><div class="backup-actions"><form method="post"><?php echo csrf_field(); ?><button type="submit" name="create_database_backup" class="backup-button">Create Database Backup</button></form><?php if(isset($_SESSION[$backup_key]['id'])): ?><a class="backup-download" href="?download=<?php echo rawurlencode((string)$_SESSION[$backup_key]['id']); ?>">Download Backup</a><span class="backup-meta">Download expires after 15 minutes.</span><?php endif; ?></div></div></section></main></div><?php include __DIR__.'/../includes/footer.php'; ?></body></html>