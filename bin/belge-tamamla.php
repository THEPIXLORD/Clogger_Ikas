<?php
// Belge no'suz kalan IKAS kredi kartı tahsilatlarını tamamlar (bakım komutu, idempotent).
//
// Ne yapar: ACTION_DETAIL 'IKAS-%' olup PAPER_NO'su boş kayıtlara, Workcube'ün
// GENERAL_PAPERS sayacından sırayla belge no (BKKT-n) verir ve kk_banka_hesap_id
// tanımlıysa banka hesabına bağlar (ACTION_TO_ACCOUNT_ID + IS_ACCOUNT=1).
// Yalnız İKAS kayıtlarına dokunur; dolu belge no'ları asla değiştirmez.
//
// Kullanım: php bin/belge-tamamla.php [--dry-run]

declare(strict_types=1);
$cfg = require __DIR__ . '/../config.php';
$dry = in_array('--dry-run', $argv, true);

$ort = $cfg['ortamlar'][$cfg['ortam']];
$m = $cfg['mssql'];
$sema = $ort['siparis_sema'];
$onek = $cfg['kk_tahsilat']['belge_onek'] ?? 'BKKT';
$hesapId = $ort['kk_banka_hesap_id'] ?? null;

if (in_array('sqlsrv', PDO::getAvailableDrivers(), true)) {
    $wc = new PDO("sqlsrv:Server={$m['host']},{$m['port']};Database={$ort['hedef_db']};TrustServerCertificate=1",
        $m['user'], $m['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                                 PDO::SQLSRV_ATTR_ENCODING => PDO::SQLSRV_ENCODING_UTF8]);
} else {
    $wc = new PDO("dblib:host={$m['host']}:{$m['port']};dbname={$ort['hedef_db']};charset=UTF-8",
        $m['user'], $m['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}

$st = $wc->prepare("SELECT CREDITCARD_PAYMENT_ID, ACTION_DETAIL FROM [$sema].CREDIT_CARD_BANK_PAYMENTS
                     WHERE ACTION_DETAIL LIKE 'IKAS-%' AND (PAPER_NO IS NULL OR PAPER_NO = '')
                     ORDER BY CREDITCARD_PAYMENT_ID");
$st->execute();
$kayitlar = $st->fetchAll(PDO::FETCH_ASSOC);
if (!$kayitlar) { echo "Belge no'suz IKAS tahsilatı yok — her şey tamam.\n"; exit(0); }

echo count($kayitlar) . " kayıt tamamlanacak (ortam: {$cfg['ortam']} → {$ort['hedef_db']}, hesap: "
   . ($hesapId ?: 'yok') . ")\n";
if ($dry) { foreach ($kayitlar as $r) echo "  [DRY] #{$r['CREDITCARD_PAYMENT_ID']} {$r['ACTION_DETAIL']}\n"; exit(0); }

$wc->beginTransaction();
try {
    // sayacı kilitle ve tek seferde ilerlet
    $q = $wc->prepare("SELECT CREDITCARD_REVENUE_NUMBER FROM [$sema].GENERAL_PAPERS WITH (UPDLOCK)
                        WHERE CREDITCARD_REVENUE_NO = ?");
    $q->execute([$onek]);
    $n = $q->fetchColumn();
    if ($n === false) throw new RuntimeException("GENERAL_PAPERS'ta '$onek' sayacı yok");
    $n = (int)$n;

    $g = $wc->prepare("UPDATE [$sema].CREDIT_CARD_BANK_PAYMENTS
                          SET PAPER_NO = ?, ACTION_TO_ACCOUNT_ID = COALESCE(?, ACTION_TO_ACCOUNT_ID),
                              IS_ACCOUNT = CASE WHEN ? IS NULL THEN IS_ACCOUNT ELSE 1 END
                        WHERE CREDITCARD_PAYMENT_ID = ? AND (PAPER_NO IS NULL OR PAPER_NO = '')");
    foreach ($kayitlar as $r) {
        $n++;
        $g->execute(["$onek-$n", $hesapId, $hesapId, $r['CREDITCARD_PAYMENT_ID']]);
        echo "  #{$r['CREDITCARD_PAYMENT_ID']} {$r['ACTION_DETAIL']} → $onek-$n\n";
    }
    $wc->prepare("UPDATE [$sema].GENERAL_PAPERS SET CREDITCARD_REVENUE_NUMBER = ?
                   WHERE CREDITCARD_REVENUE_NO = ?")->execute([$n, $onek]);
    $wc->commit();
    echo "Bitti — sayaç $onek-$n'e ilerletildi.\n";
} catch (Throwable $e) {
    $wc->rollBack();
    fwrite(STDERR, 'HATA (geri alındı): ' . $e->getMessage() . "\n");
    exit(1);
}
