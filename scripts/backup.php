<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit('CLI only'); }
require dirname(__DIR__) . '/app/bootstrap.php';
$destination = $argv[1] ?? (dirname(__DIR__) . '/storage/backup-' . date('Ymd-His') . (mysql_backend() ? '.sql' : '.sqlite'));
if (file_exists($destination)) { exit("目标文件已存在。\n"); }
if (mysql_backend()) {
    $pdo = db();
    $handle = fopen($destination, 'x');
    if (!$handle) { exit("不能创建备份文件。\n"); }
    chmod($destination,0600);
    try {
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->beginTransaction();
        fwrite($handle,"-- Month Tracker MySQL backup: restore into an EMPTY database\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n");
        foreach (['schema_migrations','items','records','audit_log','login_attempts','user_write_locks','month_reviews','outputs','remembered_devices'] as $table) {
            $rows = $pdo->query("SELECT * FROM `$table`");
            $create = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM);
            fwrite($handle,$create[1].";\n");
            while ($row = $rows->fetch()) {
                $values = array_map(fn($v)=>$v === null ? 'NULL' : $pdo->quote((string)$v),array_values($row));
                fwrite($handle,"INSERT INTO `$table` (`".implode('`,`',array_keys($row))."`) VALUES (".implode(',',$values).");\n");
            }
        }
        fwrite($handle,"SET FOREIGN_KEY_CHECKS=1;\n");
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        fclose($handle); unlink($destination); throw $e;
    }
    fclose($handle);
} else { db()->exec('VACUUM INTO ' . db()->quote($destination)); }
echo "已备份：$destination\n";
