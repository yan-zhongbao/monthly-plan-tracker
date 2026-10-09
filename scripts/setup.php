<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit('CLI only'); }
$path = dirname(__DIR__) . '/config.php';
if (is_file($path)) { fwrite(STDERR, "config.php 已存在，不会覆盖。\n"); exit(1); }
if (!extension_loaded('pdo_sqlite')) { fwrite(STDERR, "请先启用 pdo_sqlite 扩展。\n"); exit(1); }
echo "设置网页登录密码（至少 12 个字符；终端输入会显示，请在私人终端操作）：\n";
$password = trim(fgets(STDIN));
if (strlen($password) < 12) { fwrite(STDERR, "密码太短。\n"); exit(1); }
$token = bin2hex(random_bytes(32));
$config = ['timezone'=>'Asia/Shanghai','database'=>dirname(__DIR__).'/storage/tracker.sqlite','users'=>[1=>['name'=>'我','password_hash'=>password_hash($password,PASSWORD_DEFAULT),'api_token_hash'=>hash('sha256',$token)]]];
file_put_contents($path, "<?php\nreturn " . var_export($config,true) . ";\n");
chmod($path,0600);
require dirname(__DIR__) . '/app/bootstrap.php';
db();
echo "\n初始化完成。API Token 仅此一次显示，请保存到 OpenClaw 的私密配置中：\n" . $token . "\n";
