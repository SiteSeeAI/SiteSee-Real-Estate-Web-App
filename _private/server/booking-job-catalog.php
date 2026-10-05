<?php
declare(strict_types=1);

/** Onsite prices always come from the same PHP calculators used to place orders. */
function booking_job_catalog(array $row): array
{
    $quote=booking_request($row)['quote'];
    $commercial=$row['market']==='commercial';
    $categories=$commercial
        ? ['small'=>'Small Commercial / Retail','mid'=>'Warehouse / Office','large'=>'Factory / Industrial']
        : ['small'=>'Small Home / Condo','average'=>'Average Home','large'=>'Large Home','luxury'=>'Luxury Home'];
    $category=array_search($quote['category']??'', $categories, true)?:'small';
    $base=$commercial?real_estate_commercial_quote(['category'=>$category,'selected'=>[]])
        :real_estate_residential_quote(['package'=>'custom','category'=>'small','sqft'=>1200,'selected'=>[]]);
    $ordered=array_column($quote['lines']??[],'key');
    // Photography is mandatory on every original order, including older quote shapes.
    $ordered[]='photo';
    $number=static fn(string $key,string $label,int $min,int $max,mixed $value):array=>compact('key','label','min','max','value')+['type'=>'number'];
    $choice=static fn(string $key,string $label,array $options,string $value):array=>compact('key','label','options','value')+['type'=>'select'];
    $fields=[];
    if($commercial){
        [$min,$max,$included]=match($category){'mid'=>[30,55,45],'large'=>[45,65,55],default=>[25,35,30]};
        $fields['photo']=[$number('photoCount','Number Of Photos',$min,$max,$included)];
        $fields['platform']=[$number('platformMonths','Platform Term (Months)',6,18,6)];
        $fields['mp']=[$number('matterportSqft','Matterport Coverage (SQF)',1,$category==='small'?10000:20000,($quote['matterportSqft']??0)?:''),
            $number('hostingMonths','Matterport Hosting (Months)',6,18,6),
            $choice('hostingPrepaid','Hosting Payment',['no'=>'Monthly after six included months','yes'=>'Pay extra months in advance'],'no')];
        $fields['views360']=[$number('views360','Number Of 360° Photos',1,100,1)];
        $fields['drone']=[$number('aerialImages','Number Of Aerial Images',1,100,1)];
        $fields['floor']=[$number('plans','Number Of Floor Plan Sets',1,20,1)];
    }else{
        $fields['photo']=[$choice('category','Photography Category',$categories,$category),$number('sqft','Photography Coverage (SQF)',1,10000,($quote['sqft']??0)?:'')];
        $fields['mp']=[$number('matterportSqft','Matterport Coverage (SQF)',1,10000,($quote['matterportSqft']??$quote['sqft']??0)?:'')];
        $fields['twilight']=[$number('images','Number Of Twilight Images',1,100,1)];
    }
    $fields['video']=array_merge($commercial?[$number('videos','Number Of Finished Videos',1,20,1)]:[],[
        $number('videoMinutes','Number Of Minutes',1,3,1),$number('videoSecondsPart','Additional Seconds',0,59,0)]);
    if($commercial)foreach(['photo','drone','video'] as $key){
        $fields[$key][]=$choice('licenseType','Media License',['term'=>'Fixed term','unlimited'=>'Unlimited'],$quote['licenseType']??'term');
        $fields[$key][]=$number('licenseMonths','License Term (Months)',6,18,$quote['licenseMonths']??6);
    }
    $services=[];
    foreach($base['services'] as $key=>$label){
        $note='';
        if(!$commercial&&$key==='platform')$note='$49/month after publication, billed separately. No monthly fee or commission is collected in this final payment.';
        if($commercial&&$key==='mp')$note='First six hosting months are included. Additional hosting follows the ordering form prices.';
        if($commercial&&$key==='views360')$note='Requires Matterport and SiteSee Platform on the original order or in these additions.';
        $services[$key]=['label'=>$label,'fields'=>$fields[$key]??[],'ordered'=>in_array($key,$ordered,true),'note'=>$note];
    }
    return ['market'=>$row['market'],'category'=>$category,'services'=>$services];
}

