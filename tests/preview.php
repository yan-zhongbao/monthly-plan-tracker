<?php
// Local preview only. Never upload tests/ or .tools/ to the web server.
if (PHP_SAPI !== 'cli') { exit; }
require dirname(__DIR__) . '/app/bootstrap.php';
$month = '2026-10';
if (query('SELECT id FROM items WHERE user_id=1 LIMIT 1')) { exit("预览数据已存在。\n"); }
$items = [
 ['多邻国','成长',true,31,false],['得到','成长',true,31,false],['阅读','成长',true,20,false],
 ['跑步','健康',true,16,false],['羽毛球','健康',true,8,false],['力量训练','健康',true,8,false],
 ['钢琴','自我实现',true,20,false],['每周反思','成长',true,4,false],['月回顾','成长',true,1,false],
 ['安排国庆旅行','家庭',false,null,false],['见一位老朋友','社交',false,null,false],['看一部电影','娱乐',false,null,false],['玩游戏三小时','娱乐',false,null,false],
 ['临时参加徒步','健康',true,null,true],['读完一本书','成长',true,null,true],
];
foreach ($items as $index => [$title,$category,$tracked,$target,$unplanned]) {
    $now = date(DATE_ATOM);
    execute('INSERT INTO items (user_id,month,title,category,target,tracked,unplanned,sort_order,created_at,updated_at) VALUES (1,?,?,?,?,?,?,?,?,?)', [$month,$title,$category,$target,(int)$tracked,(int)$unplanned,$index*10,$now,$now]);
    $id = (int)db()->lastInsertId();
    if (!$tracked) { continue; }
    $days = match($title) { '跑步'=>[1,3,5,8], '羽毛球'=>[2,6], '力量训练'=>[4,7], '每周反思'=>[4], '月回顾'=>[], '临时参加徒步'=>[3], '读完一本书'=>[7], '阅读'=>[1,2,4,6,7], default=>[1,2,3,4,5,6,7,8] };
    foreach ($days as $day) {
        $note = $title==='读完一本书' ? '《悉达多》' : ($title==='临时参加徒步' ? '与朋友一起走了山间小路' : '');
        execute('INSERT INTO records (user_id,item_id,date,completed,note,source,updated_at) VALUES (1,?,?,1,?,?,?)', [$id,$month.'-'.str_pad((string)$day,2,'0',STR_PAD_LEFT),$note,'openclaw',$now]);
    }
}
echo "预览样例已生成（不是正式记录）。\n";
