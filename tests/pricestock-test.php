<?php
namespace OneCatalog\Import\Service;
require __DIR__ . "/../app/code/OneCatalog/Import/Service/PriceStock.php";
$fail = 0;
function chk($l,$g,$w,&$fail){ $ok=((string)$g===(string)$w); echo ($ok?'  ok  ':'  FAIL ')."$l (got=".var_export($g,true)." want=".var_export($w,true).")\n"; if(!$ok)$fail++; }

$o1 = array('supplier_id'=>1,'code'=>'A1','status'=>1,
  'product_prices'=>array(array('region_id'=>10,'base_price'=>100,'promo_price'=>90,'purchasing_price'=>70),
                          array('region_id'=>20,'base_price'=>110)),
  'products_stocks'=>array(array('warehouse_id'=>1,'quantity'=>5),array('warehouse_id'=>2,'quantity'=>3)));
$o2 = array('supplier_id'=>2,'code'=>'B2','status'=>1,
  'product_prices'=>array(array('region_id'=>10,'base_price'=>80)),
  'products_stocks'=>array(array('warehouse_id'=>1,'quantity'=>2)));
$legacy = array('supplier'=>array('id'=>7));

// supplier id (оба формата)
chk('supplierId scalar', PriceStock::offerSupplierId($o1), 1, $fail);
chk('supplierId legacy', PriceStock::offerSupplierId($legacy), 7, $fail);

// приоритет регионов
$p = PriceStock::priceForOffer($o1, array(20,10)); chk('region prio 20→110', $p['base'], 110, $fail);
$p = PriceStock::priceForOffer($o1, array(10));    chk('region prio 10→100', $p['base'], 100, $fail);

// стратегии
$r = PriceStock::resolvePrice(array($o1,$o2), array(10), array(), 'min'); chk('min → 80', $r['regular'], 80, $fail);
$r = PriceStock::resolvePrice(array($o1,$o2), array(10), array(1), 'priority'); chk('priority [1] → 100', $r['regular'], 100, $fail);
$r = PriceStock::resolvePrice(array($o1,$o2), array(10), array(), 'supplier', 2); chk('supplier-fixed 2 → 80', $r['regular'], 80, $fail);

// скидка
$r = PriceStock::resolvePrice(array($o1), array(10), array(), 'min', 0, true); chk('promo 90<100 → sale 90', $r['sale'], 90, $fail);
$r = PriceStock::resolvePrice(array($o1), array(20), array(), 'min', 0, true); chk('no promo (region20) → no sale', var_export($r['sale'],true), 'NULL', $fail);

// остаток
chk('stock sum = 8', PriceStock::resolveStock(array($o1,$o2)), 10, $fail);

// сигнатура: qty игнорируется при manage off
$a = PriceStock::signature(array('regular'=>100,'sale'=>null,'manage'=>false,'qty'=>5,'status'=>'instock'));
$b = PriceStock::signature(array('regular'=>100,'sale'=>null,'manage'=>false,'qty'=>99,'status'=>'instock'));
chk('sig игнорит qty при manage off', ($a===$b)?'1':'0', '1', $fail);
$c = PriceStock::signature(array('regular'=>100,'sale'=>null,'manage'=>true,'qty'=>5,'status'=>'instock'));
$d = PriceStock::signature(array('regular'=>100,'sale'=>null,'manage'=>true,'qty'=>99,'status'=>'instock'));
chk('sig учитывает qty при manage on', ($c!==$d)?'1':'0', '1', $fail);

// полный резолв
$rec = PriceStock::resolveRecord(array($o1,$o2), array('region_prio'=>array(10),'strategy'=>'min','promo_as_sale'=>true,'manage_stock'=>true,'decimal_stock'=>false));
chk('record qty=10 (floor)', $rec['qty'], 10, $fail);
chk('record status instock', $rec['status'], 'instock', $fail);

echo $fail===0 ? "\nALL PASS\n" : "\n$fail FAILED\n";
exit($fail===0?0:1);
