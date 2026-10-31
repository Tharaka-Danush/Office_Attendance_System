<?php
declare(strict_types=1);
function change_password(array $u): string {
 $old=(string)($_POST['current_password']??'');$new=(string)($_POST['new_password']??'');
 if(strlen($new)<10||strlen($new)>200||$new!==($_POST['confirm_password']??''))throw new RuntimeException('Use matching passwords of 10–200 characters.');
 $p=db();$p->beginTransaction();try {
  query('SELECT id FROM employees WHERE id=? FOR UPDATE',[$u['id']]);
  $hash=query('SELECT password_hash FROM accounts WHERE employee_id=? FOR UPDATE',[$u['id']])->fetchColumn();
  if(!password_verify($old,$hash)||password_verify($new,$hash))throw new RuntimeException('Check your current password and choose a different new password.');
  query('UPDATE accounts SET password_hash=? WHERE employee_id=?',[password_hash($new,PASSWORD_DEFAULT),$u['id']]);audit((int)$u['id'],'password_changed',[]);$p->commit();
 }catch(Throwable $x){if($p->inTransaction())$p->rollBack();throw $x;}
 session_regenerate_id(true);$_SESSION=['csrf'=>bin2hex(random_bytes(32)),'setup_notice'=>'Password changed. Sign in again. All previous sessions have expired.'];header('Location: '.app_url('login.php'),true,303);exit;
}
function request_leave(array $u): string {
 $start=valid_date((string)($_POST['start_date']??''));$end=valid_date((string)($_POST['end_date']??''));$type=(string)($_POST['leave_type']??'');$reason=text_field('reason',500);
 if($start>$end||strtotime($end)-strtotime($start)>366*86400||!in_array($type,['annual','sick','casual','unpaid'],true))throw new RuntimeException('Choose a valid leave type and date range up to one year.');
 $leave_part=(string)($_POST['leave_part']??'full');
 if(!in_array($leave_part,['full','first_half','second_half'],true)||($leave_part!=='full'&&$start!==$end))throw new RuntimeException('Half-day leave must use a single date and a valid half.');
 $p=db();$p->beginTransaction();try {
  query('SELECT id FROM employees WHERE id=? FOR UPDATE',[$u['id']]);
  if(query("SELECT id FROM leave_requests WHERE employee_id=? AND status IN ('pending','approved') AND start_date<=? AND end_date>=? AND (leave_part='full' OR ?='full' OR leave_part=?) LIMIT 1",[$u['id'],$end,$start,$leave_part,$leave_part])->fetch())throw new RuntimeException('A pending or approved leave request overlaps these dates.');
  query('INSERT INTO leave_requests(employee_id,start_date,end_date,leave_type,reason,leave_part,created_at) VALUES(?,?,?,?,?,?,?)',[$u['id'],$start,$end,$type,$reason,$leave_part,utc()]);audit((int)$u['id'],'leave_requested',['start'=>$start,'end'=>$end,'leave_part'=>$leave_part]);$p->commit();
 }catch(Throwable $x){if($p->inTransaction())$p->rollBack();throw $x;}return 'Leave request sent for approval.';
}
function review_leave(array $u): string {
 if(!staff($u))throw new RuntimeException('HR access required.');$id=(int)($_POST['id']??0);$decision=(string)($_POST['decision']??'');$note=text_field('review_note',500);
 if(!in_array($decision,['approved','rejected','cancelled'],true))throw new RuntimeException('Invalid decision.');
 $r=query('SELECT employee_id FROM leave_requests WHERE id=?',[$id])->fetch();if(!$r||$r['employee_id']==$u['id'])throw new RuntimeException('Another administrator or HR member must review your own leave.');
 $p=db();$p->beginTransaction();try {
  query('SELECT id FROM employees WHERE id=? FOR UPDATE',[$r['employee_id']]);$r=query('SELECT * FROM leave_requests WHERE id=? FOR UPDATE',[$id])->fetch();
  if(!$r||!($r['status']==='pending'&&$decision!=='cancelled'||$r['status']==='approved'&&$decision==='cancelled'))throw new RuntimeException('This request has already changed. Refresh the calendar.');
  if($decision==='approved') {
   if(query("SELECT 1 FROM leave_requests WHERE employee_id=? AND id<>? AND status='approved' AND start_date<=? AND end_date>=? AND (leave_part='full' OR ?='full' OR leave_part=?) LIMIT 1",[$r['employee_id'],$id,$r['end_date'],$r['start_date'],$r['leave_part'],$r['leave_part']])->fetch())throw new RuntimeException('Cannot approve: overlapping approved leave exists.');
   $startYear=(int)substr($r['start_date'],0,4);$endYear=(int)substr($r['end_date'],0,4);
   for($year=$startYear;$year<=$endYear;$year++) {
    $balances=leave_balance_rows((int)$r['employee_id'],$year);
    foreach($balances as $balance)if($balance['type']===$r['leave_type']) {
     if($balance['remaining']===null)throw new RuntimeException('Cannot approve: leave allocation is not configured for '.$year.'.');
     $from=max($r['start_date'],sprintf('%04d-01-01',$year));$to=min($r['end_date'],sprintf('%04d-12-31',$year));
     $holidays=array_fill_keys(array_column(query('SELECT holiday_date FROM holidays WHERE holiday_date BETWEEN ? AND ?',[$from,$to])->fetchAll(),'holiday_date'),true);
     $schedules=query('SELECT effective_date,weekdays FROM weekly_schedules WHERE employee_id=? AND effective_date<=? ORDER BY effective_date',[$r['employee_id'],$to])->fetchAll();
     $units=leave_chargeable_units($from,$to,$r['leave_part'],$holidays,$schedules,config()['work_week']??[1,2,3,4,5]);
     if($units>$balance['remaining']+0.0001)throw new RuntimeException('Insufficient '.$r['leave_type'].' leave balance for '.$year.'.');
    }
   }
  }
  query('UPDATE leave_requests SET status=?,reviewed_by=?,review_note=?,reviewed_at=? WHERE id=?',[$decision,$u['id'],$note,utc(),$id]);audit((int)$u['id'],'leave_reviewed',['id'=>$id,'decision'=>$decision]);$p->commit();
 }catch(Throwable $x){if($p->inTransaction())$p->rollBack();throw $x;}return 'Leave request updated.';
}
function cancel_leave(array $u): string {
 $id=(int)($_POST['id']??0);$p=db();$p->beginTransaction();try {
  query('SELECT id FROM employees WHERE id=? FOR UPDATE',[$u['id']]);$changed=query("UPDATE leave_requests SET status='cancelled' WHERE id=? AND employee_id=? AND status='pending'",[$id,$u['id']])->rowCount();
  if(!$changed)throw new RuntimeException('Only your pending requests can be cancelled.');audit((int)$u['id'],'leave_cancelled',['id'=>$id]);$p->commit();
 }catch(Throwable $x){if($p->inTransaction())$p->rollBack();throw $x;}return 'Pending leave cancelled.';
}
function save_holiday(array $u): string {
 if(!staff($u))throw new RuntimeException('HR access required.');$date=valid_date((string)($_POST['holiday_date']??''));$p=db();$p->beginTransaction();try {
  if(($_POST['operation']??'')==='remove')query('DELETE FROM holidays WHERE holiday_date=?',[$date]);
  else query('INSERT INTO holidays(holiday_date,title) VALUES(?,?) ON DUPLICATE KEY UPDATE title=VALUES(title)',[$date,text_field('title',120)]);
  audit((int)$u['id'],'holiday_updated',['date'=>$date,'operation'=>$_POST['operation']??'save']);$p->commit();
 }catch(Throwable $x){if($p->inTransaction())$p->rollBack();throw $x;}return 'Holiday calendar updated.';
}
function checkout_overdue(array $r): bool {
 if($r['checked_out'])return false;
 if(weekend_work_date($r['work_date']))return time()-strtotime($r['checked_in'].' UTC')>86400;
 $end=new DateTimeImmutable($r['work_date'].' 17:30:00',tz());
 $in=strtotime($r['checked_in'].' UTC');$minutes=max(0,(int)(config()['checkout_alert_minutes']??60));
 return time()>max($end->getTimestamp()+$minutes*60,$in+$minutes*60);
}
function month_range(): array {
 $month=(string)($_GET['month']??substr(today(),0,7));if(!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/',$month))fail('Invalid month.',400);
 $start=valid_date($month.'-01');return [$month,$start,(new DateTimeImmutable($start,tz()))->format('Y-m-t')];
}
function save_weekly_schedule(int $employee): void {
 $date=valid_date((string)($_POST['schedule_from']??''));$days=$_POST['weekdays']??[];
 if(!is_array($days)||!$days||count($days)>7)throw new RuntimeException('Select at least one working weekday.');
 foreach($days as $day)if(!is_string($day)||!preg_match('/^[1-7]$/',$day))throw new RuntimeException('Invalid weekday.');
 $days=array_unique($days);sort($days,SORT_NUMERIC);
 query('INSERT INTO weekly_schedules(employee_id,effective_date,weekdays) VALUES(?,?,?) ON DUPLICATE KEY UPDATE weekdays=VALUES(weekdays)',[$employee,$date,implode(',',$days)]);
 audit((int)$_SESSION['local_employee_id'],'weekly_schedule_saved',['employee_id'=>$employee,'effective_date'=>$date,'weekdays'=>$days]);
}
function employee_week(int $employee,string $date): array {
 $week=query('SELECT weekdays FROM weekly_schedules WHERE employee_id=? AND effective_date<=? ORDER BY effective_date DESC LIMIT 1',[$employee,$date])->fetchColumn();
 return $week===false?(config()['work_week']??[1,2,3,4,5]):array_map('intval',explode(',',$week));
}
function leave_part_label(string $leave_part): string {return ['full'=>'Full day','first_half'=>'First half','second_half'=>'Second half'][$leave_part]??'Full day';}
function calendar_data(int $employee,string $start,string $end): array {
 $rows=query('SELECT a.*,e.name,e.employee_code,e.department FROM attendance a JOIN employees e ON e.id=a.employee_id WHERE a.employee_id=? AND a.work_date BETWEEN ? AND ?',[$employee,$start,$end])->fetchAll();$days=[];foreach(summary($rows) as $d)$days[$d['row']['work_date']]=$d;
 $holidays=query('SELECT * FROM holidays WHERE holiday_date BETWEEN ? AND ?',[$start,$end])->fetchAll(PDO::FETCH_KEY_PAIR);
 $leave=query("SELECT * FROM leave_requests WHERE employee_id=? AND status='approved' AND start_date<=? AND end_date>=?",[$employee,$end,$start])->fetchAll();
 $schedules=query('SELECT * FROM weekly_schedules WHERE employee_id=? AND effective_date<=? ORDER BY effective_date',[$employee,$end])->fetchAll();$out=[];
 for($date=new DateTimeImmutable($start,tz());$date->format('Y-m-d')<=$end;$date=$date->modify('+1 day')) {
  $key=$date->format('Y-m-d');$week=config()['work_week']??[1,2,3,4,5];foreach($schedules as $schedule)if($schedule['effective_date']<=$key)$week=array_map('intval',explode(',',$schedule['weekdays']));
  $leaves=[];$units=0;$first=false;foreach($leave as $r)if($r['start_date']<=$key&&$r['end_date']>=$key){$leaves[]=$r;$units+=$r['leave_part']==='full'?1:0.5;$first=$first||in_array($r['leave_part'],['full','first_half'],true);}$units=min(1,$units);
  $working=!weekend_work_date($key)&&in_array((int)$date->format('N'),$week,true)&&!isset($holidays[$key]);$d=$days[$key]??null;
  if($d&&$first&&!weekend_work_date($key)){$r=$d['row'];$expected=new DateTimeImmutable($key.' 08:00:00',tz());$allowance=$units>=1?34200:17100;$d['late']=max(0,(int)floor((strtotime($d['first'].' UTC')-$expected->getTimestamp()-$allowance)/60)-(int)$r['grace_minutes']);}
  $status=$d?($d['open']?(array_filter($d['session_rows'],fn($r)=>!empty($r['auto_closed']))?'Auto checkout · Needs review':'Open session'):'Recorded'):(isset($holidays[$key])?'Holiday':(!$working?(weekend_work_date($key)?'Flexible weekend':'Weekly off'):($units>=1?'Approved leave':($units>0?($key<today()?'Half-day leave; remaining half needs review':'Approved half-day leave'):($key<today()?'No entry':($key===today()?'Not checked in yet':'Upcoming'))))));
  $missing=$working&&$key<today()&&!$d?1-$units:0;
  $out[]=['date'=>$key,'status'=>$status,'holiday'=>$holidays[$key]??'','leaves'=>$leaves,'leave_units'=>$units,'missing_units'=>$missing,'day'=>$d,'working'=>$working];
 }return $out;
}
function backup_write($stream,string $text): void {
 $length=strlen($text);$offset=0;while($offset<$length){$n=fwrite($stream,substr($text,$offset));if($n===false||$n===0)throw new RuntimeException('Backup storage is full or unavailable. No backup was downloaded.');$offset+=$n;}
}
function download_backup(array $u): never {
 if($u['role']!=='admin')throw new RuntimeException('Administrator access required.');
 $p=db();$f=tmpfile();if(!$f)throw new RuntimeException('Unable to create backup. Check temporary storage.');
 try {
  audit((int)$u['id'],'database_backup',[]);$p->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$p->beginTransaction();
  backup_write($f,"-- Office Attendance backup (contains account password hashes)\n-- Restore into a separate empty database selected in phpMyAdmin.\nSET NAMES utf8mb4;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\nSET FOREIGN_KEY_CHECKS=0;\n");
  foreach(['employees','accounts','devices','attendance','punch_requests','corrections','audit_log','holidays','leave_requests','weekly_schedules','salary_months','salary_snapshots','salary_reconciliations','payroll_locks','leave_allocations'] as $table) {
   $ddl=query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_NUM)[1];backup_write($f,$ddl.";\n");
   $q=query('SELECT * FROM `'.$table.'`');while($r=$q->fetch()) { $values=array_map(fn($v)=>$v===null?'NULL':$p->quote((string)$v),array_values($r));backup_write($f,'INSERT INTO `'.$table.'` VALUES('.implode(',',$values).");\n"); }
  }
  backup_write($f,"SET FOREIGN_KEY_CHECKS=1;\n");$p->commit();rewind($f);
 }catch(Throwable $x){if($p->inTransaction())$p->rollBack();fclose($f);throw $x;}
 header('Content-Type: application/sql; charset=utf-8');header('Content-Disposition: attachment; filename="attendance_backup_'.gmdate('Y-m-d_His').'.sql"');fpassthru($f);fclose($f);exit;
}
