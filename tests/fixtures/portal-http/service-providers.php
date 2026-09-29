<?php
declare(strict_types=1);
// Loaded only by the isolated CLI fixture router after its temp-root guard.
function portal_fixture_calendar(string $method,string $path,?array $body=null,?string $etag=null): array
{
    $file=getenv('PORTAL_TEST_PRIVATE').'/data/provider-events.json';$events=is_file($file)?json_decode(file_get_contents($file),true):[];
    if($method==='POST'){$id='isolated-event';$event=$body+['id'=>$id,'@odata.etag'=>'W/"v1"','organizer'=>['emailAddress'=>['address'=>BOOKING_MS_MAILBOX]],'isCancelled'=>false,'type'=>'singleInstance'];$events[$id]=$event;file_put_contents($file,json_encode($events));return ['status'=>201,'body'=>$event];}
    if(in_array($method,['PATCH','DELETE'],true)){
        $id=rawurldecode(basename($path));if($etag!==($events[$id]['@odata.etag']??null))throw new RuntimeException('Fixture etag mismatch');
        if($method==='PATCH'){$events[$id]=array_replace($events[$id],$body);$events[$id]['@odata.etag']='W/"v2"';$out=['status'=>200,'body'=>$events[$id]];}else{unset($events[$id]);$out=['status'=>204,'body'=>[]];}file_put_contents($file,json_encode($events));return $out;
    }
    if($method!=='GET')throw new RuntimeException('Unexpected fixture request');
    if(str_contains($path,'calendarView'))return ['status'=>200,'body'=>['value'=>array_values($events)]];
    if(str_contains($path,'/events/')){$id=rawurldecode(basename($path));return isset($events[$id])?['status'=>200,'body'=>$events[$id]]:['status'=>404,'body'=>['error'=>['code'=>'ErrorItemNotFound']]];}
    return ['status'=>200,'body'=>['id'=>BOOKING_MS_CALENDAR,'canEdit'=>true,'isDefaultCalendar'=>true,'owner'=>['address'=>BOOKING_MS_MAILBOX]]];
}
function booking_lifecycle_connection(array $claim): callable {return 'portal_fixture_calendar';}
function portal_stripe(string $method,string $path,array $body=[],string $key=''): array
{
    $db=booking_db();$ref='DDDD000001';$row=booking_get($db,$ref);$amount=(int)$row['deposit_cents'];
    if($path==='/checkout/sessions/cs_test_http_deposit')return ['id'=>'cs_test_http_deposit','livemode'=>false,'mode'=>'payment','status'=>'complete','payment_status'=>'paid','currency'=>'usd','amount_total'=>$amount,'client_reference_id'=>$ref,'metadata'=>['booking_reference'=>$ref],'customer'=>'cus_http','payment_intent'=>'pi_http'];
    if($path==='/payment_intents/pi_http')return ['id'=>'pi_http','livemode'=>false,'customer'=>'cus_http','status'=>'succeeded','currency'=>'usd','amount_received'=>$amount,'latest_charge'=>'ch_http'];
    if($path==='/charges/ch_http')return ['id'=>'ch_http','livemode'=>false,'customer'=>'cus_http','payment_intent'=>'pi_http','paid'=>true,'captured'=>true,'currency'=>'usd','amount'=>$amount,'amount_captured'=>$amount,'amount_refunded'=>0,'disputed'=>false,'receipt_url'=>'https://pay.stripe.com/receipts/payment/synthetic-http'];
    if($path==='/checkout/sessions'&&$method==='POST')return ['id'=>'cs_test_http_balance','livemode'=>false,'mode'=>'payment','status'=>'open','payment_status'=>'unpaid','currency'=>'usd','amount_total'=>(int)$body['line_items[0][price_data][unit_amount]'],'client_reference_id'=>$ref,'metadata'=>['booking_reference'=>$ref,'portal_payment_kind'=>'balance','portal_attempt'=>$body['metadata[portal_attempt]']],'customer'=>'cus_http','url'=>'https://checkout.stripe.com/c/pay/synthetic-http'];
    throw new RuntimeException('Fixture blocked unrecognized provider request');
}
