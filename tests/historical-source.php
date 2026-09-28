<?php
/** Build a disposable copy of the actual pre-lifecycle release for historical diagnostics. */
function historical_source(): string {
    $base=dirname(__DIR__);$dir=sys_get_temp_dir().'/sitesee-historical-'.bin2hex(random_bytes(6));mkdir($dir,0700);mkdir($dir.'/server',0700);
    foreach(glob($base.'/_private/*.php')as$p)copy($p,$dir.'/'.basename($p));
    foreach(glob($base.'/_private/server/*.php')as$p){$f=__DIR__.'/fixtures/lifecycle-before/'.basename($p);copy(is_file($f)?$f:$p,$dir.'/server/'.basename($p));}
    register_shutdown_function(static function()use($dir){foreach(glob($dir.'/server/*')as$p)unlink($p);rmdir($dir.'/server');foreach(glob($dir.'/*')as$p)unlink($p);rmdir($dir);});
    return $dir;
}
