<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/core.php';
try {
 $username=strtolower(trim((string)fgets(STDIN)));$password=rtrim((string)fgets(STDIN),"\r\n");$confirm=rtrim((string)fgets(STDIN),"\r\n");
 if(strlen($password)<10||strlen($password)>200||$password!==$confirm)throw new RuntimeException('Passwords must match and contain 10-200 characters.');
 $table=table_exists('accounts')&&(int)query('SELECT COUNT(*) FROM accounts')->fetchColumn()>0?'accounts':'local_accounts';
 $p=db();$p->beginTransaction();
 $admin=query('SELECT a.employee_id FROM '.$table." a JOIN employees e ON e.id=a.employee_id WHERE a.username=? AND e.active=1 AND e.role='admin' FOR UPDATE",[$username])->fetch();
 if(!$admin)throw new RuntimeException('Active admin username not found. No account was changed.');
 query('UPDATE '.$table.' SET password_hash=? WHERE employee_id=?',[password_hash($password,PASSWORD_DEFAULT),$admin['employee_id']]);
 audit((int)$admin['employee_id'],'admin_password_reset_console',['source'=>'XAMPP server console']);
 $p->commit();echo "Admin password updated. Attendance records are unchanged.\n";
}catch(Throwable $x){if(isset($p)&&$p->inTransaction())$p->rollBack();fwrite(STDERR,$x instanceof PDOException?"Could not access MySQL. Check config.php and start MySQL.\n":$x->getMessage()."\n");exit(1);}
