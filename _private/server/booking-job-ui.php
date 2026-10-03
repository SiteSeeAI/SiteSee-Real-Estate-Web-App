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
    if($action==='job_add'||$action==='job_remove'){
        booking_job_save_extras($db,$ref,$scope,substr($action,4),staff_job_input('label',160),staff_job_input('price',16),staff_job_input('remove',16));
        return 'Additional services saved. The customer must approve the current list before Job Complete.';
    }
    if($action==='job_complete'){
        $job=booking_job_complete($db,$ref,$scope,staff_job_input('agreed',8)==='yes');
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
        if($job['payment_state']!=='paid')$html.=staff_job_form($reference,'job_recover','','Check / Recover Final Payment').'<p><a href="/account.php?view=job&amp;reference='.$e($reference).'" target="_blank" rel="noopener noreferrer">Customer Payment Recovery Page ↗</a></p>';
        return $html.'<p><a href="staff-production.php?reference='.$e($reference).'">Open Production</a></p></section>';
    }
    try{$bill=booking_job_bill($db,$reference);}catch(InvalidArgumentException){return '';}
    $draft=booking_job_extras($db,$reference);$lines=json_decode($draft['lines_json'],true,16,JSON_THROW_ON_ERROR);$scope='<input type="hidden" name="scope" value="'.$e($draft['scope']).'">';
    $html='<section class="card next-step"><h2>Onsite Closeout</h2><p>Record any additional services, then complete the job to collect the final amount and start Production.</p>';
    if($lines){$html.='<h3>Additional Services</h3><ul>';foreach($lines as $line)$html.='<li>'.$e($line['label']).' · '.staff_money($line['cents']).staff_job_form($reference,'job_remove',$scope.'<input type="hidden" name="remove" value="'.$e($line['id']).'">','Remove Service').'</li>';$html.='</ul><p>'.($draft['approved_at']?'Customer approval recorded.':'Customer approval needed.').'</p>';
        if(!$draft['approved_at'])$html.='<p>The customer can approve these services in My Orders → Order Details → Job Status.</p><p><a href="/account.php?view=job&amp;reference='.$e($reference).'" target="_blank" rel="noopener noreferrer">Open Customer Approval Page ↗</a></p>';
    }
    $html.='<details><summary>Add Additional Services</summary>'.staff_job_form($reference,'job_add',$scope.'<label>Service *<input name="label" maxlength="160" required placeholder="For example: additional aerial photographs"></label><label>Total For This Service ($) *<input name="price" inputmode="decimal" pattern="[0-9]+([.][0-9]{1,2})?" required></label>','Add Service').'</details><dl class="job-facts facts"><div><dt>Final Job Total</dt><dd>'.staff_money($bill['total_cents']).'</dd></div><div><dt>Remaining To Collect</dt><dd>'.staff_money($bill['due_cents']).'</dd></div></dl>';
    if(!$lines||$draft['approved_at'])$html.=staff_job_form($reference,'job_complete','<input type="hidden" name="scope" value="'.$e($bill['scope']).'"><label><input type="checkbox" name="agreed" value="yes" required>I confirm the onsite work is finished and the final amount shown above is correct.</label>','Job Complete');
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
