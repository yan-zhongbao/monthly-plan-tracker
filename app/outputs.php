<?php
declare(strict_types=1);
function output_types(): array { return ['book_note'=>'读书笔记','article'=>'文章','travel_note'=>'旅行记录']; }
function normalize_output(array $row): array {
    foreach (['id','user_id','revision'] as $field) { $row[$field]=(int)$row[$field]; }
    foreach (['manual_lock','archived'] as $field) { $row[$field]=(bool)$row[$field]; }
    $row['links']=json_decode($row['links'],true);
    return $row;
}
function output_rows(int $user,string $month,bool $archived=false): array {
    return array_map('normalize_output',query('SELECT * FROM outputs WHERE user_id=? AND date LIKE ?'.($archived?'':' AND archived=0').' ORDER BY date,id',[$user,$month.'-%']));
}
function legacy_outputs(array $items,array $records): array {
    $ids=[]; foreach ($items as $item) { if ($item['tracked'] && $item['title']==='输出' && !$item['unplanned']) { $ids[$item['id']]=true; } }
    return array_values(array_filter($records,fn($record)=>isset($ids[$record['item_id']]) && ($record['completed'] || $record['note']!=='' || count($record['links'])>0)));
}
function output_links(mixed $links): array {
    if (!is_array($links) || !is_list_array($links) || count($links)>10) { fail('链接须为数组，最多10条。'); }
    foreach ($links as &$link) {
        $link=string_value($link,2000,'链接');
        if (!filter_var($link,FILTER_VALIDATE_URL) || !in_array(strtolower(parse_url($link,PHP_URL_SCHEME)??''),['http','https'],true)) { fail('链接须为完整的http/https地址。'); }
    }
    unset($link); return $links;
}
function output_conflict(string $message,string $code): void { rollback_write(); respond(['error'=>$message,'code'=>$code],409); }
function handle_outputs(int $user,string $source,string $method): void {
    if ($method==='GET') {
        $month=month_value($_GET['month']??date('Y-m')); $data=month_data($user,$month);
        $rows=output_rows($user,$month,($_GET['include_archived']??'')==='true');
        if (isset($_GET['date'])) { $date=date_value($_GET['date']); $rows=array_values(array_filter($rows,fn($r)=>$r['date']===$date)); }
        if (isset($_GET['type'])) { if (!isset(output_types()[$_GET['type']])) { fail('输出类型无效。'); } $rows=array_values(array_filter($rows,fn($r)=>$r['type']===$_GET['type'])); }
        respond(['month'=>$month,'access'=>$data['access'],'types'=>output_types(),'outputs'=>$rows,'legacy_outputs'=>$data['legacy_outputs']]);
    }
    if (!in_array($method,['POST','PUT','PATCH','DELETE'],true)) { fail('输出接口方法不支持。',405); }
    $input=body();
    if (in_array($method,['PATCH','DELETE'],true) && (!is_int($input['id']??null) || $input['id']<1)) { fail('请提供输出ID。'); }
    $external=$input['external_id']??null;
    if ($external!==null) { $external=string_value($external,128,'外部记录ID'); if ($external==='' || strlen($external)>128) { fail('外部记录ID须为1—128个UTF-8字节。'); } }
    if ($method==='PUT' && $external===null) { fail('PUT须提供external_id，用于重复请求去重。'); }
    begin_write($user);
    $rows=in_array($method,['PATCH','DELETE'],true)
        ? query('SELECT * FROM outputs WHERE user_id=? AND id=?',[$user,$input['id']])
        : ($external===null?[]:query('SELECT * FROM outputs WHERE user_id=? AND external_id=?',[$user,$external]));
    $before=$rows?normalize_output($rows[0]):null;
    if (in_array($method,['PATCH','DELETE'],true) && !$before) { rollback_write(); fail('找不到这条输出。',404); }
    if ($before) { assert_month_writable($user,substr($before['date'],0,7)); }
    $merged=array_replace($before??['date'=>'','type'=>'','title'=>'','note'=>'','links'=>[],'archived'=>false],$input);
    $date=date_value($merged['date']);
    assert_month_writable($user,substr($date,0,7));
    if ($date>date('Y-m-d')) { rollback_write(); respond(['error'=>'未来日期不能填写输出。','code'=>'FUTURE_DATE'],403); }
    if (!is_string($merged['type']) || !isset(output_types()[$merged['type']])) { fail('请选择读书笔记、文章或旅行记录。'); }
    $title=string_value($merged['title'],120,'输出标题'); if ($title==='') { fail('请填写输出标题。'); }
    $note=string_value($merged['note'],4000,'输出备注'); $links=output_links($merged['links']);
    $archived=$method==='DELETE'?1:boolean_value($merged['archived']);
    if ($before && in_array($method,['POST','PUT'],true) && $before['archived']) { output_conflict('这条输出已移除，请明确恢复，不会自动重新添加。','OUTPUT_ARCHIVED'); }
    if ($before && array_key_exists('external_id',$input) && $external!==$before['external_id']) { output_conflict('已有输出的external_id不能修改。','EXTERNAL_ID_IMMUTABLE'); }
    if (isset($input['revision']) && (!is_int($input['revision']) || $input['revision']!==($before['revision']??0))) { output_conflict('输出已被修改，请刷新。','REVISION_CONFLICT'); }
    $same=$before && $before['date']===$date && $before['type']===$merged['type'] && $before['title']===$title && $before['note']===$note && $before['links']===$links && $before['archived']===(bool)$archived;
    if ($same && in_array($method,['POST','PUT'],true)) { commit_write(); respond(['output'=>$before,'created'=>false,'unchanged'=>true]); }
    $force=array_key_exists('force',$input)?boolean_value($input['force']):0;
    if ($before && $source!=='manual' && $before['manual_lock'] && !$force) { output_conflict('这条输出已由用户修正，自动写入被保护。','MANUAL_LOCK'); }
    $lock=$source==='manual'?(array_key_exists('manual_lock',$input)?boolean_value($input['manual_lock']):1):(int)($before['manual_lock']??false);
    $now=date(DATE_ATOM); $revision=($before['revision']??0)+1;
    if ($before) {
        $id=$before['id'];
        execute('UPDATE outputs SET date=?,type=?,title=?,note=?,links=?,source=?,manual_lock=?,revision=?,archived=?,updated_at=? WHERE user_id=? AND id=?',[$date,$merged['type'],$title,$note,json_encode($links,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$source,$lock,$revision,$archived,$now,$user,$id]);
    } else {
        execute('INSERT INTO outputs (user_id,date,type,title,note,links,external_id,source,manual_lock,revision,archived,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',[$user,$date,$merged['type'],$title,$note,json_encode($links,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$external,$source,$lock,$revision,$archived,$now,$now]);
        $id=(int)db()->lastInsertId();
    }
    $after=normalize_output(query('SELECT * FROM outputs WHERE user_id=? AND id=?',[$user,$id])[0]);
    audit($user,$before?'update_output':'create_output',-$id,$source,$before,$after);
    commit_write(); respond(['output'=>$after,'created'=>!$before],$before?200:201);
}
