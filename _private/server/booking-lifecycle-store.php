<?php
declare(strict_types=1);
/** Additive lifecycle journal. Financial columns and original invitation evidence are never reset. */
function booking_lifecycle_schema(PDO $db): void
{
    $db->exec("CREATE TABLE IF NOT EXISTS booking_lifecycle (
        reference TEXT PRIMARY KEY, state TEXT NOT NULL DEFAULT 'active', revision INTEGER NOT NULL DEFAULT 0,
        actual_start INTEGER, actual_end INTEGER, missing_since INTEGER, checked_at INTEGER,
        diagnostic TEXT, token_hash TEXT, token_expires INTEGER)");
    $db->exec("CREATE TABLE IF NOT EXISTS booking_lifecycle_operations (
        operation_id TEXT PRIMARY KEY, reference TEXT NOT NULL, revision INTEGER NOT NULL,
        action TEXT NOT NULL, actor TEXT NOT NULL, state TEXT NOT NULL,
        payload_json TEXT NOT NULL, created_at TEXT NOT NULL, completed_at TEXT,
        UNIQUE(reference,revision))");
    $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS booking_lifecycle_pending ON booking_lifecycle_operations(reference)
        WHERE state IN ('prepared','uncertain')");
    $db->exec('CREATE TABLE IF NOT EXISTS booking_management_attempts (ip_hash TEXT NOT NULL, at INTEGER NOT NULL)');
    $db->exec('CREATE INDEX IF NOT EXISTS booking_management_rate ON booking_management_attempts(ip_hash,at)');
}
function booking_lifecycle_state(PDO $db, string $reference): array
{
    $q=$db->prepare('SELECT * FROM booking_lifecycle WHERE reference=?');$q->execute([$reference]);
    return $q->fetch() ?: ['reference'=>$reference,'state'=>'active','revision'=>0,'actual_start'=>null,'actual_end'=>null,
        'missing_since'=>null,'checked_at'=>null,'diagnostic'=>null,'token_hash'=>null,'token_expires'=>null];
}
function booking_lifecycle_set(PDO $db, string $reference, array $values): void
{
    $allowed=['state','revision','actual_start','actual_end','missing_since','checked_at','diagnostic','token_hash','token_expires'];
    foreach(array_keys($values)as$k)if(!in_array($k,$allowed,true))throw new LogicException('Invalid lifecycle column.');
    $db->prepare('INSERT OR IGNORE INTO booking_lifecycle(reference) VALUES(?)')->execute([$reference]);
    $db->prepare('UPDATE booking_lifecycle SET '.implode(',',array_map(static fn($k)=>$k.'=?',array_keys($values))).' WHERE reference=?')
        ->execute([...array_values($values),$reference]);
}
function booking_lifecycle_pending(PDO $db, string $reference): array|false
{
    $q=$db->prepare("SELECT * FROM booking_lifecycle_operations WHERE reference=? AND state IN ('prepared','uncertain')");$q->execute([$reference]);return $q->fetch();
}
function booking_lifecycle_assert_active(PDO $db, string $reference): void
{
    if(booking_lifecycle_state($db,$reference)['state']!=='active'||booking_lifecycle_pending($db,$reference))
        throw new InvalidArgumentException('Appointment management needs review. Use Manage Appointment; do not confirm or resend the original invitation.');
}
/** Never expire uncertain writes. Hold both old and proposed intervals until readback resolves them. */
function booking_lifecycle_busy(PDO $db, array $snapshot, ?string $exclude=null): array
{
    foreach($db->query('SELECT c.*,l.state AS lifecycle_state,l.actual_start,l.actual_end FROM booking_confirmations c LEFT JOIN booking_lifecycle l ON c.reference=l.reference')->fetchAll()as$c){
        if($c['reference']===$exclude)continue;
        $state=$c['lifecycle_state']??'active';
        if(!in_array($state,['cancelled','calendar_missing'],true)){
            $snapshot['busy'][]=$state==='calendar_changed'&&$c['actual_start']!==null
                ?[(int)$c['actual_start'],(int)$c['actual_end']]:[(int)$c['planned_start'],(int)$c['planned_end']];
        }
    }
    foreach($db->query("SELECT reference,payload_json FROM booking_lifecycle_operations WHERE state IN ('prepared','uncertain')")->fetchAll()as$o){
        if($o['reference']===$exclude)continue;
        $p=json_decode($o['payload_json'],true,64,JSON_THROW_ON_ERROR);
        foreach(['old_interval','new_interval']as$k)if(isset($p[$k]))$snapshot['busy'][]=$p[$k];
    }
    return $snapshot;
}
