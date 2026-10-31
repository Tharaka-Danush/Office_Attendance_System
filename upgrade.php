<?php
declare(strict_types=1);
require __DIR__.'/app/core.php';
require_local_test($_SERVER);local_session();
header("Content-Security-Policy: default-src 'self'; style-src 'self'; frame-ancestors 'none'; form-action 'self'; base-uri 'none'");
header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
$message='';$error='';
if($_SERVER['REQUEST_METHOD']==='POST') {
 $locked=false;
 try {
  check_csrf();
  $table=table_exists('accounts')&&(int)query('SELECT COUNT(*) FROM accounts')->fetchColumn()>0?'accounts':'local_accounts';
  $admin=query('SELECT a.*,e.role,e.active FROM '.$table.' a JOIN employees e ON e.id=a.employee_id WHERE a.username=?',[strtolower(trim((string)($_POST['username']??'')))])->fetch();
  if(!$admin||$admin['role']!=='admin'||!$admin['active']||!password_verify((string)($_POST['password']??''),$admin['password_hash']))throw new RuntimeException('Enter the existing active administrator username and password.');
  $locked=(int)query("SELECT GET_LOCK('attendance_xampp_upgrade',10)")->fetchColumn()===1;
  if(!$locked)throw new RuntimeException('Another upgrade is running. Try again.');
  upgrade_schema();
  audit((int)$admin['employee_id'],'database_upgrade',['authentication'=>'database','attendance_preserved'=>true]);
  $_SESSION=[];session_regenerate_id(true);$_SESSION['csrf']=bin2hex(random_bytes(32));
  $message='Upgrade complete. Your usernames, passwords and attendance records are preserved. Sign in with your existing password.';
 } catch(Throwable $x){error_log((string)$x);$error=$x instanceof PDOException?'Database upgrade could not finish. Check the MySQL log and database permissions, then retry.':$x->getMessage();}
 finally{if($locked)query("SELECT RELEASE_LOCK('attendance_xampp_upgrade')");}
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>XAMPP database upgrade</title><link rel="stylesheet" href="assets/app.css"></head><body class="auth-body"><main class="auth-shell"><section class="panel"><h1>XAMPP database upgrade</h1><p>Export the configured database in phpMyAdmin first. This upgrade preserves accounts, attendance, correction requests and activity history. It adds leave, holiday and weekly schedule tables, half-day leave fields and monthly salary settings, including separate travelling allowance and attendance bonus fields. Existing allowance amounts remain under Other allowances / additions. Older installations also have unused domain authentication fields removed.</p><?php if($message):?><div class="notice"><?=e($message)?></div><a class="button-link" href="login.php">Sign in</a><?php else:?><?php if($error):?><div class="notice error"><?=e($error)?></div><?php endif;?><form method="post" class="auth-form"><?=csrf()?><label>Existing admin username<input name="username" required maxlength="40" autocomplete="off"></label><label>Existing admin password<input type="password" name="password" required maxlength="200" autocomplete="off"></label><button>Upgrade existing database</button></form><?php endif;?></section></main></body></html>
