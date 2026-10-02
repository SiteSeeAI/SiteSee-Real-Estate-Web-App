<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
$root=getenv('STAFF_TEST_PRIVATE');
if (!$root || !str_starts_with($root,sys_get_temp_dir().'/sitesee-staff-http-')) throw new RuntimeException('Isolated fixture directory required.');
require $root.'/server/booking-lifecycle-ui.php';
$db=booking_db();booking_communication_schema($db);
if (($argv[1]??'')==='snapshot') {
    $all=[];foreach(['bookings','booking_scheduling','booking_confirmations','booking_lifecycle','booking_lifecycle_operations','booking_communications','booking_contact_links','booking_schedule_events','stripe_events'] as $t)$all[$t]=$db->query('SELECT * FROM '.$t.' ORDER BY rowid')->fetchAll();
    echo hash('sha256',json_encode($all));exit;
}
$save=static function(string $name,array $value)use($root):void{file_put_contents($root.'/'.$name,json_encode($value));chmod($root.'/'.$name,0600);};
$save('microsoft-scheduling.json',booking_scheduling_ms_config());
$save('booking-lifecycle.json',['schema'=>1,'stage'=>'test','enabled'=>true,'recipient'=>'sales@re.sitesee.ai']);
$save('booking-mail.json',['sender'=>BOOKING_MAIL_SENDER,'stage'=>'test','graph_credentials'=>'/home/sitesee/.sitesee-graph-mail.json','test_recipient_email'=>'sales@re.sitesee.ai','enabled'=>true]);
$save('zoho-crm.json',['client_id'=>'isolated','client_secret'=>'isolated','refresh_token'=>'isolated','org_id'=>'123','user_id'=>'456','api_domain'=>'https://www.zohoapis.com','accounts_domain'=>'https://accounts.zoho.com','sync_mode'=>'api','original_sync_mode'=>'api','sync_reviewed_at'=>gmdate('c'),'owner_verified_at'=>gmdate('c')]);
$cases=['legacy','unpaid','paid','rush','declined','reviewed-unlinked','reviewed','uncertain','confirmed','submitted','complete','draft','pending-change','moved','cancelled-notice','cancelled','other-recipient','missing-evidence','legacy-uncertain'];$refs=[];
foreach($cases as $i=>$case){
    $ref=sprintf('AB%08X',$i);$refs[$case]=$ref;
    $s=real_estate_prepare_submission(['version'=>2,'action'=>'request_appointment','market'=>'residential','details'=>['first'=>'Jordan','last'=>'Example','company'=>'Example Realty','email'=>in_array($case,['other-recipient','legacy-uncertain'])?'fixture@example.test':'sales@re.sitesee.ai','phone'=>'3125550100','street'=>'404 Example Lane','city'=>'Chicago','state'=>'IL','zip'=>'60601'],'state'=>['category'=>'small','package'=>'gold','sqft'=>1500,'selected'=>['photo','mp'],'matterportSqft'=>1500],'appointment'=>['date'=>(new DateTimeImmutable('+10 days',new DateTimeZone('America/Chicago')))->format('Y-m-d'),'time'=>'09:00','rushRequested'=>in_array($case,['rush','declined']),'meetPhotographer'=>'No','accessType'=>'Lockbox','lockboxCode'=>'1234567890','cancellationAccepted'=>true]]);
    booking_capture($db,$s,$ref,$case!=='legacy');
    $db->prepare('UPDATE bookings SET created_at=? WHERE reference=?')->execute(['2026-10-02T14:00:00Z',$ref]);
    if(in_array($case,['legacy','unpaid']))continue;
    $db->prepare("UPDATE bookings SET status='deposit_paid_test',deposit_paid_at=? WHERE reference=?")->execute([gmdate('c'),$ref]);
    if($case==='declined'){booking_decline_rush($db,$ref,'Customer requested a standard window.');continue;}
    if(in_array($case,['paid','rush','other-recipient']))continue;
    booking_review_paid($db,$ref,95,'David J Cro',true);
    if($case==='reviewed-unlinked')continue;
    $db->prepare('INSERT INTO booking_contact_links VALUES (?,?,?,?,?)')->execute([$ref,'123','789','sales@re.sitesee.ai',gmdate('c')]);
    $db->prepare('UPDATE bookings SET crm_contact_id=? WHERE reference=?')->execute(['789',$ref]);
    if($case==='reviewed')continue;
    $start=strtotime(booking_get($db,$ref)['requested_utc']);
    $db->prepare('INSERT INTO booking_confirmations(reference,state,calendar_uid,event_uid,planned_start,planned_end,event_json,created_at,confirmed_at) VALUES (?,?,?,?,?,?,?,?,?)')->execute([$ref,in_array($case,['uncertain','legacy-uncertain'])?'uncertain':'confirmed',booking_scheduling_ms_config()['calendar_uid'],'synthetic-'.$ref,$start,$start+5700,'{}',gmdate('c'),gmdate('c')]);
    if(in_array($case,['uncertain','legacy-uncertain','confirmed']))continue;
    if($case==='missing-evidence'){$db->prepare("UPDATE booking_confirmations SET invitation_state='sent' WHERE reference=?")->execute([$ref]);continue;}
    $claim=booking_confirmation_get($db,$ref);
    $message=booking_invitation_message(booking_get($db,$ref),$claim,booking_management_issue($db,$ref));
    $key=booking_communication_enqueue($db,$ref,'invitation',$message,$claim);
    if($case==='draft'){
        $db->prepare("UPDATE booking_confirmations SET invitation_state='uncertain' WHERE reference=?")->execute([$ref]);
        booking_communication_update($db,$key,['submission_state'=>'draft_blocked','provider_message_id'=>'synthetic-draft']);continue;
    }
    $db->prepare("UPDATE booking_confirmations SET invitation_state='sent',invitation_recipient='sales@re.sitesee.ai',invitation_sent_at=? WHERE reference=?")->execute([gmdate('c'),$ref]);
    booking_communication_update($db,$key,['submission_state'=>$case==='submitted'?'accepted':'sent_observed','delivery_state'=>$case==='submitted'?'unverified':'recipient_copy_observed','crm_state'=>$case==='submitted'?'pending':'associated']);
    if($case==='pending-change'){
        $db->prepare("INSERT INTO booking_lifecycle_operations VALUES (?,?,1,'reschedule','staff','uncertain','{}',?,NULL)")->execute(['pending-'.$ref,$ref,gmdate('c')]);
        booking_lifecycle_set($db,$ref,['diagnostic'=>'Existing change needs verification.']);
    }
    if($case==='moved')booking_lifecycle_set($db,$ref,['state'=>'calendar_changed','actual_start'=>$start+3600,'actual_end'=>$start+9300,'diagnostic'=>'Review the observed calendar move.']);
    if(str_starts_with($case,'cancelled')){
        booking_lifecycle_set($db,$ref,['state'=>'cancelled','revision'=>1]);
        $key=booking_communication_enqueue($db,$ref,'lifecycle-1',$message,$claim);
        if($case==='cancelled')booking_communication_update($db,$key,['submission_state'=>'sent_observed','delivery_state'=>'recipient_copy_observed','crm_state'=>'associated']);
    }
}
echo json_encode(['hash'=>'base64:'.base64_encode(password_hash('isolated-staff-password',PASSWORD_DEFAULT)),'refs'=>$refs]);
