<?php
// Workcube ERP yazıcısı — cari (COMPANY + COMPANY_PARTNER), sipariş (ORDERS + ORDER_ROW)
// ve süreç aşaması güncellemeleri. Kayıt desenleri crm-arsiv aktarımlarıyla canlıda
// doğrulanmış şablonlardan uyarlandı (bkz. ~/crm-arsiv/public/wc.php).

class WorkcubeWriter
{
    private PDO $wc;
    private array $cfg;      // tüm yapılandırma
    private array $ort;      // aktif ortam şablonu (hedef_db, siparis_sema, PERIOD_ID...)

    public function __construct(array $cfg)
    {
        $this->cfg = $cfg;
        $this->ort = $cfg['ortamlar'][$cfg['ortam']];
        $m = $cfg['mssql'];
        $hedef = $this->ort['hedef_db'];
        // Sürücü otomatik: Windows (ERP sunucusu) → sqlsrv; Mac → dblib. SyncStore ile aynı desen.
        if (in_array('sqlsrv', PDO::getAvailableDrivers(), true)) {
            $this->wc = new PDO("sqlsrv:Server={$m['host']},{$m['port']};Database=$hedef;TrustServerCertificate=1",
                $m['user'], $m['pass'], [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::SQLSRV_ATTR_ENCODING => PDO::SQLSRV_ENCODING_UTF8,  // Türkçe karakterler için şart
                ]);
        } else {
            $this->wc = new PDO("dblib:host={$m['host']}:{$m['port']};dbname=$hedef;charset=UTF-8",
                $m['user'], $m['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        }
    }

    public function hedefDb(): string { return $this->ort['hedef_db']; }

    /** SKU (STOCK_CODE) veya barkodla ürün arar; önce urun_map'ten ERP koduna çevirir. */
    public function urunBul(?string $sku, ?string $barkod): ?array
    {
        $sku = $this->cfg['urun_map'][$sku] ?? $sku;
        $sema = $this->ort['siparis_sema'];
        $st = $this->wc->prepare(
            "SELECT TOP 1 STOCK_ID, PRODUCT_ID, PRODUCT_NAME, TAX
               FROM [$sema].STOCKS
              WHERE (? <> '' AND STOCK_CODE = ?) OR (? <> '' AND BARCOD = ?)");
        $st->execute([(string)$sku, (string)$sku, (string)$barkod, (string)$barkod]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Vergi no / TC kimlik no veya e-posta ile mevcut cari arar. */
    public function cariBul(?string $vergiNo, ?string $eposta): ?array
    {
        $h = $this->ort['hedef_db'];
        if ($vergiNo) {
            // TAXNO'da (VKN veya şahıs TC'si) ve yetkili kişinin TC_IDENTITY'sinde ara
            $st = $this->wc->prepare(
                "SELECT TOP 1 COMPANY_ID, MEMBER_CODE, MANAGER_PARTNER_ID
                   FROM [$h].COMPANY WHERE LTRIM(RTRIM(TAXNO)) = ?
                 UNION
                 SELECT TOP 1 C.COMPANY_ID, C.MEMBER_CODE, C.MANAGER_PARTNER_ID
                   FROM [$h].COMPANY_PARTNER P
                   JOIN [$h].COMPANY C ON C.COMPANY_ID = P.COMPANY_ID
                  WHERE LTRIM(RTRIM(P.TC_IDENTITY)) = ?");
            $st->execute([trim($vergiNo), trim($vergiNo)]);
            if ($r = $st->fetch(PDO::FETCH_ASSOC)) return $r;
        }
        if ($eposta) {
            $st = $this->wc->prepare(
                "SELECT TOP 1 COMPANY_ID, MEMBER_CODE, MANAGER_PARTNER_ID
                   FROM [$h].COMPANY WHERE COMPANY_EMAIL = ?");
            $st->execute([trim($eposta)]);
            if ($r = $st->fetch(PDO::FETCH_ASSOC)) return $r;
        }
        return null;
    }

    /**
     * ikas müşterisinden cari açar. tip: 'kurumsal' (VKN → TAXNO),
     * 'sahis' (şahıs firması: TC → TAXNO + IS_CIVIL_COMPANY=1) veya
     * 'bireysel' (kimliksiz, IS_CIVIL_COMPANY=1). TC ayrıca yetkilinin
     * TC_IDENTITY alanına yazılır. COMPANY_PARTNER olmadan Workcube
     * arayüzü cariyi listelemez; o yüzden yetkili kişi de burada açılır.
     * Dönen: [company_id, partner_id, member_code]
     */
    public function cariAc(array $m): array
    {
        $h = $this->ort['hedef_db'];
        $s = $this->ort;
        $kurumsal = $m['tip'] === 'kurumsal';

        $this->wc->beginTransaction();
        try {
            $st = $this->wc->prepare(
                "INSERT INTO [$h].COMPANY
                    (FULLNAME, NICKNAME, TAXNO, TAXOFFICE, COMPANY_EMAIL,
                     MOBIL_CODE, MOBILTEL, COMPANY_ADDRESS,
                     COMPANY_STATUS, COMPANYCAT_ID, OUR_COMPANY_ID, IS_BUYER, IS_SELLER,
                     ISPOTANTIAL, IS_EXPORT, IS_PERSON, IS_CIVIL_COMPANY, PERIOD_ID, COMPANY_STATE,
                     COUNTRY, RECORD_EMP, RECORD_IP, WRK_ID, RECORD_DATE)
                 VALUES (?,?,?,?,?,?,?,?, 1, ?, ?, 1, 0, 0, 0, ?, 0, ?, ?, ?, ?, '127.0.0.1', ?, GETDATE())");
                 // IS_PERSON: şahıs/bireysel=1 (formdaki 'Şahıs' kutusu); IS_CIVIL_COMPANY=Kamu, DAİMA 0
            $st->execute([
                mb_substr($m['unvan'], 0, 250), mb_substr($m['unvan'], 0, 250),
                $m['vergi_no'] ?? '', $m['vergi_dairesi'] ?? '', $m['eposta'] ?? '',
                $m['tel_kod'] ?? '', $m['tel'] ?? '', mb_substr($m['adres'] ?? '', 0, 500),
                $s['COMPANYCAT_ID'], $s['OUR_COMPANY_ID'], $kurumsal ? 0 : 1,
                $s['PERIOD_ID'], $s['COMPANY_STATE'], $s['COUNTRY'], $s['RECORD_EMP'],
                date('YmdHis') . '_ikas_' . substr($m['ikas_customer_id'] ?? '0', 0, 8),
            ]);
            $companyId = (int)$this->wc->query('SELECT SCOPE_IDENTITY()')->fetchColumn();
            if (!$companyId) {  // dblib bazı sürümlerde scope kaybeder; adla geri bul
                $st = $this->wc->prepare("SELECT MAX(COMPANY_ID) FROM [$h].COMPANY WHERE FULLNAME = ?");
                $st->execute([mb_substr($m['unvan'], 0, 250)]);
                $companyId = (int)$st->fetchColumn();
            }
            $memberCode = 'C' . $companyId;
            $this->wc->prepare("UPDATE [$h].COMPANY SET MEMBER_CODE = ? WHERE COMPANY_ID = ?")
                     ->execute([$memberCode, $companyId]);

            // Yetkili kişi
            $st = $this->wc->prepare(
                "INSERT INTO [$h].COMPANY_PARTNER
                    (COMPANY_PARTNER_STATUS, COMPANY_ID, COMPANY_PARTNER_NAME, COMPANY_PARTNER_SURNAME,
                     COMPANY_PARTNER_EMAIL, TC_IDENTITY, MOBIL_CODE, MOBILTEL, COUNTRY,
                     LANGUAGE_ID, TIME_ZONE, IS_AGENDA_OPEN, MEMBER_TYPE, WANT_EMAIL, WANT_SMS,
                     RECORD_MEMBER, RECORD_IP, RECORD_DATE)
                 VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, 'tr', 0, 1, 1, 1, 1, ?, '127.0.0.1', GETDATE())");
            $st->execute([$companyId, mb_substr($m['ad'], 0, 50), mb_substr($m['soyad'], 0, 50),
                          mb_substr($m['eposta'] ?? '', 0, 100), $m['tc'] ?? '',
                          $m['tel_kod'] ?? '', $m['tel'] ?? '', $s['COUNTRY'], $s['RECORD_EMP']]);
            $partnerId = (int)$this->wc->query('SELECT SCOPE_IDENTITY()')->fetchColumn();
            if (!$partnerId) {
                $st = $this->wc->prepare("SELECT MAX(PARTNER_ID) FROM [$h].COMPANY_PARTNER WHERE COMPANY_ID = ?");
                $st->execute([$companyId]);
                $partnerId = (int)$st->fetchColumn();
            }
            $this->wc->prepare("UPDATE [$h].COMPANY_PARTNER SET MEMBER_CODE = ? WHERE PARTNER_ID = ?")
                     ->execute(['CP' . $partnerId, $partnerId]);
            $this->wc->prepare("UPDATE [$h].COMPANY SET MANAGER_PARTNER_ID = ? WHERE COMPANY_ID = ?")
                     ->execute([$partnerId, $companyId]);
            $this->wc->commit();
        } catch (Throwable $e) {
            $this->wc->rollBack();
            throw $e;
        }
        return ['company_id' => $companyId, 'partner_id' => $partnerId, 'member_code' => $memberCode];
    }

    /**
     * ikas siparişini ORDERS + ORDER_ROW olarak açar. $satirlar: her biri
     * [stock_id, product_id, ad, adet, kdv_orani, matrah_birim] (matrah = KDV hariç birim).
     * Dönen: wc_order_id
     */
    public function siparisAc(array $sip, int $companyId, ?int $partnerId, array $satirlar, int $stage): int
    {
        $sema = $this->ort['siparis_sema'];
        $grossToplam = 0.0; $kdvToplam = 0.0;
        foreach ($satirlar as $r) {
            $grossToplam += $r['matrah_birim'] * $r['adet'];
            $kdvToplam   += $r['matrah_birim'] * $r['adet'] * $r['kdv_orani'] / 100;
        }
        $grossToplam = round($grossToplam, 2);
        $kdvToplam = round($kdvToplam, 2);
        $netToplam = round($grossToplam + $kdvToplam, 2);
        $isPaid = !empty($sip['odendi']) ? 1 : 0;   // aşamadan bağımsız (ürüne özel aşamalar var)
        $paymethod = $this->cfg['paymethod_map'][$sip['odeme_tipi'] ?? ''] ?? null;

        $this->wc->beginTransaction();
        try {
            $this->wc->prepare(
                "INSERT INTO [$sema].ORDERS
                    (WRK_ID, ORDER_HEAD, ORDER_NUMBER, ORDER_DATE, DELIVERDATE, ORDER_STAGE, ORDER_STATUS,
                     ORDER_ZONE, PURCHASE_SALES, COMPANY_ID, PARTNER_ID, ORDER_EMPLOYEE_ID,
                     IS_WORK, IS_PROCESSED, INCLUDED_KDV, INVISIBLE,
                     RESERVED, DISCOUNTTOTAL, GROSSTOTAL, NETTOTAL, OTV_TOTAL, TAXTOTAL,
                     OTHER_MONEY, OTHER_MONEY_VALUE, IS_PAID, PAYMETHOD,
                     IS_RECEIVED_WEBSERVICE, COUNTRY_ID, RECORD_DATE, RECORD_EMP, RECORD_IP)
                 VALUES (?, ?, ?, ?, DATEADD(day, 4, CAST(? AS datetime)), ?, 1, 0, 1, ?, ?, ?,
                         0, 0, 0, 0, 0, 0, ?, ?, 0, ?, 'TL', ?, ?, ?, 1, 215, GETDATE(), ?, '127.0.0.1')")
                ->execute([
                    date('YmdHis') . '_ikas_' . substr($sip['ikas_order_id'], 0, 8),
                    'ikas e-ticaret - ' . $sip['order_number'],
                    'IKAS-' . $sip['order_number'],
                    $sip['tarih'], $sip['tarih'], $stage,
                    $companyId, $partnerId, $this->ort['ORDER_EMPLOYEE_ID'],
                    $grossToplam, $netToplam, $kdvToplam, $netToplam, $isPaid, $paymethod,
                    $this->ort['RECORD_EMP'],
                ]);
            $orderId = (int)$this->wc->query('SELECT SCOPE_IDENTITY()')->fetchColumn();
            if (!$orderId) {
                $st = $this->wc->prepare("SELECT MAX(ORDER_ID) FROM [$sema].ORDERS WHERE ORDER_NUMBER = ?");
                $st->execute(['IKAS-' . $sip['order_number']]);
                $orderId = (int)$st->fetchColumn();
            }

            $rowSt = $this->wc->prepare(
                "INSERT INTO [$sema].ORDER_ROW
                    (ORDER_ID, STOCK_ID, PRODUCT_ID, PRODUCT_NAME, QUANTITY, PRICE, PRICE_OTHER, UNIT, UNIT_ID,
                     TAX, NETTOTAL, ORDER_ROW_CURRENCY, OTHER_MONEY, OTHER_MONEY_VALUE, COST_PRICE, EXTRA_COST,
                     MARJ, PROM_COST, IS_PROMOTION, IS_COMMISSION, OTVTOTAL, RESERVE_TYPE, WRK_ROW_ID, CANCEL_AMOUNT)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 'Adet', ?, ?, ?, -5, 'TL', ?, 0, 0, 0, 0, 0, 0, 0, -1, ?, 0)");
            $i = 0;
            foreach ($satirlar as $r) {
                $i++;
                $satirMatrah = round($r['matrah_birim'] * $r['adet'], 2);
                $rowSt->execute([
                    $orderId, $r['stock_id'], $r['product_id'], mb_substr($r['ad'], 0, 250),
                    $r['adet'], round($r['matrah_birim'], 4), round($r['matrah_birim'], 4),
                    $r['stock_id'], $r['kdv_orani'], $satirMatrah, $satirMatrah,
                    'ikas_' . substr($sip['ikas_order_id'], 0, 8) . '_' . $i . '_' . $orderId,
                ]);
            }
            $this->wc->commit();
        } catch (Throwable $e) {
            $this->wc->rollBack();
            throw $e;
        }
        return $orderId;
    }

    /**
     * Carinin muhasebe (alıcılar) hesap kodunu COMPANY_PERIOD'a işler.
     * Dönen durum: 'atandi' | 'guncellendi' (boş koddan) | 'zaten' (aynı kod) |
     * 'farkli:<mevcut>' (dolu ve farklı kod — dokunulmaz, çağıran uyarı loglar).
     */
    public function cariHesapKoduAta(int $companyId, string $kod): string
    {
        $h = $this->ort['hedef_db'];
        $periodId = $this->ort['PERIOD_ID'];
        $st = $this->wc->prepare(
            "SELECT TOP 1 ID, ACCOUNT_CODE FROM [$h].COMPANY_PERIOD
              WHERE COMPANY_ID = ? AND PERIOD_ID = ? ORDER BY ID DESC");
        $st->execute([$companyId, $periodId]);
        $mevcut = $st->fetch(PDO::FETCH_ASSOC);

        if (!$mevcut) {
            $this->wc->prepare(
                "INSERT INTO [$h].COMPANY_PERIOD (COMPANY_ID, PERIOD_ID, ACCOUNT_CODE) VALUES (?,?,?)")
                ->execute([$companyId, $periodId, $kod]);
            return 'atandi';
        }
        $eski = trim((string)$mevcut['ACCOUNT_CODE']);
        if ($eski === $kod) return 'zaten';
        if ($eski !== '') return 'farkli:' . $eski;
        $this->wc->prepare("UPDATE [$h].COMPANY_PERIOD SET ACCOUNT_CODE = ? WHERE ID = ?")
                 ->execute([$kod, (int)$mevcut['ID']]);
        return 'guncellendi';
    }

    /**
     * Kredi kartı tahsilat kaydı yazar (CREDIT_CARD_BANK_PAYMENTS →
     * bank.list_creditcard_revenue ekranı). Tutar KDV dahil çekilen toplamdır.
     * Dönen: CREDITCARD_PAYMENT_ID
     */
    public function kkTahsilatYaz(int $wcOrderId, int $companyId, float $tutar, string $tarih, string $detay): int
    {
        $sema = $this->ort['siparis_sema'];
        $kk = $this->cfg['kk_tahsilat'];
        $hesapId = $this->ort['kk_banka_hesap_id'] ?? null;   // ör. canlı: 29 (Iyzico)
        $this->wc->beginTransaction();
        try {
            // Belge no: Workcube'ün kendi sayacından (GENERAL_PAPERS, tek satır kilitli artış)
            $paperNo = null;
            $onek = $kk['belge_onek'] ?? 'BKKT';
            $st = $this->wc->prepare(
                "UPDATE [$sema].GENERAL_PAPERS SET CREDITCARD_REVENUE_NUMBER = CREDITCARD_REVENUE_NUMBER + 1
                  WHERE CREDITCARD_REVENUE_NO = ?");
            $st->execute([$onek]);
            if ($st->rowCount() > 0) {
                $q = $this->wc->prepare(
                    "SELECT CREDITCARD_REVENUE_NUMBER FROM [$sema].GENERAL_PAPERS WHERE CREDITCARD_REVENUE_NO = ?");
                $q->execute([$onek]);
                $paperNo = $onek . '-' . (int)$q->fetchColumn();
            }

            $this->wc->prepare(
                "INSERT INTO [$sema].CREDIT_CARD_BANK_PAYMENTS
                    (PAYMENT_TYPE_ID, STORE_REPORT_DATE, SALES_CREDIT, NUMBER_OF_INSTALMENT,
                     ACTION_DETAIL, ACTION_FROM_COMPANY_ID, ACTION_TYPE, PROCESS_CAT,
                     ORDER_ID, IS_ONLINE_POS, IS_VOID, ACTION_PERIOD_ID,
                     PAPER_NO, ACTION_TO_ACCOUNT_ID, IS_ACCOUNT,
                     RECORD_EMP, RECORD_DATE, RECORD_IP)
                 VALUES (?, CAST(? AS datetime), ?, 1, ?, ?, ?, ?, ?, 1, 0, ?, ?, ?, ?, ?, GETDATE(), '127.0.0.1')")
                ->execute([$kk['payment_type_id'], substr($tarih, 0, 10), round($tutar, 2),
                           mb_substr($detay, 0, 250), $companyId, $kk['action_type'], $kk['process_cat'],
                           $wcOrderId, $this->ort['PERIOD_ID'],
                           $paperNo, $hesapId, $hesapId ? 1 : 0,
                           $this->ort['RECORD_EMP']]);
            $id = (int)$this->wc->query('SELECT SCOPE_IDENTITY()')->fetchColumn();
            if (!$id) {
                $st = $this->wc->prepare(
                    "SELECT MAX(CREDITCARD_PAYMENT_ID) FROM [$sema].CREDIT_CARD_BANK_PAYMENTS WHERE ORDER_ID = ?");
                $st->execute([$wcOrderId]);
                $id = (int)$st->fetchColumn();
            }
            $this->wc->commit();
        } catch (Throwable $e) {
            $this->wc->rollBack();
            throw $e;
        }
        return $id;
    }

    /** Sipariş süreç aşamasını günceller (ödeme onayı / iptal / ürüne özel aşama). */
    public function stageGuncelle(int $wcOrderId, int $stage, bool $odendi): void
    {
        $sema = $this->ort['siparis_sema'];
        $isPaid = $odendi ? 1 : 0;
        $this->wc->prepare(
            "UPDATE [$sema].ORDERS
                SET ORDER_STAGE = ?, IS_PAID = ?, UPDATE_DATE = GETDATE(), UPDATE_EMP = ?, UPDATE_IP = '127.0.0.1'
              WHERE ORDER_ID = ?")
            ->execute([$stage, $isPaid, $this->ort['RECORD_EMP'], $wcOrderId]);
    }
}
