<?php
declare(strict_types=1);

// Translate old bookmarked filters without changing the authenticated employee scope.
function monthly_legacy_options(array $input): ?array {
 if(!isset($input['daily_from'])&&!isset($input['daily_to'])&&!isset($input['export_scope']))return null;
 $output=array_filter(array_intersect_key($input,array_flip(['employee','month','summary_from','summary_to','export'])),'is_scalar');
 if(isset($input['daily_from'])||isset($input['daily_to'])){
  try{
   if(!is_string($input['daily_from']??null)||!is_string($input['daily_to']??null))throw new RuntimeException('Invalid dates.');
   $from=valid_date((string)($input['daily_from']??''));$to=valid_date((string)($input['daily_to']??''));
   if($from>$to||strtotime($to)-strtotime($from)>366*86400)throw new RuntimeException('Invalid range.');
   $output['summary_from']=$from;$output['summary_to']=$to;unset($output['month']);
  }catch(RuntimeException $x){unset($output['month'],$output['summary_from'],$output['summary_to'],$output['export']);}
 }
 return $output;
}
