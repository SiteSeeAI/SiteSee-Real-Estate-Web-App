<?php
declare(strict_types=1);

/** Display aliases only. Internal references, signed URLs and provider IDs stay intact. */
function booking_identifier_schema(PDO $db): void
{
    $db->exec("CREATE TABLE IF NOT EXISTS booking_order_numbers (reference TEXT PRIMARY KEY,
        order_number TEXT NOT NULL UNIQUE CHECK(length(order_number)=8), created_at TEXT NOT NULL)");
    $db->exec("CREATE TABLE IF NOT EXISTS booking_order_closures (reference TEXT PRIMARY KEY,
        closed_at TEXT NOT NULL, actor TEXT NOT NULL, detail_json TEXT NOT NULL)");
}
function booking_order_number(PDO $db,string $reference,?callable $random=null): string
{
    if(!preg_match('/^[A-F0-9]{10,32}$/D',$reference))throw new InvalidArgumentException('Order unavailable.');
    $exists=$db->prepare('SELECT 1 FROM bookings WHERE reference=?');$exists->execute([$reference]);
    if(!$exists->fetchColumn())throw new InvalidArgumentException('Order unavailable.');
    $q=$db->prepare('SELECT order_number FROM booking_order_numbers WHERE reference=?');
    for($attempt=0;$attempt<30;$attempt++){
        $q->execute([$reference]);$number=$q->fetchColumn();if(is_string($number))return $number;
        if($random)$number=$random();
        else{$number='';$alphabet='ABCDEFGHJKLMNPQRSTUVWXYZ23456789';for($i=0;$i<8;$i++)$number.=$alphabet[random_int(0,31)];}
        if(!is_string($number)||!preg_match('/^[A-Z0-9]{8}$/D',$number))throw new RuntimeException('Order number unavailable.');
        $db->prepare('INSERT OR IGNORE INTO booking_order_numbers(reference,order_number,created_at) VALUES(?,?,?)')->execute([$reference,$number,gmdate('c')]);
    }
    throw new RuntimeException('Order number could not be allocated.');
}
function booking_property_subject(string $prefix,array $details,string $suffix=''): string
{
    $address=trim(implode(' ',array_filter(array_map(static fn($k)=>is_string($details[$k]??null)?$details[$k]:'',['street','unit','city','state','zip']))));
    $address=preg_replace('/[\x00-\x1f\x7f]+/u',' ',$address)??'';
    $address=preg_replace('/\s+/u',' ',$address)??'';
    if($address==='')$address='Property appointment';
    preg_match('/^.{0,220}/us',$address,$match);
    return $prefix.' | '.($match[0]??'Property appointment').($suffix!==''?' | '.$suffix:'');
}
function booking_order_list(PDO $db,?string $account,string $group,int $page=1,string $size='5'): array
{
    if(!in_array($group,['open','previous'],true)||!in_array($size,['5','25','all'],true))throw new InvalidArgumentException('Choose a valid order list.');
    booking_job_schema($db);
    $completed="(j.production_complete_at IS NOT NULL OR x.closed_at IS NOT NULL)";
    $owned=$account===null?'':" JOIN portal_order_owners o ON o.reference=b.reference JOIN portal_accounts a ON a.id=o.account_id AND a.disabled=0 ";
    $from=' FROM bookings b'.$owned.' LEFT JOIN booking_scheduling s ON s.reference=b.reference LEFT JOIN booking_confirmations c ON c.reference=b.reference LEFT JOIN booking_lifecycle l ON l.reference=b.reference LEFT JOIN booking_jobs j ON j.reference=b.reference LEFT JOIN booking_order_closures x ON x.reference=b.reference';
    $where=' WHERE '.($group==='previous'?$completed:'NOT '.$completed).($account===null?'':' AND o.account_id=?');
    $params=$account===null?[]:[$account];$q=$db->prepare('SELECT COUNT(*)'.$from.$where);$q->execute($params);$total=(int)$q->fetchColumn();
    $limit=$size==='all'?null:(int)$size;$pages=$limit===null?1:max(1,(int)ceil($total/$limit));$page=max(1,min($pages,$page));
    $sql='SELECT b.*,s.rush_status,s.rush_fee_cents,s.reschedule_required,c.state AS calendar_state,l.state AS lifecycle_state,j.production_complete_at,j.completed_at,j.payment_state AS job_payment_state,j.bill_json,x.closed_at'.$from.$where.' ORDER BY b.created_at DESC,b.reference DESC';
    if($limit!==null)$sql.=' LIMIT '.(int)$limit.' OFFSET '.(($page-1)*$limit);
    $q=$db->prepare($sql);$q->execute($params);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
    foreach($rows as &$row)$row['order_number']=booking_order_number($db,$row['reference']);unset($row);
    return ['orders'=>$rows,'total'=>$total,'page'=>$page,'pages'=>$pages,'size'=>$size,'has_more'=>$page<$pages];
}
function booking_order_can_close(PDO $db,string $reference): bool
{
    $life=booking_lifecycle_state($db,$reference);
    if($life['state']!=='cancelled'||booking_lifecycle_pending($db,$reference))return false;
    $op=$db->prepare("SELECT 1 FROM booking_lifecycle_operations WHERE reference=? AND revision=? AND action='cancel' AND state='applied'");$op->execute([$reference,$life['revision']]);
    $mail=booking_communication_get($db,'lifecycle-'.$life['revision'].':'.$reference);
    $row=booking_get($db,$reference);
    return (bool)$op->fetchColumn()&&$mail&&$row&&$mail['reference']===$reference&&$mail['kind']==='lifecycle-'.$life['revision']
        &&$mail['sender']===BOOKING_MAIL_SENDER&&$mail['recipient']===$row['email']&&$mail['submission_state']==='sent_observed';
}
/** Explicit manager closeout; records disposition without moving or refunding money. */
function booking_order_close_cancelled(PDO $db,string $reference,string $fingerprint,bool $agreed,string $note): void
{
    $note=trim($note);
    if(!booking_test_enabled()||!$agreed||$note===''||strlen($note)>500||preg_match('/[\x00-\x1f\x7f]/',$note))throw new InvalidArgumentException('Confirm cancellation closeout and record the payment or credit disposition.');
    $db->exec('BEGIN IMMEDIATE');
    try{
        if(!booking_order_can_close($db,$reference)||!hash_equals(booking_lifecycle_fingerprint($db,$reference),$fingerprint))throw new InvalidArgumentException('Verify the saved cancellation and customer notice before closeout.');
        $db->prepare('INSERT OR IGNORE INTO booking_order_closures VALUES(?,?,?,?)')->execute([$reference,gmdate('c'),'staff',json_encode(['payment_disposition'=>$note],JSON_THROW_ON_ERROR)]);
        $db->exec('COMMIT');
    }catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
}
