<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
$root = dirname(__DIR__);
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; style-src 'self'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
if (is_file($root.'/config.php')) { http_response_code(403); exit('已经初始化，网页配置已关闭。'); }
if (empty($_SERVER['HTTPS']) && getenv('TRACKER_ALLOW_HTTP_SETUP') !== '1') { http_response_code(403); exit('请使用 HTTPS 初始化。'); }
if (!is_file($root.'/storage/setup.key')) { http_response_code(403); exit('网页初始化尚未启用，请按部署文档在服务器执行 scripts/prepare-web-setup.php。'); }
if (!is_dir($root.'/storage/sessions')) { mkdir($root.'/storage/sessions',0700,true); }
session_name('month_setup');
session_start(['save_path'=>$root.'/storage/sessions','use_strict_mode'=>1,'cookie_httponly'=>true,'cookie_samesite'=>'Strict','cookie_secure'=>!empty($_SERVER['HTTPS'])]);
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));
$error = ''; $token = null;
function setup_h(string $v): string { return htmlspecialchars($v,ENT_QUOTES,'UTF-8'); }
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $handle = null;
    try {
        if (!hash_equals($_SESSION['csrf'],(string)($_POST['csrf'] ?? ''))) { throw new RuntimeException('页面已失效，请刷新。'); }
        if (!hash_equals(trim(file_get_contents($root.'/storage/setup.key')),trim((string)($_POST['setup_key'] ?? '')))) { usleep(500000); throw new RuntimeException('初始化码不正确。'); }
        $password = (string)($_POST['login_password'] ?? '');
        if (strlen($password)<12 || strlen($password)>1024 || $password !== ($_POST['confirm_password'] ?? '')) { throw new RuntimeException('登录密码至少 12 个字符，两次输入须一致。'); }
        $settings = ['host'=>trim((string)($_POST['host'] ?? '127.0.0.1')),'port'=>(int)($_POST['port'] ?? 3306),'name'=>trim((string)($_POST['database'] ?? '')),'user'=>trim((string)($_POST['db_user'] ?? '')),'password'=>(string)($_POST['db_password'] ?? '')];
        if (!in_array($settings['host'],['127.0.0.1','localhost'],true)) { throw new RuntimeException('网页初始化只连接本机数据库。'); }
        if ($settings['user']==='' || strtolower($settings['user'])==='root' || $settings['password']==='') { throw new RuntimeException('请使用本项目专用数据库账号和密码。'); }
        if (!extension_loaded('pdo_mysql')) { throw new RuntimeException('请启用 PHP 的 pdo_mysql 扩展。'); }
        $handle = fopen($root.'/storage/setup.lock','c');
        if (!$handle || !flock($handle,LOCK_EX)) { throw new RuntimeException('初始化正在进行，请稍后重试。'); }
        if (is_file($root.'/config.php')) { throw new RuntimeException('已经初始化，不可重复提交。'); }
        $pdo = mysql_connect($settings);
        $allowed = ['schema_migrations','items','records','audit_log','login_attempts','user_write_locks','month_reviews'];
        foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) { if (!in_array($table,$allowed,true)) { throw new RuntimeException('请选择本项目专用的空数据库。'); } }
        mysql_migrate($pdo);
        if ((int)$pdo->query('SELECT COUNT(*) FROM items')->fetchColumn() !== 0 || (int)$pdo->query('SELECT COUNT(*) FROM audit_log')->fetchColumn() !== 0) { throw new RuntimeException('数据库已有记录，请使用原配置恢复，不能重新初始化。'); }
        $token = bin2hex(random_bytes(32));
        $configuration = ['timezone'=>'Asia/Shanghai','database'=>$settings,'storage'=>$root.'/storage','users'=>[1=>['name'=>'我','password_hash'=>password_hash($password,PASSWORD_DEFAULT),'api_token_hash'=>hash('sha256',$token)]]];
        $output = fopen($root.'/config.php','x');
        if (!$output) { throw new RuntimeException('不能创建 config.php，请检查项目目录权限。'); }
        chmod($root.'/config.php',0600);
        $text = "<?php\nreturn ".var_export($configuration,true).";\n";
        $written = fwrite($output,$text); fclose($output);
        if ($written !== strlen($text)) { unlink($root.'/config.php'); throw new RuntimeException('配置写入失败。'); }
        unlink($root.'/storage/setup.key');
        $_SESSION = []; session_destroy();
    } catch (Throwable $e) {
        $token = null;
        $error = $e instanceof PDOException ? '数据库连接或建表失败（MySQL 错误码 '.(int)($e->errorInfo[1] ?? 0).'），请先使用数据库检测页面定位。' : $e->getMessage();
        error_log('Month setup failed: '.($e instanceof PDOException ? 'database error '.$e->getCode() : $error));
    } finally { if (is_resource($handle)) { flock($handle,LOCK_UN); fclose($handle); } }
}
?>
<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>初始化月度计划与追踪</title><link rel="stylesheet" href="assets/app.css"></head><body>
<main class="login-page"><section class="login-card"><h1>初始化月度计划与追踪</h1>
<?php if ($token !== null): ?>
<p>MySQL 连接成功，数据库与登录配置已保存。初始化页面已关闭。</p>
<p>请立即把下面的 API Token 保存到 OpenClaw 的私密配置中，仅此一次显示：</p><textarea readonly rows="4"><?= setup_h($token) ?></textarea>
<p><a href="./">打开笔记本并登录 →</a></p>
<?php else: ?>
<p class="muted">首次配置，使用宝塔创建的本地专用数据库。已有 SQLite 配置请按迁移文档处理。</p>
<?php if ($error !== ''): ?><p class="error"><?= setup_h($error) ?></p><?php endif; ?>
<form method="post" autocomplete="off"><input type="hidden" name="csrf" value="<?= setup_h($_SESSION['csrf']) ?>">
<label>初始化码<input type="password" name="setup_key" required autocomplete="off"></label>
<label>数据库地址<input name="host" value="127.0.0.1" required></label>
<label>端口<input name="port" type="number" value="3306" min="1" max="65535" required></label>
<label>数据库名称<input name="database" value="month_tracker" required></label>
<label>数据库用户名<input name="db_user" value="month_tracker" required></label>
<label>数据库密码<input type="password" name="db_password" required autocomplete="off"></label>
<label>笔记本登录密码<input type="password" name="login_password" minlength="12" required autocomplete="new-password"></label>
<label>再次输入登录密码<input type="password" name="confirm_password" minlength="12" required autocomplete="new-password"></label>
<button class="primary full" type="submit">连接数据库并完成初始化</button>
</form><p><a href="database-check.php">单独检测数据库连接与读写</a></p><?php endif; ?></section></main></body></html>
