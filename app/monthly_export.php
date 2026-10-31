<?php
declare(strict_types=1);
function monthly_csv_header(): array {
 return ['Employee ID','Name','Department','Work date','First check in','Last check out','Sessions','Completed net hours (HH:MM)','Late minutes','Weekday OT (HH:MM)','Weekend OT first 8h (HH:MM)','Weekend OT after 8h (HH:MM)','Total OT (HH:MM)','Status','Holiday','Approved leave','Leave days','OT state','Pending correction requests'];
}
function monthly_csv_rows(array $calendar,array $person,array $pending): Generator {
 foreach(array_reverse($calendar) as $c){
  $d=$c['day'];$leave=[];
  foreach($c['leaves'] as $item)$leave[]=ucfirst($item['leave_type']).' · '.leave_part_label($item['leave_part']);
  $ot=fn($key)=>$d&&$d[$key]!==null?hours($d[$key]):'';
  $status=$c['status'].($d&&!$d['open']&&$d['late']>0?' · Late':'');
  $fields=[$person['employee_code'],$person['name'],$person['department'],$c['date'],
   $d?local_time($d['first']):'',
   $d&&$d['last']?local_time($d['last']):($d&&$d['open']?'Pending checkout':''),
   $d?$d['sessions']:0,$d?hours($d['net']):'',$d?$d['late']:'',
   $ot('weekday_ot'),$ot('single_ot'),$ot('double_ot'),$ot('ot'),$status,$c['holiday'],
   implode(' / ',$leave),$c['leave_units'],$d?($d['open']?'Pending':'Finalized'):'No attendance',
   implode(' / ',array_map(fn($x)=>'#'.$x['id'],$pending[$c['date']]??[]))];
  // Text in a CSV must not become an executable spreadsheet formula.
  yield array_map(fn($x)=>preg_match('/^[=+@\-\t\r\n]/',(string)$x)?"'".$x:$x,$fields);
 }
}
