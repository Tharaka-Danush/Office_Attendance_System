<?php
declare(strict_types=1);
function verification_tables(): array {return ['employees','accounts','devices','attendance','punch_requests','corrections','audit_log','holidays','leave_requests','weekly_schedules','salary_months'];}
function verification_statements(string $sql): array {
 $out=[];$buffer='';$quote=null;$length=strlen($sql);
 for($i=0;$i<$length;$i++){
  $c=$sql[$i];
  if($quote!==null){$buffer.=$c;if($c==='\\'&&$quote!=="`"){if(++$i>=$length)throw new RuntimeException('Incomplete escape.');$buffer.=$sql[$i];continue;}if($c===$quote){if(($sql[$i+1]??null)===$quote){$buffer.=$sql[++$i];}else $quote=null;}continue;}
  if($c==='-'&&substr($sql,$i,3)==='-- '){while($i<$length&&$sql[$i]!=="\n")$i++;$buffer.="\n";continue;}
  if($c==="'"||$c==='"'||$c==='`'){$quote=$c;$buffer.=$c;continue;}
  if($c===';'){if(trim($buffer)!=='')$out[]=trim($buffer);$buffer='';continue;}$buffer.=$c;
 }
 if($quote!==null||trim($buffer)!=='')throw new RuntimeException('Incomplete backup statement.');
 return $out;
}
function verification_values(string $text): array {
 $values=[];$i=0;$n=strlen($text);
 while($i<$n){
  while($i<$n&&ctype_space($text[$i]))$i++;
  if(substr($text,$i,4)==='NULL'){$values[]=null;$i+=4;}
  elseif(($text[$i]??'')==="'"){
   $i++;$value='';$closed=false;
   while($i<$n){$c=$text[$i++];if($c==='\\'){if($i>=$n)throw new RuntimeException('Incomplete value.');$escaped=$text[$i++];$value.=match($escaped){'0'=>"\0",'n'=>"\n",'r'=>"\r",'Z'=>"\x1a",default=>$escaped};}
    elseif($c==="'"){if(($text[$i]??null)==="'"){$value.="'";$i++;}else{$closed=true;break;}}
    else $value.=$c;
   }
   if(!$closed)throw new RuntimeException('Incomplete value.');$values[]=$value;
  }else throw new RuntimeException('Only generated literal values are supported.');
  while($i<$n&&ctype_space($text[$i]))$i++;
  if($i===$n)break;if($text[$i++]!==','||$i===$n)throw new RuntimeException('Invalid value list.');
 }
 if(!$values)throw new RuntimeException('Empty values.');return $values;
}
function verification_plan(string $sql): array {
 $plan=[];$created=[];
 foreach(verification_statements($sql) as $statement){
  if(in_array($statement,['SET NAMES utf8mb4',"SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO'",'SET FOREIGN_KEY_CHECKS=0','SET FOREIGN_KEY_CHECKS=1'],true))continue;
  if(preg_match('/^CREATE TABLE(?: IF NOT EXISTS)? `([a-z_]+)`\s*\(.*\)\s*ENGINE=InnoDB(?:\s+AUTO_INCREMENT=\d+)?\s+DEFAULT CHARSET=\w+(?:\s+COLLATE=\w+)?$/sD',$statement,$m)){
   if(preg_match('/`\s*\.\s*`/',$statement)||!in_array($m[1],verification_tables(),true)||isset($created[$m[1]])||preg_match('/\b(?:SELECT|LOAD_FILE|OUTFILE|INFILE|TRIGGER|PROCEDURE|FUNCTION|EVENT)\b/i',$statement))throw new RuntimeException('Unexpected table definition.');
   $created[$m[1]]=true;$plan[]=['create',$m[1],$statement];continue;
  }
  if(preg_match('/^INSERT INTO `([a-z_]+)` VALUES\((.*)\)$/sD',$statement,$m)&&isset($created[$m[1]])){$plan[]=['insert',$m[1],verification_values($m[2])];continue;}
  throw new RuntimeException('Unsupported backup statement.');
 }
 if(array_diff(verification_tables(),array_keys($created)))throw new RuntimeException('Application tables missing from backup.');
 return $plan;
}
function verification_row_hash(array $values): string {return hash('sha256',serialize(array_map(fn($v)=>$v===null?null:(string)$v,$values)));}
