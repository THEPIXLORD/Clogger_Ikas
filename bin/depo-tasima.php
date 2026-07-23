<?php
// Mac'teki MySQL deposunu ERP sunucusundaki MSSQL ikas_entegrasyon'a taşır.
// MAC'TE, BİR KEZ çalıştırılır (Mac her iki veritabanına da erişebiliyor).
// Önkoşul: kurulum/mssql-kurulum.sql ERP sunucusunda çalıştırılmış olmalı.
//
// Kullanım: php bin/depo-tasima.php

declare(strict_types=1);
require __DIR__ . '/../src/SyncStore.php';
$cfg = require __DIR__ . '/../config.php';

$kaynak = new SyncStore(array_merge($cfg, ['depo' => 'mysql']));
$hedef  = new SyncStore(array_merge($cfg, ['depo' => 'mssql']));

// SyncStore private $db — taşıma için yansımayla eriş (tek seferlik araç)
$al = function (SyncStore $s): PDO {
    $p = new ReflectionProperty(SyncStore::class, 'db');
    return $p->getValue($s);
};
$my = $al($kaynak);
$ms = $al($hedef);

$toplam = 0;

foreach ($my->query('SELECT anahtar, deger FROM sync_state') as $r) {
    $hedef->stateYaz($r['anahtar'], $r['deger']);
    $toplam++;
}

$st = $ms->prepare('SELECT 1 FROM musteri WHERE ikas_customer_id=? AND hedef_db=?');
foreach ($my->query('SELECT * FROM musteri') as $r) {
    $st->execute([$r['ikas_customer_id'], $r['hedef_db']]);
    if ($st->fetchColumn()) continue;
    $ms->prepare('INSERT INTO musteri (ikas_customer_id, hedef_db, wc_company_id, wc_partner_id, eposta, vergi_no, tip)
                  VALUES (?,?,?,?,?,?,?)')
       ->execute([$r['ikas_customer_id'], $r['hedef_db'], $r['wc_company_id'], $r['wc_partner_id'],
                  $r['eposta'], $r['vergi_no'], $r['tip']]);
    $toplam++;
}

$st = $ms->prepare('SELECT 1 FROM siparis WHERE ikas_order_id=? AND hedef_db=?');
foreach ($my->query('SELECT * FROM siparis') as $r) {
    $st->execute([$r['ikas_order_id'], $r['hedef_db']]);
    if ($st->fetchColumn()) continue;
    $ms->prepare('INSERT INTO siparis (ikas_order_id, hedef_db, ikas_order_number, wc_order_id, wc_company_id,
                                       odeme_durumu, siparis_durumu, wc_stage, kk_tahsilat_id, tutar, veri)
                  VALUES (?,?,?,?,?,?,?,?,?,?,?)')
       ->execute([$r['ikas_order_id'], $r['hedef_db'], $r['ikas_order_number'], $r['wc_order_id'],
                  $r['wc_company_id'], $r['odeme_durumu'], $r['siparis_durumu'], $r['wc_stage'],
                  $r['kk_tahsilat_id'], $r['tutar'], $r['veri']]);
    $toplam++;
}

echo "Taşınan kayıt: $toplam\n";
echo "MSSQL doğrulama — siparis: " . $ms->query('SELECT COUNT(*) FROM siparis')->fetchColumn()
   . ", musteri: " . $ms->query('SELECT COUNT(*) FROM musteri')->fetchColumn()
   . ", sync_state: " . $ms->query('SELECT COUNT(*) FROM sync_state')->fetchColumn() . "\n";
