<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
require dirname(__DIR__) . '/app/api-spec.php';
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'discovery') { respond(api_spec()); }
try {
    [$user, $source] = auth();
    $pdo = db();
    $action = $_GET['action'] ?? 'month';
    $method = $_SERVER['REQUEST_METHOD'];
    if ($action==='outputs') { handle_outputs($user,$source,$method); }
    if ($method === 'GET') {
        if ($action === 'suggestions') {
            $month = month_value($_GET['month'] ?? date('Y-m'));
            $category = string_value($_GET['category'] ?? '', 20, '所属方面');
            respond(['month'=>$month,'category'=>$category,'items'=>suggestions($user,$month,$category)]);
        }
        if ($action === 'audit') {
            $id = (int)($_GET['item_id'] ?? 0);
            respond(['entries' => query('SELECT * FROM audit_log WHERE user_id = ? AND entity_id = ? ORDER BY id DESC LIMIT 100', [$user, $id])]);
        }
        $data = month_data($user, month_value($_GET['month'] ?? date('Y-m')));
        if ($action === 'month') { respond($data); }
        if ($action === 'plans') { respond(['month' => $data['month'], 'access'=>$data['access'], 'items' => array_values(array_filter($data['items'], fn($i) => !$i['unplanned']))]); }
        if ($action === 'projects') { respond(['month' => $data['month'], 'access'=>$data['access'], 'items' => array_values(array_filter($data['items'], fn($i) => $i['tracked']))]); }
        if ($action === 'records') { respond(['month' => $data['month'], 'access'=>$data['access'], 'records' => $data['records']]); }
        fail('未知查询接口。', 404);
    }
    $data = body();
    if ($action === 'review' && $method === 'PATCH') {
        $month = month_value($data['month'] ?? null);
        if (!array_key_exists('completed', $data)) { fail('请提供月回顾完成状态。'); }
        $completed = boolean_value($data['completed']);
        begin_write($user);
        $before = month_access($user, $month);
        if (!$before['can_review']) {
            rollback_write();
            respond(['error'=>'只可设置或取消上个月和当前月的月回顾标记。','code'=>'REVIEW_NOT_ALLOWED','access'=>$before],403);
        }
        upsert('month_reviews', ['user_id','month','completed','updated_at','source'], ['user_id','month'], [$user,$month,$completed,date(DATE_ATOM),$source]);
        $after = month_access($user, $month);
        if ($before['review_completed'] !== (bool)$completed) { audit($user,'month_review',0,$source,['month'=>$month,'completed'=>$before['review_completed']],['month'=>$month,'completed'=>(bool)$completed]); }
        commit_write(); respond(['month'=>$month,'access'=>$after]);
    }
    if ($action === 'items' && in_array($method, ['POST', 'PATCH', 'DELETE'], true)) {
        $before = $method === 'POST' ? null : item_for($user, (int)($data['id'] ?? 0));
        if ($method === 'DELETE') {
            begin_write($user);
            assert_month_writable($user, $before['month']);
            audit($user, 'delete_item', $before['id'], $source, ['item' => $before, 'records' => query('SELECT * FROM records WHERE user_id = ? AND item_id = ?', [$user, $before['id']])], null);
            execute('DELETE FROM items WHERE user_id = ? AND id = ?', [$user, $before['id']]);
            commit_write(); respond(['deleted' => true]);
        }
        $merged = array_replace($before ?? ['month' => '', 'title' => '', 'category' => '成长', 'note' => '', 'target' => null, 'tracked' => false, 'unplanned' => false, 'completed' => false, 'sort_order' => 0, 'once_only'=>false, 'symbol'=>''], $data);
        $month = month_value($merged['month']);
        if ($before && $month !== $before['month']) { fail('项目不能移动月份，请复制到下个月。'); }
        $title = string_value($merged['title'], 120, '项目名称');
        if ($title === '') { fail('请填写项目名称。'); }
        if (!in_array($merged['category'], categories(), true)) { fail('请选择八个方面之一。'); }
        $note = string_value($merged['note'], 4000, '计划说明');
        $target = $merged['target'];
        if ($target !== null && (!is_int($target) || $target < 1 || $target > 366)) { fail('目标天数须为 1—366 的整数，或留空。'); }
        $tracked = boolean_value($merged['tracked']); $unplanned = boolean_value($merged['unplanned']); $completed = boolean_value($merged['completed']);
        $once = boolean_value($merged['once_only']);
        $symbol = string_value($merged['symbol'], 32, '标记符号');
        if ($once) { $tracked = 1; $target = null; }
        if ($unplanned) { $tracked = 1; $target = null; }
        if ($before && $before['tracked'] && !$tracked && query('SELECT id FROM records WHERE user_id = ? AND item_id = ? LIMIT 1', [$user, $before['id']])) { fail('已有每日记录，不能移出追踪表；可删除项目后重建。', 409); }
        if (!is_int($merged['sort_order']) || abs($merged['sort_order']) > 100000) { fail('排序值无效。'); }
        $now = date(DATE_ATOM);
        begin_write($user);
        assert_month_writable($user, $month);
        if ($once && $before && count(query('SELECT id FROM records WHERE user_id=? AND item_id=? AND completed=1', [$user,$before['id']])) > 1) {
            rollback_write(); fail('这个项目已在多天完成，请先取消多余标记，再改为一次性事件。',409);
        }
        if (!$before && ($data['avoid_duplicate'] ?? false) === true && query('SELECT id FROM items WHERE user_id=? AND month=? AND category=? AND title=? LIMIT 1', [$user,$month,$merged['category'],$title])) {
            rollback_write(); fail('本月这个方面已经有同名项目，请选择其他项目。',409);
        }
        if ($before) {
            $id = $before['id'];
            execute('UPDATE items SET title=?, category=?, note=?, target=?, tracked=?, unplanned=?, completed=?, sort_order=?, updated_at=?, once_only=?, symbol=? WHERE user_id=? AND id=?', [$title,$merged['category'],$note,$target,$tracked,$unplanned,$completed,$merged['sort_order'],$now,$once,$symbol,$user,$id]);
        } else {
            execute('INSERT INTO items (user_id,month,title,category,note,target,tracked,unplanned,completed,sort_order,created_at,updated_at,once_only,symbol) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [$user,$month,$title,$merged['category'],$note,$target,$tracked,$unplanned,$completed,$merged['sort_order'],$now,$now,$once,$symbol]);
            $id = (int)db()->lastInsertId();
        }
        $after = item_for($user, $id); audit($user, $before ? 'update_item' : 'create_item', $id, $source, $before, $after);
        commit_write(); respond(['item' => $after], $before ? 200 : 201);
    }
    if ($action === 'records' && $method === 'PUT') {
        $date = date_value($data['date'] ?? null);
        if (isset($data['item_id'])) { $item = item_for($user, (int)$data['item_id']); }
        else {
            $title = string_value($data['title'] ?? '', 120, '项目名称');
            $matches = query('SELECT * FROM items WHERE user_id = ? AND month = ? AND title = ? AND tracked = 1', [$user, substr($date, 0, 7), $title]);
            if (count($matches) !== 1) { fail(count($matches) ? '同名项目不唯一，请传 item_id。' : '当月没有此追踪项目，请先创建。', 409); }
            $item = normalize_item($matches[0]);
        }
        if (!$item['tracked'] || $item['month'] !== substr($date, 0, 7)) { fail('日期必须属于项目月份，且项目须加入追踪表。'); }
        if ($date > date('Y-m-d')) { respond(['error'=>'未来日期只能查看，不能填写记录。','code'=>'FUTURE_DATE'],403); }
        $completed = array_key_exists('completed', $data) ? boolean_value($data['completed']) : null;
        $note = array_key_exists('note', $data) ? string_value($data['note'], 4000, '备注') : null;
        $links = $data['links'] ?? null;
        if (array_key_exists('links', $data)) {
            if (!is_array($links) || !is_list_array($links) || count($links) > 10) { fail('链接须为数组，最多 10 条。'); }
            foreach ($links as &$link) {
                $link = string_value($link, 2000, '链接');
                if (!filter_var($link, FILTER_VALIDATE_URL) || !in_array(strtolower(parse_url($link, PHP_URL_SCHEME) ?? ''), ['https','http'], true)) { fail('链接须为完整的 http/https 地址。'); }
            }
            unset($link);
        }
        begin_write($user);
        assert_month_writable($user, $item['month']);
        $rows = query('SELECT * FROM records WHERE user_id = ? AND item_id = ? AND date = ?', [$user, $item['id'], $date]);
        $before = $rows ? normalize_record($rows[0]) : null;
        $item = item_for($user, $item['id']);
        if ($item['once_only'] && ($completed ?? (int)($before['completed'] ?? true)) && query('SELECT id FROM records WHERE user_id=? AND item_id=? AND completed=1 AND date<>?', [$user,$item['id'],$date])) {
            rollback_write(); fail('一次性事件本月已在其他日期完成；请先取消原日期的标记。',409);
        }
        if (isset($data['revision']) && (!is_int($data['revision']) || $data['revision'] !== ($before['revision'] ?? 0))) {
            rollback_write(); fail('记录已被修改，请刷新后重试。', 409);
        }
        if ($source !== 'manual' && ($before['manual_lock'] ?? false) && ($data['force'] ?? false) !== true) {
            rollback_write(); respond(['error' => '这条记录已由用户修正，自动写入被保护。', 'record' => $before], 409);
        }
        $lock = $source === 'manual' ? (array_key_exists('manual_lock', $data) ? boolean_value($data['manual_lock']) : 1) : (int)($before['manual_lock'] ?? false);
        $params = [$user,$item['id'],$date,$completed ?? (int)($before['completed'] ?? true),$note ?? ($before['note'] ?? ''),json_encode($links ?? ($before['links'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),$source,$lock,($before['revision'] ?? 0)+1,date(DATE_ATOM)];
        upsert('records', ['user_id','item_id','date','completed','note','links','source','manual_lock','revision','updated_at'], ['user_id','item_id','date'], $params);
        $after = normalize_record(query('SELECT * FROM records WHERE user_id=? AND item_id=? AND date=?', [$user,$item['id'],$date])[0]);
        audit($user, 'upsert_record', $item['id'], $source, $before, $after);
        commit_write(); respond(['record' => $after]);
    }
    if ($action === 'copy' && $method === 'POST') {
        $from = month_value($data['from'] ?? null); $to = month_value($data['to'] ?? null);
        if ($from === $to) { fail('请选择不同月份。'); }
        begin_write($user);
        assert_month_writable($user, $to);
        if (query('SELECT id FROM items WHERE user_id=? AND month=? LIMIT 1', [$user,$to]) || query('SELECT id FROM outputs WHERE user_id=? AND date LIKE ? LIMIT 1',[$user,$to.'-%'])) { rollback_write(); fail('目标月份已有项目或输出，不能覆盖。', 409); }
        $now = date(DATE_ATOM);
        execute('INSERT INTO items (user_id,month,title,category,note,target,tracked,unplanned,completed,sort_order,created_at,updated_at,once_only,symbol) SELECT user_id,?,title,category,note,target,tracked,0,0,sort_order,?,?,once_only,symbol FROM items WHERE user_id=? AND month=? AND unplanned=0', [$to,$now,$now,$user,$from]);
        audit($user, 'copy_month', 0, $source, ['month'=>$from], ['month'=>$to]);
        commit_write(); respond(month_data($user,$to));
    }
    fail('接口或请求方法不支持。', 405);
} catch (Throwable $e) {
    if (isset($pdo)) { try { $pdo->exec('ROLLBACK'); } catch (Throwable $ignored) {} }
    error_log((string)$e);
    respond(['error' => '服务暂时不可用，请检查配置和服务器日志。'], 500);
}
