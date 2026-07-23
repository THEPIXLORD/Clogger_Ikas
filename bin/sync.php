<?php
// ikas → Workcube eşitleme koşusu (cron uyumlu, tek geçiş).
//
// Kullanım:
//   php bin/sync.php                  # son eşitlemeden bu yana güncellenen siparişleri işle
//   php bin/sync.php --dry-run        # ERP'ye yazmadan ne yapılacağını göster
//   php bin/sync.php --mock=DOSYA     # ikas yerine dosyadan sipariş oku (test)
//
// Akış: yeni sipariş → cari eşle/aç + ORDERS/ORDER_ROW yaz;
//       mevcut sipariş → ödeme/iptal durumu değiştiyse ORDER_STAGE güncelle.

declare(strict_types=1);
require __DIR__ . '/../src/IkasClient.php';
require __DIR__ . '/../src/SyncStore.php';
require __DIR__ . '/../src/WorkcubeWriter.php';

$cfg = require __DIR__ . '/../config.php';
$secenek = getopt('', ['dry-run', 'mock:']);
$dry = isset($secenek['dry-run']);

// Eşzamanlı koşu kilidi: cron + elle koşu çakışırsa (2026-07-22 kazası) mükerrer sipariş yazılır.
$kilit = fopen(__DIR__ . '/../var/sync.lock', 'c');
if (!$kilit || !flock($kilit, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Başka bir eşitleme koşusu aktif — çıkılıyor.\n");
    exit(0);
}

$store = new SyncStore($cfg);
$wc = new WorkcubeWriter($cfg);
$hedefDb = $wc->hedefDb();

// ── Siparişleri topla ─────────────────────────────────────────────────────────
const SIPARIS_ALANLARI = <<<'GQL'
    id orderNumber status orderPaymentStatus currencyCode
    totalFinalPrice orderedAt updatedAt
    customer { id email firstName lastName phone }
    billingAddress { firstName lastName company taxNumber taxOffice identityNumber phone
                     addressLine1 addressLine2 city { name } district { name } }
    shippingLines { price }
    orderLineItems { id quantity finalPrice finalUnitPrice
                     variant { id sku barcodeList name } }
    paymentMethods { type price }
GQL;

if (isset($secenek['mock'])) {
    $siparisler = json_decode((string)file_get_contents($secenek['mock']), true);
    if (!is_array($siparisler)) { fwrite(STDERR, "Mock dosyası okunamadı\n"); exit(1); }
    if (isset($siparisler['id'])) $siparisler = [$siparisler];   // tek sipariş de kabul et
    $store->log('bilgi', 'Mock modu: ' . count($siparisler) . ' sipariş ' . basename($secenek['mock']) . ' dosyasından okundu');
} else {
    $ikas = new IkasClient($cfg);
    $imlecKey = 'son_esitleme_' . $cfg['ortam'];
    // imleç epoch-ms tutulur (ikas updatedAt bu formatta dönüyor)
    $son = (int)$store->stateAl($imlecKey, (string)((time() - 86400) * 1000));
    // taşkın payı: son eşitlemeden N dk geriden tara (kaçan güncelleme güvencesi; mükerrer işlem imkânsız,
    // çünkü sipariş eşleme tablosunda varsa yalnız durum farkına bakılır)
    $gte = max(0, $son - $cfg['tarama_taskini_dk'] * 60 * 1000);
    $siparisler = [];
    for ($sayfa = 1; ; $sayfa++) {
        $d = $ikas->gql('query($p: PaginationInput, $u: DateFilterInput) {
            listOrder(pagination: $p, updatedAt: $u, sort: "updatedAt") {
                count data { ' . SIPARIS_ALANLARI . ' } } }',
            ['p' => ['limit' => 50, 'page' => $sayfa],
             'u' => ['gte' => $gte]]);
        $parca = $d['listOrder']['data'] ?? [];
        $siparisler = array_merge($siparisler, $parca);
        if (count($parca) < 50) break;
    }
    $store->log('bilgi', "ikas'tan " . count($siparisler) . " sipariş alındı (updatedAt >= $gte)");
}