function booking_job_item_inputs(array $service,array $input): array
{
    $out=[];
    foreach($service['fields'] as $field){
        $key=$field['key'];$value=$input[$key]??$field['value'];
        if($key==='licenseMonths'&&($out['licenseType']??'')==='unlimited'){$out[$key]=6;continue;}
        if($field['type']==='number')$out[$key]=real_estate_integer($value,$field['min'],$field['max'],'Enter a valid '.$field['label'].'.');
        else{
            if(!is_string($value)||!isset($field['options'][$value]))throw new InvalidArgumentException('Choose a valid '.$field['label'].'.');
            $out[$key]=$value;
        }
    }
    return $out;
}

/** No posted price, commission, label or approval is trusted. */
function booking_job_catalog_lines(array $row,array $items,array $saved): array
{
    $catalog=booking_job_catalog($row);$commercial=$catalog['market']==='commercial';
    if(!array_is_list($items)||count($items)>20)throw new InvalidArgumentException('Choose a valid list of additional services.');
    $keys=[];$normalized=[];$legacy=[];
    foreach($saved as $line)if(!isset($line['service']))$legacy[$line['id']]=$line;
    foreach($items as $item){
        if(!is_array($item))throw new InvalidArgumentException('Choose a valid additional service.');
        if(isset($item['legacy_id'])){
            $id=$item['legacy_id'];
            if(!is_string($id)||!isset($legacy[$id])||isset($keys['legacy:'.$id]))throw new InvalidArgumentException('The saved service changed. Refresh before continuing.');
            $keys['legacy:'.$id]=true;$normalized[]=['legacy'=>$legacy[$id]];continue;
        }
        $key=$item['service']??null;
        if(!is_string($key)||!isset($catalog['services'][$key])||isset($keys[$key]))throw new InvalidArgumentException('Choose each available service only once.');
        $input=$item['inputs']??[];if(!is_array($input))throw new InvalidArgumentException('Complete the service quantities.');
        $keys[$key]=true;$normalized[]=['service'=>$key,'inputs'=>booking_job_item_inputs($catalog['services'][$key],$input)];
    }
    if(isset($keys['views360']))foreach(['mp','platform'] as $required){
        if(!isset($keys[$required])&&!$catalog['services'][$required]['ordered'])throw new InvalidArgumentException('Single 360° Views require Matterport and SiteSee Platform. Add the missing service first.');
    }
    $out=[];
    foreach($normalized as $item){
        if(isset($item['legacy'])){$out[]=$item['legacy'];continue;}
        $key=$item['service'];$input=$item['inputs'];$service=$catalog['services'][$key];
        $state=['category'=>$catalog['category'],'selected'=>[$key],'delivery'=>'files','package'=>'custom','sqft'=>1200,
            'photoCount'=>match($catalog['category']){'mid'=>45,'large'=>55,default=>30},'hostingMonths'=>6,'hostingPrepaid'=>false,
            'platformMonths'=>6,'licenseType'=>'term','licenseMonths'=>6];
        $state=array_replace($state,$input);
        if($key==='video')$state['videoSeconds']=$input['videoMinutes']*60+$input['videoSecondsPart'];
        if($key==='mp'&&!$commercial){
            $state['sqft']=$input['matterportSqft'];
            $state['category']=$state['sqft']<2000?'small':($state['sqft']<=4000?'average':($state['sqft']<=5000?'large':'luxury'));
        }elseif(!$commercial&&$key!=='photo')$state['category']='small';
        if($commercial){
            if($key==='mp')$state['hostingPrepaid']=$input['hostingPrepaid']==='yes';
            if($key==='views360'){$state['selected']=['views360','mp','platform'];$state['matterportSqft']=1;}
            $priced=real_estate_commercial_quote($state);
        }else $priced=real_estate_residential_quote($state);
        $byKey=array_column($priced['lines'],null,'key');$line=$byKey[$key];$cents=$line['cents'];$details=[];
        if($key==='mp'&&!$commercial)$line['label'].=' · '.number_format($input['matterportSqft']).' sq ft scanned';
        $commissionBase=$key==='platform'?0:$cents;
        if($commercial){
            if($key==='photo'&&isset($byKey['extraPhotos'])){$cents+=$byKey['extraPhotos']['cents'];$commissionBase+=$byKey['extraPhotos']['cents'];$details[]=$byKey['extraPhotos']['label'];}
            if(in_array($key,['photo','drone','video'],true)){
                // The calculator's license includes mandatory photography. Subtract that
                // unchanged base for drone/video so originals are never charged again.
                $license=$byKey['license']['cents'];
                if($key!=='photo'){
                    $baseState=$state;$baseState['selected']=[];
                    $license-=real_estate_commercial_quote($baseState)['licenseCents'];
                }
                $cents+=$license;
                if($license)$details[]=$byKey['license']['label'].' · '.real_estate_money($license);
            }
            if($key==='mp'){$cents+=$priced['hostingCents'];$details[]=$byKey['hosting']['label'];}
        }
        $monthly=!$commercial&&$key==='platform'?$cents:0;if($monthly)$cents=0;
        $out[]=['id'=>substr(hash('sha256','catalog:'.$key),0,16),'service'=>$key,'inputs'=>$input,
            'label'=>$line['label'],'details'=>$details,'cents'=>$cents,'monthly_cents'=>$monthly,
            'commission_eligible'=>!$service['ordered']&&$key!=='platform',
            'commission_base_cents'=>!$service['ordered']?$commissionBase:0];
    }
    if(array_sum(array_column($out,'cents'))>10000000)throw new InvalidArgumentException('This additional scope needs manual billing review.');
    return $out;
}

