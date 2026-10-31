<?php
declare(strict_types=1);
require __DIR__.'/app/core.php';
require __DIR__.'/app/defaults.php';
require_local_test($_SERVER);local_session();
header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'self'; style-src 'self'; frame-ancestors 'none'; form-action 'self'; base-uri 'none'");
$error='';$state='';
try{$state=installation_state();}catch(Throwable $x){error_log((string)$x);$error='Start MySQL in XAMPP and check db_user, db_password and port in config.php.';}
if($state==='ready'){header('Location: login.php',true,303);exit;}
if($state==='upgrade'){header('Location: upgrade.php',true,303);exit;}
if($state==='blocked')fail('Existing employees have no password accounts. Setup will not replace existing records. Check the database name in config.php.');
if($_SERVER['REQUEST_METHOD']==='POST'&&$state==='setup') {
 $p=null;$locked=false;
 try {
  check_csrf();$defaults=isset($_POST['use_defaults']);
  if($defaults)$_POST=['username'=>'tharaka','name'=>'Tharaka','password'=>'Tharaka@123','confirm'=>'Tharaka@123'];
  $username=strtolower(trim((string)($_POST['username']??'')));$name=trim((string)($_POST['name']??''));$password=(string)($_POST['password']??'');
  if(!preg_match('/^[a-z0-9._-]{3,40}$/',$username))throw new RuntimeException('Username needs 3–40 letters, numbers, dots, underscores or hyphens.');
  if($name===''||strlen($name)>120)throw new RuntimeException('Enter a name up to 120 characters.');
  if(strlen($password)<10||strlen($password)>200||$password!==($_POST['confirm']??''))throw new RuntimeException('Passwords must match and contain 10–200 characters.');
  $c=config();
  if(!preg_match('/(?:^|;)dbname=([a-zA-Z0-9_]+)(?:;|$)/',$c['dsn'],$matches))throw new RuntimeException('Use a valid MySQL database name in config.php.');
  $database=$matches[1];$serverDsn=preg_replace('/dbname=[^;]+;?/','',$c['dsn']);
  $p=new PDO($serverDsn,$c['db_user'],$c['db_password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
  $lock='attendance_setup_'.substr(hash('sha256',$database),0,32);
  $locked=(int)$p->query("SELECT GET_LOCK('$lock',10)")->fetchColumn()===1;
  if(!$locked)throw new RuntimeException('Another setup is running. Try again.');
  $p->exec("CREATE DATABASE IF NOT EXISTS `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");$p->exec("USE `$database`");$p->exec("SET time_zone = '+00:00'");
  // Recheck under the setup lock. Never bootstrap over any existing application records.
  $s=$p->query('SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE()');$tables=$s->fetchAll(PDO::FETCH_COLUMN);
  foreach(['employees','accounts','local_accounts','attendance','corrections','devices','audit_log','punch_requests'] as $table)if(in_array($table,$tables,true)&&(int)$p->query("SELECT COUNT(*) FROM `$table`")->fetchColumn()>0)throw new RuntimeException('The database already contains records. Setup will not overwrite them.');
  // Empty old dumps can be converted without deleting any tables or rows.
  upgrade_schema();
  $schema=file_get_contents(__DIR__.'/database/office_attendance.sql');
  $schema=preg_replace('/\ACREATE DATABASE[^;]*;\s*USE[^;]*;\s*/','',$schema);
  foreach(preg_split('/;\s*(?:\r?\n|$)/',$schema) as $sql)if(trim($sql)!=='')$p->exec($sql);
  $p->beginTransaction();
  if($defaults){provision_default_accounts($p,false);}else {
  $s=$p->prepare("INSERT INTO employees(employee_code,name,role,department) VALUES(?,?,'admin','IT')");$s->execute(['EMP001',$name]);$id=(int)$p->lastInsertId();
  $s=$p->prepare('INSERT INTO accounts(employee_id,username,password_hash) VALUES(?,?,?)');$s->execute([$id,$username,password_hash($password,PASSWORD_DEFAULT)]);
  $s=$p->prepare("INSERT INTO devices(employee_id,ip_address,label,status,created_at) VALUES(?,'127.0.0.1','XAMPP server localhost','approved',?)");$s->execute([$id,utc()]);
  $s=$p->prepare('INSERT INTO audit_log(actor_id,action,detail,created_at) VALUES(?,?,?,?)');$s->execute([$id,'setup_completed',json_encode(['authentication'=>'database']),utc()]);
  }
  $p->commit();session_regenerate_id(true);$_SESSION=['csrf'=>bin2hex(random_bytes(32))];$_SESSION['setup_notice']=$defaults?'Default accounts created. Sign in using the credentials supplied with the project.':'Admin account created: '.$username.'. Sign in with the password you just chose.';
  header('Location: login.php',true,303);
 }catch(PDOException $x){if($p&&$p->inTransaction())$p->rollBack();error_log((string)$x);$error='Database setup could not finish. Check MySQL and config.php. First setup needs CREATE and ALTER permissions.';}
 catch(Throwable $x){if($p&&$p->inTransaction())$p->rollBack();$error=$x->getMessage();}
 finally{if($locked&&$p)$p->query("SELECT RELEASE_LOCK('$lock')");}
 if($error==='')exit;
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Set up Office Attendance</title><link rel="stylesheet" href="assets/app.css"></head><body class="auth-body"><main class="auth-shell"><div class="auth-brand"><span class="brand-icon">✓</span><h1>Office Attendance</h1></div><section class="panel"><h2>Set up your accounts</h2><form method="post" class="auth-form"><?=csrf()?><input type="hidden" name="use_defaults" value="1"><p>Default admin: <strong>tharaka</strong> / <strong>Tharaka@123</strong><br>Default user: <strong>employee</strong> / <strong>Employee@123</strong></p><button>Create default admin &amp; user</button></form><hr><h3>Or choose your own admin password</h3><p>One-time setup. Add employees after signing in. Your database is created automatically.</p><?php if($error):?><div role="alert" class="notice error"><?=e($error)?></div><?php endif;?><form method="post" class="auth-form"><?=csrf()?><label>Admin username<input name="username" value="tharaka" minlength="3" maxlength="40" autocomplete="off" required></label><label>Admin name<input name="name" value="Tharaka" maxlength="120" required></label><label>Create password<input type="password" name="password" minlength="10" maxlength="200" autocomplete="new-password" required></label><label>Confirm password<input type="password" name="confirm" minlength="10" maxlength="200" autocomplete="new-password" required></label><p class="muted">Use at least 10 characters. Use this form to choose a custom admin password.</p><button>Create admin account</button></form></section></main></body></html>
