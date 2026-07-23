<?php
// ikas-sync yapılandırması — gizli bilgiler config.local.php'de (web kökü dışı, 0600 izinli).
// Ortam değiştirmek için config.local.php'de 'ortam' => 'canli' yazılır; bu dosyaya dokunulmaz.
$yerel = require __DIR__ . '/config.local.php';

return array_replace_recursive([
    'ortam' => 'test',   // test | canli

    'ortamlar' => [
        // Desen kaynağı: crm-arsiv aktarımlarıyla doğrulanmış Workcube kayıt şablonları.
        // PERIOD_ID/COMPANY_STATE/COUNTRY + COMPANY_PARTNER olmadan cari arayüzde görünmez.
        'test' => [
            'hedef_db' => 'workcube_test', 'siparis_sema' => 'workcube_test_1',
            'PERIOD_ID' => 4, 'COMPANY_STATE' => 41, 'COUNTRY' => 215,
            'COMPANYCAT_ID' => 3, 'OUR_COMPANY_ID' => 1,
            'RECORD_EMP' => 1, 'ORDER_EMPLOYEE_ID' => 1,
        ],
        'canli' => [
            'hedef_db' => 'erp_visiott', 'siparis_sema' => 'erp_visiott_1',
            'PERIOD_ID' => 7, 'COMPANY_STATE' => 41, 'COUNTRY' => 215,
            'COMPANYCAT_ID' => 3,  // TODO: e-ticaret müşterisi için ayrı kategori — Erdem'le netleşecek
            'OUR_COMPANY_ID' => 1,
            'RECORD_EMP' => 106, 'ORDER_EMPLOYEE_ID' => 106,  // İbrahim Kara — canlı sipariş deseni
        ],
    ],

    // Workcube sipariş süreç aşamaları (PROCESS_TYPE_ROWS; test ve canlıda aynı ID'ler doğrulandı)
    'stage' => ['bekliyor' => 19, 'odendi' => 363, 'iptal' => 410],

    // Ürüne özel "ödeme alındı" aşaması: siparişte bu SKU varsa, ödeme alındığında
    // 363 yerine buradaki aşamaya geçer (süreç zinciri oradan devam eder).
    'odendi_stage_map' => [
        'Clg-Klb-200' => 364,   // Kalibrasyon Web Barındırma → Cihaz Bekleniyor (Erdem, 2026-07-22)
    ],

    // ikas satış fiyatları KDV DAHİL gelir; matrah = fiyat / (1 + KDV/100).
    // KDV oranı üründen (STOCKS.TAX) okunur, bulunamazsa bu değer kullanılır.
    'kdv_varsayilan' => 20,

    // Kargo bedeli ayrı sipariş satırı olarak yazılır; STOCK_ID tanımlanmadıysa
    // kargo tutarlı sipariş UYARI loglanır ve kargo satırı atlanır (tutar farkı oluşur!).
    'kargo_stock_id' => null,

    // ikas PaymentMethodType => SETUP_PAYMETHOD.PAYMETHOD_ID eşlemesi (boş anahtar → NULL yazılır)
    'paymethod_map' => [
        // 'MONEY_ORDER' => 0,   // ikas — Havale/EFT (SETUP_PAYMETHOD'a kayıt açılınca doldurulacak)
        // 'CREDIT_CARD' => 0,   // ikas — Kredi Kartı
    ],

    // ikas SKU => ERP STOCK_CODE eşlemesi. ikas SKU'ları ERP stok kodlarıyla birebir DEĞİL;
    // burada eşleşmeyen SKU'lar yine STOCK_CODE/BARCOD ile doğrudan aranır.
    'urun_map' => [
        'Clg-Klb-100' => 'CLG.01.11.BRN.11794',  // Clogger Eczane Web Barındırma Hizmeti
        'Clg-Klb-200' => 'CLG.01.11.KLB.11792',  // Kalibrasyon Web Barındırma Hizmeti
        'CLG_Eczane'  => 'CLG.01.01.ECZ.11793',  // Clogger Eczane Isı Nem Takip Sistemi → CLOGGER ECZANE SICAKLIK VE NEM TAKİP SİSTEMLERİ
        'CLG-MT'      => 'CLG.01.12.TDL.11795',  // Tek Kullanımlık Data Logger → CLOGGER TEK KULLANIMLI MOBİL DATALOGGER
        'CLG_MC'      => 'CLG.01.12.CDL.11796',  // Çok Kullanımlı Veri Kaydedici → CLOGGER ÇOK KULLANIMLI MOBİL DATALOGGER
        // TODO Erdem: 'CLG_Kurumsal-1' (Kurumsal Isı Nem Takip) — ERP'de çok varyant var (BLT_W_4M/40/66/68,
        // BLT_E_4M/55...), hangisi olduğu netleşince eklenecek. O güne dek bu ürünlü sipariş atlanır+hata loglanır.
    ],

    // ikas SKU => cari muhasebe (alıcılar) kodu, ORTAM BAZLI. Siparişte bu SKU varsa carinin
    // COMPANY_PERIOD.ACCOUNT_CODE alanına bu kod yazılır (dolu ve FARKLI kod varsa
    // üzerine YAZILMAZ, uyarı loglanır — muhasebe verisi ezilmez).
    'cari_hesap_kodu_map' => [
        'test' => [
            'Clg-Klb-100' => '120.01.0039',  // kayıtlı mevcut hesap (Erdem 2026-07-15; 120.01.003 planda yoktu)
        ],
        'canli' => [
            // BOŞ BİLİNÇLİ: canlı hesap kodu muhasebeyle netleşince doldurulacak.
            // Boşken cari yine açılır, sadece muhasebe kodu atanmaz.
        ],
    ],

    // Kredi kartı tahsilat kaydı (bank.list_creditcard_revenue ekranına düşer).
    // Desen canlı SA-XXXX kayıtlarından: PAYMENT_TYPE_ID=1 (İyzico), PROCESS_CAT=154 (Kredi Kartı Tahsilat).
    'kk_tahsilat' => [
        'aktif'           => true,
        'payment_type_id' => 1,      // CREDITCARD_PAYMENT_TYPE: İyzico (test ve canlıda 1)
        'process_cat'     => 154,    // SETUP_PROCESS_CAT: Kredi Kartı Tahsilat
        'action_type'     => 'KREDİ KARTI TAHSİLAT',
    ],

    // ikas API
    'ikas' => [
        'token_url'   => 'https://api.myikas.com/api/admin/oauth/token',
        'graphql_url' => 'https://api.myikas.com/api/v2/admin/graphql',
        // rate limit: 50 istek / 10 sn — güvenli bant için istekler arası en az 250 ms
        'istek_araligi_ms' => 250,
    ],

    // Sipariş çekme: son eşitlemeden bu kadar geriye taşarak sorgula (kaçırma güvencesi)
    'tarama_taskini_dk' => 10,
], $yerel);