function booking_job_commission(array $lines): array
{
    $basis=0;$unclassified=false;$monthly=0;
    foreach($lines as $line){
        if(!isset($line['service']))$unclassified=true;
        elseif(($line['commission_eligible']??false)===true)$basis+=(int)($line['commission_base_cents']??0);
        $monthly+=(int)($line['monthly_cents']??0);
    }
    return ['commission_basis_cents'=>$basis,'commission_cents'=>intdiv($basis*18+50,100),
        'commission_unclassified'=>$unclassified,'additional_monthly_cents'=>$monthly];
}

function booking_job_onsite_preview(PDO $db,string $reference,array $items,string $draftScope): array
{
    $row=booking_job_guard($db,$reference);
    if(booking_job_get($db,$reference))throw new InvalidArgumentException('The final bill is already fixed.');
    $draft=booking_job_extras($db,$reference);
    if(!hash_equals($draft['scope'],$draftScope))throw new InvalidArgumentException('Additional services changed. Reload this booking before continuing.');
    $lines=booking_job_catalog_lines($row,$items,json_decode($draft['lines_json'],true,16,JSON_THROW_ON_ERROR));
    $json=json_encode($lines,JSON_THROW_ON_ERROR);
    if($json!==$draft['lines_json']){
        $draft['revision']=(int)$draft['revision']+1;$draft['lines_json']=$json;
        $draft['scope']=hash('sha256',$reference.':'.$draft['revision'].':'.$json);$draft['approved_by']=null;$draft['approved_at']=null;
    }
    $bill=booking_job_bill_from($row,$draft,portal_balance_paid($db,$reference));
    return ['draft'=>$draft,'bill'=>$bill];
}

/** Caller holds the closeout transaction; this never changes the original booking. */
function booking_job_write_draft(PDO $db,array $draft): void
{
    $db->prepare('INSERT INTO booking_job_extras(reference,revision,lines_json,scope,approved_by,approved_at) VALUES (?,?,?,?,?,?) ON CONFLICT(reference) DO UPDATE SET revision=excluded.revision,lines_json=excluded.lines_json,scope=excluded.scope,approved_by=excluded.approved_by,approved_at=excluded.approved_at')
        ->execute([$draft['reference'],$draft['revision'],$draft['lines_json'],$draft['scope'],$draft['approved_by'],$draft['approved_at']]);
}
