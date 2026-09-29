<?php
declare(strict_types=1);
require __DIR__.'/../_private/server/portal-release.php';
$p=sys_get_temp_dir().'/portal-release-'.bin2hex(random_bytes(10));
function check(bool $value): void {if(!$value)throw new RuntimeException('Release gate check failed.');}
try {
    check(!portal_release_enabled($p));
    foreach(['', '{}', '{broken', json_encode(['release'=>'portal-20260929-r2','stage'=>'LIVE','enabled'=>true]), json_encode(['release'=>'portal-20260929-r2','stage'=>'TEST','enabled'=>false])] as $bad){file_put_contents($p,$bad);chmod($p,0600);check(!portal_release_enabled($p));}
    file_put_contents($p,json_encode(['release'=>'portal-20260929-r2','stage'=>'TEST','enabled'=>true]));chmod($p,0600);check(portal_release_enabled($p));
    chmod($p,0644);check(!portal_release_enabled($p));chmod($p,0600);
    symlink($p,$p.'-link');check(!portal_release_enabled($p.'-link'));
    echo "PASS portal release gate\n";
}finally {@unlink($p.'-link');@unlink($p);}
