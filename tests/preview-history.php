<?php
// Isolated demonstration data only, for verifying history suggestions.
if (PHP_SAPI !== 'cli') { exit; }
require dirname(__DIR__) . '/app/bootstrap.php';
foreach ([['2026-09','英语听力','成长'],['2026-08','写作练习','成长'],['2026-07','英语听力','成长'],['2026-09','多邻国','成长'],['2026-09','社保','财务'],['2026-08','公积金','财务']] as $index=>[$month,$title,$category]) {
    if (query('SELECT id FROM items WHERE user_id=1 AND month=? AND title=?',[$month,$title])) { continue; }
    $now = date(DATE_ATOM);
    execute('INSERT INTO items (user_id,month,title,category,tracked,sort_order,created_at,updated_at) VALUES (1,?,?,?,1,?,?,?)',[$month,$title,$category,$index*10,$now,$now]);
}
echo "历史候选预览样例已准备。\n";
