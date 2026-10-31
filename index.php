<?php
declare(strict_types=1);
require __DIR__.'/app/core.php';
require __DIR__.'/app/actions.php';
require __DIR__.'/app/features.php';
require __DIR__.'/app/salary.php';
require __DIR__.'/app/payroll_controls.php';
require __DIR__.'/app/my_attendance.php';
require __DIR__.'/app/monthly_options.php';
require __DIR__.'/app/monthly_export.php';
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; frame-ancestors 'none'; form-action 'self'; base-uri 'none'");
try {$u=authenticate();} catch(Throwable $x) {error_log((string)$x);fail('Attendance service is unavailable. Check MySQL and config.php. Check the configured database. Setup and upgrades run from localhost on the XAMPP server.',503);}
// Catch up if the server was asleep at midnight. Scheduler is the primary trigger.
try { auto_checkout_overdue(); } catch(Throwable $x) { error_log('Midnight auto-checkout: '.$x->getMessage()); }
$page=attendance_page($u);
$allowed=['my','monthly','calendar','corrections','salary','password'];if(staff($u))$allowed=[...$allowed,'dashboard','reports','review','alerts','approvals'];if($u['role']==='admin')$allowed=['dashboard','reports','approvals','review','alerts','calendar','employees','devices','payroll','audit','backup','password'];
// Keep secondary review routes available from Approval Center, without cluttering Admin navigation.
$navigation=$u['role']==='admin'?['dashboard','reports','approvals','calendar','employees','payroll','devices','audit','backup','password']:$allowed;
if(!in_array($page,$allowed,true))fail('Page access denied.',403);
if($page==='payroll' && ($_GET['export']??'')==='csv') {
 $pm=(string)($_GET['month']??date('Y-m'));if(!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D',$pm))fail('Invalid payroll month.',400);
 $records=query('SELECT e.employee_code,e.name,e.department,s.salary_month,s.gross_cents,s.deductions_cents,s.net_cents,r.actual_gross,r.actual_net FROM salary_snapshots s JOIN employees e ON e.id=s.employee_id LEFT JOIN salary_reconciliations r ON r.employee_id=s.employee_id AND r.salary_month=s.salary_month WHERE s.salary_month=? ORDER BY e.employee_code',[$pm.'-01'])->fetchAll();
 header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="payroll_'. $pm .'.csv"');$csv=fopen('php://output','w');fwrite($csv,"\xEF\xBB\xBF");fputcsv($csv,['Employee code','Employee','Department','Salary month','Final gross','Final deductions','Final net','Actual gross','Actual net']);
 foreach($records as $r){$v=[$r['employee_code'],$r['name'],$r['department'],$r['salary_month'],salary_money((int)$r['gross_cents']),salary_money((int)$r['deductions_cents']),salary_money((int)$r['net_cents']),$r['actual_gross'],$r['actual_net']];fputcsv($csv,array_map(static fn($v)=>preg_match('/^[=+@\-\t\r\n]/',(string)$v)?"'".$v:$v,$v));}fclose($csv);exit;
}
if($_SERVER['REQUEST_METHOD']==='GET') {
 if($page==='monthly'&&($legacyOptions=monthly_legacy_options($_GET))!==null){$query=http_build_query($legacyOptions);header('Location: '.app_url('monthly').($query!==''?'?'.$query:''),true,302);exit;}

 $path=(string)parse_url((string)($_SERVER['REQUEST_URI']??''),PHP_URL_PATH);
 if($path!==app_url($page) && !($path===app_url('index.php') && isset($_GET['page']))) {
  $params=$_GET;unset($params['page']);$query=http_build_query($params);
  header('Location: '.app_url($page).($query!==''?'?'.$query:''),true,302);exit;
 }
}
if($_SERVER['REQUEST_METHOD']==='POST') {
 try {
  check_csrf();$a=(string)($_POST['action']??'');
  if($a==='backup')download_backup($u);
  $message=match($a) {
   'in','out'=>($u['role']==='admin'?throw new RuntimeException('Admin accounts cannot record personal attendance.'):punch($u,$a,(string)($_POST['request_key']??''))),
   'request_correction'=>($u['role']==='admin'?throw new RuntimeException('Admin personal requests are disabled.'):request_correction($u)),
   'admin_correct_attendance'=>admin_correct_attendance($u),
   'review'=>review_correction($u),
   'save_employee'=>save_employee($u),
   'device'=>change_device($u),
   'password'=>change_password($u),
   'leave'=>($u['role']==='admin'?throw new RuntimeException('Admin personal leave requests are disabled.'):request_leave($u)),
   'leave_review'=>review_leave($u),
   'leave_cancel'=>($u['role']==='admin'?throw new RuntimeException('Admin personal leave requests are disabled.'):cancel_leave($u)),
   'holiday'=>save_holiday($u),
   'save_salary'=>($u['role']==='admin'?throw new RuntimeException('Admin personal salary is disabled.'):save_salary($u)),
   'save_salary_reconciliation'=>($u['role']==='admin'?throw new RuntimeException('Admin personal salary is disabled.'):save_salary_reconciliation($u)),
   'payroll_lock'=>payroll_lock_action($u),
   'leave_allocation'=>save_leave_allocation($u),
   default=>throw new RuntimeException('Unknown action.'),
  };
  $_SESSION['flash']=['success',$message];
 } catch(PDOException $x) {error_log((string)$x);$_SESSION['flash']=['error','Unable to save. Check for duplicate employee codes/accounts or contact IT.'];}
 catch(RuntimeException $x) {$_SESSION['flash']=['error',$x->getMessage()];}
 catch(Throwable $x) {error_log((string)$x);$_SESSION['flash']=['error','Unable to save. Check for a duplicate employee code/account or contact IT.'];}
 $destination=app_url($page);
 if(in_array(($a??''),['save_salary','save_salary_reconciliation'],true)&&preg_match('/^\d{4}-(0[1-9]|1[0-2])$/',(string)($_POST['month']??'')))$destination=app_url('salary').'?'.http_build_query(['month'=>$_POST['month']]);
 header('Location: '.$destination,true,303);exit;
}
$pendingCorrections=[];$summarySelected=false;
$from=today();$to=today();$rows=[];$days=[];$filterEmployee=0;$department='';
if(in_array($page,['monthly','reports'],true)) {
 try {
  if($page==='monthly'){[$recordMonth,$from,$to]=month_range();$hasSummaryDates=isset($_GET['summary_from'])||isset($_GET['summary_to']);$summarySelected=$hasSummaryDates;if($hasSummaryDates){$from=valid_date((string)($_GET['summary_from']??''));$to=valid_date((string)($_GET['summary_to']??''));}}
  else{$from=valid_date((string)($_GET['from']??(new DateTimeImmutable('now',tz()))->format('Y-m-01')));$to=valid_date((string)($_GET['to']??today()));}
  if($from>$to||strtotime($to)-strtotime($from)>366*86400)throw new RuntimeException('Choose a date range up to one year.');
 } catch(RuntimeException $x) {fail($x->getMessage(),400);}

 $where='a.work_date BETWEEN ? AND ?';$params=[$from,$to];
 if($page==='monthly') {$filterEmployee=staff($u)?max(1,(int)($_GET['employee']??$u['id'])):(int)$u['id'];$where.=' AND a.employee_id=?';$params[]=$filterEmployee;}
 else {
  $filterEmployee=max(0,(int)($_GET['employee']??0));$department=trim((string)($_GET['department']??''));
  if($filterEmployee){$where.=' AND a.employee_id=?';$params[]=$filterEmployee;}
  if($department!==''){$where.=' AND e.department=?';$params[]=$department;}
 }
 $rows=($page==='monthly'&&!$summarySelected)?[]:query('SELECT a.*,e.name,e.employee_code,e.department FROM attendance a JOIN employees e ON e.id=a.employee_id WHERE '.$where.' ORDER BY a.work_date DESC,e.employee_code,a.checked_in',$params)->fetchAll();
 $days=summary($rows);
 if($page==='monthly'){$monthlyCalendar=$summarySelected?calendar_data($filterEmployee,$from,$to):[];$days=array_values(array_filter(array_column(array_reverse($monthlyCalendar),'day'),fn($d)=>$d!==null));}
 if($page==='monthly'&&$summarySelected)foreach(query("SELECT id,attendance_id,work_date FROM corrections WHERE employee_id=? AND work_date BETWEEN ? AND ? AND status='pending' ORDER BY id",[$filterEmployee,$from,$to])->fetchAll() as $pending)$pendingCorrections[$pending['work_date']][]=$pending;
 if(($_GET['export']??'')==='csv') {
  if($page==='monthly'&&!$summarySelected)fail('Select a summary date range before exporting.',400);
  $exportFrom=$from;$exportTo=$to;
  header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="attendance_'.$exportFrom.'_'.$exportTo.'.csv"');
  $f=fopen('php://output','w');fwrite($f,"\xEF\xBB\xBF");
  if($page==='monthly'){
   $exportPerson=query('SELECT name,employee_code,department FROM employees WHERE id=?',[$filterEmployee])->fetch();
   if(!$exportPerson){fclose($f);fail('Employee not found.',404);}
   fputcsv($f,monthly_csv_header());
   foreach(monthly_csv_rows($monthlyCalendar,$exportPerson,$pendingCorrections) as $fields)fputcsv($f,$fields);
   fclose($f);exit;
  }
  fputcsv($f,['Employee ID','Name','Department','Work date','First check in','Last check out','Sessions','Completed net hours','Late minutes','Weekday OT hours',($page==='monthly'?'Weekend OT first 8h (HH:MM)':'Weekend Single OT hours'),($page==='monthly'?'Weekend OT after 8h (HH:MM)':'Weekend Double OT hours'),'Total OT hours','Status',...($page==='monthly'?['Pending correction requests']:[])]);
  foreach($days as $d) {
   $r=$d['row'];$fields=[$r['employee_code'],$r['name'],$r['department'],$r['work_date'],local_time($d['first']),local_time($d['last']),$d['sessions'],hours($d['net']),$d['late'],$d['weekday_ot']===null?'':hours($d['weekday_ot']),$d['single_ot']===null?'':hours($d['single_ot']),$d['double_ot']===null?'':hours($d['double_ot']),$d['ot']===null?'':hours($d['ot']),$d['open']?'Open':'Closed'];
   if($page==='monthly')$fields[]=implode(' / ',array_map(fn($x)=>'#'.$x['id'],$pendingCorrections[$r['work_date']]??[]));
   // Prevent spreadsheet formulas from employee-controlled text.
   $fields=array_map(fn($x)=>preg_match('/^[=+@\-\t\r\n]/',(string)$x)?"'".$x:$x,$fields);fputcsv($f,$fields);
  } fclose($f);exit;
 }
}
$open=query('SELECT * FROM attendance WHERE employee_id=? AND checked_out IS NULL ORDER BY id DESC LIMIT 1',[$u['id']])->fetch();
$requestDate=null;if($page==='corrections'&&isset($_GET['date'])){try{$requestDate=valid_date((string)$_GET['date']);}catch(RuntimeException $x){fail('Invalid correction date.',400);}}
$flash=$_SESSION['flash']??null;unset($_SESSION['flash']);
$names=['my'=>'My Attendance','corrections'=>'My Requests','dashboard'=>'Management Dashboard','reports'=>'Employee Attendance','approvals'=>'Approval Center','review'=>'Correction Approvals','employees'=>'Employees & Shifts','devices'=>'Office Computers','audit'=>'Activity Log','password'=>'Change Password','monthly'=>'Monthly Summary','calendar'=>'Leave & Calendar','alerts'=>'Checkout Alerts','backup'=>'Database Backup','salary'=>'My Salary','payroll'=>'Payroll Management'];
function ui_icon(string $name): string {
 $paths=[
 'salary'=>'M12 3v18m5-14H9a3 3 0 0 0 0 6h6a3 3 0 0 1 0 6H7',
 'my'=>'M8 3v4m8-4v4M4 10h16M6 5h12a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2m3 10 2 2 4-4',
 'monthly'=>'M4 20h16M7 16v-5m5 5V5m5 11V8',
 'calendar'=>'M8 3v4m8-4v4M4 10h16M6 5h12a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2m2 9h2m4 0h2m-8 3h2',
 'corrections'=>'M8 4H6a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2h-2M8 3h8v4H8zM8 12h8m-8 4h5',
 'dashboard'=>'M3 3h7v7H3zM14 3h7v7h-7zM3 14h7v7H3zM14 14h7v7h-7z',
 'reports'=>'M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9l-6-6v6h6M8 13h8m-8 4h6',
 'review'=>'m5 12 4 4L19 6M4 4h5m11 10v6H4v-6',
 'employees'=>'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2m20 0v-2a4 4 0 0 0-3-3.87M9 3a4 4 0 1 0 0 8 4 4 0 0 0 0-8m8 .2a4 4 0 0 1 0 7.6',
 'devices'=>'M3 4h18v12H3zm5 16h8m-4-4v4',
 'audit'=>'M3 12h4l3-8 4 16 3-8h4',
 'password'=>'M5 10h14v11H5zm3 0V7a4 4 0 0 1 8 0v3m-4 5v2',
 'alerts'=>'m12 3 10 18H2L12 3zm0 6v5m0 3h.01',
 'payroll'=>'M4 6h16v12H4z M8 10h8 M8 14h5',
 'backup'=>'M4 5c0-4 16-4 16 0s-16 4-16 0m0 0v7c0 4 16 4 16 0V5M4 12v7c0 4 16 4 16 0v-7',
 'logout'=>'M9 4H4v16h5m5-13 5 5-5 5m-7-5h12',
 'in'=>'M14 4h6v16h-6m-4-13 5 5-5 5m-7-5h12',
 'out'=>'M10 4H4v16h6m4-13 5 5-5 5m-7-5h12',
 'clock'=>'M12 8v4l3 2M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18',
 'shield'=>'m12 3 8 3v6c0 5-8 9-8 9s-8-4-8-9V6l8-3zm-4 9 3 3 5-5'
 ];
 return '<svg class="ui-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="'.($paths[$name]??$paths['my']).'"/></svg>';
}
function hidden(string $action): string {return csrf().'<input type="hidden" name="action" value="'.e($action).'">';}
function date_filter(string $page,string $from,string $to,array $u,int $employee,string $department): void { ?>
<form class="filters" method="get" action="<?=e(app_url($page))?>"><label>From<input type="date" name="from" value="<?=e($from)?>" required></label><label>To<input type="date" name="to" value="<?=e($to)?>" required></label>
<?php if($page==='reports'):?><label>Employee<select name="employee"><option value="0">All employees</option><?php foreach(query('SELECT id,name,employee_code FROM employees ORDER BY employee_code')->fetchAll() as $x):?><option value="<?=e($x['id'])?>" <?=$employee==$x['id']?'selected':''?>><?=e($x['employee_code'].' — '.$x['name'])?></option><?php endforeach;?></select></label><label>Department<select name="department"><option value="">All departments</option><?php foreach(query('SELECT DISTINCT department FROM employees ORDER BY department')->fetchAll() as $x):?><option value="<?=e($x['department'])?>" <?=$department===$x['department']?'selected':''?>><?=e($x['department']?:'Unassigned')?></option><?php endforeach;?></select></label><?php endif;?>
<button>Apply</button><button class="secondary" name="export" value="csv">Export CSV</button><button class="secondary print-trigger" type="button">Print / PDF</button></form>
<?php }
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=e($names[$page])?> · <?=e(config()['company'])?></title><link rel="stylesheet" href="<?=e(app_url('assets/app.css'))?>?v=<?=e((string)filemtime(__DIR__.'/assets/app.css'))?>"><link rel="stylesheet" href="<?=e(app_url('assets/workspace.css'))?>?v=<?=e((string)filemtime(__DIR__.'/assets/workspace.css'))?>"><script src="<?=e(app_url('assets/app.js'))?>?v=<?=e((string)filemtime(__DIR__.'/assets/app.js'))?>" defer></script></head>
<body class="app-page" data-server-time="<?=e(gmdate('c'))?>" data-timezone="<?=e(config()['timezone'])?>" data-live="<?=$page==='dashboard'?'true':'false'?>">
<header class="app-header"><a class="brand" href="<?=e(app_url())?>"><span class="arpico-logo-window"><img src="<?=e(app_url('assets/arpico-logo.jpg'))?>" alt="ARPICO" width="1200" height="630"></span><span><?=e(config()['company'])?><small>YOUR WORKDAY, CONNECTED</small></span></a><div class="header-account"><span class="account-avatar"><?=e(strtoupper(substr($u['name'],0,1)))?></span><div><strong><?=e($u['name'])?></strong><small><?=e($u['username'])?> · <?=e(strtoupper($u['role']))?></small></div></div></header>
<div class="layout"><aside><div class="nav-label"><?= $u['role']==='admin'?'MANAGEMENT':'YOUR WORKSPACE' ?></div><nav aria-label="Main navigation"><?php foreach($navigation as $link):?><?php if($u['role']==='admin' && isset(['dashboard'=>'MONITOR','reports'=>'ATTENDANCE','employees'=>'WORKFORCE','devices'=>'SYSTEM','password'=>'ACCOUNT'][$link])): ?><span class="nav-group-label"><?=e(['dashboard'=>'MONITOR','reports'=>'ATTENDANCE','employees'=>'WORKFORCE','devices'=>'SYSTEM','password'=>'ACCOUNT'][$link])?></span><?php endif;?><a href="<?=e(app_url($link))?>" class="<?=$link===$page?'active':''?>" <?=$link===$page?'aria-current="page"':''?>><?=ui_icon($link)?><span><?=e($names[$link])?></span></a><?php endforeach;?><a class="logout-link" href="<?=e(app_url('logout.php'))?>" data-logout-confirm="true"><?=ui_icon('logout')?><span>Log out</span></a></nav><div class="office-status <?=!$u['device_id']?'approval-needed':''?>"><?=ui_icon('shield')?><div><strong><?=$u['device_id']?'Office computer approved':'Computer approval needed'?></strong><small><?=e(config()['timezone'])?></small></div></div></aside>
<main><div class="page-heading"><div><p class="eyebrow"><?= $u['role']==='admin'?'OFFICE MANAGEMENT':'YOUR OFFICE WORKSPACE' ?></p><h1><?=e($names[$page])?></h1><p class="page-description"><?php $descriptions=['my'=>'A clear view of your time, every working day.','dashboard'=>'Your team’s workday, at a glance.','monthly'=>'Your attendance, leave and hours in one place.','calendar'=>'Plan your time away and view your working days.','employees'=>'Manage your people, shifts and weekly schedules.','password'=>'Keep your account access up to date.','reports'=>'Explore attendance records and export your reports.','devices'=>'Manage the computers approved for attendance.','corrections'=>'Request corrections and follow their progress.','approvals'=>'Leave, attendance and checkout reviews in one place.','review'=>'Review attendance requests from your team.','alerts'=>'Follow up on work sessions that need attention.','salary'=>'Your monthly salary and OT earnings, clearly explained.','backup'=>'Keep a safe copy of your attendance records.','audit'=>'See the changes made across your workspace.','payroll'=>'Finalized salary snapshots and employee payroll export.'];echo e($descriptions[$page]??'Manage your office attendance.');?></p></div><div class="clock"><?=ui_icon('clock')?><div><strong id="clock"><?=e(local_time(utc(),'H:i:s'))?></strong><span><?=e(local_time(utc(),'l, d M Y'))?></span></div></div></div>

<?php if($flash):?><div role="status" class="notice <?=e($flash[0])?>"><?=e($flash[1])?></div><?php endif;?>
<?php if($page==='my'):
 $liveDate=$open?$open['work_date']:today();$liveRows=query('SELECT * FROM attendance WHERE employee_id=? AND work_date=? ORDER BY checked_in',[$u['id'],$liveDate])->fetchAll();
 $live=personal_live_data($liveRows,$liveDate,time());$workSeconds=$live['completed']+($live['active']===null?0:max(0,$live['now']-$live['active']));$todayDays=summary($liveRows);$todayDay=$todayDays[0]??null;
?>
<section class="punch-card"><div class="punch-copy"><span class="badge <?=$open?'green':'neutral'?>"><?=$open?'Currently checked in':($todayDay?'Checked out':'Not checked in today')?></span><h2><?=$open?'Your work session is active':($todayDay?'Ready for your next session':'Start your workday')?></h2><p><?=$open?'Checked in: '.e(local_time($open['checked_in'])):'You can check in and out for multiple sessions today.'?></p></div><div class="punch-buttons"><form method="post"><?=hidden('in')?><input type="hidden" name="request_key" value="<?=e(bin2hex(random_bytes(16)))?>"><button class="check-in" <?=$open?'disabled':''?> ><?=ui_icon('in')?> <span>Check In</span></button></form><form method="post" data-confirm="Finish your current work session?" data-confirm-kind="checkout"><?=hidden('out')?><input type="hidden" name="request_key" value="<?=e(bin2hex(random_bytes(16)))?>"><button class="check-out" <?=!$open?'disabled':''?> ><?=ui_icon('out')?> <span>Check Out</span></button></form></div></section>
<section class="panel"><div class="section-heading"><h2><?=$liveDate===today()?'Today’s time':'Active workday · '.e($liveDate)?></h2><span class="badge neutral">Live estimate</span></div>
<div class="stats compact" id="personal-live" data-live="<?=e(json_encode($live))?>"><div><span>First check in</span><strong><?=$todayDay?e(local_time($todayDay['first'],'H:i')):'—'?></strong></div><div><span>Last check out</span><strong><?=$todayDay&&$todayDay['last']?e(local_time($todayDay['last'],'H:i')):'—'?></strong></div><div><span>Worked time · before breaks</span><strong data-time="worked"><?=e(hours($workSeconds))?></strong></div></div><p class="muted">Completed sessions and your active session are included; gaps are excluded. <a href="<?=e(app_url('monthly'))?>">View monthly summary and OT details</a></p></section>
<?php if($open&&checkout_overdue($open)):?><div class="notice error"><strong>Check your open session · Record #<?=e($open['id'])?></strong><p>You may have missed checkout. Review the times before checking out.</p><a class="button secondary" href="<?=e(app_url('corrections'))?>?record=<?=e($open['id'])?>">Request a correction</a></div><?php endif;?>
<?php endif;?>
<?php if($page==='dashboard'):
$present=query('SELECT a.*,e.name,e.employee_code,e.department FROM attendance a JOIN employees e ON e.id=a.employee_id WHERE a.checked_out IS NULL ORDER BY a.checked_in')->fetchAll();
$todayRows=query('SELECT a.*,e.name,e.employee_code,e.department FROM attendance a JOIN employees e ON e.id=a.employee_id WHERE a.work_date=?',[today()])->fetchAll();$td=summary($todayRows);
$active=(int)query('SELECT COUNT(*) FROM employees WHERE active=1')->fetchColumn();$pending=(int)query("SELECT COUNT(*) FROM corrections WHERE status='pending'")->fetchColumn();
$pendingLeave=(int)query("SELECT COUNT(*) FROM leave_requests WHERE status='pending'")->fetchColumn();
$autoReview=(int)query('SELECT COUNT(*) FROM attendance WHERE auto_closed=1')->fetchColumn();
$checkedToday=count(array_unique(array_column($todayRows,'employee_id')));
$leaveToday=(int)query("SELECT COUNT(DISTINCT employee_id) FROM leave_requests WHERE status='approved' AND start_date<=? AND end_date>=?",[today(),today()])->fetchColumn();
$notRecorded=max(0,$active-$checkedToday-$leaveToday);
$oldSessions=(int)query('SELECT COUNT(*) FROM attendance WHERE checked_out IS NOT NULL AND TIMESTAMPDIFF(HOUR,checked_in,checked_out)>24')->fetchColumn();
$checkoutWarnings=$autoReview+$oldSessions;
$taskStatusFile='C:/xampp/attendance-backups/midnight_checkout_status.json';$taskWarning=!is_file($taskStatusFile)||(time()-(filemtime($taskStatusFile)?:0))>129600;

?>
<div class="stats"><div><span>Active employees</span><strong><?=$active?></strong></div><div><span>Checked in now</span><strong><?=count($present)?></strong></div><div><span>Late today</span><strong><?=count(array_filter($td,fn($d)=>$d['late']>0))?></strong></div><div><span>Pending corrections</span><strong><?=$pending?></strong></div></div>
<?php if($checkoutWarnings||$taskWarning):?><section class="panel"><h2>System attention needed</h2><p class="notice error"><?=e((string)$checkoutWarnings)?> attendance session(s) need review. Midnight scheduler <?= $taskWarning ? "has no recent status report" : "has a recent status report" ?>. <a href="<?=e(app_url('alerts'))?>">Review sessions</a> · <a href="<?=e(app_url('backup'))?>">System diagnostics</a></p></section><?php endif;?>
<section class="panel"><h2>Daily review summary</h2><div class="admin-metrics"><div><span>Recorded today</span><strong><?= $checkedToday ?></strong></div><div><span>Approved leave today</span><strong><?= $leaveToday ?></strong></div><div><span>Not recorded / review</span><strong><?= $notRecorded ?></strong></div><div><span>Pending leave</span><strong><?= $pendingLeave ?></strong></div><div><span>Auto checkouts to review</span><strong><?= $autoReview ?></strong></div></div><p class="muted">Not recorded is a preliminary indicator, not a confirmed absence. It may include weekly off-days and holidays. Auto-checkout count includes historical records until cleared through the existing correction workflow.</p><p><a href="<?=e(app_url('review'))?>">Correction approvals</a> · <a href="<?=e(app_url('alerts'))?>">Checkout alerts</a> · <a href="<?=e(app_url('calendar'))?>">Leave approvals</a></p></section>
<section class="panel"><div class="section-heading"><h2>Currently at work</h2><span class="badge green">Refreshes every 60 seconds</span></div><p class="muted">Includes overnight sessions. “Not checked in” is not an absence decision; Check the leave calendar before reviewing missing attendance.</p><div class="table-wrap"><table><thead><tr><th>Employee</th><th>Department</th><th>Work date</th><th>Check in</th><th>Session</th></tr></thead><tbody><?php foreach($present as $r):?><tr><td><strong><?=e($r['name'])?></strong><small><?=e($r['employee_code'])?></small></td><td><?=e($r['department'])?></td><td><?=e($r['work_date'])?></td><td><?=e(local_time($r['checked_in']))?></td><td><span class="badge <?=checkout_overdue($r)?'amber':'green'?>"><?=checkout_overdue($r)?'Review: missing checkout':'Active'?></span></td></tr><?php endforeach;?><?php if(!$present):?><tr><td colspan="5" class="empty">No open work sessions.</td></tr><?php endif;?></tbody></table></div></section>
<?php endif;?>
<?php if($page==='corrections'):?>
<section class="panel"><h2>Request an attendance correction</h2><?php if($requestDate!==null):?><p class="notice">Missing entry for <?=e($requestDate)?>. Enter the actual check-in and checkout times for this workday.</p><?php endif;?><p class="muted">For an existing session enter its record number. Leave it blank for a missing session. HR must approve changes; all times are Sri Lanka local time.</p><form method="post" class="form-grid"><?=hidden('request_correction')?><label>Existing record # (optional)<input type="number" name="attendance_id" min="1" value="<?=e(max(0,(int)($_GET['record']??0))?:'')?>"></label><label>Correct check-in<input type="datetime-local" name="proposed_in" required></label><label>Correct check-out<input type="datetime-local" name="proposed_out" required></label><label class="wide">Reason<textarea name="reason" maxlength="500" required></textarea></label><div><button>Send request</button></div></form></section>
<section class="panel"><h2>My requests</h2><div class="table-wrap"><table><thead><tr><th>Request</th><th>Record</th><th>Proposed times</th><th>Reason</th><th>Status</th><th>HR note</th></tr></thead><tbody><?php foreach(query('SELECT * FROM corrections WHERE employee_id=? ORDER BY id DESC LIMIT 200',[$u['id']])->fetchAll() as $r):?><tr><td>#<?=e($r['id'])?></td><td><?=e($r['attendance_id']?:'Missing')?></td><td><?=e(local_time($r['proposed_in']))?><small>to <?=e(local_time($r['proposed_out']))?></small></td><td><?=e($r['reason'])?></td><td><span class="badge neutral"><?=e(ucfirst($r['status']))?></span></td><td><?=e($r['review_note'])?></td></tr><?php endforeach;?></tbody></table></div></section>
<?php endif;?>
<?php if($page==='review'):?><section class="panel"><h2>Pending requests</h2><p class="muted">Review the original record and employee explanation. You cannot approve your own requests.</p>
<?php $pendingRows=query("SELECT c.*,e.name,e.employee_code FROM corrections c JOIN employees e ON e.id=c.employee_id WHERE c.status='pending' ORDER BY c.id")->fetchAll();foreach($pendingRows as $r):?><article class="request"><div><h3><?=e($r['name'])?> <small><?=e($r['employee_code'])?> · Request #<?=e($r['id'])?></small></h3><p><strong>Original:</strong> <?=$r['attendance_id']?'Record #'.e($r['attendance_id']).' · '.e(local_time($r['base_in'])).' → '.e(local_time($r['base_out'])):'Missing session'?></p><p><strong>Requested:</strong> <?=e(local_time($r['proposed_in']))?> → <?=e(local_time($r['proposed_out']))?></p><p><strong>Reason:</strong> <?=e($r['reason'])?></p></div><form method="post"><?=hidden('review')?><input type="hidden" name="id" value="<?=e($r['id'])?>"><label>Review note<textarea name="review_note" maxlength="500" required></textarea></label><div class="row"><button name="decision" value="approved" <?=$r['employee_id']==$u['id']?'disabled':''?>>Approve</button><button name="decision" value="rejected" class="danger" <?=$r['employee_id']==$u['id']?'disabled':''?>>Reject</button></div></form></article><?php endforeach;if(!$pendingRows):?><p class="empty">No requests waiting for approval.</p><?php endif;?></section><?php endif;?>
<?php if($page==='employees'):
$edit=(int)($_GET['edit']??0);$x=$edit?query('SELECT * FROM employees WHERE id=?',[$edit])->fetch():null;
$x=$x?:['id'=>0,'employee_code'=>'','name'=>'','department'=>'','role'=>'employee','active'=>1,'shift_start'=>'08:00:00','shift_end'=>'17:30:00','grace_minutes'=>0,'break_minutes'=>60];
?><section class="panel"><h2><?=$x['id']?'Edit employee':'Add employee'?></h2><form method="post" class="form-grid"><?=hidden('save_employee')?><input type="hidden" name="id" value="<?=e($x['id'])?>"><?php foreach(['employee_code'=>'Employee ID','name'=>'Full name','department'=>'Department'] as $key=>$label):?><label><?=e($label)?><input name="<?=e($key)?>" value="<?=e($x[$key])?>" <?=$key==='department'?'':'required'?> maxlength="<?=$key==='employee_code'?40:($key==='name'?120:100)?>"></label><?php endforeach;?>
<?php $account=$x['id']?query('SELECT username FROM accounts WHERE employee_id=?',[$x['id']])->fetch():null;?><label>Username<input name="local_username" value="<?=e($account['username']??'')?>" maxlength="40" <?=$account?'readonly':'required'?> autocomplete="off"></label><label><?=$account?'New password (optional)':'Password'?><input type="password" name="local_password" minlength="10" maxlength="200" <?=$account?'':'required'?> autocomplete="new-password"></label>
<?php $selectedWeek=$x['id']?employee_week((int)$x['id'],today()):(config()['work_week']??[1,2,3,4,5]);?><fieldset class="wide"><legend>Working weekdays</legend><input type="hidden" name="schedule_present" value="1"><div class="weekday-options"><?php foreach([1=>'Monday',2=>'Tuesday',3=>'Wednesday',4=>'Thursday',5=>'Friday',6=>'Saturday',7=>'Sunday'] as $n=>$label):?><label class="checkbox"><input type="checkbox" name="weekdays[]" value="<?=$n?>" <?=in_array($n,$selectedWeek,true)?'checked':''?>><?=e($label)?></label><?php endforeach;?></div><label>Schedule effective from<input type="date" name="schedule_from" value="<?=e(today())?>" required></label><p class="muted">Earlier dates keep the earlier schedule. Shift times still apply to all working days.</p></fieldset><label>Role<select name="role"><?php foreach(['employee','hr','admin'] as $r):?><option <?=$x['role']===$r?'selected':''?> value="<?=e($r)?>"><?=e(ucfirst($r))?></option><?php endforeach;?></select></label><label>Shift start<input type="time" name="shift_start" value="<?=e(substr($x['shift_start'],0,5))?>" required></label><label>Shift end<input type="time" name="shift_end" value="<?=e(substr($x['shift_end'],0,5))?>" required></label><label>Grace (minutes)<input type="number" name="grace_minutes" min="0" max="120" value="<?=e($x['grace_minutes'])?>" required></label><label>Scheduled break (minutes)<input type="number" name="break_minutes" min="0" max="240" value="<?=e($x['break_minutes'])?>" required></label><label class="checkbox"><input type="checkbox" name="active" <?=$x['active']?'checked':''?>> Active employee</label><div><button>Save employee</button> <a href="<?=e(app_url('employees'))?>">New employee</a></div></form><p class="muted">Attendance policy: Mon–Fri 08:00–17:30; Saturday/Sunday are flexible. OT and late calculations follow this company policy. Break minutes apply to weekday net hours. Salary and leave balances are not calculated.</p></section>
<?php if($x['id']):?><section class="panel"><h2>Weekly schedule history</h2><?php foreach(query('SELECT * FROM weekly_schedules WHERE employee_id=? ORDER BY effective_date DESC',[$x['id']])->fetchAll() as $s):?><p><?=e($s['effective_date'])?> onwards · <?=e($s['weekdays'])?> (Mon=1 … Sun=7)</p><?php endforeach;?></section><?php endif;?>
<?php
$employeeSearch=trim((string)($_GET['q']??''));$employeeDept=trim((string)($_GET['dept']??''));$employeeRole=(string)($_GET['role_filter']??'');$employeePage=max(1,min(100000,(int)($_GET['p']??1)));$ep=[];$ew=['1=1'];
if($employeeSearch!==''){$ew[]='(e.name LIKE ? OR e.employee_code LIKE ? OR a.username LIKE ?)';$ep=array_merge($ep,array_fill(0,3,'%'.$employeeSearch.'%'));}
if($employeeDept!==''){$ew[]='e.department=?';$ep[]=$employeeDept;}
if(in_array($employeeRole,['admin','hr','employee'],true)){$ew[]='e.role=?';$ep[]=$employeeRole;}
$employeeWhere=implode(' AND ',$ew);$employeeCount=(int)query('SELECT COUNT(*) FROM employees e LEFT JOIN accounts a ON a.employee_id=e.id WHERE '.$employeeWhere,$ep)->fetchColumn();
$employeeMax=max(1,(int)ceil($employeeCount/20));$employeePage=min($employeePage,$employeeMax);$employeeRows=query('SELECT e.*,a.username FROM employees e LEFT JOIN accounts a ON a.employee_id=e.id WHERE '.$employeeWhere.' ORDER BY e.employee_code LIMIT 20 OFFSET '.(($employeePage-1)*20),$ep)->fetchAll();
?>
<section class="panel"><h2>Employee directory</h2><form method="get" class="filters admin-filter"><label>Search<input name="q" value="<?=e($employeeSearch)?>" placeholder="Name, code or username"></label><label>Department<select name="dept"><option value="">All departments</option><?php foreach(query('SELECT DISTINCT department FROM employees ORDER BY department')->fetchAll() as $dep):?><option value="<?=e($dep['department'])?>" <?=$employeeDept===$dep['department']?'selected':''?>><?=e($dep['department'])?></option><?php endforeach;?></select></label><label>Role<select name="role_filter"><option value="">All roles</option><?php foreach(['employee','hr','admin'] as $er):?><option value="<?=$er?>" <?=$employeeRole===$er?'selected':''?>><?=ucfirst($er)?></option><?php endforeach;?></select></label><button>Filter</button><a href="<?=e(app_url('employees'))?>">Clear</a></form><p class="muted"><?= $employeeCount ?> matching employees · page <?= $employeePage ?> of <?= $employeeMax ?></p><div class="table-wrap"><table><thead><tr><th>ID</th><th>Name / username</th><th>Department</th><th>Role</th><th>Shift</th><th>Status</th><th>Action</th></tr></thead><tbody><?php foreach($employeeRows as $r):?><tr><td><?=e($r['employee_code'])?></td><td><strong><?=e($r['name'])?></strong><small><?=e($r['username'])?></small></td><td><?=e($r['department'])?></td><td><?=e($r['role'])?></td><td><?=e(substr($r['shift_start'],0,5).'–'.substr($r['shift_end'],0,5))?></td><td><?=e($r['active']?'Active':'Inactive')?></td><td><a href="<?=e(app_url('employees'))?>?edit=<?=e($r['id'])?>">Edit</a></td></tr><?php endforeach;?></tbody></table></div><div class="admin-pager"><?php foreach([max(1,$employeePage-1),min($employeeMax,$employeePage+1)] as $pg):?><a href="<?=e(app_url('employees').'?'.http_build_query(['q'=>$employeeSearch,'dept'=>$employeeDept,'role_filter'=>$employeeRole,'p'=>$pg]))?>"><?= $pg<$employeePage?'Previous':'Next' ?></a><?php endforeach;?></div></section><?php endif;?>
<?php if($page==='devices'):
$computers=query('SELECT d.*, e.name AS employee_name, e.employee_code FROM devices d LEFT JOIN employees e ON e.id=d.employee_id ORDER BY d.id DESC')->fetchAll();
$people=query('SELECT id,name,employee_code FROM employees WHERE active=1 ORDER BY name')->fetchAll();
$approvedCount=count(array_filter($computers,static fn($r)=>$r['status']==='approved'));
$pendingCount=count(array_filter($computers,static fn($r)=>$r['status']==='pending'));
?>
<section class="panel pc-workspace">
 <div class="pc-heading"><div><span class="pc-eyebrow">DEVICE ACCESS MANAGEMENT</span><h2>Office Computers</h2><p>Approve and manage office computers used for attendance.</p></div><span class="pc-ip">Current IP <strong><?=e(client_ip($_SERVER))?></strong></span></div>
 <div class="pc-metrics"><div><span>Total computers</span><strong><?=count($computers)?></strong></div><div><span>Approved</span><strong><?=e((string)$approvedCount)?></strong></div><div><span>Pending</span><strong><?=e((string)$pendingCount)?></strong></div></div>
 <div class="pc-add"><div class="pc-block-heading"><div><h3>Add office computer</h3><p>Register a device with its reserved office IPv4 address.</p></div><span class="badge green">New device</span></div>
 <form method="post" class="pc-form"><?=hidden('device')?><input type="hidden" name="id" value="0">
 <label>Employee<select name="employee_id" required><?php foreach($people as $person):?><option value="<?=e($person['id'])?>"><?=e($person['name'].' · '.$person['employee_code'])?></option><?php endforeach;?></select></label>
 <label>PC name<input name="label" maxlength="120" placeholder="e.g. Front Office PC" required></label>
 <label>PC IPv4 address<input name="ip_address" maxlength="45" placeholder="192.168.1.25" required></label>
 <div class="pc-form-action"><button name="status" value="approved">Approve computer</button></div></form>
 <p class="pc-hint">Use a fixed IP address or DHCP reservation. Only approve trusted office computers.</p></div>
 <div class="pc-block-heading pc-list-heading"><div><h3>Registered computers</h3><p>Review device ownership, IP address and access status.</p></div><span class="badge neutral"><?=count($computers)?> devices</span></div>
 <?php if(!$computers):?><div class="pc-empty">No computers registered yet. Add your first device above.</div><?php else:?>
 <div class="pc-list"><?php foreach($computers as $r):?>
 <article class="pc-item"><div class="pc-item-heading"><div><h4><?=e($r['label']?:('Computer #'.$r['id']))?></h4><p><?=e($r['employee_name']??'Unknown employee')?> · <?=e($r['employee_code']??'')?></p></div><span class="badge <?= $r['status']==='approved'?'green':($r['status']==='pending'?'amber':'neutral') ?>"><?=e(ucfirst($r['status']))?></span></div>
 <form method="post" class="pc-form pc-edit-form"><?=hidden('device')?><input type="hidden" name="id" value="<?=e($r['id'])?>">
 <label>Employee<select name="employee_id" required><?php foreach($people as $person):?><option value="<?=e($person['id'])?>" <?=$r['employee_id']==$person['id']?'selected':''?>><?=e($person['name'].' · '.$person['employee_code'])?></option><?php endforeach;?></select></label>
 <label>PC name<input name="label" value="<?=e($r['label'])?>" maxlength="120" required></label>
 <label>PC IPv4 address<input name="ip_address" value="<?=e($r['ip_address'])?>" maxlength="45" required></label>
 <div class="pc-form-action"><button name="status" value="approved" class="pc-approve">Approve / Save</button><button class="danger" name="status" value="revoked" data-confirm="Revoke this computer?">Revoke</button></div></form></article>
 <?php endforeach;?></div><?php endif;?>
</section><?php endif;?>
<?php if($page==='audit'):
$auditActor=max(0,(int)($_GET['actor']??0));$auditAction=trim((string)($_GET['event']??''));$auditStart=trim((string)($_GET['start']??''));$auditEnd=trim((string)($_GET['end']??''));$auditPage=max(1,min(100000,(int)($_GET['p']??1)));$auditW=['1=1'];$auditP=[];
if($auditActor){$auditW[]='l.actor_id=?';$auditP[]=$auditActor;}if($auditAction!==''){$auditW[]='l.action=?';$auditP[]=$auditAction;}
foreach([[$auditStart,'>='],[$auditEnd,'<=']] as [$dt,$operator])if($dt!==''){try{$dt=valid_date($dt);$auditW[]='DATE(l.created_at) '.$operator.' ?';$auditP[]=$dt;}catch(RuntimeException $ex){fail('Invalid audit date.',400);}}
$auditWhere=implode(' AND ',$auditW);$auditCount=(int)query('SELECT COUNT(*) FROM audit_log l WHERE '.$auditWhere,$auditP)->fetchColumn();$auditMax=max(1,(int)ceil($auditCount/50));$auditPage=min($auditPage,$auditMax);
$auditRows=query('SELECT l.*,e.name FROM audit_log l JOIN employees e ON e.id=l.actor_id WHERE '.$auditWhere.' ORDER BY l.id DESC LIMIT 50 OFFSET '.(($auditPage-1)*50),$auditP)->fetchAll();
?><section class="panel"><h2>Activity history</h2><form method="get" class="filters admin-filter"><label>Actor<select name="actor"><option value="0">All employees</option><?php foreach(query('SELECT id,name FROM employees ORDER BY name')->fetchAll() as $person):?><option value="<?=e($person['id'])?>" <?=$auditActor==(int)$person['id']?'selected':''?>><?=e($person['name'])?></option><?php endforeach;?></select></label><label>Action<select name="event"><option value="">All actions</option><?php foreach(query('SELECT DISTINCT action FROM audit_log ORDER BY action')->fetchAll() as $event):?><option value="<?=e($event['action'])?>" <?=$auditAction===$event['action']?'selected':''?>><?=e($event['action'])?></option><?php endforeach;?></select></label><label>From<input type="date" name="start" value="<?=e($auditStart)?>"></label><label>To<input type="date" name="end" value="<?=e($auditEnd)?>"></label><button>Filter</button><a href="<?=e(app_url('audit'))?>">Clear</a></form><p class="muted"><?= $auditCount ?> activities · page <?= $auditPage ?> of <?= $auditMax ?></p><div class="table-wrap"><table><thead><tr><th>Time</th><th>Actor</th><th>Action</th><th>Details</th></tr></thead><tbody><?php foreach($auditRows as $r):?><tr><td><?=e(local_time($r['created_at']))?></td><td><?=e($r['name'])?></td><td><?=e($r['action'])?></td><td class="audit-detail"><?=e($r['detail'])?></td></tr><?php endforeach;?></tbody></table></div><div class="admin-pager"><?php foreach([max(1,$auditPage-1),min($auditMax,$auditPage+1)] as $pg):?><a href="<?=e(app_url('audit').'?'.http_build_query(['actor'=>$auditActor,'event'=>$auditAction,'start'=>$auditStart,'end'=>$auditEnd,'p'=>$pg]))?>"><?= $pg<$auditPage?'Previous':'Next' ?></a><?php endforeach;?></div></section><?php endif;?>
<?php if($page==='payroll'):
$payMonth=(string)($_GET['month']??date('Y-m'));if(!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D',$payMonth))$payMonth=date('Y-m');
$payRows=query('SELECT e.employee_code,e.name,e.department,s.salary_month,s.gross_cents,s.deductions_cents,s.net_cents,s.finalized_at,r.actual_gross,r.actual_net FROM salary_snapshots s JOIN employees e ON e.id=s.employee_id LEFT JOIN salary_reconciliations r ON r.employee_id=s.employee_id AND r.salary_month=s.salary_month WHERE s.salary_month=? ORDER BY e.employee_code',[$payMonth.'-01'])->fetchAll();
$draftCount=(int)query('SELECT COUNT(*) FROM salary_months m LEFT JOIN salary_snapshots s ON s.employee_id=m.employee_id AND s.salary_month=m.salary_month WHERE m.salary_month=? AND s.employee_id IS NULL',[$payMonth.'-01'])->fetchColumn();
?><section class="panel"><h2>Employee payroll overview</h2><p class="muted">Read-only report of finalized salary snapshots. Employees without finalized snapshots are counted as drafts, not included in export. Review and lock the previous calendar month using the existing payroll controls.</p><form method="get" class="filters"><label>Salary month<input type="month" name="month" value="<?=e($payMonth)?>" required></label><button>Apply</button><a class="button secondary" href="<?=e(app_url('payroll').'?'.http_build_query(['month'=>$payMonth,'export'=>'csv']))?>">Download CSV</a></form><p><span class="badge green"><?=count($payRows)?> finalized</span> <span class="badge amber"><?=$draftCount?> drafts</span></p><p><a href="<?=e(app_url('approvals'))?>">Check pending approvals</a> · <a href="<?=e(app_url('payroll'))?>#payroll-locks">Payroll locks</a></p>
<section class="admin-draft-panel"><h3>Salary drafts awaiting finalization</h3><p class="muted">To finalize, resolve attendance reviews, save salary settings, then lock the full previous calendar month per employee. Drafts are not paid or exported as final salary.</p>
<?php $draftRows=query('SELECT e.employee_code,e.name,e.id,m.salary_month FROM salary_months m JOIN employees e ON e.id=m.employee_id LEFT JOIN salary_snapshots s ON s.employee_id=m.employee_id AND s.salary_month=m.salary_month WHERE m.salary_month=? AND s.employee_id IS NULL ORDER BY e.employee_code',[$payMonth.'-01'])->fetchAll(); ?>
<div class="table-wrap"><table><thead><tr><th>Employee</th><th>Salary month</th><th>Readiness</th><th>Action</th></tr></thead><tbody><?php foreach($draftRows as $draft):?><tr><td><?=e($draft['employee_code'].' · '.$draft['name'])?></td><td><?=e($draft['salary_month'])?></td><?php
$periodFrom=date('Y-m-01',strtotime($draft['salary_month'].' -1 month'));
$periodTo=date('Y-m-t',strtotime($periodFrom));
$openCount=(int)query('SELECT COUNT(*) FROM attendance WHERE employee_id=? AND work_date BETWEEN ? AND ? AND (checked_out IS NULL OR auto_closed=1)',[$draft['id'],$periodFrom,$periodTo])->fetchColumn();
$pendingCount=(int)query("SELECT COUNT(*) FROM corrections WHERE employee_id=? AND work_date BETWEEN ? AND ? AND status='pending'",[$draft['id'],$periodFrom,$periodTo])->fetchColumn();
$lockCount=(int)query('SELECT COUNT(*) FROM payroll_locks WHERE employee_id=? AND period_start=? AND period_end=?',[$draft['id'],$periodFrom,$periodTo])->fetchColumn();
$issues=[];if($openCount)$issues[]=$openCount.' open / auto-closed';if($pendingCount)$issues[]=$pendingCount.' corrections pending';if($lockCount)$issues[]='Already locked without snapshot';
?><td><?=e($issues?implode(' · ',$issues):'Ready for admin review (not finalized)')?></td><td><a href="<?=e(app_url('approvals'))?>">Review blockers</a> · <a href="<?=e(app_url('payroll'))?>#payroll-locks">Payroll lock</a></td></tr><?php endforeach;if(!$draftRows):?><tr><td colspan="4">No saved salary drafts awaiting finalization.</td></tr><?php endif;?></tbody></table></div></section>
<div class="table-wrap"><table><thead><tr><th>Employee</th><th>Department</th><th>Gross</th><th>Deductions</th><th>Net</th><th>Actual net</th><th>Finalized</th></tr></thead><tbody><?php foreach($payRows as $r):?><tr><td><?=e($r['employee_code'])?><small><?=e($r['name'])?></small></td><td><?=e($r['department'])?></td><td class="money-column"><?=e(salary_money((int)$r['gross_cents']))?></td><td class="money-column"><?=e(salary_money((int)$r['deductions_cents']))?></td><td class="money-column"><strong><?=e(salary_money((int)$r['net_cents']))?></strong></td><td class="money-column"><?= $r['actual_net']===null?'—':e(number_format((float)$r['actual_net'],2)) ?></td><td><?=e($r['finalized_at'])?></td></tr><?php endforeach;if(!$payRows):?><tr><td colspan="7" class="empty">No finalized payroll records for this month.</td></tr><?php endif;?></tbody></table></div></section><?php endif;?>
<?php if($page==='approvals' && staff($u)):
$corrections=query("SELECT c.id,c.work_date,c.reason,e.name,e.employee_code FROM corrections c JOIN employees e ON e.id=c.employee_id WHERE c.status='pending' ORDER BY c.created_at ASC LIMIT 50")->fetchAll();
$leaves=query("SELECT l.id,l.employee_id,l.start_date,l.end_date,l.leave_type,l.leave_part,e.name,e.employee_code FROM leave_requests l JOIN employees e ON e.id=l.employee_id WHERE l.status='pending' ORDER BY l.created_at ASC LIMIT 50")->fetchAll();
$checkoutAlerts=query('SELECT a.id,a.employee_id,a.work_date,a.auto_closed,a.checked_in,a.checked_out,e.name,e.employee_code FROM attendance a JOIN employees e ON e.id=a.employee_id WHERE a.checked_out IS NULL OR a.auto_closed=1 ORDER BY a.work_date DESC LIMIT 100')->fetchAll();
$checkoutAlerts=array_values(array_filter($checkoutAlerts,static fn($a)=>$a['auto_closed']||checkout_overdue($a)));
?>
<section class="panel"><h2>Unified approval center</h2><p class="muted">Pending actions shown here link to existing approval forms with full context and audit history. Never approve a checkout automatically; employees must submit a correction. Latest 50 requests per category and 100 recent checkout records are considered.</p><div class="admin-metrics"><div><span>Corrections</span><strong><?=count($corrections)?></strong></div><div><span>Leave requests</span><strong><?=count($leaves)?></strong></div><div><span>Checkout reviews</span><strong><?=count($checkoutAlerts)?></strong></div></div></section>
<section class="panel"><h2>Pending attendance corrections</h2><div class="table-wrap"><table><thead><tr><th>Employee</th><th>Work date</th><th>Reason</th><th>Review</th></tr></thead><tbody><?php foreach($corrections as $c):?><tr><td><?=e($c['employee_code'].' · '.$c['name'])?></td><td><?=e($c['work_date'])?></td><td><?=e($c['reason'])?></td><td><a href="<?=e(app_url('review'))?>">Review #<?=e($c['id'])?></a></td></tr><?php endforeach;if(!$corrections):?><tr><td colspan="4">No pending corrections.</td></tr><?php endif;?></tbody></table></div></section>
<section class="panel"><h2>Pending leave requests</h2><div class="table-wrap"><table><thead><tr><th>Employee</th><th>Dates</th><th>Leave balance</th><th>Review</th></tr></thead><tbody><?php foreach($leaves as $l):?><?php $balances=leave_balance_rows((int)$l['employee_id'],(int)substr($l['start_date'],0,4));$matchingBalance=null;foreach($balances as $b){if($b['type']===$l['leave_type'])$matchingBalance=$b;} ?><tr><td><?=e($l['employee_code'].' · '.$l['name'])?></td><td><?=e($l['start_date'].' → '.$l['end_date'].' · '.$l['leave_part'])?></td><td><?=e($matchingBalance===null?'Not applicable':($matchingBalance['remaining']===null?'Not allocated':(string)$matchingBalance['remaining'].' day(s) remaining'))?></td><td><a href="<?=e(app_url('calendar').'?'.http_build_query(['month'=>substr($l['start_date'],0,7),'employee'=>$l['employee_id'],'leave_status'=>'pending']))?>">Review #<?=e($l['id'])?></a></td></tr><?php endforeach;if(!$leaves):?><tr><td colspan="4">No pending leave requests.</td></tr><?php endif;?></tbody></table></div></section>
<section class="panel"><h2>Open &amp; auto-closed checkout alerts</h2><div class="table-wrap"><table><thead><tr><th>Employee</th><th>Date</th><th>Status</th><th>Action</th></tr></thead><tbody><?php foreach($checkoutAlerts as $a):?><tr><td><?=e($a['employee_code'].' · '.$a['name'])?></td><td><?=e($a['work_date'])?></td><td><?=e($a['auto_closed']?'Auto-closed · correction needed':'Overdue open session')?></td><td><a href="<?=e(app_url('alerts'))?>">View record #<?=e($a['id'])?></a></td></tr><?php endforeach;if(!$checkoutAlerts):?><tr><td colspan="4">No checkout alerts.</td></tr><?php endif;?></tbody></table></div></section>
<?php endif;?>
<?php require __DIR__.'/app/feature_views.php';require __DIR__.'/app/monthly_summary.php';require __DIR__.'/app/attendance_records.php';require __DIR__.'/app/salary_view.php';?>
<?php if($page!=='monthly'):?><footer>Server-recorded attendance · <?=e(config()['company'])?> · <?=e(config()['timezone'])?></footer><?php endif;?>
</main></div>
<?php if($page==='my'):?><dialog id="checkout-confirm" class="checkout-dialog" aria-labelledby="checkout-confirm-title" aria-describedby="checkout-confirm-description"><div class="checkout-dialog-icon"><?=ui_icon('out')?></div><h2 id="checkout-confirm-title">Check out now?</h2><p id="checkout-confirm-description">Your current work session will finish and your checkout time will be saved.</p><div class="checkout-dialog-time"><span>Checkout time</span><strong id="checkout-confirm-time"></strong></div><div class="checkout-dialog-actions"><button type="button" class="secondary" id="checkout-confirm-cancel" autofocus>Cancel</button><button type="button" id="checkout-confirm-save">Check Out</button></div></dialog><?php endif;?>
<dialog id="logout-confirm" class="logout-confirm-dialog" aria-labelledby="logout-confirm-title" aria-describedby="logout-confirm-description"><div class="logout-dialog-icon"><?=ui_icon('logout')?></div><h2 id="logout-confirm-title">Log out of your account?</h2><p id="logout-confirm-description">You will be returned to the login page. Your saved attendance will not be changed.</p><div class="logout-dialog-actions"><button type="button" class="secondary" id="logout-confirm-cancel" autofocus>Stay logged in</button><a class="logout-confirm-action" id="logout-confirm-yes" href="<?=e(app_url('logout.php'))?>">Yes, log out</a></div></dialog>
</body></html>
