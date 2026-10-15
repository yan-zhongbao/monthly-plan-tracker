<?php
declare(strict_types=1);
function normalize_journal(array $row): array {
    $row['user_id']=(int)$row['user_id']; $row['revision']=(int)$row['revision']; $row['manual_lock']=(bool)$row['manual_lock']; return $row;
}
function journal_rows(int $user,string $month): array {
    return array_map('normalize_journal',query('SELECT * FROM journals WHERE user_id=? AND date LIKE ? ORDER BY date',[$user,$month.'-%']));
}
function handle_journals(int $user,string $source,string $method): void {
    if ($method==='GET') {
        if (isset($_GET['date'])) {
            $date=date_value($_GET['date']); $access=month_access($user,substr($date,0,7));
            $row=query('SELECT * FROM journals WHERE user_id=? AND date=?',[$user,$date])[0]??null;
            respond(['date'=>$date,'journal'=>$row?normalize_journal($row):null,'access'=>$access,'read_only'=>$access['read_only']||$date>date('Y-m-d')]);
        }
        $month=month_value($_GET['month']??date('Y-m'));respond(['month'=>$month,'access'=>month_access($user,$month),'journals'=>journal_rows($user,$month)]);
    }
    if ($method!=='PUT') { fail('日记接口支持GET与PUT。',405); }
    $input=body();$date=date_value($input['date']??null);$content=$input['content']??null;
    string_value($content,10000,'日记'); // Validate UTF-8 and length, retaining original spaces and line breaks.
    if (!is_int($input['revision']??null)||$input['revision']<0) { fail('请先查询日记并传入revision；新日记为0。'); }
    $force=array_key_exists('force',$input)?boolean_value($input['force']):0;
    begin_write($user);assert_month_writable($user,substr($date,0,7));
    if ($date>date('Y-m-d')) { rollback_write(); respond(['error'=>'未来日期只能查看，不能填写日记。','code'=>'FUTURE_DATE'],403); }
    $before=query('SELECT * FROM journals WHERE user_id=? AND date=?',[$user,$date])[0]??null;
    $before=$before?normalize_journal($before):null;
    if ($before&&$before['content']===$content) { commit_write(); respond(['journal'=>$before,'unchanged'=>true]); }
    if ($input['revision']!==($before['revision']??0)) { rollback_write(); respond(['error'=>'日记已被其他设备或龙虾修改，草稿已保留，请重新查询后修改。','code'=>'REVISION_CONFLICT','journal'=>$before],409); }
    if ($before&&$source!=='manual'&&$before['manual_lock']&&!$force) { rollback_write(); respond(['error'=>'这篇日记已手动修改，自动覆盖需要明确force意图。','code'=>'MANUAL_LOCK','journal'=>$before],409); }
    $lock=$source==='manual'?1:(int)($before['manual_lock']??false);
    upsert('journals',['user_id','date','content','revision','manual_lock','source','updated_at'],['user_id','date'],[$user,$date,$content,($before['revision']??0)+1,$lock,$source,date(DATE_ATOM)]);
    $after=normalize_journal(query('SELECT * FROM journals WHERE user_id=? AND date=?',[$user,$date])[0]);
    audit($user,'upsert_journal',0,$source,$before,$after);commit_write();respond(['journal'=>$after,'created'=>!$before],$before?200:201);
}
