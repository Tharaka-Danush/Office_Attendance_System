<?php
declare(strict_types=1);
function client_ip(array $server): string { $ip=(string)($server['REMOTE_ADDR']??'');return $ip==='::1'?'127.0.0.1':$ip; }
function require_office_network(array $server): void {
 if(!network_allowed(client_ip($server),config()['allowed_networks'])) fail('Use an approved office network to access attendance.');
}
function require_local_test(array $server): void {
 if(!in_array(client_ip($server),['127.0.0.1'],true)) fail('Run setup or database upgrade at localhost on the XAMPP server.');
}
function local_session(): void {
 session_name('attendance_'.substr(hash('sha256',config()['dsn']),0,16));
 session_set_cookie_params(['secure'=>strtolower((string)($_SERVER['HTTPS']??''))==='on','httponly'=>true,'samesite'=>'Strict','path'=>'/']);
 if(!@session_start())throw new RuntimeException('PHP session storage is unavailable. Check session.save_path in XAMPP php.ini.');
 $_SESSION['csrf']??=bin2hex(random_bytes(32));
}
function local_user(): array {
 require_office_network($_SERVER);local_session();
 if(!isset($_SESSION['local_employee_id'])) {header('Location: '.app_url('login.php'),true,303);exit;}
 $u=query('SELECT e.*,l.username,l.password_hash FROM employees e JOIN accounts l ON l.employee_id=e.id WHERE e.id=? AND e.active=1',[$_SESSION['local_employee_id']])->fetch();
 if(!$u||!hash_equals(hash('sha256',$u['password_hash']), (string)($_SESSION['credential']??''))){$_SESSION=[];session_destroy();header('Location: '.app_url('login.php'),true,303);exit;}
 $device=query("SELECT id FROM devices WHERE employee_id=? AND ip_address=? AND status='approved' ORDER BY id LIMIT 1",[$u['id'],client_ip($_SERVER)])->fetch();
 $u['device_id']=$device['id']??null;return $u;
}
function local_login(string $username,string $password): bool {
 $r=query('SELECT l.*,e.active FROM accounts l JOIN employees e ON e.id=l.employee_id WHERE l.username=?',[strtolower(trim($username))])->fetch();
 if(!$r||!$r['active']||!password_verify($password,$r['password_hash'])) return false;
 session_regenerate_id(true);$_SESSION=['local_employee_id'=>$r['employee_id'],'credential'=>hash('sha256',$r['password_hash']),'csrf'=>bin2hex(random_bytes(32))];return true;
}
function save_local_account(int $employeeId,bool $new): void {
 $username=strtolower(trim((string)($_POST['local_username']??'')));
 $password=(string)($_POST['local_password']??'');
 $existing=query('SELECT username FROM accounts WHERE employee_id=?',[$employeeId])->fetch();
 if($new||!$existing) {
  if(!preg_match('/^[a-z0-9._-]{3,40}$/',$username)||strlen($password)<10||strlen($password)>200)throw new RuntimeException('Login needs a username (3–40 characters) and a password (10–200 characters).');
  query('INSERT INTO accounts(employee_id,username,password_hash) VALUES(?,?,?)',[$employeeId,$username,password_hash($password,PASSWORD_DEFAULT)]);
 } elseif($password!=='') {
  if(strlen($password)<10||strlen($password)>200)throw new RuntimeException('Password must be 10–200 characters.');
  query('UPDATE accounts SET password_hash=? WHERE employee_id=?',[password_hash($password,PASSWORD_DEFAULT),$employeeId]);
 }
}
