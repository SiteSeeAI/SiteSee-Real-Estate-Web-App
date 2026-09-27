<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/tools/verify-microsoft-calendar-connection.php';
function check(bool $condition, string $message = 'assertion'): void { if (!$condition) throw new RuntimeException($message); }
function rejected(callable $fn): void { try { $fn(); } catch (BookingMicrosoftCalendarError $e) { return; } throw new RuntimeException('Expected rejection'); }
function ev(string $id = 'opaque+/=ID', string $show = 'busy'): array {
    return ['id'=>$id, 'start'=>['dateTime'=>'2026-09-30T12:00:00.0000000','timeZone'=>'UTC'],
        'end'=>['dateTime'=>'2026-09-30T13:35:00.0000000','timeZone'=>'UTC'], 'type'=>'singleInstance', 'isCancelled'=>false, 'showAs'=>$show];
}
function reply(array $data, int $status = 200): array { return ['status'=>$status, 'body'=>$data]; }
$config = ['schema'=>1,'stage'=>'test','mailbox'=>BOOKING_MS_MAILBOX,'application_id'=>BOOKING_MS_APP,
    'calendar_id'=>BOOKING_MS_CALENDAR,'graph_credentials'=>'/home/sitesee/.sitesee-graph-mail.json',
    'scheduling_enabled'=>false,'invitations_enabled'=>false];
$secret = ['tenant_id'=>'11111111-1111-1111-1111-111111111111','client_id'=>BOOKING_MS_APP,'client_secret'=>'fake-test-only'];
$range = ['start'=>strtotime('2026-09-30T00:00:00Z'), 'end'=>strtotime('2026-10-01T00:00:00Z')];
$count = 0;
function test(string $name, callable $fn): void { global $count; $fn(); $count++; echo "PASS $name\n"; }

