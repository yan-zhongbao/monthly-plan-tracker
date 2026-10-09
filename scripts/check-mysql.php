<?php
declare(strict_types=1);
// Run only after configuring the site. Temporary negative-user rows roll back.
if (PHP_SAPI !== 'cli') { exit; }
require dirname(__DIR__).'/app/bootstrap.php';
if (!mysql_backend()) { exit("MySQL configuration required\n"); }
$user = -2147000000;
$month = date('Y-m');
$previous = (new DateTimeImmutable($month.'-01'))->modify('-1 month')->format('Y-m');
$date = $month.'-08'; $now=date(DATE_ATOM);
$checks=0;
function check_mysql(bool $ok): void { global $checks; if (!$ok) { throw new RuntimeException('MySQL smoke assertion failed: '.($checks+1)); } $checks++; }
try {
 begin_write($user);
 $second=mysql_connect(config()['database']);
 $second->exec('SET SESSION innodb_lock_wait_timeout=1');
 $second->beginTransaction();
 try {
  $stmt=$second->prepare('SELECT user_id FROM user_write_locks WHERE user_id=? FOR UPDATE'); $stmt->execute([$user]);
  throw new RuntimeException('Concurrent write was not blocked');
 } catch (PDOException $locked) { check_mysql((int)($locked->errorInfo[1] ?? 0)===1205); }
 finally { if ($second->inTransaction()) { $second->rollBack(); } }
 execute('INSERT INTO items (user_id,month,title,category,note,tracked,created_at,updated_at) VALUES (?,?,?,?,?,1,?,?)',[$user,$month,'测试阅读😀','成长','数据库测试',$now,$now]);
 $id=(int)db()->lastInsertId();
 check_mysql(item_for($user,$id)['title']==='测试阅读😀');
 upsert('records',['user_id','item_id','date','completed','note','links','source','manual_lock','revision','updated_at'],['user_id','item_id','date'],[$user,$id,$date,1,'第一条','[]','openclaw',0,1,$now]);
 upsert('records',['user_id','item_id','date','completed','note','links','source','manual_lock','revision','updated_at'],['user_id','item_id','date'],[$user,$id,$date,0,'手动修改','["https://example.com"]','manual',1,2,$now]);
 $records=month_data($user,$month)['records'];
 check_mysql(count($records)===1); check_mysql($records[0]['revision']===2); check_mysql($records[0]['manual_lock']===true); check_mysql($records[0]['note']==='手动修改');
 upsert('month_reviews',['user_id','month','completed','updated_at','source'],['user_id','month'],[$user,$month,1,$now,'manual']);
 check_mysql(month_access($user,$month)['read_only']===true);
 upsert('month_reviews',['user_id','month','completed','updated_at','source'],['user_id','month'],[$user,$month,0,$now,'manual']);
 check_mysql(month_access($user,$month)['read_only']===false);
 $ip=hash('sha256','temporary-mysql-smoke-'.$user);
 upsert('login_attempts',['ip_hash','failures','last_attempt'],['ip_hash'],[$ip,1,time()]);
 upsert('login_attempts',['ip_hash','failures','last_attempt'],['ip_hash'],[$ip,2,time()]);
 check_mysql((int)query('SELECT failures FROM login_attempts WHERE ip_hash=?',[$ip])[0]['failures']===2);
 execute('INSERT INTO items (user_id,month,title,category,note,tracked,created_at,updated_at) VALUES (?,?,?,?,?,1,?,?)',[$user,$previous,'历史英语','成长','',$now,$now]);
 check_mysql(suggestions($user,$month,'成长')[0]['title']==='历史英语');
 audit($user,'test',$id,'manual',null,['test'=>true]);
 check_mysql(count(query('SELECT id FROM audit_log WHERE user_id=?',[$user]))===1);
 execute('DELETE FROM items WHERE user_id=? AND id=?',[$user,$id]);
 check_mysql(count(query('SELECT id FROM records WHERE user_id=?',[$user]))===0);
 execute('INSERT INTO outputs (user_id,date,type,title,note,links,external_id,source,manual_lock,revision,archived,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',[$user,$date,'book_note','测试输出😀','备注','["https://example.com"]','mysql-smoke:1','openclaw',0,1,0,$now,$now]);
 $outputs=output_rows($user,$month);check_mysql(count($outputs)===1);check_mysql($outputs[0]['title']==='测试输出😀');check_mysql($outputs[0]['links']===['https://example.com']);
 execute('UPDATE outputs SET archived=1 WHERE user_id=?',[$user]);check_mysql(count(output_rows($user,$month))===0);check_mysql(count(output_rows($user,$month,true))===1);
 rollback_write();
 check_mysql(count(output_rows($user,$month,true))===0);
 check_mysql(count(query('SELECT id FROM items WHERE user_id=?',[$user]))===0);
 echo "PASS: $checks MySQL checks; temporary business rows rolled back.\n";
} catch (Throwable $e) {
 if (db()->inTransaction()) { db()->rollBack(); }
 throw $e;
} finally { execute('DELETE FROM user_write_locks WHERE user_id=?',[$user]); }
