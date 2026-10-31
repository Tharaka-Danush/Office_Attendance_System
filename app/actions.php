<?php
declare(strict_types=1);
function text_field(string $name,int $max,bool $required=true): string {
 $v=trim((string)($_POST[$name]??''));
 if (($required&&$v==='')||strlen($v)>$max) throw new RuntimeException('Invalid '.$name.'.');
 return $v;
}
function request_correction(array $u): string {
 $in=proposed_time((string)($_POST['proposed_in']??''));$out=proposed_time((string)($_POST['proposed_out']??''));
 if ($out<=$in||strtotime($out.' UTC')-strtotime($in.' UTC')>172800||$out>utc()) throw new RuntimeException('End must follow start, must not be in the future, and the session must be at most 48 hours.');
 $reason=text_field('reason',500);$id=(int)($_POST['attendance_id']??0);
 $p=db();$p->beginTransaction();
 try {
  $employee=query('SELECT * FROM employees WHERE id=? FOR UPDATE',[$u['id']])->fetch();
  $base=$id?query('SELECT * FROM attendance WHERE id=? AND employee_id=? FOR UPDATE',[$id,$u['id']])->fetch():null;
  if ($id&&!$base) throw new RuntimeException('Attendance record not found.');
  $pending=query("SELECT id FROM corrections WHERE employee_id=? AND status='pending' AND (attendance_id=? OR (attendance_id IS NULL AND proposed_in=? AND proposed_out=?))",[$u['id'],$id,$in,$out])->fetch();
  if($pending) throw new RuntimeException('A matching request is already pending.');
  $local=(new DateTimeImmutable($in,new DateTimeZone('UTC')))->setTimezone(tz());
  $date=work_date($local,$employee['shift_start'],$employee['shift_end']);
  require_unlocked_period((int)$u['id'],$date);
  if($base)require_unlocked_period((int)$u['id'],(string)$base['work_date']);
  query('INSERT INTO corrections(employee_id,attendance_id,work_date,proposed_in,proposed_out,reason,base_in,base_out,created_at) VALUES(?,?,?,?,?,?,?,?,?)',[$u['id'],$id?:null,$date,$in,$out,$reason,$base['checked_in']??null,$base['checked_out']??null,utc()]);
  audit((int)$u['id'],'correction_requested',['request_id'=>$p->lastInsertId(),'attendance_id'=>$id?:null,'reason'=>$reason]);
  $p->commit();return 'Correction sent to HR for approval.';
 } catch(Throwable $x) {if($p->inTransaction())$p->rollBack();throw $x;}
}
function review_correction(array $u): string {
 if(!staff($u)) throw new RuntimeException('HR access required.');
 $id=(int)($_POST['id']??0);$decision=text_field('decision',20);$note=text_field('review_note',500);
 if(!in_array($decision,['approved','rejected'],true))throw new RuntimeException('Invalid decision.');
 $hint=query('SELECT employee_id FROM corrections WHERE id=?',[$id])->fetch();
 if(!$hint)throw new RuntimeException('Request not found.');
 if($hint['employee_id']==$u['id'])throw new RuntimeException('Another HR/Admin user must review your attendance.');
 $p=db();$p->beginTransaction();
 try {
  $employee=query('SELECT * FROM employees WHERE id=? FOR UPDATE',[$hint['employee_id']])->fetch();
  $r=query('SELECT * FROM corrections WHERE id=? FOR UPDATE',[$id])->fetch();
  if(!$r||$r['status']!=='pending')throw new RuntimeException('This request has already been reviewed.');
  $before=null;$record=$r['attendance_id'];
  if($decision==='approved') {
   require_unlocked_period((int)$employee['id'],(string)$r['work_date']);
   $correctedLocal=(new DateTimeImmutable($r['proposed_in'],new DateTimeZone('UTC')))->setTimezone(tz());$correctedDate=work_date($correctedLocal,$employee['shift_start'],$employee['shift_end']);require_unlocked_period((int)$employee['id'],$correctedDate);
   if(!$employee['active'])throw new RuntimeException('Employee is inactive.');
   if($record) {
    $before=query('SELECT * FROM attendance WHERE id=? AND employee_id=? FOR UPDATE',[$record,$employee['id']])->fetch();
    if(!$before||$before['checked_in']!==$r['base_in']||$before['checked_out']!==$r['base_out']) throw new RuntimeException('Attendance changed after this request. Reject it and ask for a new request.');
   }
   $overlap=query('SELECT id FROM attendance WHERE employee_id=? AND id<>? AND checked_in<? AND (checked_out IS NULL OR checked_out>?) LIMIT 1 FOR UPDATE',[$employee['id'],$record?:0,$r['proposed_out'],$r['proposed_in']])->fetch();
   if($overlap)throw new RuntimeException('Requested times overlap another session.');
   if($record) query('UPDATE attendance SET work_date=?,checked_in=?,checked_out=?,auto_closed=0 WHERE id=?',[$correctedDate,$r['proposed_in'],$r['proposed_out'],$record]);
   else {
    if(!$u['device_id'])throw new RuntimeException('Approve your current office computer before adding a missing attendance session.');
    query('INSERT INTO attendance(employee_id,work_date,checked_in,checked_out,in_device,out_device,shift_start,shift_end,grace_minutes,break_minutes) VALUES(?,?,?,?,?,?,?,?,?,?)',[$employee['id'],$correctedDate,$r['proposed_in'],$r['proposed_out'],$u['device_id'],$u['device_id'],$employee['shift_start'],$employee['shift_end'],$employee['grace_minutes'],$employee['break_minutes']]);
    $record=$p->lastInsertId();
   }
  }
  if($decision==='approved')query('UPDATE corrections SET work_date=? WHERE id=?',[$correctedDate,$id]);
  query('UPDATE corrections SET status=?,reviewed_by=?,reviewed_at=?,review_note=? WHERE id=?',[$decision,$u['id'],utc(),$note,$id]);
  audit((int)$u['id'],'correction_'.$decision,['request_id'=>$id,'attendance_id'=>$record,'before'=>$before,'proposed_in'=>$r['proposed_in'],'proposed_out'=>$r['proposed_out'],'note'=>$note]);
  $p->commit();return 'Request '.$decision.'.';
 } catch(Throwable $x) {if($p->inTransaction())$p->rollBack();throw $x;}
}
function save_employee(array $u): string {
 if($u['role']!=='admin')throw new RuntimeException('Admin access required.');
 $id=(int)($_POST['id']??0);$newEmployee=$id===0;$name=text_field('name',120);$code=text_field('employee_code',40);$department=text_field('department',100,false);
 $role=text_field('role',20);if(!in_array($role,['employee','hr','admin'],true))throw new RuntimeException('Invalid role.');
 $active=isset($_POST['active'])?1:0;
 $start=text_field('shift_start',8);$end=text_field('shift_end',8);
 foreach([$start,$end] as $t) if(!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$t))throw new RuntimeException('Invalid shift time.');
 if($start===$end)throw new RuntimeException('Shift start and end must differ.');
 $grace=filter_var($_POST['grace_minutes']??null,FILTER_VALIDATE_INT);$break=filter_var($_POST['break_minutes']??null,FILTER_VALIDATE_INT);
 if($grace===false||$break===false||$grace<0||$grace>120||$break<0||$break>240||$break*60>=shift_seconds($start.':00',$end.':00'))throw new RuntimeException('Invalid grace/break duration.');
 if($id===$u['id']&&(!$active||$role!=='admin'))throw new RuntimeException('You cannot remove your own admin access.');
 $p=db();$p->beginTransaction();
 try {
  // Lock active admins in stable order before changing roles; preserve at least one.
  $admins=query("SELECT id FROM employees WHERE role='admin' AND active=1 ORDER BY id FOR UPDATE")->fetchAll();
  $old=$id?query('SELECT * FROM employees WHERE id=? FOR UPDATE',[$id])->fetch():null;
  if($id&&!$old)throw new RuntimeException('Employee not found.');
  if($old&&$old['role']==='admin'&&$old['active']&&(!$active||$role!=='admin')&&count($admins)<=1)throw new RuntimeException('Keep at least one active admin.');
  $values=[$code,$name,$department,$role,$active,$start,$end,$grace,$break];
  if($id)query('UPDATE employees SET employee_code=?,name=?,department=?,role=?,active=?,shift_start=?,shift_end=?,grace_minutes=?,break_minutes=? WHERE id=?',[...$values,$id]);
  else {query('INSERT INTO employees(employee_code,name,department,role,active,shift_start,shift_end,grace_minutes,break_minutes) VALUES(?,?,?,?,?,?,?,?,?)',$values);$id=(int)$p->lastInsertId();}
  if(isset($_POST['schedule_present']))save_weekly_schedule($id);
  save_local_account($id,$newEmployee);
  audit((int)$u['id'],'employee_saved',['employee_id'=>$id,'before'=>$old,'name'=>$name,'role'=>$role,'active'=>$active,'shift_start'=>$start,'shift_end'=>$end]);
  $p->commit();return 'Employee saved. Shift changes apply to new sessions.';
 } catch(Throwable $x) {if($p->inTransaction())$p->rollBack();throw $x;}
}
function change_device(array $u): string {
 if($u['role']!=='admin')throw new RuntimeException('Admin access required.');
 $id=(int)($_POST['id']??0);$status=text_field('status',20);$label=text_field('label',120);
 $ip=text_field('ip_address',45);$employee=(int)($_POST['employee_id']??0);
 if(!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)||!network_allowed($ip,config()['allowed_networks']))throw new RuntimeException('Enter an IPv4 address inside the configured office networks.');
 if(!in_array($status,['approved','revoked'],true))throw new RuntimeException('Invalid status.');
 if(!query('SELECT id FROM employees WHERE id=?',[$employee])->fetch())throw new RuntimeException('Employee not found.');
 $p=db();$p->beginTransaction();
 try {
  $old=$id?query('SELECT * FROM devices WHERE id=? FOR UPDATE',[$id])->fetch():null;
  if($id&&!$old)throw new RuntimeException('Computer not found.');
  if($id)query('UPDATE devices SET employee_id=?,ip_address=?,label=?,status=? WHERE id=?',[$employee,$ip,$label,$status,$id]);
  else query('INSERT INTO devices(employee_id,ip_address,label,status,created_at) VALUES(?,?,?,?,?)',[$employee,$ip,$label,$status,utc()]);
  audit((int)$u['id'],'computer_saved',['device_id'=>$id?:$p->lastInsertId(),'before'=>$old,'employee_id'=>$employee,'ip_address'=>$ip,'status'=>$status]);
  $p->commit();return 'Computer saved.';
 }catch(Throwable $x){if($p->inTransaction())$p->rollBack();throw $x;}
}

