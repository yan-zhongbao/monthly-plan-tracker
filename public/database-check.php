<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
$root=dirname(__DIR__);
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; style-src 'self'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
if (is_file($root.'/config.php') || !is_file($root.'/storage/setup.key') || empty($_SERVER['HTTPS'])) { http_response_code(403); exit('检测入口已关闭或需要 HTTPS。'); }
if (!is_dir($root.'/storage/sessions')) { mkdir($root.'/storage/sessions',0700,true); }
session_name('month_setup');
session_start(['save_path'=>$root.'/storage/sessions','use_strict_mode'=>1,'cookie_httponly'=>true,'cookie_samesite'=>'Strict','cookie_secure'=>true]);
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));
$message=''; $stage='校验请求';
if ($_SERVER['REQUEST_METHOD']==='POST') {
 try {
  if (!hash_equals($_SESSION['csrf'],(string)($_POST['csrf'] ?? '')) || !hash_equals(trim(file_get_contents($root.'/storage/setup.key')),trim((string)($_POST['setup_key'] ?? '')))) { throw new RuntimeException('初始化码或页面校验失败。'); }
  $settings=['host'=>'127.0.0.1','port'=>(int)($_POST['port'] ?? 3306),'name'=>trim((string)($_POST['database'] ?? '')),'user'=>trim((string)($_POST['db_user'] ?? '')),'password'=>(string)($_POST['db_password'] ?? '')];
  if ($settings['user']==='' || strtolower($settings['user'])==='root') { throw new RuntimeException('请使用专用数据库账号。'); }
  $stage='连接数据库'; $pdo=mysql_connect($settings);
  $stage='检查专用库';
  foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) { if (!in_array($table,['schema_migrations','items','records','audit_log','login_attempts','user_write_locks','month_reviews'],true)) { throw new RuntimeException('数据库包含其他项目的数据表。'); } }
  $stage='执行建表迁移'; mysql_migrate($pdo);
  $stage='校验数据库读写';
  $pdo->beginTransaction();
  $pdo->exec('INSERT IGNORE INTO user_write_locks (user_id) VALUES (-2147000001)');
  $stmt=$pdo->prepare('SELECT user_id FROM user_write_locks WHERE user_id=? FOR UPDATE'); $stmt->execute([-2147000001]);
  if ((int)$stmt->fetchColumn()!==-2147000001) { throw new RuntimeException('读写测试失败。'); }
  $pdo->rollBack();
  $message='数据库连接、建表和事务读写检查全部通过。MySQL 版本：'.$pdo->query('SELECT VERSION()')->fetchColumn();
 } catch (Throwable $e) {
  if (isset($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); }
  if ($e instanceof PDOException) {
   $code=(int)($e->errorInfo[1] ?? 0);
   $details=[1045=>'数据库用户名或密码校验未通过',1044=>'账号没有数据库访问权限',1049=>'数据库不存在',2002=>'数据库服务地址或端口不可连接',1064=>'数据库语句语法不兼容',1146=>'需要的数据表未创建',1071=>'索引长度不兼容'];
   $message=$stage.'失败；MySQL 错误码 '.$code.'：'.($details[$code] ?? '需要检查服务器数据库日志');
   error_log('Month database check stage='.$stage.' driver_code='.$code);
  } else { $message=$stage.'失败：'.$e->getMessage(); }
 }
}
function dbcheck_h(string $s): string { return htmlspecialchars($s,ENT_QUOTES,'UTF-8'); }
?>
<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>数据库连接检测</title><link rel="stylesheet" href="assets/app.css"></head><body><main class="login-page"><section class="login-card"><h1>数据库连接检测</h1><p>仅检测专用数据库，不设置或修改笔记本登录密码。</p>
<?php if ($message!==''): ?><p role="status"><?= dbcheck_h($message) ?></p><?php endif; ?>
<form method="post" autocomplete="off"><input type="hidden" name="csrf" value="<?= dbcheck_h($_SESSION['csrf']) ?>">
<label>初始化码<input type="password" name="setup_key" required></label>
<label>端口<input type="number" name="port" value="3306" required></label>
<label>数据库名称<input name="database" value="month_tracker" required></label>
<label>数据库用户名<input name="db_user" value="month_tracker" required></label>
<label>数据库密码<input type="password" name="db_password" required></label>
<button class="primary full" type="submit">检测数据库连接与读写</button></form><p><a href="setup.php">返回初始化</a></p></section></main></body></html>
