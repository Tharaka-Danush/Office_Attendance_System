<?php
declare(strict_types=1);
require __DIR__.'/app/core.php';
header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; frame-ancestors 'none'; form-action 'self'; base-uri 'none'");
require_office_network($_SERVER);local_session();
function login_destination(): string {
 $role=query('SELECT role FROM employees WHERE id=? AND active=1',[$_SESSION['local_employee_id']])->fetchColumn();
 return app_url(in_array($role,['admin','hr'],true)?'dashboard':'my');
}
$error=(string)($_SESSION['login_error']??'');unset($_SESSION['login_error']);
$notice=(string)($_SESSION['setup_notice']??'');unset($_SESSION['setup_notice']);
try {$state=installation_state();}catch(Throwable $x){error_log((string)$x);fail('Start XAMPP MySQL and check the database username/password in config.php.',503);}
$setupNeeded=$state!=='ready';$upgradeNeeded=$state==='upgrade';
if($state==='blocked')fail('The selected database contains employees but no password accounts. Check the database name in config.php.');
if(!$setupNeeded&&isset($_SESSION['local_employee_id'])) {
 $hash=query('SELECT a.password_hash FROM accounts a JOIN employees e ON e.id=a.employee_id WHERE e.id=? AND e.active=1',[$_SESSION['local_employee_id']])->fetchColumn();
 $active=$hash&&hash_equals(hash('sha256',$hash),(string)($_SESSION['credential']??''));
 if($active){header('Location: '.login_destination(),true,303);exit;}
 unset($_SESSION['local_employee_id']);
}
if($_SERVER['REQUEST_METHOD']==='POST'&&!$setupNeeded) {
 try {
  check_csrf();
  if((int)($_SESSION['retry_at']??0)>time())throw new RuntimeException('Please wait one minute before trying again.');
  $username=(string)($_POST['username']??'');$password=(string)($_POST['password']??'');
  if(strlen($username)>40||strlen($password)>200||!local_login($username,$password)) {
   $_SESSION['failures']=((int)($_SESSION['failures']??0))+1;
   if($_SESSION['failures']>=5){$_SESSION['retry_at']=time()+60;$_SESSION['failures']=0;}
   throw new RuntimeException('Incorrect username or password, or inactive account.');
  }
  header('Location: '.login_destination(),true,303);exit;
 }catch(PDOException $x){error_log((string)$x);$error='Login service unavailable. Check local setup and MySQL.';}
 catch(RuntimeException $x){$error=$x->getMessage();}
 catch(Throwable $x){error_log((string)$x);$error='Login service unavailable. Check local setup and MySQL.';}
 $_SESSION['login_error']=$error;header('Location: login.php',true,303);exit;
}
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Sign in · Office Attendance</title><link rel="stylesheet" href="assets/app.css"><link rel="stylesheet" href="assets/login.css?v=20261015arpico"><script src="assets/login.js" defer></script></head>
<body class="portal-login"><main class="login-layout">
<section class="login-story" aria-label="Office Attendance overview"><a class="login-logo" href="login.php"><span class="arpico-logo-window"><img src="assets/arpico-logo.jpg" alt="ARPICO" width="1200" height="630"></span><span>Office<span class="logo-light">Attendance</span></span></a><div class="story-copy"><span class="story-kicker">YOUR WORKDAY, IN VIEW</span><h1>A simple start.<br>A clear record.</h1><p>Check in, check out and keep track of your workday. All your attendance, in one place.</p></div>
<div class="attendance-art" aria-hidden="true"><div class="art-circle circle-one"></div><div class="art-circle circle-two"></div><div class="art-sheet"><div class="art-sheet-top"><span class="art-avatar">✓</span><div><strong>Your workday</strong><span>Attendance at a glance</span></div><span class="art-status">●</span></div><div class="art-session"><span class="art-dot"></span><div><strong>Check in</strong><span>Start your day</span></div><svg viewBox="0 0 24 24" fill="none"><path d="m5 12 4 4L19 6" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/></svg></div><div class="art-track"></div><div class="art-session"><span class="art-dot out"></span><div><strong>Check out</strong><span>Finish your shift</span></div><svg viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="8" stroke="currentColor" stroke-width="2"/><path d="M12 7v5l3 2" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg></div><div class="art-footer"><span>One workspace</span><span>Every workday</span></div></div><div class="art-float"><span>✓</span>Clear records. Less effort.</div></div>
<div class="story-footer"><span class="story-line"></span>Built around your working day</div></section>
<section class="login-entry"><div class="login-mode"><span></span>Office workspace</div><div class="login-form-area"><div class="entry-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><rect x="4" y="6" width="16" height="15" rx="3" stroke="currentColor" stroke-width="1.7"/><path d="M8 3v6m8-6v6M4 12h16m-11 5 2 2 4-4" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg></div>
<?php if($setupNeeded):?><h2>Let’s get started</h2><p class="entry-description"><?=$upgradeNeeded?'Update your existing database to use this version.':'Set up your workspace to record attendance.'?></p><a class="login-submit button-link" href="<?=$upgradeNeeded?'upgrade.php':'setup.php'?>"><?=$upgradeNeeded?'Upgrade database':'Open setup'?> <span aria-hidden="true">→</span></a>
<?php else:?><p class="entry-kicker">WELCOME BACK</p><h2>Sign in to your workspace</h2><p class="entry-description">One login for employees, admins and HR.</p>
<?php if($notice):?><div class="notice" role="status"><?=e($notice)?></div><?php endif;?><?php if($error):?><div role="alert" class="login-error"><?=e($error)?></div><?php endif;?>
<form method="post" class="portal-form" id="login-form" autocomplete="off"><?=csrf()?>
<label for="login-username">Username</label><div class="login-input"><svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="8" r="3.5" stroke="currentColor" stroke-width="1.7"/><path d="M5 21v-2a7 7 0 0 1 14 0v2" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg><input id="login-username" name="username" type="text" value="" placeholder="Enter your username" maxlength="40" autocomplete="off" autocapitalize="none" spellcheck="false" required></div>
<label for="login-password">Password</label><div class="login-input"><svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><rect x="5" y="10" width="14" height="11" rx="3" stroke="currentColor" stroke-width="1.7"/><path d="M8 10V7a4 4 0 0 1 8 0v3m-4 5v2" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg><input id="login-password" name="password" type="password" value="" placeholder="Enter your password" maxlength="200" autocomplete="new-password" required><button class="password-toggle" type="button" aria-label="Show password" aria-pressed="false"><svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z" stroke="currentColor" stroke-width="1.7"/><circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="1.7"/></svg></button></div>
<button class="login-submit" type="submit">Sign in <span aria-hidden="true">→</span></button></form><p class="role-hint"><span aria-hidden="true">✓</span>Your account determines your access.</p><?php endif;?>
<div class="login-support">Need access? Contact your HR or system administrator.</div></div><footer class="login-footer"><strong><?=e(config()['company'])?></strong><span>Office Attendance · XAMPP</span></footer></section>
</main></body></html>
