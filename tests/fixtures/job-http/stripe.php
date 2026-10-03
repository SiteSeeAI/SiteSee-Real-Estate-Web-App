<?php
declare(strict_types=1);
function booking_job_stripe(string $method,string $path,array $body=[],string $key=''):array{return portal_stripe($method,$path,$body,$key);}
function portal_stripe(string $method,string $path,array $body=[],string $key=''):array
{
    $file=getenv('PORTAL_TEST_PRIVATE').'/data/job-provider.json';
    $state=is_file($file)?json_decode(file_get_contents($file),true):['creates'=>0,'confirms'=>0,'paid'=>false,'refunded'=>0];
    $save=static function()use($file,&$state):void{file_put_contents($file,json_encode($state));};
    if($path==='/payment_intents/pi_http')return portal_fixture_base_stripe($method,$path)+['setup_future_usage'=>'off_session','payment_method'=>'pm_saved'];
    if($path==='/payment_methods/pm_saved')return ['id'=>'pm_saved','type'=>'card','customer'=>'cus_http','livemode'=>false];
    if($method==='POST'&&$path==='/payment_intents'){
        ++$state['creates'];$state['intent']=['id'=>'pi_job_http','livemode'=>false,'amount'=>(int)$body['amount'],'currency'=>'usd','customer'=>$body['customer'],'metadata'=>['booking_reference'=>$body['metadata[booking_reference]'],'portal_payment_kind'=>$body['metadata[portal_payment_kind]'],'job_scope'=>$body['metadata[job_scope]']],'status'=>'requires_payment_method','client_secret'=>'synthetic_http_job_secret','amount_received'=>0,'latest_charge'=>null];$save();return $state['intent'];
    }
    if($method==='POST'&&$path==='/payment_intents/pi_job_http/confirm'){++$state['confirms'];$save();return $state['intent'];}
    if($path==='/payment_intents/pi_job_http')return array_replace($state['intent'],$state['paid']?['status'=>'succeeded','amount_received'=>$state['intent']['amount'],'latest_charge'=>'ch_job_http']:[]);
    if($path==='/charges/ch_job_http')return ['id'=>'ch_job_http','livemode'=>false,'customer'=>'cus_http','payment_intent'=>'pi_job_http','paid'=>true,'captured'=>true,'currency'=>'usd','amount'=>$state['intent']['amount'],'amount_captured'=>$state['intent']['amount'],'amount_refunded'=>$state['refunded'],'disputed'=>false,'receipt_url'=>'https://pay.stripe.com/receipts/synthetic-job'];
    return portal_fixture_base_stripe($method,$path,$body,$key);
}
