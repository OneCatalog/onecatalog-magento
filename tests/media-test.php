<?php
namespace OneCatalog\Import\Service;
require __DIR__ . '/../app/code/OneCatalog/Import/Service/Media.php';
$fail=0;
function chk($l,$g,$w,&$fail){ $ok=((string)$g===(string)$w); echo ($ok?'  ok  ':'  FAIL ')."$l\n"; if(!$ok)$fail++; }
function seg($raw){ return strtr(base64_encode($raw),'+/','-_'); }
$u1='https://api/media_files/'.seg('max|images/foo/bar|TOK1').'.S1';
$u2='https://api/media_files/'.seg('max|images/foo/bar|TOK2').'.S2';
chk('fileKey stable across tokens', Media::fileKey($u1), Media::fileKey($u2), $fail);
chk('fileKey = sha1(path#size)', Media::fileKey($u1), sha1('images/foo/bar#max'), $fail);
$p=Media::pickSizeInfo(['min'=>'a','middle'=>'b','max'=>'c'], true);  chk('pick token→max',$p['size'],'max',$fail);
$p=Media::pickSizeInfo(['min'=>'a','middle'=>'b','max'=>'c'], false); chk('pick no-token→middle',$p['size'],'middle',$fail);
chk('mime jpeg→jpg', Media::mimeToExt('image/jpeg'),'jpg',$fail);
chk('mime svg→null', var_export(Media::mimeToExt('image/svg+xml'),true),'NULL',$fail);
echo $fail===0?"\nALL PASS\n":"\n$fail FAILED\n"; exit($fail?1:0);
