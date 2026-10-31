<?php
declare(strict_types=1);
function salary_cents(string $amount): int {
 if(!preg_match('/^\d{1,7}(?:\.\d{1,2})?$/D',$amount))throw new RuntimeException('Enter an amount between 0 and 9,999,999.99 with up to two decimal places.');
 $parts=explode('.',$amount);return (int)$parts[0]*100+(int)str_pad($parts[1]??'',2,'0');
}
function salary_money(int $cents): string {return number_format($cents/100,2,'.',',');}
function salary_rounded(int $numerator,int $denominator): int {return intdiv($numerator+intdiv($denominator,2),$denominator);}
function salary_attendance_period(string $salaryMonth): array {
 if(!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D',$salaryMonth))throw new RuntimeException('Choose a valid salary month.');
 // Salary uses the previous complete calendar month for OT and lateness.
 $current=new DateTimeImmutable($salaryMonth.'-01',tz());
 $previous=$current->modify('-1 month');
 return [$previous->format('Y-m'),$previous->format('Y-m-01'),$previous->format('Y-m-t')];
}
function salary_estimate(array $settings,array $days): array {
 $base=salary_cents((string)$settings['basic_salary']);$allowance=salary_cents((string)$settings['allowances']);$deductions=salary_cents((string)$settings['deductions']);$travelling=salary_cents((string)($settings['travelling_allowance']??'0.00'));$bonus=salary_cents((string)($settings['attendance_bonus']??'0.00'));$night=salary_cents((string)($settings['night_allowance']??'0'));$meal=salary_cents((string)($settings['meal_allowance']??'0'));$ll=salary_cents((string)($settings['ll_day_payment']??'0'));$daysPay=salary_cents((string)($settings['days_payment']??'0'));$epfExtra=salary_cents((string)($settings['epf_other_earnings']??'0'));$stamp=salary_cents((string)($settings['stamp_duty']??'0'));$insurance=salary_cents((string)($settings['insurance_deduction']??'0'));$donation=salary_cents((string)($settings['donation_deduction']??'0'));$epfRate=(int)($settings['epf_rate']??8);if(!in_array($epfRate,[0,8],true))throw new RuntimeException('EPF rate must be 0 or 8 percent.');
 $seconds=['weekday_ot'=>0,'single_ot'=>0,'double_ot'=>0];$open=0;$lateMinutes=0;
 foreach($days as $d){if($d['open']){$open++;continue;}$lateMinutes+=max(0,(int)($d['late']??0));foreach($seconds as $key=>$unused)$seconds[$key]+=$d[$key];}
 // Keep the hourly base unrounded. Round each component only to final cents.
 $weekday=salary_rounded($base*$seconds['weekday_ot']*3,30*8*3600*2);
 $single=salary_rounded($base*$seconds['single_ot']*3,30*8*3600*2);
 $lateness=salary_rounded($base*$lateMinutes,30*8*60);
 $double=salary_rounded($base*$seconds['double_ot']*2,30*8*3600);
 $epfBase=$base+$ll+$daysPay+$epfExtra;
 $gross=$epfBase+$allowance+$travelling+$bonus+$night+$meal+$weekday+$single+$double;
 $epf=salary_rounded($epfBase*$epfRate,100);$employerEpf=salary_rounded($epfBase*12,100);$employerEtf=salary_rounded($epfBase*3,100);$allDeductions=$deductions+$lateness+$epf+$stamp+$insurance+$donation;
 return ['base'=>$base,'ll_day_payment'=>$ll,'days_payment'=>$daysPay,'epf_other_earnings'=>$epfExtra,'epf_base'=>$epfBase,'stamp_duty'=>$stamp,'insurance_deduction'=>$insurance,'donation_deduction'=>$donation,'employer_epf_reference'=>$employerEpf,'allowances'=>$allowance,'travelling'=>$travelling,'attendance_bonus'=>$bonus,'deductions'=>$deductions,'weekday'=>$weekday,'single'=>$single,'double'=>$double,'night'=>$night,'meal'=>$meal,'epf'=>$epf,'epf_rate'=>$epfRate,'employer_etf_reference'=>$employerEtf,'gross'=>$gross,'late_minutes'=>$lateMinutes,'lateness'=>$lateness,'total_deductions'=>$allDeductions,'net'=>$gross-$allDeductions,'seconds'=>$seconds,'open'=>$open,'provisional'=>$open>0];
}
function save_salary(array $u): string {
 $employee=(int)$u['id'];
 if(isset($_POST['employee_id'])&&(string)$_POST['employee_id']!==(string)$employee)throw new RuntimeException('You can only update your own salary estimate.');
 $month=(string)($_POST['month']??'');
 if(!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D',$month))throw new RuntimeException('Choose a valid salary month.');$date=valid_date($month.'-01');
 $basic=salary_cents(text_field('basic_salary',12));$allowance=salary_cents(text_field('allowances',12,false)?:'0');$deductions=salary_cents(text_field('deductions',12,false)?:'0');$travelling=salary_cents(text_field('travelling_allowance',12,false)?:'0');$bonus=salary_cents(text_field('attendance_bonus',12,false)?:'0');$night=salary_cents(text_field('night_allowance',12,false)?:'0');$meal=salary_cents(text_field('meal_allowance',12,false)?:'0');$ll=salary_cents(text_field('ll_day_payment',12,false)?:'0');$daysPay=salary_cents(text_field('days_payment',12,false)?:'0');$epfExtra=salary_cents(text_field('epf_other_earnings',12,false)?:'0');$stamp=salary_cents(text_field('stamp_duty',12,false)?:'0');$insurance=salary_cents(text_field('insurance_deduction',12,false)?:'0');$donation=salary_cents(text_field('donation_deduction',12,false)?:'0');$epfRate=(int)($_POST['epf_rate']??8);if(!in_array($epfRate,[0,8],true))throw new RuntimeException('Invalid EPF rate.');$note=text_field('salary_note',500,false);
 if($basic<=0)throw new RuntimeException('Basic salary must be greater than zero.');
 $p=db();$p->beginTransaction();try {
  if(!query('SELECT id FROM employees WHERE id=? FOR UPDATE',[$employee])->fetch())throw new RuntimeException('Employee not found.');
  [$sourceMonth,$sourceStart,$sourceEnd]=salary_attendance_period($month);
  if(query('SELECT 1 FROM salary_snapshots WHERE employee_id=? AND salary_month=? LIMIT 1',[$employee,$date])->fetchColumn())throw new RuntimeException('This salary month is finalized. Unlock the payroll period before editing salary inputs.');
  if(query('SELECT 1 FROM payroll_locks WHERE employee_id=? AND period_start<=? AND period_end>=? LIMIT 1',[$employee,$sourceEnd,$sourceStart])->fetch())throw new RuntimeException('The attendance period supplying this salary month is payroll-locked.');
  $decimal=fn(int $c)=>intdiv($c,100).'.'.str_pad((string)($c%100),2,'0',STR_PAD_LEFT);
  query('INSERT INTO salary_months(employee_id,salary_month,basic_salary,allowances,travelling_allowance,attendance_bonus,night_allowance,meal_allowance,epf_rate,ll_day_payment,days_payment,epf_other_earnings,stamp_duty,insurance_deduction,donation_deduction,deductions,note,updated_by,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE basic_salary=VALUES(basic_salary),allowances=VALUES(allowances),travelling_allowance=VALUES(travelling_allowance),attendance_bonus=VALUES(attendance_bonus),night_allowance=VALUES(night_allowance),meal_allowance=VALUES(meal_allowance),epf_rate=VALUES(epf_rate),ll_day_payment=VALUES(ll_day_payment),days_payment=VALUES(days_payment),epf_other_earnings=VALUES(epf_other_earnings),stamp_duty=VALUES(stamp_duty),insurance_deduction=VALUES(insurance_deduction),donation_deduction=VALUES(donation_deduction),deductions=VALUES(deductions),note=VALUES(note),updated_by=VALUES(updated_by),updated_at=VALUES(updated_at)',[$employee,$date,$decimal($basic),$decimal($allowance),$decimal($travelling),$decimal($bonus),$decimal($night),$decimal($meal),$epfRate,$decimal($ll),$decimal($daysPay),$decimal($epfExtra),$decimal($stamp),$decimal($insurance),$decimal($donation),$decimal($deductions),$note,$u['id'],utc()]);
  audit((int)$u['id'],'salary_settings_saved',['employee_id'=>$employee,'month'=>$month]);$p->commit();
 }catch(Throwable $x){if($p->inTransaction())$p->rollBack();throw $x;}
 return 'Your estimate inputs saved for '.$month.'.';
}

function save_salary_reconciliation(array $u): string {
 $employee=(int)$u['id'];$month=(string)($_POST['month']??'');
 if(!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D',$month))throw new RuntimeException('Invalid month.');
 $gross=salary_cents((string)($_POST['actual_gross']??''));$net=salary_cents((string)($_POST['actual_net']??''));
 $note=text_field('comparison_note',500,false);
 if($net>$gross)throw new RuntimeException('Actual net cannot be larger than gross.');
 $decimal=static fn(int $v):string=>intdiv($v,100).'.'.str_pad((string)($v%100),2,'0',STR_PAD_LEFT);
 if(!query('SELECT 1 FROM salary_months WHERE employee_id=? AND salary_month=?',[$employee,$month.'-01'])->fetchColumn())throw new RuntimeException('Save the salary settings for that month first.');
 query('INSERT INTO salary_reconciliations(employee_id,salary_month,actual_gross,actual_net,note,updated_at) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE actual_gross=VALUES(actual_gross),actual_net=VALUES(actual_net),note=VALUES(note),updated_at=VALUES(updated_at)',[$employee,$month.'-01',$decimal($gross),$decimal($net),$note,utc()]);
 audit($employee,'salary_payslip_comparison',['month'=>$month]);return 'Actual payslip amounts saved for comparison.';
}
