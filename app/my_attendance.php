<?php
declare(strict_types=1);
function personal_live_data(array $rows,string $date,int $now): array {
 $selected=array_values(array_filter($rows,fn($r)=>$r['work_date']===$date));
 $completed=0;$weekday=0;$active=null;
 $cutoff=(new DateTimeImmutable($date.' 17:30:00',tz()))->getTimestamp();
 foreach($selected as $r){$in=strtotime($r['checked_in'].' UTC');if($r['checked_out']){$out=strtotime($r['checked_out'].' UTC');$completed+=max(0,$out-$in);$weekday+=max(0,$out-max($in,$cutoff));}else $active=$in;}
 return ['completed'=>$completed,'weekday'=>$weekday,'active'=>$active,'cutoff'=>$cutoff,'weekend'=>weekend_work_date($date),'now'=>$now];
}
