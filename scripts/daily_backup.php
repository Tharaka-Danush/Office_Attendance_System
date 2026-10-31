<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Command line only.');}
require dirname(__DIR__).'/app/core.php';require dirname(__DIR__).'/app/features.php';
$stream=null;$lock=null;$p=null;$part=null;
try{
 $folder=$argv[1]??'C:/xampp/attendance-backups';if(!is_dir($folder)&&!mkdir($folder,0700,true))throw new RuntimeException('Cannot create backup folder.');
 $folder=realpath($folder);$web=realpath(dirname(__DIR__,2));$normal=fn($x)=>strtolower(str_replace('\\','/',$x));
 if(!$folder||($web&&str_starts_with($normal($folder).'/',rtrim($normal($web),'/').'/')))throw new RuntimeException('Backup folder must be outside htdocs.');
 $lock=fopen($folder.'/backup.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('Backup already running.');
 $target=$folder.'/attendance_'.gmdate('Y-m-d_His').'_'.bin2hex(random_bytes(3)).'.sql';$part=$target.'.part';$stream=fopen($part,'xb');if(!$stream)throw new RuntimeException('Cannot write backup.');chmod($part,0600);
 $p=db();$p->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$p->beginTransaction();
 backup_write($stream,"-- Office attendance scheduled backup\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n");
 foreach(['employees','accounts','devices','attendance','punch_requests','corrections','audit_log','holidays','leave_requests','weekly_schedules','salary_months'] as $table){
  $ddl=query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_NUM)[1];backup_write($stream,$ddl.";\n");
  foreach(query('SELECT * FROM `'.$table.'`') as $row){$values=array_map(fn($v)=>$v===null?'NULL':$p->quote((string)$v),$row);backup_write($stream,'INSERT INTO `'.$table.'` VALUES('.implode(',',$values).");\n");}
 }
 backup_write($stream,"SET FOREIGN_KEY_CHECKS=1;\n");$p->commit();if(!fflush($stream))throw new RuntimeException('Cannot flush backup.');fclose($stream);$stream=null;if(!rename($part,$target))throw new RuntimeException('Cannot finalize backup.');echo "Backup completed.\n";
}catch(Throwable $x){if($p&&$p->inTransaction())$p->rollBack();if(is_resource($stream))fclose($stream);if($part)@unlink($part);fwrite(STDERR,"Backup failed; check MySQL, configuration and folder permissions.\n");exit(1);}
