<?php
declare(strict_types=1);

function respond(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}
function fail(string $message, int $status = 400): void { respond(['error' => $message], $status); }
function is_list_array(array $value): bool {
    $index = 0;
    foreach ($value as $key => $_) { if ($key !== $index++) { return false; } }
    return true;
}
function config(): array {
    static $config;
    if ($config === null) {
        $path = getenv('TRACKER_CONFIG') ?: dirname(__DIR__) . '/config.php';
        if (!is_file($path)) { throw new RuntimeException('请先在服务器终端运行 php scripts/setup.php。'); }
        $config = require $path;
        date_default_timezone_set($config['timezone'] ?? 'Asia/Shanghai');
    }
    return $config;
}
function mysql_backend(): bool { return is_array(config()['database']); }
function mysql_connect(array $settings): PDO {
    $host = $settings['host'] ?? '127.0.0.1';
    $name = $settings['name'] ?? '';
    if (!preg_match('/^[a-zA-Z0-9_.:-]+$/', $host) || !preg_match('/^[a-zA-Z0-9_]+$/', $name)) { throw new RuntimeException('数据库地址或名称无效。'); }
    $port = (int)($settings['port'] ?? 3306);
    if ($port < 1 || $port > 65535) { throw new RuntimeException('数据库端口无效。'); }
    return new PDO("mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4", $settings['user'], $settings['password'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]);
}
// Shared migrations use SQL supported by both MySQL and SQLite; legacy dialect-specific migrations remain unchanged.
function migration_files(bool $mysql): array {
    $base = dirname(__DIR__) . '/migrations';
    $files = array_merge(glob($mysql ? $base . '/mysql/*.sql' : $base . '/*.sql'), glob($base . '/common/*.sql'));
    usort($files, fn($a,$b)=>(int)basename($a) <=> (int)basename($b));
    return $files;
}
function mysql_migrate(PDO $pdo): void {
    $lock = 'month_schema_' . hash('sha256', (string)$pdo->query('SELECT DATABASE()')->fetchColumn());
    $lock = substr($lock, 0, 64);
    $stmt = $pdo->prepare('SELECT GET_LOCK(?, 15)'); $stmt->execute([$lock]);
    if ((int)$stmt->fetchColumn() !== 1) { throw new RuntimeException('数据库初始化忙，请稍后重试。'); }
    try {
        $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (version INT PRIMARY KEY, applied_at VARCHAR(40) NOT NULL) ENGINE=InnoDB');
        foreach (migration_files(true) as $file) {
            $version = (int)basename($file);
            if (query('SELECT version FROM schema_migrations WHERE version=?', [$version], $pdo)) { continue; }
            // MySQL DDL commits implicitly. Every migration is retryable; the named lock serializes it.
            foreach (explode(';', file_get_contents($file)) as $sql) {
                if (trim($sql) === '') { continue; }
                try { $pdo->exec($sql); }
                catch (PDOException $e) {
                    // Resume an interrupted additive migration after MySQL's implicit DDL commit.
                    if ((int)($e->errorInfo[1] ?? 0) !== 1060 || !preg_match('/^ALTER TABLE items ADD COLUMN /i', trim($sql))) { throw $e; }
                }
            }
            $pdo->prepare('INSERT INTO schema_migrations VALUES (?,?)')->execute([$version,date(DATE_ATOM)]);
        }
    } finally { $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]); }
}
function db(): PDO {
    static $pdo;
    if (!$pdo) {
        if (mysql_backend()) {
            $pdo = mysql_connect(config()['database']);
            try { mysql_migrate($pdo); } catch (Throwable $e) { $pdo = null; throw $e; }
            return $pdo;
        }
        $path = config()['database'];
        if (!is_dir(dirname($path))) { mkdir(dirname($path), 0700, true); }
        $pdo = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $pdo->exec('PRAGMA foreign_keys = ON; PRAGMA busy_timeout = 5000;');
        $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (version INTEGER PRIMARY KEY, applied_at TEXT NOT NULL)');
        foreach (migration_files(false) as $file) {
            $version = (int)basename($file);
            if (query('SELECT version FROM schema_migrations WHERE version = ?', [$version], $pdo)) { continue; }
            $pdo->exec('BEGIN IMMEDIATE');
            try {
                if (!query('SELECT version FROM schema_migrations WHERE version = ?', [$version], $pdo)) {
                    $pdo->exec(file_get_contents($file));
                    $stmt = $pdo->prepare('INSERT INTO schema_migrations VALUES (?, ?)');
                    $stmt->execute([$version, date(DATE_ATOM)]);
                }
                $pdo->exec('COMMIT');
            } catch (Throwable $e) { $pdo->exec('ROLLBACK'); throw $e; }
        }
    }
    return $pdo;
}
function query(string $sql, array $params = [], ?PDO $pdo = null): array {
    $stmt = ($pdo ?? db())->prepare($sql); $stmt->execute($params); return $stmt->fetchAll();
}
function execute(string $sql, array $params = []): void { db()->prepare($sql)->execute($params); }
function begin_write(int $user): void {
    if (!mysql_backend()) { db()->exec('BEGIN IMMEDIATE'); return; }
    execute('INSERT IGNORE INTO user_write_locks (user_id) VALUES (?)', [$user]);
    db()->beginTransaction();
    // Serialize all writes for this user, including new cells and month-review locks.
    query('SELECT user_id FROM user_write_locks WHERE user_id=? FOR UPDATE', [$user]);
}
function commit_write(): void { db()->exec('COMMIT'); }
function rollback_write(): void { db()->exec('ROLLBACK'); }
function upsert(string $table, array $columns, array $keys, array $values): void {
    // Only internal, fixed identifiers may be passed here.
    $updates = array_values(array_diff($columns, $keys));
    $sql = 'INSERT INTO ' . $table . ' (' . implode(',', $columns) . ') VALUES (' . implode(',', array_fill(0,count($columns),'?')) . ')';
    $sql .= mysql_backend()
        ? ' ON DUPLICATE KEY UPDATE ' . implode(',',array_map(fn($c)=>"$c=VALUES($c)",$updates))
        : ' ON CONFLICT(' . implode(',',$keys) . ') DO UPDATE SET ' . implode(',',array_map(fn($c)=>"$c=excluded.$c",$updates));
    execute($sql,$values);
}
require_once __DIR__ . '/remember-login.php';
function session_boot(): void {
    if (session_status() === PHP_SESSION_ACTIVE) { return; }
    $sessionPath = mysql_backend() ? (config()['storage'] ?? dirname(__DIR__) . '/storage') . '/sessions' : dirname(config()['database']) . '/sessions';
    if (!is_dir($sessionPath)) { mkdir($sessionPath, 0700, true); }
    session_name('month_tracker');
    session_start(['save_path' => $sessionPath, 'gc_maxlifetime' => 604800, 'use_strict_mode' => 1, 'cookie_httponly' => true, 'cookie_samesite' => 'Strict', 'cookie_secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
    if (isset($_SESSION['last_seen']) && time() - $_SESSION['last_seen'] > 604800) { $_SESSION = []; session_regenerate_id(true); }
    if (isset($_SESSION['user_id'])) {
        $user = config()['users'][$_SESSION['user_id']] ?? null;
        $expired = isset($_SESSION['remember_until']) && (int)$_SESSION['remember_until'] <= time();
        $changed = isset($_SESSION['password_fingerprint']) && (!$user || !hash_equals($_SESSION['password_fingerprint'], hash('sha256', $user['password_hash'])));
        $revoked = isset($_SESSION['remember_selector']) && !query('SELECT selector FROM remembered_devices WHERE selector=? AND user_id=? AND expires_at>?', [$_SESSION['remember_selector'], $_SESSION['user_id'], time()]);
        if ($expired || $changed || $revoked || !$user) { $_SESSION = []; session_regenerate_id(true); }
    }
    if (!isset($_SESSION['user_id'])) { remember_restore(); }
    $_SESSION['last_seen'] = time();
    $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}
function csrf_check(): void {
    if (!hash_equals($_SESSION['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf'] ?? ''))) { fail('页面已失效，请刷新后重试。', 403); }
}
function auth(): array {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/^Bearer\s+(\S+)$/i', $header, $m)) {
        foreach (config()['users'] as $id => $user) {
            if (hash_equals($user['api_token_hash'], hash('sha256', $m[1]))) { return [(int)$id, 'openclaw']; }
        }
        fail('API Token 无效。', 401);
    }
    session_boot();
    if (!isset($_SESSION['user_id'], config()['users'][$_SESSION['user_id']])) { fail('请先登录。', 401); }
    if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD'], true)) { csrf_check(); }
    return [(int)$_SESSION['user_id'], 'manual'];
}
function month_value(mixed $value): string {
    if (!is_string($value) || !preg_match('/^(20\d{2})-(0[1-9]|1[0-2])$/', $value)) { fail('月份格式须为 YYYY-MM（2000—2099）。'); }
    return $value;
}
function date_value(mixed $value): string {
    if (!is_string($value) || !preg_match('/^(20\d{2})-(\d{2})-(\d{2})$/', $value, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) { fail('日期格式须为有效的 YYYY-MM-DD。'); }
    return $value;
}
function string_value(mixed $value, int $limit, string $label): string {
    if (!is_string($value) || !preg_match('//u', $value) || strlen($value) > $limit * 4) { fail($label . '格式或长度无效。'); }
    return trim($value);
}
function boolean_value(mixed $value): int {
    if (!is_bool($value) && $value !== 0 && $value !== 1) { fail('完成状态必须是 true 或 false。'); }
    return (int)$value;
}
function body(): array {
    $raw = file_get_contents('php://input', false, null, 0, 65537);
    if (strlen($raw) > 65536) { fail('请求内容过大。', 413); }
    try { $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR); } catch (Throwable $e) { fail('请求须为 JSON 对象。'); }
    if (!is_array($data) || !str_starts_with(ltrim($raw), '{')) { fail('请求须为 JSON 对象。'); }
    return $data;
}
function item_for(int $user, int $id): array {
    $rows = query('SELECT * FROM items WHERE user_id = ? AND id = ?', [$user, $id]);
    if (!$rows) { fail('找不到这个项目。', 404); }
    return normalize_item($rows[0]);
}
function normalize_item(array $item): array {
    foreach (['id','user_id','sort_order'] as $key) { $item[$key] = (int)$item[$key]; }
    foreach (['tracked','unplanned','completed','once_only'] as $key) { $item[$key] = (bool)$item[$key]; }
    $item['target'] = $item['target'] === null ? null : (int)$item['target'];
    return $item;
}
function normalize_record(array $record): array {
    foreach (['id','user_id','item_id','revision'] as $key) { $record[$key] = (int)$record[$key]; }
    foreach (['completed','manual_lock'] as $key) { $record[$key] = (bool)$record[$key]; }
    $record['links'] = json_decode($record['links'], true);
    return $record;
}
function month_data(int $user, string $month): array {
    $items = array_map('normalize_item', query('SELECT * FROM items WHERE user_id = ? AND month = ? ORDER BY sort_order, id', [$user, $month]));
    $records = array_map('normalize_record', query('SELECT r.* FROM records r JOIN items i ON i.id = r.item_id AND i.user_id = r.user_id WHERE r.user_id = ? AND i.month = ? ORDER BY r.date, r.item_id', [$user, $month]));
    return ['month' => $month, 'timezone' => config()['timezone'] ?? 'Asia/Shanghai', 'access' => month_access($user, $month), 'items' => $items, 'records' => $records, 'outputs'=>output_rows($user,$month),'output_types'=>output_types(),'legacy_outputs'=>legacy_outputs($items,$records)];
}
function month_access(int $user, string $month): array {
    config();
    $current = date('Y-m');
    $previous = (new DateTimeImmutable($current . '-01'))->modify('-1 month')->format('Y-m');
    $completed = (bool)(query('SELECT completed FROM month_reviews WHERE user_id=? AND month=?', [$user,$month])[0]['completed'] ?? false);
    $old = $month < $previous;
    return ['current_month'=>$current, 'previous_month'=>$previous, 'review_completed'=>$completed,
        'read_only'=>$old || $completed, 'can_review'=>!$old && $month <= $current,
        'reason'=>$old ? '再上个月及更早的月份只读。' : ($completed ? '已完成月回顾总结，本月已锁定；取消标记后可以修改。' : '')];
}
// Called inside the write transaction, so closing a month and editing cannot race.
function assert_month_writable(int $user, string $month): void {
    $access = month_access($user, $month);
    if ($access['read_only']) {
        rollback_write();
        respond(['error'=>$access['reason'], 'code'=>'MONTH_READ_ONLY', 'access'=>$access], 403);
    }
}
function audit(int $user, string $action, int $id, string $source, mixed $before, mixed $after): void {
    execute('INSERT INTO audit_log (user_id, action, entity_id, source, before_json, after_json, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)', [$user, $action, $id, $source, $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE), $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE), date(DATE_ATOM)]);
}
function categories(): array { return ['成长','健康','家庭','财务','自我实现','社交','娱乐','职业']; }
require_once __DIR__ . '/outputs.php';
function suggestions(int $user, string $month, string $category): array {
    if (!in_array($category, categories(), true)) { fail('请选择八个方面之一。'); }
    $history = query('SELECT title,month AS last_used_month,updated_at AS last_used_at,tracked,target FROM items WHERE user_id=? AND category=? AND month<? ORDER BY month DESC,updated_at DESC,id DESC', [$user,$category,$month]);
    $seen = [];
    foreach (query('SELECT title FROM items WHERE user_id=? AND month=? AND category=?',[$user,$month,$category]) as $item) { $seen[$item['title']] = true; }
    $rows = [];
    foreach ($history as $row) { if (isset($seen[$row['title']])) { continue; } $seen[$row['title']] = true; $rows[] = $row; }
    usort($rows,fn($a,$b)=>strcmp($b['last_used_month'],$a['last_used_month']) ?: strcmp($b['last_used_at'],$a['last_used_at']) ?: strcmp($a['title'],$b['title']));
    return array_map(function ($row) {
        $row['tracked'] = (bool)$row['tracked'];
        $row['target'] = $row['target'] === null ? null : (int)$row['target'];
        return $row;
    }, $rows);
}
