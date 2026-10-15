<?php
declare(strict_types=1);
/** Disposable fixture only: no provider credentials or production storage. */
function sitesee_fixture_activate_live(string $private): void
{
    foreach (['runtime','runtime/live','runtime/live/data'] as $relative) mkdir($private.'/'.$relative,0700);
    $realm=str_repeat('a',32);$merchant='acct_SyntheticMerchant123';
    $control=['format'=>1,'stage'=>'LIVE','enabled'=>true,'realm'=>$realm,'stripe_account'=>$merchant,'initial_code_release'=>'unified-test-111111111111'];
    $write=static function(string $path,array $value):void{file_put_contents($path,json_encode($value,JSON_THROW_ON_ERROR));chmod($path,0600);};
    $write($private.'/application-live.json',$control);
    $write($private.'/live-activation-journal.json',['state'=>'active','realm'=>$realm,'stripe_account'=>$merchant,'initial_code_release'=>$control['initial_code_release']]);
    $write($private.'/runtime/live/environment.json',['stage'=>'LIVE','realm'=>$realm,'values'=>[
        'SITESEE_REAL_ESTATE_STRIPE_LIVE_SECRET'=>'sk_'.'live_'.str_repeat('0',32),
        'SITESEE_REAL_ESTATE_PRICING_GATE_SECRET'=>'isolated-live-finance-secret-'.str_repeat('1',32),
    ]]);
    $write($private.'/runtime/live/booking-lifecycle.json',['schema'=>1,'stage'=>'live','enabled'=>true,'recipient'=>'booking-customer']);
    $db=new PDO('sqlite:'.$private.'/runtime/live/data/bookings.sqlite');
    $db->exec('CREATE TABLE application_realm (singleton INTEGER PRIMARY KEY CHECK(singleton=1),stage TEXT NOT NULL,realm TEXT NOT NULL,stripe_account TEXT NOT NULL)');
    $db->prepare('INSERT INTO application_realm VALUES (1,?,?,?)')->execute(['LIVE',$realm,$merchant]);$db=null;
    chmod($private.'/runtime/live/data/bookings.sqlite',0600);
}
