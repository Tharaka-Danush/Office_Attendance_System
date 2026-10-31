<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Command line only.');}
$issues=0;
function office_check(bool $ok,string $message): void {global $issues;echo ($ok?'PASS: ':'CHECK: ').$message.PHP_EOL;if(!$ok)$issues++;}
try{
 $configPath=getenv('ATTENDANCE_CONFIG')?:dirname(__DIR__).'/config.php';
 if(!is_file($configPath))throw new RuntimeException('Configuration missing.');
 $config=require $configPath;
 office_check(strtolower((string)($config['db_user']??''))!=='root'&&!empty($config['db_password']),'Dedicated database account with a password (SETUP_OFFICE_SERVER.bat).');
 $lan=false;foreach($config['allowed_networks']??[] as $cidr){if(preg_match('/^(10\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[01])\.)/',$cidr))$lan=true;}
 office_check($lan,'Office private network configured; verify the CIDR matches your real office network.');
 $zone=new DateTimeZone((string)($config['timezone']??'UTC'));
 office_check($zone->getName()==='Asia/Colombo','Office timezone is Asia/Colombo.');
 echo 'Office server time: '.(new DateTimeImmutable('now',$zone))->format('Y-m-d H:i:s T').PHP_EOL;
 $p=new PDO($config['dsn'],$config['db_user'],$config['db_password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
 office_check(true,'Configured database connection works.');
 $dbTime=strtotime((string)$p->query('SELECT UTC_TIMESTAMP()')->fetchColumn().' UTC');
 office_check(abs(time()-$dbTime)<60,'PHP and database clocks agree within one minute; also compare server time with an office PC.');
 $required=['employees','accounts','devices','attendance','punch_requests','corrections','audit_log','holidays','leave_requests','weekly_schedules','salary_months'];
 $tables=$p->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);$missing=array_diff($required,$tables);
 office_check(!$missing,'Required application tables exist.'.($missing?' Missing: '.implode(', ',$missing):''));
 if(in_array('salary_months',$tables,true)){
  $columns=$p->query('SHOW COLUMNS FROM salary_months')->fetchAll(PDO::FETCH_COLUMN);
  $needed=array_diff(['travelling_allowance','attendance_bonus','ll_day_payment','days_payment','actual_gross','actual_net'],$columns);
  office_check(!$needed,'Salary fields are up to date.'.($needed?' Run upgrade.php at localhost.':''));
 }
 foreach(array_intersect($required,$tables) as $table)$p->query('SELECT 1 FROM `'.$table.'` LIMIT 1');
 office_check(true,'Application tables are readable.');
 echo 'This check does not change configuration, accounts or attendance.'.PHP_EOL;
}catch(Throwable $x){office_check(false,'Unable to complete database/configuration checks. Check MySQL, PHP PDO MySQL and config.php.');}
exit($issues?1:0);
