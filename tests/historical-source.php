<?php
/** Build a disposable copy of the actual pre-lifecycle release for historical diagnostics. */
function historical_source(): string {
    $base=dirname(__DIR__);$dir=sys_get_temp_dir().'/sitesee-historical-'.bin2hex(random_bytes(6));mkdir($dir,0700);mkdir($dir.'/server',0700);
    $workflow=json_decode(file_get_contents($base.'/documents/unified/account-workflow-source.json'),true);
    foreach(glob($base.'/_private/*.php')as$p){$change=$workflow['files']['_private/'.basename($p)]??null;if($change&&!$change['added']){if(hash_file('sha256',$p)!==$change['after_sha256'])throw new RuntimeException('Unknown historical pricing edit');file_put_contents($dir.'/'.basename($p),base64_decode($change['before'],true));}else copy($p,$dir.'/'.basename($p));}
    foreach(glob($base.'/_private/server/*.php')as$p){$f=__DIR__.'/fixtures/lifecycle-before/'.basename($p);if(is_file($f))copy($f,$dir.'/server/'.basename($p));else { $key='_private/server/'.basename($p);$change=$workflow['files'][$key]??null; if($change&&!$change['added']){if(hash_file('sha256',$p)!==$change['after_sha256'])throw new RuntimeException('Unknown historical source edit');file_put_contents($dir.'/server/'.basename($p),base64_decode($change['before'],true));}else copy($p,$dir.'/server/'.basename($p)); }}
    register_shutdown_function(static function()use($dir){foreach(glob($dir.'/server/*')as$p)unlink($p);rmdir($dir.'/server');foreach(glob($dir.'/*')as$p)unlink($p);rmdir($dir);});
    return $dir;
}
