<?php
declare(strict_types=1);
function config(): array {
 static $c;
 if ($c === null) {
  $file = PHP_SAPI === 'cli' && getenv('ATTENDANCE_CONFIG') ? getenv('ATTENDANCE_CONFIG') : dirname(__DIR__).'/config.php';
  if (!is_file($file)) throw new RuntimeException('Configuration missing. Ask IT to follow SETUP.md.');
  $c = require $file;
 }
 return $c;
}
function db(): PDO {
 static $p;
 if (!$p) {
  $c=config();
  $p=new PDO($c['dsn'],$c['db_user'],$c['db_password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
  $p->exec("SET time_zone = '+00:00'");
 }
 return $p;
}
function query(string $sql,array $params=[]): PDOStatement { $s=db()->prepare($sql);$s->execute($params);return $s; }
function utc(): string { return gmdate('Y-m-d H:i:s'); }
function tz(): DateTimeZone { return new DateTimeZone(config()['timezone']); }
function today(): string { return (new DateTimeImmutable('now',tz()))->format('Y-m-d'); }
function local_time(?string $v,string $format='d M Y, H:i'): string {return $v ? (new DateTimeImmutable($v,new DateTimeZone('UTC')))->setTimezone(tz())->format($format) : '—';}
function e(mixed $s): string { return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
function fail(string $message,int $code=403): never { http_response_code($code);echo '<!doctype html><meta charset="utf-8"><title>Attendance access</title><main style="font:18px system-ui;max-width:700px;margin:10vh auto;padding:30px"><h1>Office Attendance</h1><p>'.e($message).'</p></main>';exit; }
function network_allowed(string $ip,array $ranges): bool {
 if (filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)===false) return false;
 $v=ip2long($ip);
 foreach ($ranges as $range) {
  $parts=explode('/',$range);$base=$parts[0];$bits=$parts[1]??'32';
  if (!ctype_digit((string)$bits)|| (int)$bits>32 ||filter_var($base,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)===false) continue;
  $mask=(int)$bits===0 ? 0 : (-1 << (32-(int)$bits));
  if (($v & $mask)===(ip2long($base)&$mask)) return true;
 }
 return false;
}
function authenticate(): array {
 require_office_network($_SERVER);
 $state=installation_state();
 if($state==='setup'){header('Location: '.app_url('setup.php'),true,303);exit;}
 if($state==='upgrade'){header('Location: '.app_url('upgrade.php'),true,303);exit;}
 if($state!=='ready')fail('Existing employee records have no password accounts. Use the database containing your existing accounts, or contact the administrator.');
 return local_user();
}
function csrf(): string {return '<input type="hidden" name="csrf" value="'.e($_SESSION['csrf']).'">';}
function check_csrf(): void {if (!is_string($_POST['csrf']??null)||!hash_equals($_SESSION['csrf'],$_POST['csrf'])) throw new RuntimeException('Page expired. Refresh and try again.');}
function staff(array $u): bool {return in_array($u['role'],['admin','hr'],true);}
function audit(int $actor,string $action,array $detail): void {query('INSERT INTO audit_log(actor_id,action,detail,created_at) VALUES(?,?,?,?)',[$actor,$action,json_encode($detail,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),utc()]);}
function work_date(DateTimeImmutable $local,string $start,string $end): string {
 // Flexible weekend work belongs to the local date of check-in.
 return $local->format('Y-m-d');
}
function shift_seconds(string $start,string $end): int {
 $a=strtotime('2000-01-01 '.$start.' UTC');$b=strtotime('2000-01-01 '.$end.' UTC');
 if ($b<=$a) $b+=86400;
 return $b-$a;
}
/** Close stale sessions at the first local midnight after check-in.
 * Automatic cutoffs are provisional: their OT is held for manual HR review.
 * Safe to run repeatedly from Task Scheduler or during a page request.
 */
function midnight_cutoff_utc(string $checkin): string {
 $local=(new DateTimeImmutable($checkin,new DateTimeZone('UTC')))->setTimezone(tz());
 return $local->modify('tomorrow')->setTime(0,0,0)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}
function auto_checkout_overdue(): int {
 if(!column_exists('attendance','auto_closed'))return 0;
 $p=db();$count=0;
 $ids=query('SELECT id,employee_id,checked_in FROM attendance WHERE checked_out IS NULL AND checked_in<? ORDER BY employee_id,id', [utc()])->fetchAll();
 foreach($ids as $candidate) {
  $cutoff=midnight_cutoff_utc($candidate['checked_in']);
  if($cutoff>utc())continue;
  $p->beginTransaction();
  try {
   $employee=query('SELECT id FROM employees WHERE id=? FOR UPDATE',[$candidate['employee_id']])->fetch();
   $row=query('SELECT id,checked_in,checked_out FROM attendance WHERE id=? FOR UPDATE',[$candidate['id']])->fetch();
   if($employee && $row && $row['checked_out']===null && $row['checked_in']<$cutoff) {
    query('UPDATE attendance SET checked_out=?, auto_closed=1, out_device=NULL WHERE id=? AND checked_out IS NULL',[$cutoff,$row['id']]);
    audit((int)$candidate['employee_id'],'system_midnight_checkout',['attendance_id'=>(int)$row['id'],'cutoff_utc'=>$cutoff,'requires_review'=>true]);
    $count++;
   }
   $p->commit();
  }catch(Throwable $e){if($p->inTransaction())$p->rollBack();throw $e;}
 }
 return $count;
}
function punch(array $u,string $action,string $key): string {
 if($u['role']==='admin')throw new RuntimeException('Admin attendance check-in/out is disabled.');
 if (!in_array($action,['in','out'],true)||!preg_match('/^[a-f0-9]{32}$/',$key)) throw new RuntimeException('Invalid attendance request.');
 $p=db();$p->beginTransaction();
 try {
  // All punch/review paths lock the employee row first to serialize concurrent writes.
  $current=query('SELECT * FROM employees WHERE id=? FOR UPDATE',[$u['id']])->fetch();
  if (!$current||!$current['active']) throw new RuntimeException('Employee inactive.');
  $device=query("SELECT id FROM devices WHERE id=? AND employee_id=? AND ip_address=? AND status='approved' FOR UPDATE",[$u['device_id'],$u['id'],client_ip($_SERVER)])->fetch();
  if (!$device) throw new RuntimeException('This computer is not approved for attendance. Ask your admin to approve its IP address.');
  $old=query('SELECT result FROM punch_requests WHERE employee_id=? AND request_key=?',[$u['id'],$key])->fetch();
  if ($old) {$p->commit();return $old['result'];}
  $open=query('SELECT * FROM attendance WHERE employee_id=? AND checked_out IS NULL ORDER BY id DESC LIMIT 1 FOR UPDATE',[$u['id']])->fetch();
  $now=utc();
  if ($action==='in') {
   if ($open) $message='Already checked in. No duplicate entry was created.';
   else {
    $local=(new DateTimeImmutable($now,new DateTimeZone('UTC')))->setTimezone(tz());
    $date=work_date($local,$current['shift_start'],$current['shift_end']);
    query('INSERT INTO attendance(employee_id,work_date,checked_in,in_device,shift_start,shift_end,grace_minutes,break_minutes) VALUES(?,?,?,?,?,?,?,?)',[$u['id'],$date,$now,$u['device_id'],$current['shift_start'],$current['shift_end'],$current['grace_minutes'],$current['break_minutes']]);
    audit((int)$u['id'],'check_in',['attendance_id'=>$p->lastInsertId(),'device'=>$u['device_id'],'time'=>$now]);
    $message='Checked in at '.local_time($now,'H:i:s').'.';
   }
  } else {
   if (!$open) $message='You have no open session to check out.';
   else {
    if (strtotime($now.' UTC')<strtotime($open['checked_in'].' UTC')) throw new RuntimeException('Server clock is earlier than check-in. Contact IT.');
    query('UPDATE attendance SET checked_out=?,out_device=?,auto_closed=0 WHERE id=?',[$now,$u['device_id'],$open['id']]);
    audit((int)$u['id'],'check_out',['attendance_id'=>$open['id'],'device'=>$u['device_id'],'time'=>$now]);
    $message='Checked out at '.local_time($now,'H:i:s').'.';
   }
  }
  query('INSERT INTO punch_requests(employee_id,request_key,action,result) VALUES(?,?,?,?)',[$u['id'],$key,$action,$message]);
  $p->commit();return $message;
 } catch(Throwable $x) {if($p->inTransaction())$p->rollBack();throw $x;}
}
function valid_date(string $s): string {
 $d=DateTimeImmutable::createFromFormat('!Y-m-d',$s,tz());
 if (!$d||$d->format('Y-m-d')!==$s) throw new RuntimeException('Invalid date.');
 return $s;
}
function proposed_time(string $s): string {
 $d=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$s,tz());
 if (!$d||$d->format('Y-m-d\TH:i')!==$s) throw new RuntimeException('Enter a valid date and time.');
 return $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}
function weekend_work_date(string $date): bool {return (int)(new DateTimeImmutable($date,tz()))->format('N')>=6;}
function remaining_weekday_break(array $sessions,string $date,int $scheduled): int {
 usort($sessions,fn($a,$b)=>strcmp($a['checked_in'],$b['checked_in']));
 $start=(new DateTimeImmutable($date.' 08:00:00',tz()))->getTimestamp();$end=(new DateTimeImmutable($date.' 17:30:00',tz()))->getTimestamp();$gap=0;$previousOut=null;
 foreach($sessions as $session){$in=strtotime($session['checked_in'].' UTC');if($previousOut!==null)$gap+=max(0,min($in,$end)-max($previousOut,$start));$previousOut=$session['checked_out']?max($previousOut??0,strtotime($session['checked_out'].' UTC')):null;}
 return max(0,$scheduled-$gap);
}
function summary(array $rows): array {
 $days=[];
 foreach($rows as $r) {
  $k=$r['employee_id'].'|'.$r['work_date'];
  if (!isset($days[$k])) $days[$k]=['row'=>$r,'first'=>$r['checked_in'],'last'=>null,'seconds'=>0,'completed_ot'=>0,'open'=>false,'sessions'=>0,'session_rows'=>[]];
  $d=&$days[$k];$d['sessions']++;$d['session_rows'][]=$r;
  if($r['checked_in']<$d['first']) {$d['first']=$r['checked_in'];$d['row']=$r;}
  if(!empty($r['auto_closed']))$d['open']=true; // provisional automatic cutoff: suppress final OT
  if($r['checked_out']) {
   $end=new DateTimeImmutable($r['work_date'].' 17:30:00',tz());
   $d['completed_ot']+=max(0,strtotime($r['checked_out'].' UTC')-max(strtotime($r['checked_in'].' UTC'),$end->getTimestamp()));
   $d['seconds']+=max(0,strtotime($r['checked_out'].' UTC')-strtotime($r['checked_in'].' UTC'));if(!$d['last']||$r['checked_out']>$d['last'])$d['last']=$r['checked_out'];}
  else $d['open']=true;
  unset($d);
 }
 foreach($days as &$d) {
  $r=$d['row'];$expected=new DateTimeImmutable($r['work_date'].' 08:00:00',tz());
  $weekend=weekend_work_date($r['work_date']);
  $d['late']=$weekend?0:max(0,(int)floor((strtotime($d['first'].' UTC')-$expected->getTimestamp())/60)-(int)$r['grace_minutes']);
  $d['break_deducted']=$weekend?0:remaining_weekday_break($d['session_rows'],$r['work_date'],(int)$r['break_minutes']*60);
  $d['net']=max(0,$d['seconds']-$d['break_deducted']);
  $d['weekday_ot']=$d['open']?null:($weekend?0:$d['completed_ot']);
  $d['single_ot']=$d['open']?null:($weekend?min(8*3600,$d['seconds']):0);
  $d['double_ot']=$d['open']?null:($weekend?max(0,$d['seconds']-8*3600):0);
  $d['ot']=$d['open']?null:$d['weekday_ot']+$d['single_ot']+$d['double_ot'];
 }
 return array_values($days);
}
function hours(int $seconds): string {return sprintf('%02d:%02d',intdiv($seconds,3600),intdiv($seconds%3600,60));}

require_once __DIR__.'/local.php';

require_once __DIR__.'/schema.php';

function app_url(string $path=''): string { return '/Office_Attendance_System/'.ltrim($path,'/'); }
function attendance_page(array $user): string {
 $pages=['my','corrections','dashboard','reports','review','employees','devices','audit','password','monthly','calendar','alerts','backup','salary','approvals','payroll'];
 $path=(string)parse_url((string)($_SERVER['REQUEST_URI']??''),PHP_URL_PATH);
 $last=basename(rtrim($path,'/'));
 if(in_array($last,$pages,true))return $last;
 if(isset($_GET['page'])) {
  if(!is_string($_GET['page'])||!in_array($_GET['page'],$pages,true))fail('Page not found.',404);
  return $_GET['page'];
 }
 return staff($user)?'dashboard':'my';
}
