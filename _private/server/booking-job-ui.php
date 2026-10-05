<?php
declare(strict_types=1);
require_once __DIR__.'/portal-billing.php';

function staff_job_input(string $key,int $max=2048): string
{
    $value=$_POST[$key]??'';if(!is_string($value)||strlen($value)>$max)throw new InvalidArgumentException('Invalid job entry.');return $value;
}
function staff_job_action(PDO $db,string $action): string
{
    $ref=staff_job_input('reference',32);$scope=staff_job_input('scope',64);
    if($action==='job_save'){
        $onsite=staff_job_onsite_input();$db->exec('BEGIN IMMEDIATE');
        try{
            $preview=booking_job_onsite_preview($db,$ref,$onsite['items'],$onsite['draft_scope']);
            if(!hash_equals($preview['bill']['scope'],$scope))throw new InvalidArgumentException('Review the current service total before saving.');
            booking_job_write_draft($db,$preview['draft']);$db->exec('COMMIT');
        }catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
        return 'Additional services saved. Confirm the agent’s verbal approval and complete the job when the onsite work is finished.';
    }
    if($action==='job_complete'){
        $job=booking_job_complete($db,$ref,$scope,staff_job_input('agreed',8)==='yes',null,staff_job_onsite_input());
        return $job['payment_state']==='paid'?'Onsite work complete. Payment verified. Job moved to Production.':'Onsite work complete. Job moved to Production. Review the final payment status below.';
    }
    if($action==='job_recover'){
        $job=booking_job_collect($db,$ref);return $job['payment_state']==='paid'?'Final payment verified.':'The saved payment was checked. Review its status below.';
    }
    if(in_array($action,['job_production_save','job_production_complete'],true)){
        $revision=staff_job_input('revision',10);if(!ctype_digit($revision))throw new InvalidArgumentException('Refresh the production page.');
        booking_job_production($db,$ref,(int)$revision,$_POST,$action==='job_production_complete',staff_job_input('agreed',8)==='yes');
        return $action==='job_production_complete'?'Production complete. The finished links become available in My Orders after payment verification.':'Production progress saved. These draft links are not published.';
    }
    throw new InvalidArgumentException('Unknown job action.');
}
function staff_job_onsite_input(): array
{
    try{$items=json_decode(staff_job_input('items',24000),true,16,JSON_THROW_ON_ERROR);}
    catch(JsonException){throw new InvalidArgumentException('Review the additional services before continuing.');}
    if(!is_array($items))throw new InvalidArgumentException('Review the additional services before continuing.');
    return ['items'=>$items,'draft_scope'=>staff_job_input('draft_scope',64)];
}
function staff_job_preview_response(PDO $db): never
{
    header('Content-Type: application/json; charset=utf-8');
    try{
        $input=staff_job_onsite_input();
        $preview=booking_job_onsite_preview($db,staff_job_input('reference',32),$input['items'],$input['draft_scope']);
        echo json_encode(['bill'=>$preview['bill']],JSON_THROW_ON_ERROR);
    }catch(Throwable $e){http_response_code(400);echo json_encode(['error'=>$e instanceof InvalidArgumentException?$e->getMessage():'The additional service prices could not be checked. Reload and try again.']);}
    exit;
}
function staff_job_form(string $ref,string $action,string $fields,string $label): string
{
    return '<form method="post"><input type="hidden" name="csrf" value="'.staff_escape(staff_csrf()).'"><input type="hidden" name="reference" value="'.staff_escape($ref).'"><input type="hidden" name="action" value="'.$action.'">'.$fields.'<button>'.$label.'</button></form>';
}
function booking_job_payment_label(string $state): string
{
    return match($state){'paid'=>'Payment Verified','needs_action'=>'Customer Action Needed','processing'=>'Payment Processing','verification_pending'=>'Payment Needs Verification','needs_review','review'=>'Billing Review Needed',default=>'Collection Pending'};
}
function staff_job_panel(PDO $db,string $reference): string
{
    portal_billing_schema($db);$e='staff_escape';$job=booking_job_get($db,$reference);
    if($job){
        $bill=json_decode($job['bill_json'],true,16,JSON_THROW_ON_ERROR);
        $html='<section class="card next-step"><h2>'.($job['production_complete_at']?'Production Complete':'Production').'</h2><p>Onsite work completed. Final amount to collect: <strong>'.staff_money((int)$job['amount']).'</strong>.</p><p role="status">'.booking_job_payment_label($job['payment_state']).'</p>';
        if(isset($bill['commission_cents']))$html.='<label>Photographer Commission (18%)<input readonly value="'.staff_money($bill['commission_cents']).'"></label><p class="help">18% of eligible additional service fees. Commission is recorded separately from the customer’s payment.</p>';
        if(isset($bill['onsite_authorization']))$html.='<p>Agent’s verbal approval recorded at job completion.</p>';
        if($job['payment_state']!=='paid')$html.=staff_job_form($reference,'job_recover','','Check / Recover Final Payment').'<p><a href="/account.php?view=job&amp;reference='.$e($reference).'" target="_blank" rel="noopener noreferrer">Customer Payment Recovery Page ↗</a></p>';
        return $html.'<p><a href="staff-production.php?reference='.$e($reference).'">Open Production</a></p></section>';
    }
    try{$bill=booking_job_bill($db,$reference);}catch(InvalidArgumentException){return '';}
    $draft=booking_job_extras($db,$reference);$lines=json_decode($draft['lines_json'],true,16,JSON_THROW_ON_ERROR);
    $items=array_map(static fn($line)=>isset($line['service'])?['service'=>$line['service'],'inputs'=>$line['inputs']]:['legacy_id'=>$line['id']],$lines);
    $config=['catalog'=>booking_job_catalog(booking_get($db,$reference)),'items'=>$items,'saved'=>$lines];
    $html='<section class="card next-step"><h2>Onsite Closeout</h2><p>Select the additional services and review their total with the agent. When the shoot is finished, confirm the agent’s verbal approval and complete the job.</p>';
    $html.='<form id="job-onsite" method="post" data-config="'.$e(json_encode($config,JSON_THROW_ON_ERROR)).'"><input type="hidden" name="csrf" value="'.$e(staff_csrf()).'"><input type="hidden" name="reference" value="'.$e($reference).'"><input type="hidden" name="draft_scope" value="'.$e($draft['scope']).'"><input type="hidden" name="scope" value=""><input type="hidden" name="items" value="'.$e(json_encode($items,JSON_THROW_ON_ERROR)).'">';
    $html.='<div id="job-service-items"></div><button id="job-add-item" type="button">Add Additional Service Item</button><p id="job-price-status" role="status">Checking the current service prices…</p><dl class="facts"><div><dt>Total Additional Service Fee</dt><dd id="job-extra-total">'.staff_money(array_sum(array_column($lines,'cents'))).'</dd></div><div><dt>Final Job Total</dt><dd id="job-total">'.staff_money($bill['total_cents']).'</dd></div><div><dt>Remaining To Collect</dt><dd id="job-due">'.staff_money($bill['due_cents']).'</dd></div></dl>';
    $html.='<label id="job-commission-field" hidden>Photographer Commission (18%)<input id="job-commission" readonly value="'.staff_money($bill['commission_cents']).'"></label><p id="job-monthly" class="help" hidden></p><p id="job-commission-note" class="help" hidden>Commission applies only to service types not included in the original order. Subscriptions, hosting and licensing fees are excluded. Commission does not increase the customer’s charge.</p><p id="job-legacy-note" class="help" hidden>Previously entered custom services retain their saved fees. Re-select them from the service list to calculate commission.</p>';
    $html.='<p><button name="action" value="job_save" id="job-save" formnovalidate disabled>Save Additional Services</button></p><label><input type="checkbox" name="agreed" value="yes" required disabled>I confirm the onsite work is finished, the agent verbally approved the additional services and displayed fees, and the final amount shown is correct.</label><button name="action" value="job_complete" id="job-complete" disabled>Job Complete</button><noscript><p>Enable JavaScript to select services and verify the final amount before closing this job.</p></noscript></form><script src="/portal-assets/onsite-services.js" defer></script>';
    return $html.'</section>';
}
function staff_job_production_page(PDO $db,string $reference): string
{
    booking_job_schema($db);$e='staff_escape';$job=booking_job_get($db,$reference);
    $html='<p><a href="staff-bookings.php">← Staff Bookings</a></p>';
    if(!$job){
        $rows=$db->query('SELECT j.*,b.request_json FROM booking_jobs j JOIN bookings b ON b.reference=j.reference ORDER BY (j.production_complete_at IS NOT NULL),j.completed_at DESC LIMIT 200')->fetchAll(PDO::FETCH_ASSOC);
        $html.='<h2>Production Queue</h2><p>Open a job to add its delivery links.</p><div class="request-list">';
        foreach($rows as $row){$r=json_decode($row['request_json'],true,32,JSON_THROW_ON_ERROR);$html.='<article class="request-item"><div><p class="eyebrow">'.$e($row['reference']).'</p><h3>'.$e(portal_property($r['details']??[])).'</h3><p>'.($row['production_complete_at']?'Production Complete':'Production').' · '.booking_job_payment_label($row['payment_state']).'</p></div><a class="request-link" href="staff-production.php?reference='.$e($row['reference']).'">Open Job →</a></article>';}
        return $html.($rows?'':'<p>No jobs are awaiting production.</p>').'</div>';
    }
    $row=booking_get($db,$reference);$links=json_decode($job['links_json'],true,16,JSON_THROW_ON_ERROR);
    if($job['published_revision']!==null&&(int)$job['published_revision']<(int)$job['production_revision'])$html.='<p class="note">Unpublished changes are saved. Customers still see the last completed delivery set.</p>';
    $html.='<p><a href="staff-production.php">← Production Queue</a></p><h2>'.$e(portal_property(booking_request($row)['details'])).'</h2><p>'.($job['production_complete_at']?'Production Complete':'Production').' · '.booking_job_payment_label($job['payment_state']).'</p><p>Save links as you work. Production Complete publishes the finished set only after payment is verified.</p><section class="card"><form method="post"><input type="hidden" name="csrf" value="'.$e(staff_csrf()).'"><input type="hidden" name="reference" value="'.$e($reference).'"><input type="hidden" name="revision" value="'.$job['production_revision'].'">';
    foreach(booking_job_labels() as $key=>$label)$html.='<label>'.$label.' URL<input name="'.$key.'" type="url" maxlength="2048" placeholder="https://" value="'.$e($links[$key]['url']??'').'"></label>';
    $html.='<details><summary>Other Deliverables</summary>';
    for($i=0;$i<10;$i++)$html.='<label>Deliverable '.($i+1).' Name<input name="other_label_'.$i.'" maxlength="100" value="'.$e($links['other_'.$i]['label']??'').'"></label><label>Deliverable '.($i+1).' URL<input name="other_url_'.$i.'" type="url" maxlength="2048" value="'.$e($links['other_'.$i]['url']??'').'"></label>';
    $html.='</details><p><button name="action" value="job_production_save">Save Progress</button></p><label><input type="checkbox" name="agreed" value="yes">All ordered deliverables are complete and the links have been checked.</label><button name="action" value="job_production_complete">Production Complete</button></form></section>';
    return $html.'<p><a href="staff-bookings.php?reference='.$e($reference).'">View Booking &amp; Final Billing</a></p>';
}
