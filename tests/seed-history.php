<?php
if (PHP_SAPI !== 'cli') { exit; }
require dirname(__DIR__).'/app/bootstrap.php';
// Fixture import only. Production APIs deliberately cannot edit old months.
foreach ([['2026-06','多邻国','成长'],['2026-08','多邻国','成长'],['2026-09','阅读','成长'],['2026-07','得到','成长'],['2026-10','得到','成长'],['2026-11','未来项目','成长'],['2026-09','跑步','健康']] as [$month,$title,$category]) {
    execute('INSERT INTO items (user_id,month,title,category,tracked,created_at,updated_at) VALUES (1,?,?,?,1,?,?)',[$month,$title,$category,date(DATE_ATOM),date(DATE_ATOM)]);
}