test('credential and TEST gates precede provider calls', function () use ($config, $secret) {
    $http = function () { throw new RuntimeException('Unexpected provider call'); };
    foreach (['scheduling_enabled'=>true,'invitations_enabled'=>true,'mailbox'=>'cro@sitesee.ai','stage'=>'live','calendar_id'=>'different'] as $key=>$value) {
        rejected(fn()=>booking_ms_connection(array_replace($config, [$key=>$value]), $secret, $http));
    }
    rejected(fn()=>booking_ms_connection($config, array_replace($secret, ['client_id'=>'other']), $http));
});
test('transport refuses alternate hosts, mailboxes, redirects, actions and attendee writes', function () use ($config, $secret) {
    $calls = [];
    $http = function ($method, $url, $headers, $body) use (&$calls) {
        $calls[] = [$method,$url,$headers,$body];
        return str_contains($url, '/token') ? reply(['access_token'=>'fake-token']) : reply([]);
    };
    $request = booking_ms_connection($config, $secret, $http);
    $n = count($calls);
    foreach (['https://evil.test/v1.0/users/sales%40re.sitesee.ai/calendar',
        '//graph.microsoft.com/v1.0/users/sales%40re.sitesee.ai/calendar',
        'https://user@graph.microsoft.com/v1.0/users/sales%40re.sitesee.ai/calendar',
        BOOKING_MS_BASE . '/calendar#x', '/v1.0/users/cro%40sitesee.ai/calendar',
        booking_ms_calendar_path() . '/events/id/cancel', BOOKING_MS_BASE . '/messages',
        "https://graph.microsoft.com:443" . booking_ms_calendar_path(), booking_ms_calendar_path() . "/events/id\n"] as $path) rejected(fn()=>$request('GET',$path));
    $event = booking_ms_probe_payload(str_repeat('a',32), strtotime('2026-09-30T12:00:00Z'));
    rejected(fn()=>$request('POST',booking_ms_calendar_path().'/events',array_replace($event,['attendees'=>[['emailAddress'=>['address'=>'cro@sitesee.ai']]]])));
    rejected(fn()=>$request('PATCH',booking_ms_event_path('id'),['subject'=>'change']));
    rejected(fn()=>$request('DELETE',booking_ms_mailbox_event_path('id')));
    check(count($calls)===$n);
    $request('POST',booking_ms_calendar_path().'/events',$event);
    check(str_contains(implode(',',end($calls)[2]), 'ImmutableId'));
    check(json_decode(end($calls)[3],true)['attendees']===[]);
});
test('default and pinned calendar must both match', function () {
    $calendar = ['id'=>BOOKING_MS_CALENDAR,'owner'=>['address'=>BOOKING_MS_MAILBOX],'canEdit'=>true,'isDefaultCalendar'=>true];
    booking_ms_verify_calendar(fn()=>reply($calendar));
    rejected(fn()=>booking_ms_verify_calendar(fn()=>reply(array_replace($calendar,['id'=>'other']))));
    rejected(fn()=>booking_ms_verify_calendar(fn()=>reply([],403)));
});
test('complete pagination, recurrence expansion and conservative busy states', function () use ($range) {
    $calls=0;
    $next='https://graph.microsoft.com'.booking_ms_calendar_path().'/calendarView?%24skiptoken=opaque';
    $request=function() use (&$calls,$next) {
        if (++$calls===1) return reply(['value'=>[ev('busy'),ev('free','free')],'@odata.nextLink'=>$next]);
        $occ=ev('occ','tentative'); $occ['type']='occurrence'; $cancel=ev('cancel');$cancel['isCancelled']=true;
        return reply(['value'=>[$occ,ev('oof','oof'),ev('elsewhere','workingElsewhere'),ev('unknown','unknown'),$cancel]]);
    };
    $r=booking_ms_snapshot($request,$range); check($calls===2 && count($r['busy'])===5);
    check($r['busy'][0]['start']===strtotime('2026-09-30T12:00:00Z'));
});
test('failed, repeated or hostile continuation never becomes free availability', function () use ($range) {
    rejected(fn()=>booking_ms_snapshot(fn()=>reply(['value'=>[]],403),$range));
    foreach (['https://evil.test/x', BOOKING_MS_BASE.'/messages', booking_ms_calendar_path().'/calendarView?x=1'] as $next) {
        rejected(fn()=>booking_ms_snapshot(fn()=>reply(['value'=>[],'@odata.nextLink'=>$next]),$range));
    }
    rejected(fn()=>booking_ms_snapshot(fn()=>reply(['value'=>[ev(),ev()]]),$range));
    rejected(fn()=>booking_ms_snapshot(fn()=>reply(['value'=>'invalid']),$range));
});
test('unsupported timestamps or status fail closed; all-day UTC spans work', function () use ($range) {
    foreach ([['timeZone'=>'America/Chicago'],['dateTime'=>'2026-02-31T00:00:00'],['dateTime'=>'not-a-date']] as $replace) {
        $e=ev();$e['start']=array_replace($e['start'],$replace);
        rejected(fn()=>booking_ms_snapshot(fn()=>reply(['value'=>[$e]]),$range));
    }
    $e=ev();unset($e['isCancelled']);rejected(fn()=>booking_ms_snapshot(fn()=>reply(['value'=>[$e]]),$range));
    $e=ev();$e['start']['dateTime']='2026-09-30T05:00:00';$e['end']['dateTime']='2026-10-01T05:00:00';
    check(count(booking_ms_snapshot(fn()=>reply(['value'=>[$e]]),$range)['busy'])===1);
});

