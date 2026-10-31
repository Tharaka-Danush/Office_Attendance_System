<?php
declare(strict_types=1);
function payroll_locked(int $employee,string $workDate): bool {
 return (bool)query('SELECT 1 FROM payroll_locks WHERE employee_id=? AND period_start<=? AND period_end>=? LIMIT 1',[$employee,$workDate,$workDate])->fetchColumn();
}
function require_unlocked_period(int $employee,string $workDate): void {
 if(payroll_locked($employee,$workDate))throw new RuntimeException('This attendance period is payroll-locked. HR/Admin must unlock it before changes.');
}
function payroll_lock_action(array $u): string {
 if($u['role']!=='admin')throw new RuntimeException('Admin approval required.');
 $employee=filter_var($_POST['employee_id']??null,FILTER_VALIDATE_INT);$start=valid_date((string)($_POST['period_start']??''));$end=valid_date((string)($_POST['period_end']??''));
 $action=(string)($_POST['lock_operation']??'');$note=trim((string)($_POST['lock_note']??''));
 if(!$employee||$start>$end||strtotime($end)-strtotime($start)>40*86400||!in_array($action,['lock','unlock'],true)||strlen($note)>500||strlen($note)<5)throw new RuntimeException('Select employee, valid period (up to 41 days) and reason of at least 5 characters.');
 $pdo=db();$pdo->beginTransaction();try {
  if(!query('SELECT id FROM employees WHERE id=? FOR UPDATE',[$employee])->fetch())throw new RuntimeException('Employee not found.');
  if($action==='lock') {
   if(query('SELECT 1 FROM attendance WHERE employee_id=? AND work_date BETWEEN ? AND ? AND (checked_out IS NULL OR auto_closed=1) LIMIT 1',[$employee,$start,$end])->fetch())throw new RuntimeException('Cannot lock: open or unreviewed auto-checkout sessions exist.');
   if(query("SELECT 1 FROM corrections WHERE employee_id=? AND status='pending' AND work_date BETWEEN ? AND ? LIMIT 1",[$employee,$start,$end])->fetch())throw new RuntimeException('Resolve pending attendance corrections first.');
   // A manually closed session lasting more than a day may contain days of forgotten checkout.
   // Do not finalize it unless an approved attendance correction documents an HR review.
   if(query("SELECT 1 FROM attendance a WHERE a.employee_id=? AND a.work_date BETWEEN ? AND ? AND a.checked_out IS NOT NULL AND TIMESTAMPDIFF(SECOND,a.checked_in,a.checked_out)>86400 AND NOT EXISTS (SELECT 1 FROM corrections c WHERE c.attendance_id=a.id AND c.status='approved') LIMIT 1",[$employee,$start,$end])->fetch())throw new RuntimeException('Cannot lock: attendance over 24 hours needs an approved HR correction. Review Checkout Alerts before finalizing.');

   if(query('SELECT 1 FROM payroll_locks WHERE employee_id=? AND period_start<=? AND period_end>=? LIMIT 1',[$employee,$end,$start])->fetch())throw new RuntimeException('Overlapping payroll lock exists.');
   query('INSERT INTO payroll_locks(employee_id,period_start,period_end,locked_by,locked_at,note) VALUES(?,?,?,?,?,?)',[$employee,$start,$end,$u['id'],utc(),$note]);
   // A full previous calendar month can be finalized for the following salary month.
   if($start===substr($start,0,7).'-01' && $end===date('Y-m-t',strtotime($start))) {
    $salaryMonth=(new DateTimeImmutable($start,tz()))->modify('+1 month')->format('Y-m');
    $settings=query('SELECT * FROM salary_months WHERE employee_id=? AND salary_month=?',[$employee,$salaryMonth.'-01'])->fetch();
    if(!$settings)throw new RuntimeException('Salary inputs missing for '.$salaryMonth.'. Save the employee salary settings before finalizing this payroll month.');
    if($settings) {
     $days=array_values(array_filter(array_column(calendar_data($employee,$start,$end),'day'),static fn($d)=>$d!==null));
     $result=salary_estimate($settings,$days);
     if($result['open'])throw new RuntimeException('Cannot finalize salary: attendance needs review.');
     query('INSERT INTO salary_snapshots(employee_id,salary_month,period_start,period_end,net_cents,gross_cents,deductions_cents,details_json,finalized_at,finalized_by) VALUES(?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE net_cents=VALUES(net_cents),gross_cents=VALUES(gross_cents),deductions_cents=VALUES(deductions_cents),details_json=VALUES(details_json),finalized_at=VALUES(finalized_at),finalized_by=VALUES(finalized_by)',[$employee,$salaryMonth.'-01',$start,$end,$result['net'],$result['gross'],$result['total_deductions'],json_encode($result,JSON_THROW_ON_ERROR),utc(),$u['id']]);
    }
   }
  } else {
   $affected=query('DELETE FROM payroll_locks WHERE employee_id=? AND period_start=? AND period_end=?',[$employee,$start,$end])->rowCount();
   if(!$affected)throw new RuntimeException('Exact payroll lock not found.');
   query('DELETE FROM salary_snapshots WHERE employee_id=? AND period_start=? AND period_end=?',[$employee,$start,$end]);
  }
  audit((int)$u['id'],'payroll_'.$action,['employee_id'=>$employee,'period_start'=>$start,'period_end'=>$end,'reason'=>$note]);$pdo->commit();
 }catch(Throwable $x){if($pdo->inTransaction())$pdo->rollBack();throw $x;}
 return 'Payroll period '.($action==='lock'?'locked':'unlocked').'.';
}
/** Count only scheduled working dates excluding company holidays; half-days are 0.5 units. */
function leave_chargeable_units(string $start,string $end,string $part,array $holidayDates,array $schedules,array $defaultWeek): float {
 $used=0.0;
 for($day=new DateTimeImmutable($start);$day->format('Y-m-d')<=$end;$day=$day->modify('+1 day')) {
  $key=$day->format('Y-m-d');$week=$defaultWeek;
  foreach($schedules as $schedule){if($schedule['effective_date']<=$key)$week=array_map('intval',explode(',',$schedule['weekdays']));else break;}
  if(!isset($holidayDates[$key])&&in_array((int)$day->format('N'),$week,true))$used+=($part==='full'?1.0:0.5);
 }
 return $used;
}
function leave_balance_rows(int $employee,int $year): array {
 $rows=[];$yearStart=sprintf('%04d-01-01',$year);$yearEnd=sprintf('%04d-12-31',$year);
 $holidays=array_fill_keys(array_column(query('SELECT holiday_date FROM holidays WHERE holiday_date BETWEEN ? AND ?',[$yearStart,$yearEnd])->fetchAll(),'holiday_date'),true);
 $schedules=query('SELECT effective_date,weekdays FROM weekly_schedules WHERE employee_id=? AND effective_date<=? ORDER BY effective_date',[$employee,$yearEnd])->fetchAll();
 $defaultWeek=config()['work_week']??[1,2,3,4,5];
 foreach(['annual','casual','sick'] as $type){
  $allocation=query('SELECT days_allocated FROM leave_allocations WHERE employee_id=? AND leave_year=? AND leave_type=?',[$employee,$year,$type])->fetchColumn();
  $approved=query("SELECT start_date,end_date,leave_part FROM leave_requests WHERE employee_id=? AND leave_type=? AND status='approved' AND start_date<=? AND end_date>=?",[$employee,$type,$yearEnd,$yearStart])->fetchAll();
  $used=0.0;foreach($approved as $leave){$from=max($leave['start_date'],$yearStart);$to=min($leave['end_date'],$yearEnd);$used+=leave_chargeable_units($from,$to,$leave['leave_part'],$holidays,$schedules,$defaultWeek);}
  $rows[]=['type'=>$type,'allocated'=>$allocation===false?null:(float)$allocation,'used'=>$used,'remaining'=>$allocation===false?null:(float)$allocation-$used];
 }return $rows;
}
function save_leave_allocation(array $u): string {
 if(!staff($u))throw new RuntimeException('HR/Admin only.');
 $employee=filter_var($_POST['employee_id']??null,FILTER_VALIDATE_INT);$year=filter_var($_POST['leave_year']??null,FILTER_VALIDATE_INT);$type=(string)($_POST['leave_type']??'');$days=(string)($_POST['days_allocated']??'');
 if(!$employee||$year<2020||$year>2100||!in_array($type,['annual','casual','sick'],true)||!preg_match('/^\d{1,3}(?:\.[05])?$/D',$days)||(float)$days>366)throw new RuntimeException('Invalid leave allocation.');
 $pdo=db();$pdo->beginTransaction();try{
  if(!query('SELECT 1 FROM employees WHERE id=? FOR UPDATE',[$employee])->fetchColumn())throw new RuntimeException('Employee missing.');
  query('INSERT INTO leave_allocations(employee_id,leave_year,leave_type,days_allocated,updated_by,updated_at) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE days_allocated=VALUES(days_allocated),updated_by=VALUES(updated_by),updated_at=VALUES(updated_at)',[$employee,$year,$type,$days,$u['id'],utc()]);
  audit((int)$u['id'],'leave_allocation_updated',['employee_id'=>$employee,'year'=>$year,'type'=>$type,'days'=>$days]);$pdo->commit();
 }catch(Throwable $x){if($pdo->inTransaction())$pdo->rollBack();throw $x;}return 'Leave entitlement saved.';
}
