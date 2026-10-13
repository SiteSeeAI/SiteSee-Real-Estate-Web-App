<?php
declare(strict_types=1);
// Test-only adapters for the disposable, authenticated staff HTTPS fixture.
// The actual readiness/link functions and their session/provider guards execute.
function staff_fixture_readiness_deps(PDO $db,string $reference): array
{
    $email=booking_get($db,$reference)['email'];
    $crm=static function($method,$path,$body=null)use($email): array {
        if($method!=='GET')throw new RuntimeException('Readiness fixture blocks CRM writes.');
        if($path==='/org')return ['status'=>200,'body'=>['org'=>[['id'=>'123']]]];
        if($path==='/users?type=CurrentUser')return ['status'=>200,'body'=>['users'=>[['id'=>'456']]]];
        $count=is_file(getenv('STAFF_TEST_PRIVATE').'/readiness-single.json')?1:2;
        $contacts=array_slice([['id'=>'789','Full_Name'=>'Fixture Contact A','Email'=>$email],['id'=>'790','Full_Name'=>'Fixture Contact B','Email'=>$email]],0,$count);
        if(str_starts_with($path,'/Contacts/search'))return ['status'=>200,'body'=>['data'=>$contacts,'info'=>['more_records'=>false]]];
        foreach($contacts as $contact)if($path==='/Contacts/'.$contact['id'])return ['status'=>200,'body'=>['data'=>[$contact]]];
        throw new RuntimeException('Unknown synthetic CRM read.');
    };
    $calendar=static function($method,$path,$body=null): array {
        if($method!=='GET')throw new RuntimeException('Readiness fixture blocks calendar writes.');
        if(str_contains($path,'calendarView'))return ['status'=>200,'body'=>['value'=>[]]];
        return ['status'=>200,'body'=>['id'=>BOOKING_MS_CALENDAR,'canEdit'=>true,'isDefaultCalendar'=>true,'owner'=>['address'=>BOOKING_MS_MAILBOX]]];
    };
    return ['crm_config'=>booking_crm_config(),'crm'=>$crm,'calendar_config'=>booking_scheduling_ms_config(),'calendar'=>$calendar,'graph'=>static function(){throw new RuntimeException('Fixture mail is unavailable; no external call.');},'lock_path'=>getenv('STAFF_TEST_PRIVATE').'/data/readiness-fixture.lock'];
}
function booking_workflow_check(PDO $db,string $reference,array $deps=[]): array
{
    return booking_workflow_check_fixture_real($db,$reference,$deps+staff_fixture_readiness_deps($db,$reference));
}
function booking_workflow_link(PDO $db,string $reference,string $id,array $ticket,array $deps=[]): void
{
    booking_workflow_link_fixture_real($db,$reference,$id,$ticket,$deps+staff_fixture_readiness_deps($db,$reference));
}
