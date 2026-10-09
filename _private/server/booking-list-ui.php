<?php
declare(strict_types=1);
function booking_list_options(array $input): array
{
    $out=['show'=>'both','open_size'=>'5','previous_size'=>'5','open_page'=>1,'previous_page'=>1];
    foreach(['show'=>['both','open','previous'],'open_size'=>['5','25','all'],'previous_size'=>['5','25','all']] as $key=>$allowed)
        if(is_string($input[$key]??null)&&in_array($input[$key],$allowed,true))$out[$key]=$input[$key];
    foreach(['open_page','previous_page'] as $key)if(is_string($input[$key]??null)&&ctype_digit($input[$key])&&strlen($input[$key])<=7)$out[$key]=max(1,min(1000000,(int)$input[$key]));
    return $out;
}
/** Pure HTML around role-owned rows; no provider operation, state transition or data query. */
function booking_lists_html(array $groups,array $options,string $path,array $base,callable $render): string
{
    $e=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    $html='<form method="get" class="order-list-options">';
    foreach($base as $key=>$value)$html.='<input type="hidden" name="'.$e($key).'" value="'.$e($value).'">';
    $html.='<label>Show orders<select name="show">';
    foreach(['both'=>'Open and previous','open'=>'Open only','previous'=>'Previous only'] as $key=>$label)$html.='<option value="'.$key.'"'.($options['show']===$key?' selected':'').'>'.$label.'</option>';
    $html.='</select></label>';
    foreach(['open'=>'Open orders per page','previous'=>'Previous orders per page'] as $group=>$label){
        $html.='<label>'.$label.'<select name="'.$group.'_size">';
        foreach(['5'=>'5','25'=>'25','all'=>'All'] as $value=>$text)$html.='<option value="'.$value.'"'.($options[$group.'_size']===(string)$value?' selected':'').'>'.$text.'</option>';
        $html.='</select></label>';
    }
    $html.='<button>Update Lists</button></form><div class="order-columns">';
    foreach(['open'=>'Open Orders','previous'=>'Previous Orders'] as $group=>$label){
        if($options['show']!=='both'&&$options['show']!==$group)continue;
        $result=$groups[$group];$html.='<section class="order-column" aria-labelledby="orders-'.$group.'"><h2 id="orders-'.$group.'">'.$label.'</h2><p class="help">'.$result['total'].' '.($group==='open'?'orders awaiting SiteSee closeout':'completed or staff-closed orders').'</p><div class="order-list">';
        foreach($result['orders'] as $row)$html.=$render($row);
        if(!$result['orders'])$html.='<p>No '.strtolower($label).' to show.</p>';
        $html.='</div><nav class="pagination" aria-label="'.$label.' Pages"><span>Page '.$result['page'].' of '.$result['pages'].'</span>';
        foreach([-1=>'Previous',1=>'Next'] as $direction=>$text){$page=$result['page']+$direction;if($page<1||$page>$result['pages'])continue;
            $query=$base+$options;$query[$group.'_page']=$page;$html.='<a href="'.$e($path.'?'.http_build_query($query,'','&',PHP_QUERY_RFC3986)).'">'.$text.'</a>';
        }
        $html.='</nav></section>';
    }
    return $html.'</div>';
}