// ── Yardımcılar ───────────────────────────────────────────────────────────────

/** ikas tarihi (epoch-ms veya ISO) → 'Y-m-d H:i:s' (Türkiye saati). */
function tarihTR($v): string
{
    if (is_numeric($v)) {
        return (new DateTime('@' . intdiv((int)$v, 1000)))
            ->setTimezone(new DateTimeZone('Europe/Istanbul'))->format('Y-m-d H:i:s');
    }
    return str_replace('T', ' ', substr((string)$v, 0, 19));
}

/** ikas tarihi → epoch milisaniye (imleç karşılaştırması için). */
function tarihMs($v): int
{
    return is_numeric($v) ? (int)$v : (int)(strtotime((string)$v) * 1000);
}

/** Siparişin kredi kartıyla çekilen toplamını döner (0 ise KK ödemesi yok). */
function krediKartiTutari(array $o): float
{
    $t = 0.0;
    foreach ($o['paymentMethods'] ?? [] as $pm) {
        if (($pm['type'] ?? '') === 'CREDIT_CARD') $t += (float)($pm['price'] ?? 0);
    }
    return round($t, 2);
}

/** Sipariş iptale/iadeye düşmeden ödemesi alınmış mı? */
function odendiMi(array $o): bool
{
    return !in_array($o['status'], ['CANCELLED', 'REFUNDED'], true)
        && $o['orderPaymentStatus'] !== 'REFUNDED'
        && in_array($o['orderPaymentStatus'], ['PAID', 'OVER_PAID'], true);
}

/** ikas durumlarından Workcube süreç aşamasını türetir (ürüne özel ödendi aşaması dahil). */
function stageHesapla(array $o, array $cfg): int
{
    $stage = $cfg['stage'];
    if (in_array($o['status'], ['CANCELLED', 'REFUNDED'], true)
        || $o['orderPaymentStatus'] === 'REFUNDED') return $stage['iptal'];
    if (!odendiMi($o)) return $stage['bekliyor'];   // WAITING / PARTIALLY_PAID / FAILED
    foreach ($o['orderLineItems'] ?? [] as $li) {   // ör. Clg-Klb-200 → Cihaz Bekleniyor
        $ozel = $cfg['odendi_stage_map'][$li['variant']['sku'] ?? ''] ?? null;
        if ($ozel) return (int)$ozel;
    }
    return $stage['odendi'];
}

// ── Siparişleri işle ──────────────────────────────────────────────────────────
$yeni = 0; $guncellenen = 0; $degismeyen = 0; $hatali = 0;
$sonGorulen = null;

