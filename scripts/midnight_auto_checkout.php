<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit('CLI only');}
require dirname(__DIR__).'/app/core.php';
$at=gmdate('c');$status='failed';$count=0;$error='';
try { $count=auto_checkout_overdue();$status='success';echo "Midnight auto-checkout completed. Closed {$count} stale session(s).\n"; }
catch(Throwable $e){$error=$e->getMessage();fwrite(STDERR,'Auto-checkout failed: '.$error.PHP_EOL);}
// Store only task metadata. This directory is outside the Apache document root.
$dir='C:/xampp/attendance-backups';
if(!is_dir($dir) && !@mkdir($dir,0700,true) && !is_dir($dir)) {
 fwrite(STDERR, 'Could not create midnight status directory: '.$dir.PHP_EOL);
 $status='failed';
}
if(is_dir($dir)&&is_writable($dir)) {
 $report=['checked_at_utc'=>$at,'status'=>$status,'closed_count'=>$count,'error'=>$error];
 if(@file_put_contents($dir.'/midnight_checkout_status.json',json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),LOCK_EX)===false){fwrite(STDERR,'Could not write midnight status report.'.PHP_EOL);$status='failed';}
} else {fwrite(STDERR,'Midnight status directory is not writable.'.PHP_EOL);$status='failed';}
exit($status==='success'?0:1);
