<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/core.php';
require dirname(__DIR__).'/app/defaults.php';
try {
 if(installation_state()!=='ready')throw new RuntimeException('Open the system at localhost and finish setup/upgrade first.');
 $p=db();$p->beginTransaction();provision_default_accounts($p,true);$p->commit();
 echo "Default accounts are ready.\nAdmin: tharaka / Tharaka@123\nUser: employee / Employee@123\nOnly these two passwords were set. Attendance records were preserved.\n";
}catch(Throwable $x){if(isset($p)&&$p->inTransaction())$p->rollBack();fwrite(STDERR,$x instanceof PDOException?"Could not update accounts. Start MySQL and check config.php and employee codes.\n":$x->getMessage()."\n");exit(1);}
