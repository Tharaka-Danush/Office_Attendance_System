<?php
declare(strict_types=1);
// Called only by localhost bootstrap or the server-console provisioning command.
function provision_default_accounts(PDO $p,bool $reset): int {
 $adminId=0;
 foreach([['tharaka','Tharaka','admin','IT','DEFAULT_ADMIN','Tharaka@123'],['employee','Employee','employee','Operations','DEFAULT_USER','Employee@123']] as $person) {
  [$username,$name,$role,$department,$code,$password]=$person;
  $s=$p->prepare('SELECT e.id,e.role,a.password_hash FROM accounts a JOIN employees e ON e.id=a.employee_id WHERE a.username=? FOR UPDATE');$s->execute([$username]);$existing=$s->fetch(PDO::FETCH_ASSOC);
  if($existing) {
   if($existing['role']!==$role)throw new RuntimeException('Username '.$username.' belongs to a different role. No accounts were changed.');
   $id=(int)$existing['id'];
   if($reset){$s=$p->prepare('UPDATE accounts SET password_hash=? WHERE employee_id=?');$s->execute([password_hash($password,PASSWORD_DEFAULT),$id]);$s=$p->prepare('UPDATE employees SET active=1 WHERE id=?');$s->execute([$id]);}
  } else {
   $s=$p->prepare('INSERT INTO employees(employee_code,name,role,department) VALUES(?,?,?,?)');$s->execute([$code,$name,$role,$department]);$id=(int)$p->lastInsertId();
   $s=$p->prepare('INSERT INTO accounts(employee_id,username,password_hash) VALUES(?,?,?)');$s->execute([$id,$username,password_hash($password,PASSWORD_DEFAULT)]);
   $s=$p->prepare("INSERT INTO devices(employee_id,ip_address,label,status,created_at) VALUES(?,'127.0.0.1','XAMPP server localhost','approved',?)");$s->execute([$id,utc()]);
  }
  if($role==='admin')$adminId=$id;
 }
 $s=$p->prepare('INSERT INTO audit_log(actor_id,action,detail,created_at) VALUES(?,?,?,?)');$s->execute([$adminId,'default_accounts_configured',json_encode(['usernames'=>['tharaka','employee'],'passwords_reset'=>$reset]),utc()]);
 return $adminId;
}
