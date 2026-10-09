<?php
declare(strict_types=1);
require_once __DIR__.'/vendor-access.php';
require_once __DIR__.'/portal-sms.php';

function vendor_admin_action(PDO $db,string $action): string
{
    vendor_schema($db);
    if($action==='vendor_save'){
        $enabled=staff_job_input('enabled',1);if(!in_array($enabled,['0','1'],true))throw new InvalidArgumentException('Choose an account status.');
        vendor_save($db,staff_job_input('vendor_id',32),staff_job_input('vendor_revision',32),staff_job_input('name',120),staff_job_input('phone',40),$enabled==='1');
        return 'Vendor account saved. Any previous sign-in sessions have ended. No text was sent.';
    }
    if($action==='vendor_assign'){
        vendor_assign($db,staff_job_input('reference',32),staff_job_input('vendor_id',32),staff_job_input('assignment_revision',32));
        return 'Vendor access updated. The booking, calendar and customer payment records are preserved.';
    }
    throw new InvalidArgumentException('Unknown vendor action.');
}
function vendor_admin_page(PDO $db): string
{
    vendor_schema($db);$e='staff_escape';
    try{$allowed=portal_sms_config()['allowed_numbers'];}catch(Throwable){$allowed=[];}
    $html='<p>Vendors sign in with their cell phone at <a href="/vendor.php">Vendor Account</a>. Create an account here, then assign a job from Staff Bookings. Accounts have no access to manager controls or Production.</p>';
    $html.='<section class="card"><h2>Add Vendor</h2>'.vendor_admin_form().'</section><h2>Vendor Accounts</h2>';
    $rows=$db->query('SELECT * FROM vendor_accounts ORDER BY enabled DESC,name,id')->fetchAll(PDO::FETCH_ASSOC);
    foreach($rows as $row){
        $sms=in_array($row['phone'],$allowed,true)?'Ready for TEST phone sign-in':'This number needs approval in the existing TEST SMS configuration before it can receive a sign-in code.';
        $html.='<details class="staff-fold"><summary>'.$e($row['name']).' · '.((int)$row['enabled']===1?'Active':'Disabled').'</summary><p>'.$e($row['phone']).'</p><p class="help">'.$e($sms).'</p>'.vendor_admin_form($row).'</details>';
    }
    return $html.($rows?'':'<p>No vendor accounts yet.</p>');
}
function vendor_admin_form(?array $row=null): string
{
    $e='staff_escape';$html='<form method="post"><input type="hidden" name="csrf" value="'.$e(staff_csrf()).'"><input type="hidden" name="action" value="vendor_save"><input type="hidden" name="vendor_id" value="'.$e($row['id']??'').'"><input type="hidden" name="vendor_revision" value="'.$e($row['revision']??'').'"><label>Vendor Name<input name="name" maxlength="120" required value="'.$e($row['name']??'').'"></label><label>Cell Phone Number<input name="phone" type="tel" maxlength="40" autocomplete="tel" required value="'.$e($row['phone']??'').'"></label>';
    if($row)$html.='<label>Account Status<select name="enabled" aria-label="Account Status"><option value="1"'.((int)$row['enabled']===1?' selected':'').'>Active</option><option value="0"'.((int)$row['enabled']===0?' selected':'').'>Disabled</option></select></label><p class="help">Saving an account ends its previous sign-in sessions. Disabling it removes access to all assigned jobs.</p>';
    else $html.='<input type="hidden" name="enabled" value="1">';
    return $html.'<button>'.($row?'Save Vendor':'Create Vendor Account').'</button></form>';
}
function vendor_admin_assignment(PDO $db,string $ref,bool $fold=true): string
{
    vendor_schema($db);$e='staff_escape';$grant=vendor_assignment($db,$ref);$account=$grant?vendor_get($db,$grant['vendor_id']):false;
    $job=booking_job_get($db,$ref);$ready=true;
    try{$row=booking_job_guard($db,$ref);}catch(InvalidArgumentException){$ready=false;}
    if(!$ready&&!$grant)return '';
    $html='<p>'.($account?'Vendor: <strong>'.$e($account['name']).'</strong>':'No vendor account has been assigned.').'</p>';
    if($grant&&$ready&&$grant['photographer']!==$row['photographer'])$html.='<p class="note">The reviewed photographer changed. Confirm the vendor assignment again to restore access.</p>';
    $html.='<p class="help">This gives the selected vendor access to this job and its onsite closeout. The saved calendar and booking review remain unchanged.</p>';
    $html.='<form method="post"><input type="hidden" name="csrf" value="'.$e(staff_csrf()).'"><input type="hidden" name="action" value="vendor_assign"><input type="hidden" name="reference" value="'.$e($ref).'"><input type="hidden" name="assignment_revision" value="'.$e($grant['revision']??'').'"><label>Vendor Account<select name="vendor_id" aria-label="Vendor Account"><option value="">No vendor access</option>';
    if($ready&&!$job)foreach($db->query('SELECT * FROM vendor_accounts WHERE enabled=1 ORDER BY name,id')->fetchAll(PDO::FETCH_ASSOC) as $v)$html.='<option value="'.$e($v['id']).'"'.($grant&&$grant['vendor_id']===$v['id']?' selected':'').'>'.$e($v['name'].' · '.$v['phone']).'</option>';
    if($job||!$ready)$html.='</select></label><button>Remove Vendor Access</button>';
    else $html.='</select></label><button>Assign Vendor</button>';
    $html.='</form><p><a href="staff-vendors.php">Manage Vendor Accounts</a></p>';
    return $fold ? staff_disclosure('vendor-assignment','Vendor Access',$html,false) : '<section class="card"><h2>Assigned Vendor</h2>'.$html.'</section>';
}
function vendor_review_picker(PDO $db): string
{
    vendor_schema($db);$html='<label>Vendor<select name="vendor_selection" required><option value="">Choose a vendor</option>';
    foreach($db->query('SELECT id,name,revision FROM vendor_accounts WHERE enabled=1 ORDER BY name,id')->fetchAll(PDO::FETCH_ASSOC) as $v)
        $html.='<option value="'.staff_escape($v['id'].'.'.$v['revision']).'">'.staff_escape($v['name']).'</option>';
    return $html.'</select></label><p class="help">The selected vendor gets this job after calendar confirmation. <a href="staff-vendors.php">Manage Vendors</a></p>';
}