foreach ($siparisler as $o) {
    try {
        if (($o['updatedAt'] ?? null) && tarihMs($o['updatedAt']) > (int)$sonGorulen) {
            $sonGorulen = tarihMs($o['updatedAt']);
        }
        if ($o['status'] === 'DRAFT') { $degismeyen++; continue; }   // taslaklar ERP'ye gitmez

        $stage = stageHesapla($o, $cfg);
        $kayit = $store->siparisBul($o['id'], $hedefDb);

        // ── Mevcut sipariş: yalnız durum farkı işlenir ──
        if ($kayit) {
            if ((int)$kayit['wc_stage'] === $stage) { $degismeyen++; continue; }
            if ($dry) {
                $store->log('bilgi', "[DRY] {$o['orderNumber']}: aşama {$kayit['wc_stage']} → $stage olacaktı", $o['id']);
                $guncellenen++; continue;
            }
            $wc->stageGuncelle((int)$kayit['wc_order_id'], $stage, odendiMi($o));
            $store->siparisDurumGuncelle($o['id'], $hedefDb, $o['orderPaymentStatus'], $o['status'], $stage);
            $store->log('bilgi', sprintf('%s: aşama %d → %d (ödeme=%s, durum=%s), WC sipariş #%d',
                $o['orderNumber'], $kayit['wc_stage'], $stage,
                $o['orderPaymentStatus'], $o['status'], $kayit['wc_order_id']), $o['id']);
            // KK ile ödendiyse ve tahsilat kaydı henüz yoksa şimdi düş
            $kkTutar = krediKartiTutari($o);
            if (odendiMi($o) && $kkTutar > 0
                    && !$kayit['kk_tahsilat_id'] && $cfg['kk_tahsilat']['aktif']) {
                $tid = $wc->kkTahsilatYaz((int)$kayit['wc_order_id'], (int)$kayit['wc_company_id'],
                    $kkTutar, substr(tarihTR($o['orderedAt']), 0, 10),
                    'IKAS-' . $o['orderNumber'] . ' - ikas e-ticaret KK tahsilatı');
                $store->kkTahsilatIsaretle($o['id'], $hedefDb, $tid);
                $store->log('bilgi', "{$o['orderNumber']}: KK tahsilat kaydı #$tid düşüldü ($kkTutar TL)", $o['id']);
            }
            $guncellenen++; continue;
        }

        // ── Yeni sipariş ──
        // 1) Satırları ürünlere eşle (eşleşmeyen ürün varsa sipariş TÜMÜYLE atlanır — yarım sipariş yazılmaz)
        $satirlar = [];
        foreach ($o['orderLineItems'] as $li) {
            $v = $li['variant'];
            $barkod = $v['barcodeList'][0] ?? '';
            $urun = $wc->urunBul($v['sku'] ?? '', $barkod);
            if (!$urun) {
                throw new RuntimeException("Ürün ERP'de bulunamadı: SKU='{$v['sku']}' barkod='$barkod' ({$v['name']})");
            }
            $kdv = (float)($urun['TAX'] ?: $cfg['kdv_varsayilan']);
            // Birim satış (KDV dahil): finalPrice = satırın gerçekte tahsil edilen toplamı
            // (kart komisyonu/vade farkı dahil). finalUnitPrice canlı API'de boş dönebiliyor!
            $adet = (float)$li['quantity'] ?: 1;
            $birimDahil = (float)($li['finalUnitPrice'] ?: 0) ?: ((float)$li['finalPrice'] / $adet);
            if ($birimDahil <= 0) {
                throw new RuntimeException("Satır fiyatı sıfır geldi: SKU='{$v['sku']}' ({$v['name']}) — sipariş işlenmedi");
            }
            $satirlar[] = [
                'stock_id' => (int)$urun['STOCK_ID'], 'product_id' => (int)$urun['PRODUCT_ID'],
                'ad' => $v['name'], 'adet' => $adet, 'kdv_orani' => $kdv,
                'matrah_birim' => $birimDahil / (1 + $kdv / 100),  // ikas fiyatı KDV dahil
            ];
        }
        // kargo bedeli
        $kargo = array_sum(array_column($o['shippingLines'] ?? [], 'price'));
        if ($kargo > 0) {
            if ($cfg['kargo_stock_id']) {
                $kdv = (float)$cfg['kdv_varsayilan'];
                $satirlar[] = ['stock_id' => (int)$cfg['kargo_stock_id'], 'product_id' => (int)$cfg['kargo_stock_id'],
                               'ad' => 'Kargo Bedeli (ikas)', 'adet' => 1, 'kdv_orani' => $kdv,
                               'matrah_birim' => $kargo / (1 + $kdv / 100)];
            } else {
                $store->log('uyari', "{$o['orderNumber']}: kargo bedeli $kargo TL kargo_stock_id tanımsız olduğu için sipariş satırlarına EKLENMEDİ", $o['id']);
            }
        }

        // 2) Cari eşle / aç
        // Tip kuralı (Erdem, 2026-07-15): şirket → 10 haneli VKN TAXNO'ya; şahıs firması →
        // 11 haneli TC kimlik no TAXNO'ya (IS_CIVIL_COMPANY=1) + yetkilinin TC_IDENTITY'sine.
        $fatura = $o['billingAddress'] ?? [];
        $vkn = preg_replace('/\D+/', '', (string)($fatura['taxNumber'] ?? ''));
        $tc  = preg_replace('/\D+/', '', (string)($fatura['identityNumber'] ?? ''));
        if ($vkn !== '' && strlen($vkn) === 11 && $tc === '') { $tc = $vkn; $vkn = ''; } // TC, taxNumber alanına yazılmış olabilir
        $eposta = $o['customer']['email'] ?? null;

        if ($vkn !== '' && strlen($vkn) === 10) {
            $tip = 'kurumsal'; $vergiNo = $vkn;              // şirket: VKN
        } elseif ($tc !== '' && strlen($tc) === 11) {
            $tip = 'sahis';    $vergiNo = $tc;               // şahıs firması: TC kimlik no
        } else {
            $tip = 'bireysel'; $vergiNo = '';                // vergi kimliği yok
            if ($vkn !== '' || $tc !== '') {
                $store->log('uyari', "{$o['orderNumber']}: vergi/TC no hatalı uzunlukta (vkn='$vkn' tc='$tc') — cari kimliksiz açılacak", $o['id']);
            }
        }
        $kurumsal = $tip === 'kurumsal' && !empty($fatura['company']);

        if ($dry) {
            $store->log('bilgi', sprintf('[DRY] %s: %d satır, %.2f TL, aşama %d, cari=%s(%s) olacaktı',
                $o['orderNumber'], count($satirlar), $o['totalFinalPrice'], $stage,
                $tip, $vergiNo ?: $eposta), $o['id']);
            $yeni++; continue;
        }

        $musteriKaydi = $o['customer']['id'] ? $store->musteriBul($o['customer']['id'], $hedefDb) : null;
        if ($musteriKaydi) {
            $companyId = (int)$musteriKaydi['wc_company_id'];
            $partnerId = $musteriKaydi['wc_partner_id'] ? (int)$musteriKaydi['wc_partner_id'] : null;
        } else {
            $mevcut = $wc->cariBul($vergiNo ?: null, $eposta);
            if ($mevcut) {
                $companyId = (int)$mevcut['COMPANY_ID'];
                $partnerId = $mevcut['MANAGER_PARTNER_ID'] ? (int)$mevcut['MANAGER_PARTNER_ID'] : null;
                $store->log('bilgi', "{$o['orderNumber']}: mevcut cari {$mevcut['MEMBER_CODE']} ile eşleşti", $o['id']);
            } else {
                $ad = trim((string)($o['customer']['firstName'] ?? $fatura['firstName'] ?? ''));
                $soyad = trim((string)($o['customer']['lastName'] ?? $fatura['lastName'] ?? ''));
                $tel = preg_replace('/\D+/', '', (string)($o['customer']['phone'] ?? $fatura['phone'] ?? ''));
                if (strlen($tel) === 12 && str_starts_with($tel, '90')) $tel = substr($tel, 2);
                $adres = trim(($fatura['addressLine1'] ?? '') . ' ' . ($fatura['addressLine2'] ?? '')
                        . ' ' . ($fatura['district']['name'] ?? '') . '/' . ($fatura['city']['name'] ?? ''));
                $cari = $wc->cariAc([
                    'tip' => $tip,
                    // şahıs firmasında da fatura ünvanı (company) doluysa o kullanılır
                    'unvan' => !empty($fatura['company']) ? $fatura['company'] : trim("$ad $soyad"),
                    'vergi_no' => $vergiNo, 'vergi_dairesi' => $fatura['taxOffice'] ?? '',
                    'tc' => $tc,
                    'eposta' => $eposta, 'adres' => $adres,
                    'tel_kod' => $tel ? '+90' . substr($tel, 0, 3) : '',
                    'tel' => $tel ? substr($tel, 3) : '',
                    'ad' => $ad ?: 'Yetkili', 'soyad' => $soyad ?: '-',
                    'ikas_customer_id' => $o['customer']['id'],
                ]);
                $companyId = $cari['company_id']; $partnerId = $cari['partner_id'];
                $store->log('bilgi', "{$o['orderNumber']}: yeni cari açıldı {$cari['member_code']} ($tip" . ($vergiNo ? ", no=$vergiNo" : '') . ')', $o['id']);
            }
            if ($o['customer']['id']) {
                $store->musteriKaydet($o['customer']['id'], $hedefDb, $companyId, $partnerId,
                    $eposta, $vergiNo ?: null, $tip);
            }
        }

        // 2b) Ürüne bağlı cari muhasebe kodu (ortam bazlı harita; ör. web barındırma → 120.01.xxxx)
        $hesapKodlari = $cfg['cari_hesap_kodu_map'][$cfg['ortam']] ?? [];
        foreach ($o['orderLineItems'] as $li) {
            $kod = $hesapKodlari[$li['variant']['sku'] ?? ''] ?? null;
            if (!$kod) continue;
            $sonuc = $wc->cariHesapKoduAta($companyId, $kod);
            if (str_starts_with($sonuc, 'farkli:')) {
                $store->log('uyari', "{$o['orderNumber']}: cari #$companyId muhasebe kodu $kod ATANMADI — mevcut kod korunuyor: " . substr($sonuc, 7), $o['id']);
            } elseif ($sonuc !== 'zaten') {
                $store->log('bilgi', "{$o['orderNumber']}: cari #$companyId muhasebe kodu $kod işlendi ($sonuc)", $o['id']);
            }
            break;   // tek kod yeter; ilk eşleşen kural kazanır
        }

        // 3) Siparişi yaz
        $wcOrderId = $wc->siparisAc([
            'ikas_order_id' => $o['id'], 'order_number' => $o['orderNumber'],
            'tarih' => tarihTR($o['orderedAt']),
            'odeme_tipi' => $o['paymentMethods'][0]['type'] ?? '',
            'odendi' => odendiMi($o),
        ], $companyId, $partnerId, $satirlar, $stage);

        $store->siparisKaydet([
            'ikas_order_id' => $o['id'], 'hedef_db' => $hedefDb,
            'ikas_order_number' => (string)$o['orderNumber'], 'wc_order_id' => $wcOrderId,
            'wc_company_id' => $companyId, 'odeme_durumu' => $o['orderPaymentStatus'],
            'siparis_durumu' => $o['status'], 'wc_stage' => $stage,
            'tutar' => $o['totalFinalPrice'], 'veri' => $o,
        ]);
        $store->log('bilgi', sprintf('%s: WC sipariş #%d açıldı (%.2f TL, %d satır, aşama %d)',
            $o['orderNumber'], $wcOrderId, $o['totalFinalPrice'], count($satirlar), $stage), $o['id']);

        // Sipariş zaten KK ile ödenmiş geldiyse tahsilat kaydını hemen düş
        $kkTutar = krediKartiTutari($o);
        if (odendiMi($o) && $kkTutar > 0 && $cfg['kk_tahsilat']['aktif']) {
            $tid = $wc->kkTahsilatYaz($wcOrderId, $companyId, $kkTutar,
                substr(tarihTR($o['orderedAt']), 0, 10),
                'IKAS-' . $o['orderNumber'] . ' - ikas e-ticaret KK tahsilatı');
            $store->kkTahsilatIsaretle($o['id'], $hedefDb, $tid);
            $store->log('bilgi', "{$o['orderNumber']}: KK tahsilat kaydı #$tid düşüldü ($kkTutar TL)", $o['id']);
        }
        $yeni++;
    } catch (Throwable $e) {
        $hatali++;
        $store->log('hata', "{$o['orderNumber']}: " . $e->getMessage(), $o['id'] ?? null);
    }
}

// imleci ilerlet (yalnız gerçek ikas koşusunda ve hatasız geçişte)
if (!isset($secenek['mock']) && !$dry && $sonGorulen && $hatali === 0) {
    $store->stateYaz($imlecKey, (string)$sonGorulen);
}

printf("Bitti: %d yeni, %d durum güncellendi, %d değişmedi, %d hatalı (ortam: %s → %s)\n",
    $yeni, $guncellenen, $degismeyen, $hatali, $cfg['ortam'], $hedefDb);
exit($hatali > 0 ? 1 : 0);
