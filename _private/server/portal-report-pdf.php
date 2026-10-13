<?php
declare(strict_types=1);

/** Downloadable UTF-8 report. Embeds the existing, licensed Inter font, no external service. */
function portal_report_pdf(array $report): string
{
    $local=dirname(__DIR__,2).'/public/assets/fonts/Inter-Regular.ttf';
    $path=in_array(PHP_SAPI,['cli','cli-server'],true)&&is_file($local)?$local:'/home/sitesee/public_html/re/assets/fonts/Inter-Regular.ttf';
    $font=file_get_contents($path);
    // Deployed private and public roots are separate; use the fixed application document root.
    if($font===false)throw new RuntimeException('Report font unavailable.');
    $u16=static fn($offset)=>unpack('n',substr($font,$offset,2))[1];$u32=static fn($offset)=>unpack('N',substr($font,$offset,4))[1];
    $tables=[];for($i=0;$i<$u16(4);$i++){$pos=12+$i*16;$tables[substr($font,$pos,4)]=$u32($pos+8);}
    foreach(['cmap','hmtx','hhea','head'] as $key)if(!isset($tables[$key]))throw new RuntimeException('Report font differs.');
    $cmap=$tables['cmap'];$map4=null;$map12=null;
    for($i=0;$i<$u16($cmap+2);$i++){$offset=$cmap+$u32($cmap+4+$i*8+4);$format=$u16($offset);if($format===4)$map4=$offset;if($format===12)$map12=$offset;}
    $glyph=static function(int $code)use($map4,$map12,$u16,$u32):int{
        if($map12!==null)for($i=0;$i<$u32($map12+12);$i++){$pos=$map12+16+$i*12;if($code>=$u32($pos)&&$code<=$u32($pos+4))return $u32($pos+8)+$code-$u32($pos);}
        if($map4!==null&&$code<=65535){$segments=intdiv($u16($map4+6),2);$ends=$map4+14;$starts=$ends+$segments*2+2;$deltas=$starts+$segments*2;$ranges=$deltas+$segments*2;
            for($i=0;$i<$segments;$i++){if($code<$u16($starts+$i*2)||$code>$u16($ends+$i*2))continue;$delta=$u16($deltas+$i*2);$range=$u16($ranges+$i*2);$gid=$range?$u16($ranges+$i*2+$range+2*($code-$u16($starts+$i*2))):$code;return $gid?($gid+$delta)%65536:0;}}
        return 0;
    };
    $units=$u16($tables['head']+18);$metrics=$u16($tables['hhea']+34);$characters=[];
    $encode=static function(string $text)use(&$characters,$glyph,$u16,$tables,$units,$metrics):array{
        $hex='';$width=0;
        foreach(preg_split('//u',$text,-1,PREG_SPLIT_NO_EMPTY)?:[] as $char){
            if(!isset($characters[$char])){$bytes=array_values(unpack('C*',$char));
                $code=match(count($bytes)){1=>$bytes[0],2=>(($bytes[0]&31)<<6)|($bytes[1]&63),3=>(($bytes[0]&15)<<12)|(($bytes[1]&63)<<6)|($bytes[2]&63),4=>(($bytes[0]&7)<<18)|(($bytes[1]&63)<<12)|(($bytes[2]&63)<<6)|($bytes[3]&63)};
                $unicode=$code<=65535?sprintf('%04X',$code):sprintf('%04X%04X',55296+(($code-65536)>>10),56320+(($code-65536)&1023));
                $gid=$glyph($code);if($gid===0)throw new InvalidArgumentException('PDF Download is unavailable for a character in these records. Your complete text remains available in the on-screen report.');$characters[$char]=['cid'=>count($characters)+1,'gid'=>$gid,'unicode'=>$unicode,'width'=>(int)round($u16($tables['hmtx']+min($gid,$metrics-1)*4)*1000/$units)];}
            $c=$characters[$char];$hex.=sprintf('%04X',$c['cid']);$width+=$c['width'];
        }
        return [$hex,$width];
    };
    $lines=[];$add=static function(string $text,int $size=10)use(&$lines,$encode):void{
        // Wrap by actual font advances, including long addresses without spaces.
        $line='';$width=0;
        foreach(preg_split('//u',preg_replace('/[\x00-\x1f\x7f]/u',' ',$text)??'',-1,PREG_SPLIT_NO_EMPTY)?:[] as $char){[, $advance]=$encode($char);$advance=$advance*$size/1000;
            if($width+$advance>510&&$line!==''){$lines[]=[$line,$size];$line='';$width=0;}$line.=$char;$width+=$advance;}
        $lines[]=[$line,$size];
    };
    $add('SiteSee · Expense Report · TEST',18);$add($report['range']['from'].' through '.$report['range']['to'].' · Central Time',11);$add('');
    foreach(['paid'=>'Cash paid','refunded'=>'Confirmed refunds','net'=>'Net cash expenses','credit'=>'Credit applied (separate)'] as $key=>$label)$add($label.': '.($report[$key]===null?'Needs Billing Review':real_estate_money($report[$key])),12);
    $add('');$add(portal_report_notes(),9);
    if($report['review'])$add('Some records need billing review. Pending refunds are excluded.',10);
    $add('');
    foreach($report['entries'] as $entry){$add($entry['date'].' · '.$entry['order_number'].' · '.match($entry['kind']){'paid'=>'Cash payment','refunded'=>'Confirmed refund',default=>'Credit applied'}.' · '.real_estate_money($entry['amount']),11);$add($entry['property'],10);$add('');}
    if(!$report['entries'])$add('No recorded expenses in this date range.');
    $chunks=array_chunk($lines,46);$contents=[];
    foreach($chunks as $index=>$page){$content="BT\n";$y=750;
        foreach($page as [$text,$size]){[$hex]=$encode($text);$content.='/F1 '.$size.' Tf 1 0 0 1 42 '.$y.' Tm <'.$hex."> Tj\n";$y-=15;}
        [$footer]=$encode('SiteSee · TEST · Page '.($index+1).' of '.count($chunks));$content.='/F1 9 Tf 1 0 0 1 42 28 Tm <'.$footer."> Tj\nET\n";$contents[]=$content;}
    $gidMap=pack('n',0);$widths=[];$unicode=[];
    foreach($characters as $c){$gidMap.=pack('n',$c['gid']);$widths[]=$c['width'];$unicode[]='<'.sprintf('%04X',$c['cid']).'> <'.$c['unicode'].'>';}
    $toUnicode="/CIDInit /ProcSet findresource begin\n12 dict begin\nbegincmap\n/CIDSystemInfo << /Registry (Adobe) /Ordering (UCS) /Supplement 0 >> def\n/CMapName /SiteSeeUnicode def\n/CMapType 2 def\n1 begincodespacerange\n<0000> <FFFF>\nendcodespacerange\n";
    foreach(array_chunk($unicode,100) as $block)$toUnicode.=count($block)." beginbfchar\n".implode("\n",$block)."\nendbfchar\n";
    $toUnicode.="endcmap CMapName currentdict /CMap defineresource pop end end";
    $stream=static function(string $bytes,string $extra=''):string{$compressed=function_exists('gzcompress')?gzcompress($bytes):$bytes;return '<< /Length '.strlen($compressed).(function_exists('gzcompress')?' /Filter /FlateDecode ':' ').$extra.">>\nstream\n".$compressed."\nendstream";};
    $objects=[1=>'<< /Type /Catalog /Pages 2 0 R >>',3=>'<< /Type /Font /Subtype /Type0 /BaseFont /Inter /Encoding /Identity-H /DescendantFonts [4 0 R] /ToUnicode 7 0 R >>',4=>'<< /Type /Font /Subtype /CIDFontType2 /BaseFont /Inter /CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >> /FontDescriptor 5 0 R /CIDToGIDMap 8 0 R /W [1 ['.implode(' ',$widths).']] >>',5=>'<< /Type /FontDescriptor /FontName /Inter /Flags 32 /FontBBox [-1000 -1000 2500 1500] /ItalicAngle 0 /Ascent 1000 /Descent -250 /CapHeight 750 /StemV 80 /FontFile2 6 0 R >>',6=>$stream($font,'/Length1 '.strlen($font)),7=>$stream($toUnicode),8=>$stream($gidMap)];
    $kids=[];foreach($contents as $i=>$content){$id=9+$i*2;$kids[]=$id.' 0 R';$objects[$id]='<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 3 0 R >> >> /Contents '.($id+1).' 0 R >>';$objects[$id+1]=$stream($content);}
    $objects[2]='<< /Type /Pages /Count '.count($contents).' /Kids ['.implode(' ',$kids).'] >>';ksort($objects);
    $pdf="%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";$offsets=[0];foreach($objects as $id=>$object){$offsets[$id]=strlen($pdf);$pdf.=$id." 0 obj\n".$object."\nendobj\n";}
    $xref=strlen($pdf);$pdf.='xref'."\n0 ".(count($objects)+1)."\n0000000000 65535 f \n";for($i=1;$i<=count($objects);$i++)$pdf.=sprintf("%010d 00000 n \n",$offsets[$i]);
    return $pdf.'trailer << /Size '.(count($objects)+1).' /Root 1 0 R >>'."\nstartxref\n".$xref."\n%%EOF\n";
}
