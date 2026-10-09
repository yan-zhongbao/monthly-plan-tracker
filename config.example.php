<?php
// MySQL 推荐使用 public/setup.php 首次初始化；此文件仅展示格式。
return [
    'timezone' => 'Asia/Shanghai',
    'database' => [
        'host' => '127.0.0.1', 'port' => 3306,
        'name' => 'month_tracker', 'user' => 'month_tracker',
        'password' => 'REPLACE_WITH_DATABASE_PASSWORD',
    ],
    'storage' => __DIR__ . '/storage',
    'users' => [
        1 => [
            'name' => '我',
            'password_hash' => 'REPLACE_WITH_PASSWORD_HASH',
            'api_token_hash' => 'REPLACE_WITH_SHA256_OF_API_TOKEN',
        ],
    ],
];
