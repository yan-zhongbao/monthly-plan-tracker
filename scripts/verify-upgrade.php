<?php
declare(strict_types=1);
// Compare legacy data with a pre-upgrade MySQL SQL backup using connection-local temporary tables.
// No persistent table is written. Run before resuming ordinary data entry.
if (PHP_SAPI !== 'cli') { exit('CLI only'); }
require dirname(__DIR__) . '/app/bootstrap.php';
if (!mysql_backend()) { exit("This comparison requires MySQL.\n"); }
$path = $argv[1] ?? '';
if (!is_file($path)) { exit("Provide the pre-upgrade SQL backup path.\n"); }
$expectedMonth = month_value($argv[2] ?? date('Y-m'));
$allowedTitles = array_slice($argv, 3);
$pdo = db();
function audited_item_change(array $old,array $current): bool {
    $cursor=normalize_item($old);
    foreach (query('SELECT before_json,after_json FROM audit_log WHERE user_id=? AND entity_id=? AND action=? ORDER BY id',[$old['user_id'],$old['id'],'update_item']) as $entry) {
        $before=json_decode($entry['before_json'],true);
        $after=json_decode($entry['after_json'],true);
        if (!is_array($before) || !is_array($after)) { continue; }
        $before+=['once_only'=>false,'symbol'=>''];
        $after+=['once_only'=>false,'symbol'=>''];
        if ($before==$cursor) { $cursor=$after; }
    }
    return $cursor==normalize_item($current);
}
foreach (['items','records'] as $table) {
    $pdo->exec("CREATE TEMPORARY TABLE upgrade_$table LIKE $table");
}
// A note can contain a newline or a semicolon. Split only outside quoted SQL values.
$input = file_get_contents($path);
$statement = ''; $quote = null; $escaped = false;
for ($i=0,$length=strlen($input);$i<$length;$i++) {
    $char = $input[$i]; $statement .= $char;
    if ($quote !== null) {
        if ($escaped) { $escaped=false; continue; }
        if ($char==='\\' && $quote!== '`') { $escaped=true; continue; }
        if ($char===$quote) {
            if (($input[$i+1] ?? '') === $quote) { $statement.=$input[++$i]; }
            else { $quote=null; }
        }
        continue;
    }
    if (in_array($char,["'",'"','`'],true)) { $quote=$char; continue; }
    if ($char!==';') { continue; }
    if (preg_match('/^\s*INSERT INTO `(items|records)` /', $statement,$match)) {
        $pdo->exec(preg_replace('/^\s*INSERT INTO `(items|records)` /', 'INSERT INTO `upgrade_$1` ', $statement,1));
    }
    $statement='';
}
$pdo->beginTransaction();
try {
    $oldRecords = $pdo->query('SELECT * FROM upgrade_records ORDER BY id')->fetchAll();
    $newRecords = $pdo->query('SELECT * FROM records ORDER BY id')->fetchAll();
    $recordMap=array_column($newRecords,null,'id');
    foreach ($oldRecords as $oldRecord) {
        $currentRecord=$recordMap[$oldRecord['id']] ?? [];
        foreach ($oldRecord as $field=>$value) {
            if (!array_key_exists($field,$currentRecord) || $value!==$currentRecord[$field]) {
                throw new RuntimeException('Existing record changed: id='.$oldRecord['id'].', field='.$field.'. Review concurrent edits or the upgrade.');
            }
        }
    }
    $oldItems = $pdo->query('SELECT * FROM upgrade_items ORDER BY id')->fetchAll();
    $newItems = $pdo->query('SELECT * FROM items ORDER BY id')->fetchAll();
    $itemMap=array_column($newItems,null,'id');
    $converted = 0; $auditedEdits=0;
    foreach ($oldItems as $index=>$old) {
        $current = $itemMap[$old['id']] ?? [];
        if ($current['id']!==$old['id']) { throw new RuntimeException('Item identity differs.'); }
        if ((int)$current['once_only']===1 && in_array($old['title'],$allowedTitles,true) && $old['month']===$expectedMonth) {
            // Authorized conversion changes presentation, target and the edit timestamp only.
            if ((int)$current['tracked']!==1 || $current['target']!==null) { throw new RuntimeException('Invalid once-only conversion.'); }
            foreach (['once_only','target','tracked','updated_at'] as $field) { $old[$field]=$current[$field]; }
            $converted++;
        }
        if ($old!==$current) {
            $original=$oldItems[$index];
            if (!audited_item_change($original,$current)) { throw new RuntimeException('Existing item changed without a complete audit trail: id='.$old['id'].'. Compare the backup and audit log.'); }
            $auditedEdits++;
        }
    }
    $pdo->rollBack();
    echo 'PASS: '.count($oldRecords).' existing records identical in every field; '.count($oldItems).' existing items preserved; '.$converted.' authorized presentation conversions; '.$auditedEdits.' other item edits verified through the audit trail; '.(count($newRecords)-count($oldRecords)).' new records and '.(count($newItems)-count($oldItems))." new items retained.\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    fwrite(STDERR,$e->getMessage()."\n"); exit(1);
}
