<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit('CLI only'); }
$root = dirname(__DIR__);
if (is_file($root.'/config.php')) { exit("已经初始化，网页配置关闭。\n"); }
if (!is_dir($root.'/storage')) { mkdir($root.'/storage',0700,true); }
$path = $root.'/storage/setup.key';
$handle = fopen($path,'x');
if ($handle) { fwrite($handle,bin2hex(random_bytes(24))); fclose($handle); chmod($path,0600); }
echo "请在宝塔文件管理中私下查看 storage/setup.key，复制初始化码；然后打开网站 setup.php。不要把初始化码发到聊天。\n";
