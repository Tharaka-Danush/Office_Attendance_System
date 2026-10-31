<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Command line only.');}
require dirname(__DIR__).'/app/core.php';
try{
 $c=config();$file=getenv('ATTENDANCE_CONFIG')?:dirname(__DIR__).'/config.php';
 $cidr=trim((string)getenv('ATTENDANCE_OFFICE_CIDR'));
 if(!preg_match('/^([0-9.]+)\/(\d{1,2})$/D',$cidr,$m)||!filter_var($m[1],FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)||(int)$m[2]<16||(int)$m[2]>32)throw new RuntimeException('Enter your actual office IPv4 CIDR, prefix /16 to /32.');
 if(!preg_match('/^mysql:host=127\.0\.0\.1;port=(\d+);dbname=([A-Za-z0-9_]+);charset=utf8mb4$/D',$c['dsn'],$dsn))throw new RuntimeException('Expected local MySQL DSN. Keep host=127.0.0.1 and specify port/dbname.');
 if(!preg_match('/^(10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)/',$m[1]))throw new RuntimeException('Office CIDR must use a private LAN address.');
 $c['allowed_networks']=array_values(array_unique([...($c['allowed_networks']??[]),'127.0.0.1/32',$cidr]));
 $admin=getenv('ATTENDANCE_DB_ADMIN_USER');if(!$admin)throw new RuntimeException('Run SETUP_OFFICE_SERVER.bat to provide database administrator credentials.');
 $p=new PDO('mysql:host=127.0.0.1;port='.$dsn[1].';charset=utf8mb4',$admin,(string)getenv('ATTENDANCE_DB_ADMIN_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
 // Provision a new isolated account; existing account passwords are never reset.
 $user='att_app_'.bin2hex(random_bytes(5));$password=bin2hex(random_bytes(24));$database=$dsn[2];$created=false;
 $p->exec('CREATE USER '.$p->quote($user)."@'127.0.0.1' IDENTIFIED BY ".$p->quote($password));$created=true;
 try{
  $p->exec('GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP, REFERENCES ON `'.$database.'`.* TO '.$p->quote($user)."@'127.0.0.1'");
  new PDO($c['dsn'],$user,$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
  $c['db_user']=$user;$c['db_password']=$password;
  $text="<?php\nreturn ".var_export($c,true).";\n";$temp=$file.'.tmp';
  if(file_put_contents($temp,$text,LOCK_EX)!==strlen($text))throw new RuntimeException('Cannot write configuration.');chmod($temp,0600);
  if(!copy($file,$file.'.previous.php'))throw new RuntimeException('Cannot preserve previous configuration.');chmod($file.'.previous.php',0600);
  if(!rename($temp,$file))throw new RuntimeException('Cannot replace configuration.');
 }catch(Throwable $x){$p->exec('DROP USER '.$p->quote($user)."@'127.0.0.1'");@unlink($file.'.tmp');throw $x;}
 echo "Office network saved. Application database account created for this database only.\nExisting employee accounts and attendance are unchanged.\n";
}catch(Throwable $x){fwrite(STDERR,"Setup failed. Check CIDR, database credentials and writable config.php.\n");exit(1);}