final class FakeCalendar {
    public array $events=[]; public int $posts=0; public int $deletes=0;
    public bool $createTimeout=false; public bool $deleteTimeout=false; public bool $changed=false;
    public bool $hidden=false; public int $createStatus=201; public bool $moved=false;
    public function request(string $method,string $path,?array $body=null):array {
        if ($method==='POST') {
            $this->posts++;
            if ($this->createStatus!==201) return reply([],$this->createStatus);
            $this->events['immutable+/=ID']=$body + ['id'=>'immutable+/=ID','isCancelled'=>false,'isOrganizer'=>true,
                'organizer'=>['emailAddress'=>['address'=>BOOKING_MS_MAILBOX]],'type'=>'singleInstance','recurrence'=>null];
            if($this->createTimeout) { $this->createTimeout=false;throw new BookingMicrosoftCalendarError('Simulated timeout'); }
            return reply($this->events['immutable+/=ID'],201);
        }
        if (str_contains($path,'/calendarView')) return reply(['value'=>$this->hidden ? [] : array_values($this->events)]);
        if ($method==='DELETE') {
            $this->deletes++;$this->events=[];
            if($this->deleteTimeout) { $this->deleteTimeout=false;throw new BookingMicrosoftCalendarError('Simulated timeout'); }
            return reply([],204);
        }
        if ($this->moved && str_contains($path,'/calendars/')) return reply([],404);
        if (!$this->events) return reply([],404);
        $e=reset($this->events); if($this->changed) $e['attendees']=[['emailAddress'=>['address'=>'cro@sitesee.ai']]];
        return reply($e);
    }
}
function with_probe(callable $fn): void {
    $dir=sys_get_temp_dir().'/ms-probe-test-'.bin2hex(random_bytes(8));mkdir($dir,0700);
    try {$fn(new FakeCalendar(),$dir.'/journal.json');}
    finally { foreach(glob($dir.'/*') as $p) unlink($p);rmdir($dir); }
}
test('create/read/delete and unchanged rerun create exactly one event', function () {
    with_probe(function($f,$path){$j=booking_ms_run_probe([$f,'request'],$path,1790000000);
        check($j['state']==='complete' && $f->posts===1 && $f->deletes===1 && !$f->events);
        check(booking_ms_run_probe([$f,'request'],$path)===$j && $f->posts===1);
        check((fileperms($path)&0777)===0600);
    });
});
test('ambiguous creation recovers the same transaction without reposting', function () {
    with_probe(function($f,$path){$f->createTimeout=true;rejected(fn()=>booking_ms_run_probe([$f,'request'],$path));
        check(booking_ms_private_json($path)['state']==='create_started');
        $j=booking_ms_run_probe([$f,'request'],$path);check($j['state']==='complete' && $f->posts===1 && $f->deletes===1);
    });
});
test('missing uncertain create remains unresolved without another event', function () {
    with_probe(function($f,$path){$f->createTimeout=true;rejected(fn()=>booking_ms_run_probe([$f,'request'],$path));
        $f->hidden=true;rejected(fn()=>booking_ms_run_probe([$f,'request'],$path));check($f->posts===1 && $f->deletes===0);
    });
});
test('ambiguous deletion recovers by verified absence without another delete', function () {
    with_probe(function($f,$path){$f->deleteTimeout=true;rejected(fn()=>booking_ms_run_probe([$f,'request'],$path));
        check(booking_ms_private_json($path)['state']==='delete_started');
        check(booking_ms_run_probe([$f,'request'],$path)['state']==='complete' && $f->posts===1 && $f->deletes===1);
    });
});
test('changed or moved event cannot be silently deleted or declared removed', function () {
    with_probe(function($f,$path){$f->changed=true;rejected(fn()=>booking_ms_run_probe([$f,'request'],$path));check($f->deletes===0);
        $j=booking_ms_private_json($path);$j['state']='delete_started';$j['verified_at']=time();booking_ms_save_probe($path,$j);
        $f->moved=true;rejected(fn()=>booking_ms_run_probe([$f,'request'],$path));check($f->deletes===0);
    });
});
test('known permission denial retries same durable transaction', function () {
    with_probe(function($f,$path){$f->createStatus=403;rejected(fn()=>booking_ms_run_probe([$f,'request'],$path));
        $j=booking_ms_private_json($path);check($j['state']==='prepared');$f->createStatus=201;
        check(booking_ms_run_probe([$f,'request'],$path)['transaction_id']===$j['transaction_id'] && $f->posts===2);
    });
});
test('journal tampering and unsafe files stop before creating an event', function () {
    with_probe(function($f,$path){file_put_contents($path,'{}');chmod($path,0600);rejected(fn()=>booking_ms_run_probe([$f,'request'],$path));
        unlink($path);$target=dirname($path).'/target';file_put_contents($target,'{}');chmod($target,0600);symlink($target,$path);
        rejected(fn()=>booking_ms_run_probe([$f,'request'],$path));check($f->posts===0);
    });
});
echo "$count Microsoft calendar connection cases passed (provider simulated).\n";
