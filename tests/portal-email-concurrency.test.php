<?php
declare(strict_types=1);
require __DIR__.'/../_private/server/portal-email.php';
function ensure(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$dir=sys_get_temp_dir().'/sitesee-email-race-'.bin2hex(random_bytes(8));mkdir($dir,0700);$path=$dir.'/fixture.sqlite';
$connect=static function()use($path):PDO{$db=new PDO('sqlite:'.$path,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$db->exec('PRAGMA busy_timeout=5000');return $db;};
$secret=str_repeat('fixture-secret-',4);$one=str_repeat('a',32);$two=str_repeat('b',32);$now=time();
try {
 $db=$connect();portal_access_schema($db);portal_phone_schema($db);portal_email_schema($db);
 foreach([[$one,'one@example.com','3125550100'],[$two,'two@example.com','3125550101']] as [$id,$email,$phone]){
  $db->prepare('INSERT INTO portal_accounts VALUES (?,?,?,0)')->execute([$id,$email,$now]);portal_phone_enroll($db,$email,$phone,'Synthetic staff verification',static fn()=>true,$now);
 }
 $challenges=[];
 foreach([[$one,'one@example.com','+13125550100'],[$two,'two@example.com','+13125550101']] as [$id,$email,$phone]){
  $identity=portal_phone_identity($db,$phone);$_SESSION=['portal_phone'=>['phone'=>$phone,'revision'=>$identity['revision']]];$code=null;
  $challenge=portal_email_request($db,$id,$email,'shared@example.com','fixture-'.$id,$secret,static function($email,$value)use(&$code):bool{$code=$value;return true;},$now);
  $challenges[]=[$id,$challenge,$code,$_SESSION];
 }
 $race=static function(array $entries,string $label)use($connect,$secret,$dir,$now,&$db):array{
  $db=null;$children=[];
  foreach($entries as $i=>[$id,$challenge,$code,$session]){
   $pid=pcntl_fork();ensure($pid!==-1,'Fork failed');
   if($pid===0){
    try{$_SESSION=$session;$child=$connect();file_put_contents($dir.'/ready-'.$label.'-'.$i,'1');$deadline=microtime(true)+5;
     while(!is_file($dir.'/go-'.$label)){if(microtime(true)>$deadline)throw new RuntimeException('Barrier timeout');usleep(1000);}
     $result=portal_email_confirm($child,$id,$challenge,$code,$secret,$now+1);file_put_contents($dir.'/result-'.$label.'-'.$i,$result?'1':'0');exit(0);
    }catch(Throwable $e){fwrite(STDERR,$e->getMessage());exit(1);}
   }$children[]=$pid;
  }
  $deadline=microtime(true)+5;
  while(count(glob($dir.'/ready-'.$label.'-*'))<count($entries)){ensure(microtime(true)<$deadline,'Parent barrier timeout');usleep(1000);}
  file_put_contents($dir.'/go-'.$label,'1');foreach($children as $pid){pcntl_waitpid($pid,$status);ensure(pcntl_wexitstatus($status)===0,'Worker failed');}
  $db=$connect();return array_map(static fn($p)=>file_get_contents($p),glob($dir.'/result-'.$label.'-*'));
 };
 $result=$race($challenges,'duplicate');ensure(array_sum($result)===1,'Exactly one account wins duplicate address race');
 ensure((int)$db->query("SELECT COUNT(*) FROM portal_accounts WHERE email='shared@example.com'")->fetchColumn()===1,'Unique target owner');
 $winner=$result[0]==='1'?0:1;[$id,,$code,$session]=$challenges[$winner];$_SESSION=$session;
 $new=portal_email_request($db,$id,'shared@example.com','replay@example.com','fixture-new',$secret,static function($email,$value)use(&$code):bool{$code=$value;return true;},$now+61);
 $result=$race([[$id,$new,$code,$session],[$id,$new,$code,$session]],'replay');ensure(array_sum($result)===1,'Concurrent replay succeeds once');
 echo "portal-email-concurrency: PASS (separate-process duplicate activation and one-use replay)\n";
}finally{$db=null;foreach(glob($dir.'/*')?:[] as $file)unlink($file);rmdir($dir);}
