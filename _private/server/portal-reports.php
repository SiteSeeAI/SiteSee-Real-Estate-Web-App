<?php
declare(strict_types=1);
require_once __DIR__.'/portal-report-pdf.php';

/** Inclusive Central calendar dates; end is exclusive to handle DST correctly. */
function portal_report_range(array $input,?DateTimeImmutable $now=null): array
{
    $zone=new DateTimeZone('America/Chicago');$now=($now??new DateTimeImmutable('now',$zone))->setTimezone($zone);
    $period=$input['period']??'month';
    if(!is_string($period)||!in_array($period,['month','quarter','year','custom'],true))throw new InvalidArgumentException('Choose a report period.');
    if($period==='custom'){
        $dates=[];
        foreach(['from','to'] as $key){$text=$input[$key]??'';
            if(!is_string($text)||!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$text))throw new InvalidArgumentException('Choose valid start and end dates.');
            $date=DateTimeImmutable::createFromFormat('!Y-m-d',$text,$zone);
            if(!$date||$date->format('Y-m-d')!==$text)throw new InvalidArgumentException('Choose valid start and end dates.');$dates[]=$date;
        }
        [$start,$last]=$dates;
        if($last<$start||$last->getTimestamp()-$start->getTimestamp()>3660*86400)throw new InvalidArgumentException('Choose a date range of up to ten years, with the start before the end.');
    }else{
        $year=(int)$now->format('Y');$month=match($period){'year'=>1,'quarter'=>1+3*intdiv((int)$now->format('n')-1,3),default=>(int)$now->format('n')};
        $start=$now->setDate($year,$month,1)->setTime(0,0);$last=$start->modify(match($period){'year'=>'+1 year','quarter'=>'+3 months',default=>'+1 month'})->modify('-1 day');
    }
    return ['period'=>$period,'from'=>$start->format('Y-m-d'),'to'=>$last->format('Y-m-d'),'start'=>$start->getTimestamp(),'end'=>$last->modify('+1 day')->getTimestamp()];
}
/** Local, account-owned recorded cash ledger. Navigation never contacts or writes a provider. */
function portal_expense_report(PDO $db,string $account,array $range): array
{
    if(!portal_active_account($db,$account))throw new InvalidArgumentException('Sign in again.');
    $q=$db->prepare('SELECT b.* FROM portal_order_owners o JOIN portal_accounts a ON a.id=o.account_id AND a.disabled=0 JOIN bookings b ON b.reference=o.reference WHERE o.account_id=? ORDER BY b.created_at,b.reference');$q->execute([$account]);
    $out=['range'=>$range,'entries'=>[],'paid'=>0,'refunded'=>0,'credit'=>0,'net'=>0,'review'=>0,'incomplete'=>false];$seen=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){
        $property=portal_property(booking_request($row)['details']??[]);$number=booking_order_number($db,$row['reference']);
        $add=static function(string $kind,int $amount,int $when,string $key)use(&$out,&$seen,$range,$row,$property,$number):void{
            if($amount<=0||$when<$range['start']||$when>=$range['end']||isset($seen[$key]))return;
            $seen[$key]=true;$out[$kind]+=$amount;
            $out['entries'][]=['date'=>(new DateTimeImmutable('@'.$when))->setTimezone(new DateTimeZone('America/Chicago'))->format('Y-m-d'),'order_number'=>$number,'reference'=>$row['reference'],'property'=>$property,'kind'=>$kind,'amount'=>$amount,'time'=>$when];
        };
        $funding=booking_finance_funding($db,$row['reference']);$deposit=(int)($funding['cash_deposit']??$row['deposit_cents']);
        if($row['deposit_paid_at']&&$deposit>0&&$row['stripe_payment_intent_id'])$add('paid',$deposit,(int)strtotime($row['deposit_paid_at']),'cash:'.$row['stripe_payment_intent_id']);
        $b=$db->prepare("SELECT * FROM portal_balance_attempts WHERE reference=? AND paid_at IS NOT NULL AND state='paid'");$b->execute([$row['reference']]);
        foreach($b->fetchAll(PDO::FETCH_ASSOC) as $balance)if($balance['payment_intent'])$add('paid',(int)$balance['amount'],(int)strtotime($balance['paid_at']),'cash:'.$balance['payment_intent']);
        $job=booking_job_get($db,$row['reference']);
        if($job&&$job['paid_at']&&$job['payment_intent']){
            $add('paid',(int)$job['amount'],(int)strtotime($job['paid_at']),'cash:'.$job['payment_intent']);
            if(($job['disputed']||$job['refunded'])&&(int)strtotime($job['paid_at'])<$range['end']){$out['review']++;$out['incomplete']=true;}
        }
        $refunds=$db->prepare('SELECT r.*,d.verified_at FROM booking_finance_refunds r LEFT JOIN booking_finance_refund_dates d ON d.refund_id=r.refund_id JOIN booking_finance_cancellations c ON c.operation_id=r.operation_id WHERE c.reference=?');$refunds->execute([$row['reference']]);
        foreach($refunds->fetchAll(PDO::FETCH_ASSOC) as $refund){
            if($refund['state']==='succeeded'&&$refund['refund_id']&&$refund['verified_at']!==null)$add('refunded',(int)$refund['amount'],(int)$refund['verified_at'],'refund:'.$refund['refund_id']);
            elseif($refund['state']==='succeeded'&&(int)$refund['created_at']<$range['end']){$out['review']++;$out['incomplete']=true;}
            elseif((int)$refund['created_at']<$range['end'])$out['review']++;
        }
        $credits=$db->prepare("SELECT f.* FROM booking_finance_allocations f JOIN booking_finance_credits c ON c.id=f.credit_id WHERE f.reference=? AND c.account_id=? AND f.state='used'");$credits->execute([$row['reference'],$account]);
        foreach($credits->fetchAll(PDO::FETCH_ASSOC) as $credit){
            // A reservation may predate its payment. Attribute applied credit to the recorded payment.
            $when=$credit['phase']==='deposit'?strtotime($row['deposit_paid_at']??''):($job?strtotime($job['completed_at']):false);
            if($when===false){$latest=portal_billing_latest($db,$row['reference']);$when=$latest&&$latest['paid_at']?strtotime($latest['paid_at']):(int)$credit['created_at'];}
            $add('credit',(int)$credit['amount'],(int)$when,'credit:'.$credit['id']);
        }
        $plan=booking_finance_plan($db,$row['reference']);if($plan&&$plan['state']==='review')$out['review']++;
    }
    usort($out['entries'],static fn($a,$b)=>[$a['time'],$a['order_number'],$a['kind']]<=>[$b['time'],$b['order_number'],$b['kind']]);
    $out['net']=$out['incomplete']?null:$out['paid']-$out['refunded'];return $out;
}
function portal_report_notes(): string
{
    return 'USD · Central Time · TEST records. Cash totals use recorded payment dates; confirmed cancellation refunds use their first verified confirmation dates. Refunds with missing date evidence and undated manual adjustments require Billing review; their net expense total is unavailable. Applied credit is shown separately and is not added to cash expenses. Estimates, pending payments and pending refunds are excluded. Check Billing for manual adjustments, disputes and unresolved payments before using these records for tax accounting.';
}
function portal_reports_page(PDO $db,array $account,array $input): never
{
    $e='portal_escape';
    try{$range=portal_report_range($input);$report=portal_expense_report($db,$account['id'],$range);}
    catch(InvalidArgumentException $error){portal_page('Reports','<p class="notice" role="alert">'.$e($error->getMessage()).'</p><p><a href="/account.php?view=reports">Choose A Report Period</a></p>',$account,422);}
    if(($input['download']??'')==='pdf'){
        try{$pdf=portal_report_pdf($report);}catch(InvalidArgumentException $error){portal_page('Reports','<p class="notice" role="alert">'.$e($error->getMessage()).'</p><p><a href="'.$e('/account.php?'.http_build_query(['view'=>'reports','period'=>'custom','from'=>$range['from'],'to'=>$range['to']])).'">Return To On-Screen Report</a></p>',$account,422);}
        header('Content-Type: application/pdf');header('Content-Disposition: attachment; filename="SiteSee-expenses-'.$range['from'].'-to-'.$range['to'].'.pdf"');header('Content-Length: '.strlen($pdf));echo $pdf;exit;
    }
    $body='<p class="lead">Review your recorded expenses by month, quarter, year or a custom date range.</p><section class="panel"><form method="get" action="/account.php" class="report-filters"><input type="hidden" name="view" value="reports"><label>Report Period<select name="period" data-report-period>';
    foreach(['month'=>'Current Month','quarter'=>'Current Quarter','year'=>'Current Year','custom'=>'Custom Date Range'] as $key=>$label)$body.='<option value="'.$key.'"'.($range['period']===$key?' selected':'').'>'.$label.'</option>';
    $body.='</select></label><div data-report-dates class="report-filters"><label>From<input type="date" name="from" value="'.$range['from'].'" required></label><label>Through<input type="date" name="to" value="'.$range['to'].'" required></label></div><button>Run Report</button></form></section>';
    $body.='<section class="panel"><h2>Expenses · '.$range['from'].' – '.$range['to'].'</h2><dl class="report-totals">';
    foreach(['paid'=>'Cash Paid','refunded'=>'Confirmed Refunds','net'=>'Net Cash Expenses','credit'=>'Credit Applied'] as $key=>$label)$body.='<div><dt>'.$label.'</dt><dd>'.($report[$key]===null?'Needs Billing Review':real_estate_money($report[$key])).'</dd></div>';
    $body.='</dl><p class="help">'.$e(portal_report_notes()).'</p>';
    if($report['review'])$body.='<p class="notice" role="status">Some records need billing review. Pending refunds are not deducted; missing refund dates or undated adjustments prevent a reliable net expense total.</p>';
    $body.='<p><a class="step-primary" href="'.$e('/account.php?'.http_build_query(['view'=>'reports','period'=>'custom','from'=>$range['from'],'to'=>$range['to'],'download'=>'pdf'])).'">PDF Download</a></p><div class="report-table-wrap"><table class="report-table"><thead><tr><th scope="col">Date</th><th scope="col">Order / Property</th><th scope="col">Activity</th><th scope="col" class="amount">Amount</th></tr></thead><tbody>';
    foreach($report['entries'] as $entry)$body.='<tr><td>'.$entry['date'].'</td><td><a href="/account.php?view=billing&amp;reference='.$e($entry['reference']).'">'.$e($entry['order_number']).'</a><small>'.$e($entry['property']).'</small></td><td>'.match($entry['kind']){'paid'=>'Cash payment','refunded'=>'Confirmed refund',default=>'Credit applied'}.'</td><td class="amount">'.real_estate_money($entry['amount']).'</td></tr>';
    if(!$report['entries'])$body.='<tr><td colspan="4">No recorded expenses in this date range.</td></tr>';
    portal_page('Reports',$body.'</tbody></table></div></section>',$account);
}
