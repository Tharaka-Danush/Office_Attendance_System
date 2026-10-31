<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Command line only.');}
require dirname(__DIR__).'/app/core.php';require dirname(__DIR__).'/app/salary.php';require dirname(__DIR__).'/app/payroll_controls.php';
$passed=0;$failed=0;
function acceptance_check(bool $ok,string $label): void {global $passed,$failed;echo ($ok?'PASS: ':'FAIL: ').$label.PHP_EOL;$ok?$passed++:$failed++;}
function acceptance_record(string $date,string $in,?string $out,int $id=1): array {
 $time=fn($v)=>(new DateTimeImmutable($date.' '.$v,tz()))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
 return ['id'=>$id,'employee_id'=>1,'work_date'=>$date,'checked_in'=>$time($in),'checked_out'=>$out?$time($out):null,'break_minutes'=>60,'grace_minutes'=>0];
}
try{
 acceptance_check(network_allowed('192.168.1.25',['192.168.1.0/24']),'Office CIDR permits a matching IPv4 address.');
 acceptance_check(!network_allowed('192.168.2.25',['192.168.1.0/24']),'Office CIDR rejects an outside IPv4 address.');
 $d=summary([acceptance_record('2026-10-12','08:00','19:00')])[0];
 acceptance_check($d['net']===36000&&$d['weekday_ot']===5400,'Weekday 08:00-19:00: net 10h, OT 1h30.');
 $d=summary([acceptance_record('2026-10-12','08:00','12:00'),acceptance_record('2026-10-12','13:00','19:00',2)])[0];
 acceptance_check($d['net']===36000&&$d['break_deducted']===0,'Multiple sessions exclude the lunch gap once.');
 acceptance_check(local_time($d['first'],'H:i')==='08:00'&&local_time($d['last'],'H:i')==='19:00','First check-in and last completed checkout.');
 $d=summary([acceptance_record('2026-10-11','14:00','23:00')])[0];
 acceptance_check($d['late']===0&&$d['single_ot']===28800&&$d['double_ot']===3600,'Flexible weekend: no late; 8h first band plus 1h second band.');
 $r=acceptance_record('2026-10-12','08:00',null);$r['checked_out']=(new DateTimeImmutable('2026-10-13 02:00',tz()))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');$d=summary([$r])[0];
 acceptance_check($d['net']===61200&&$d['weekday_ot']===30600&&$d['row']['work_date']==='2026-10-12','Overnight session retains its check-in work date.');
 $d=summary([acceptance_record('2026-10-11','08:00','12:00'),acceptance_record('2026-10-11','13:00',null,2)])[0];
 acceptance_check($d['ot']===null&&$d['net']===14400,'Open day excludes unfinished OT; completed work remains.');
 acceptance_check(salary_attendance_period('2026-10')===['2026-09','2026-09-01','2026-09-30'],'October salary uses all of September.');
acceptance_check(salary_attendance_period('2026-01')===['2025-12','2025-12-01','2025-12-31'],'January salary uses the full previous December.');
 acceptance_check(salary_attendance_period('2024-03')===['2024-02','2024-02-01','2024-02-29'],'March salary uses leap-year February.');
 $leaveSchedules=[['effective_date'=>'2026-01-01','weekdays'=>'1,2,3,4,5']];
 acceptance_check(leave_chargeable_units('2026-10-16','2026-10-19','full',[],$leaveSchedules,[1,2,3,4,5])===2.0,'Friday–Monday leave excludes Saturday and Sunday.');
 acceptance_check(leave_chargeable_units('2026-10-16','2026-10-19','full',['2026-10-19'=>true],$leaveSchedules,[1,2,3,4,5])===1.0,'Company holiday is excluded from leave balance.');
 acceptance_check(leave_chargeable_units('2026-10-16','2026-10-16','first_half',[],$leaveSchedules,[1,2,3,4,5])===0.5,'Half-day leave charges half a scheduled day.');
 acceptance_check(leave_chargeable_units('2026-10-16','2026-10-19','full',[],[['effective_date'=>'2026-01-01','weekdays'=>'1,2,3,4,5'],['effective_date'=>'2026-10-19','weekdays'=>'2,3,4,5,6']],[1,2,3,4,5])===1.0,'Effective-dated weekly schedule changes leave charges.');
 $settings=['basic_salary'=>'30000','allowances'=>'0','travelling_allowance'=>'5000','attendance_bonus'=>'2500','night_allowance'=>'850','meal_allowance'=>'375','epf_rate'=>8,'deductions'=>'527'];
 $e=salary_estimate($settings,[['open'=>false,'weekday_ot'=>3600,'single_ot'=>0,'double_ot'=>7200,'late'=>48]]);
 acceptance_check($e['weekday']===18750 && $e['double']===50000,'One weekday OT hour at 1.5x and two double OT hours at 2x.');
 acceptance_check($e['epf']===240000 && $e['lateness']===10000,'8% basic-only EPF and 48-minute lateness estimate.');
 acceptance_check($e['gross']===3941250 && $e['net']===3638550,'Gross and net cents reconcile with travel, night and meal allowances, EPF and deductions.');
 $slipSettings=['basic_salary'=>'30000','ll_day_payment'=>'2000','days_payment'=>'1500','epf_rate'=>8,'travelling_allowance'=>'5000','attendance_bonus'=>'2500','allowances'=>'0','deductions'=>'0','stamp_duty'=>'25','insurance_deduction'=>'352','donation_deduction'=>'90'];
 $slipEstimate=salary_estimate($slipSettings,[]);
 acceptance_check($slipEstimate['epf_base']===3350000 && $slipEstimate['epf']===268000,'September payslip EPF-eligible base 33,500 and employee EPF 2,680.');
 acceptance_check($slipEstimate['employer_epf_reference']===402000 && $slipEstimate['employer_etf_reference']===100500,'Employer EPF 12% and ETF 3% of reference base.');
 acceptance_check($slipEstimate['net']===3785300,'Reference components, fixed deductions and contributions reconcile without OT.');
 $settings['epf_rate']=0;
 $e=salary_estimate($settings,[['open'=>true,'weekday_ot'=>3600,'single_ot'=>0,'double_ot'=>0,'late'=>0]]);
 acceptance_check($e['open']===1 && $e['weekday']===0 && $e['epf']===0,'Open-session OT excluded and optional EPF disabled.');
 $pending=salary_estimate(['basic_salary'=>'30000','allowances'=>'0','deductions'=>'0'],[['open'=>true,'late'=>180,'weekday_ot'=>3600,'single_ot'=>0,'double_ot'=>0]]);
 acceptance_check($pending['lateness']===0 && $pending['late_minutes']===0 && $pending['weekday']===0,'Unreviewed auto-checkout excludes OT and late deductions.');
 acceptance_check($pending['epf']===240000 && $pending['epf_rate']===8,'New salary estimates default to 8% employee EPF.');
 $cutoff=midnight_cutoff_utc((new DateTimeImmutable('2026-10-20 20:00:00',tz()))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'));
 acceptance_check(local_time($cutoff,'Y-m-d H:i:s')==='2026-10-21 00:00:00','Midnight auto checkout uses Sri Lanka local calendar midnight.');
 echo "Checks: $passed passed, $failed failed. No database records or configuration changed.".PHP_EOL;
 echo 'These fixtures do not verify real PC approval, Windows scrolling, printing or live payslip adjustments.'.PHP_EOL;
}catch(Throwable $x){fwrite(STDERR,"Checks could not finish. Check PHP extensions, configuration and installed update files.\n");exit(1);}
exit($failed?1:0);
