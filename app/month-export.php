<?php
declare(strict_types=1);
// A read-only, consistent database snapshot. Never export credentials or other users.
function month_backup(int $user,string $month): array {
    $pdo=db();
    if (mysql_backend()) { $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ'); }
    $pdo->beginTransaction();
    try {
        $snapshot=month_data($user,$month);
        $snapshot['archived_outputs']=array_values(array_filter(output_rows($user,$month,true),fn($row)=>$row['archived']));
        $review=query('SELECT * FROM month_reviews WHERE user_id=? AND month=?',[$user,$month])[0]??null;
        if ($review) { $review['user_id']=(int)$review['user_id']; $review['completed']=(bool)$review['completed']; }
        $snapshot['month_review']=$review;
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); } throw $error;
    }
    $calendar=[];$first=new DateTimeImmutable($month.'-01');
    for ($day=1;$day<=(int)$first->format('t');$day++) {
        $date=sprintf('%s-%02d',$month,$day);$weekday=(int)(new DateTimeImmutable($date))->format('N');
        $calendar[]=['date'=>$date,'weekday'=>$weekday,'week_start'=>$weekday===1,'weekend'=>$weekday>=6];
    }
    $symbols=[];
    foreach ($snapshot['items'] as $item) {
        $symbol=$item['symbol'];
        if ($symbol==='') {
            foreach (['羽毛球'=>'🏸','跑步'=>'🏃','力量'=>'💪','公积金'=>'公','社保'=>'社','对账'=>'对','财务月报'=>'¥','月计划'=>'❀','生日'=>'🎂'] as $name=>$preset) {
                if (str_contains($item['title'],$name)) { $symbol=$preset;break; }
            }
            if ($symbol==='') { $symbol=$item['once_only']?(preg_split('//u',$item['title'],-1,PREG_SPLIT_NO_EMPTY)[0]??'✓'):'✓'; }
        }
        $style=$symbol==='❀'?'flower':($item['once_only']&&preg_match_all('/./us',$symbol)===1?'circled':'plain');
        $symbols[]=['item_id'=>$item['id'],'symbol'=>$symbol,'style'=>$style];
    }
    return ['format'=>'monthly-plan-tracker/month-backup','format_version'=>1,'app_version'=>api_spec()['app_version'],
        'exported_at'=>gmdate(DATE_ATOM),'month'=>$month,'timezone'=>$snapshot['timezone'],'categories'=>categories(),'calendar'=>$calendar,
        'rendering'=>['as_of_date'=>date('Y-m-d'),'week_starts_on'=>1,'item_symbols'=>$symbols,
            'reading'=>['title_prefix'=>'阅读','completed_note_is_book_title'=>true],
            'output_letters'=>['book_note'=>'读','article'=>'文','travel_note'=>'旅','legacy'=>'出'],
            'blank_cell_meaning'=>'unrecorded'],
        'data'=>$snapshot];
}
