<?php
if (PHP_SAPI !== 'cli') { exit; }
$directory = $argv[1];
if (!is_dir($directory)) { mkdir($directory,0700,true); }
$token1 = bin2hex(random_bytes(32)); $token2 = bin2hex(random_bytes(32));
$config = ['timezone'=>'Asia/Shanghai','database'=>$directory.'/tracker.sqlite','users'=>[
    1=>['name'=>'测试用户','password_hash'=>password_hash('notebook-preview-only',PASSWORD_DEFAULT),'api_token_hash'=>hash('sha256',$token1)],
    2=>['name'=>'隔离用户','password_hash'=>password_hash('isolated-test-only',PASSWORD_DEFAULT),'api_token_hash'=>hash('sha256',$token2)],
]];
file_put_contents($directory.'/config.php',"<?php\nreturn ".var_export($config,true).";\n");
echo json_encode(['token1'=>$token1,'token2'=>$token2]);
