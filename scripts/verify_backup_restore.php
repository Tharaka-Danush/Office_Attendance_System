<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Command line only.');}
require __DIR__.'/backup_verification_lib.php';
ini_set('memory_limit','512M');
$admin=null;$target=null;$testName=null;$created=false;$ok=false;$clean=true;$counts=[];$stage='read backup';$source=null;$reportFolder=null;
try{
 $folder='C:/xampp/attendance-backups';$source=$argv[1]??'';
 if($source===''){$files=array_filter(glob($folder.'/attendance_*.sql')?:[],'is_file');usort($files,fn($a,$b)=>filemtime($b)<=>filemtime($a));$source=$files[0]??'';}
 if(!$source||!is_file($source)||filesize($source)===0||filesize($source)>16*1024*1024)throw new RuntimeException('Select a generated backup up to 16 MiB.');
 $source=realpath($source);$web=realpath(dirname(__DIR__,2));$normal=fn($p)=>strtolower(str_replace('\\','/',$p));
 if($web&&str_starts_with($normal($source),rtrim($normal($web),'/').'/'))throw new RuntimeException('Backup must be outside htdocs.');
 $reportFolder=dirname($source);$plan=verification_plan(file_get_contents($source));
 $stage='connect';$configPath=getenv('ATTENDANCE_CONFIG')?:dirname(__DIR__).'/config.php';$config=require $configPath;
 if(!preg_match('/^mysql:host=127\.0\.0\.1;port=(\d+);dbname=([A-Za-z0-9_]+);charset=utf8mb4$/D',$config['dsn'],$m))throw new RuntimeException('Expected the standard local MySQL configuration.');
 $user=getenv('ATTENDANCE_DB_ADMIN_USER');$password=getenv('ATTENDANCE_DB_ADMIN_PASSWORD')?:'';
 if(!$user)throw new RuntimeException('Run VERIFY_BACKUP_RESTORE.bat.');
 $options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_NUM,PDO::ATTR_STRINGIFY_FETCHES=>true,PDO::ATTR_EMULATE_PREPARES=>false];
 $dsn='mysql:host=127.0.0.1;port='.$m[1].';charset=utf8mb4';$admin=new PDO($dsn,$user,$password,$options);
 $testName='attendance_verify_'.gmdate('Ymd_His').'_'.bin2hex(random_bytes(4));
 $stage='create isolated test database';$admin->exec('CREATE DATABASE `'.$testName.'` CHARACTER SET utf8mb4');$created=true;echo 'Temporary verification database: '.$testName.PHP_EOL;
 $target=new PDO($dsn.';dbname='.$testName,$user,$password,$options);
 $target->exec("SET time_zone='+00:00'");$target->exec("SET SESSION sql_mode='NO_AUTO_VALUE_ON_ZERO'");$target->exec('SET FOREIGN_KEY_CHECKS=0');
 $expected=[];$inserts=[];
 foreach(verification_tables() as $table){$expected[$table]=[];$counts[$table]=0;}
 $stage='restore generated tables and rows';
 foreach($plan as [$type,$table,$payload]){
  if($type==='create'){$target->exec($payload);continue;}
  $inserts[$table]??=$target->prepare('INSERT INTO `'.$table.'` VALUES('.implode(',',array_fill(0,count($payload),'?')).')');
  $inserts[$table]->execute($payload);$hash=verification_row_hash($payload);$expected[$table][$hash]=($expected[$table][$hash]??0)+1;
 }
 $stage='compare restored row content';
 foreach(verification_tables() as $table){
  $query=$target->query('SELECT * FROM `'.$table.'`');
  while(($row=$query->fetch())!==false){$hash=verification_row_hash($row);if(empty($expected[$table][$hash]))throw new RuntimeException('Restored content mismatch.');$expected[$table][$hash]--;$counts[$table]++;}
  if(array_sum($expected[$table])!==0)throw new RuntimeException('Restored row count mismatch.');
  echo 'PASS: '.$table.' - '.$counts[$table].' row(s) match the backup.'.PHP_EOL;
 }
 $target->exec('SET FOREIGN_KEY_CHECKS=1');$ok=true;
}catch(Throwable $x){echo 'CHECK: Verification failed at '.$stage.'. Check generated backup format, MySQL, credentials and permissions. No live database writes were requested.'.PHP_EOL;}
finally{
 $target=null;
 if($created){try{$admin->exec('DROP DATABASE `'.$testName.'`');echo 'Temporary test database removed.'.PHP_EOL;}catch(Throwable $x){$clean=false;echo 'CHECK: Remove only this temporary test database after inspection: '.$testName.PHP_EOL;}}
}
$report=['checked_at_utc'=>gmdate('c'),'backup'=>$source?basename($source):null,'restored_content_matches'=>$ok,'temporary_database_removed'=>$created?$clean:null,'verified_row_counts'=>$ok?$counts:[],'live_database_changed'=>false];
if($reportFolder!==null&&$source&&is_file($source)){
 $reportPath=dirname($source).'/verification_'.gmdate('Y-m-d_His').'_'.bin2hex(random_bytes(3)).'.json';
 if(file_put_contents($reportPath,json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL,LOCK_EX)===false){echo 'CHECK: Could not save the verification report.'.PHP_EOL;$clean=false;}
 else{chmod($reportPath,0600);echo 'Verification report: '.basename($reportPath).PHP_EOL;}
}
echo $ok&&$clean?'PASS: Backup restored and all row content matched. Live database and config were not changed.'.PHP_EOL:'Verification did not fully pass. See CHECK messages above.'.PHP_EOL;
exit($ok&&$clean?0:1);
