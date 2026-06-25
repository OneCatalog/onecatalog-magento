<?php
namespace OneCatalog\Import\Service;
require __DIR__ . '/../app/code/OneCatalog/Import/Service/Units.php';
$fail = 0;
function chk($l,$g,$w,&$fail){ $ok=abs((float)$g-(float)$w)<0.0001; echo ($ok?'  ok  ':'  FAIL ')."$l\n"; if(!$ok)$fail++; }
chk('1000g→kg', Units::weight(1000,'kg'), 1.0, $fail);
chk('1200mm→cm', Units::length(1200,'cm'), 120.0, $fail);
chk('25.4mm→in', Units::length(25.4,'in'), 1.0, $fail);
chk('2kg→2000g base', Units::toBaseWeight(2,'kg'), 2000.0, $fail);
chk('120cm→1200mm base', Units::toBaseLength(120,'cm'), 1200.0, $fail);
echo $fail===0?"\nALL PASS\n":"\n$fail FAILED\n"; exit($fail?1:0);
