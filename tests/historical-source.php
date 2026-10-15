<?php
/** Build a disposable copy of the actual pre-lifecycle release for historical diagnostics. */
function historical_before_workspace(string $base, string $key): string {
    $bytes=file_get_contents($base.'/'.$key);
    $mode=json_decode(file_get_contents($base.'/documents/unified/mode-source.json'),true)['files'][$key]??null;
    if($mode){if(hash('sha256',$bytes)!==$mode['after_sha256'])throw new RuntimeException('Unknown historical mode edit');$bytes=base64_decode($mode['before'],true);if($bytes===false||hash('sha256',$bytes)!==$mode['before_sha256'])throw new RuntimeException('Invalid historical mode source');}
    $client=json_decode(file_get_contents($base.'/documents/unified/client-workspace-source.json'),true)['files'][$key]??null;
    if($client){if(hash('sha256',$bytes)!==$client['after_sha256'])throw new RuntimeException('Unknown historical client edit');$bytes=base64_decode($client['before'],true);if($bytes===false||hash('sha256',$bytes)!==$client['before_sha256'])throw new RuntimeException('Invalid historical client source');}
    $readiness=json_decode(file_get_contents($base.'/documents/unified/readiness-source.json'),true)['files'][$key]??null;
    if($readiness){if(hash('sha256',$bytes)!==$readiness['after_sha256'])throw new RuntimeException('Unknown historical readiness edit');$bytes=base64_decode($readiness['before'],true);if($bytes===false||hash('sha256',$bytes)!==$readiness['before_sha256'])throw new RuntimeException('Invalid historical readiness source');}
    $finance=json_decode(file_get_contents($base.'/documents/unified/finance-source.json'),true)['files'][$key]??null;
    if($finance){if(hash('sha256',$bytes)!==$finance['after_sha256'])throw new RuntimeException('Unknown historical financial edit');$bytes=base64_decode($finance['before'],true);if($bytes===false||hash('sha256',$bytes)!==$finance['before_sha256'])throw new RuntimeException('Invalid historical financial source');}
    $manifest=json_decode(file_get_contents($base.'/documents/unified/ux-workspace-source.json'),true);
    $change=$manifest['files'][$key]??null;
    if($change){
        if(hash('sha256',$bytes)!==$change['after_sha256'])throw new RuntimeException('Unknown historical workspace edit');
        $bytes=base64_decode($change['before'],true);
        if($bytes===false||hash('sha256',$bytes)!==$change['before_sha256'])throw new RuntimeException('Invalid historical workspace source');
    }
    return $bytes;
}
function historical_source(): string {
    $base=dirname(__DIR__);$dir=sys_get_temp_dir().'/sitesee-historical-'.bin2hex(random_bytes(6));mkdir($dir,0700);mkdir($dir.'/server',0700);
    $workflow=json_decode(file_get_contents($base.'/documents/unified/account-workflow-source.json'),true);
    foreach(glob($base.'/_private/*.php')as$p){$key='_private/'.basename($p);$bytes=historical_before_workspace($base,$key);$change=$workflow['files'][$key]??null;if($change&&!$change['added']){if(hash('sha256',$bytes)!==$change['after_sha256'])throw new RuntimeException('Unknown historical pricing edit');$bytes=base64_decode($change['before'],true);}file_put_contents($dir.'/'.basename($p),$bytes);}
    foreach(glob($base.'/_private/server/*.php')as$p){$f=__DIR__.'/fixtures/lifecycle-before/'.basename($p);if(is_file($f))copy($f,$dir.'/server/'.basename($p));else { $key='_private/server/'.basename($p);$bytes=historical_before_workspace($base,$key);$change=$workflow['files'][$key]??null; if($change&&!$change['added']){if(hash('sha256',$bytes)!==$change['after_sha256'])throw new RuntimeException('Unknown historical source edit');$bytes=base64_decode($change['before'],true);}file_put_contents($dir.'/server/'.basename($p),$bytes); }}
    register_shutdown_function(static function()use($dir){foreach(glob($dir.'/server/*')as$p)unlink($p);rmdir($dir.'/server');foreach(glob($dir.'/*')as$p)unlink($p);rmdir($dir);});
    return $dir;
}