/** Admin-only, audited correction of an existing employee attendance session. */
function admin_correct_attendance(array $u): string {
 if($u['role']!=='admin')throw new RuntimeException('Admin permission required.');
 $id=filter_var($_POST['attendance_id']??null,FILTER_VALIDATE_INT);
 if(!$id||$id<1)throw new RuntimeException('Select a valid attendance record.');
 $reason=text_field('reason',500);
 if(strlen($reason)<8)throw new RuntimeException('Enter a meaningful correction reason (at least 8 characters).');
 $in=proposed_time((string)($_POST['proposed_in']??''));
 $out=proposed_time((string)($_POST['proposed_out']??''));
 if($out<=$in||strtotime($out.' UTC')-strtotime($in.' UTC')>172800||$out>utc())throw new RuntimeException('Valid sessions must end after check-in, cannot be in the future or exceed 48 hours.');
 $hint=query('SELECT employee_id FROM attendance WHERE id=?',[$id])->fetch();
 if(!$hint)throw new RuntimeException('Attendance record not found.');
 $employeeId=(int)$hint['employee_id'];
 if($employeeId===(int)$u['id'])throw new RuntimeException('Admins cannot edit their own attendance.');
 $p=db();$p->beginTransaction();
 try {
  $employee=query('SELECT * FROM employees WHERE id=? FOR UPDATE',[$employeeId])->fetch();
  $record=query('SELECT * FROM attendance WHERE id=? AND employee_id=? FOR UPDATE',[$id,$employeeId])->fetch();
  if(!$record)throw new RuntimeException('Attendance record not found.');
  $date=work_date((new DateTimeImmutable($in,new DateTimeZone('UTC')))->setTimezone(tz()),$employee['shift_start'],$employee['shift_end']);
  require_unlocked_period($employeeId,(string)$record['work_date']);
  require_unlocked_period($employeeId,$date);
  $overlap=query('SELECT id FROM attendance WHERE employee_id=? AND id<>? AND checked_in<? AND (checked_out IS NULL OR checked_out>?) LIMIT 1 FOR UPDATE',[$employeeId,$id,$out,$in])->fetch();
  if($overlap)throw new RuntimeException('Correction would overlap another employee session.');
  query('UPDATE attendance SET work_date=?,checked_in=?,checked_out=?,auto_closed=0 WHERE id=?',[$date,$in,$out,$id]);
  audit((int)$u['id'],'admin_attendance_corrected',['attendance_id'=>$id,'employee_id'=>$employeeId,'before'=>$record,'after'=>['work_date'=>$date,'checked_in'=>$in,'checked_out'=>$out],'reason'=>$reason]);
  $p->commit();return 'Employee attendance updated. Correction recorded in the activity log.';
 }catch(Throwable $e){if($p->inTransaction())$p->rollBack();throw $e;}
}
