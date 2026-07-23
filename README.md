# Clogger ikas ↔ Workcube Entegrasyonu (ikas-sync)

sicaklikolcer.com (ikas) mağazasındaki satışları VISIOTT Workcube ERP'ye tek yönlü ve
otomatik işler: **cari + sipariş + süreç aşaması + kredi kartı tahsilatı.**

## Ne yapar?

- ikas Admin API'den (GraphQL, private app) yeni/güncellenen siparişleri çeker.
- Müşteriyi ERP'de arar (VKN → cari, TC → cari + yetkili TC'si); yoksa açar:
  10 haneli VKN → şirket, 11 haneli TC → şahıs firması (`IS_PERSON=1`), yoksa bireysel.
- Siparişi `ORDERS + ORDER_ROW` olarak yazar (SKU → ERP stok kartı eşlemesi `urun_map`,
  KDV dahil tahsil edilen tutardan matrah ayrıştırma, 'IKAS-' önekli sipariş no).
- Süreç aşamasını yönetir: ödeme bekliyor (19) → ödeme alındı (363) → iptal/iade (410);
  ürüne özel aşama desteklenir (ör. kalibrasyon barındırma → 364 "Cihaz Bekleniyor").
- Havale/EFT durum değişimini izler; kredi kartı ödemelerinde tahsilat kaydını
  (`CREDIT_CARD_BANK_PAYMENTS`, İyzico tipi) siparişe bağlı düşer.
- Ürüne bağlı cari muhasebe kodu atar (`COMPANY_PERIOD.ACCOUNT_CODE`; dolu-farklı kodu ezmez).
- Her şey mükerrer korumalı ve kilitli (eşzamanlı koşu güvenli); eşleşmeyen ürünlü
  sipariş TÜMÜYLE atlanıp hata loglanır — yarım sipariş yazılmaz.

## Yapı

| Yol | Görev |
|---|---|
| `bin/sync.php` | Eşitleme koşusu (cron/zamanlanmış görev; `--dry-run`, `--mock=dosya`) |
| `src/IkasClient.php` | ikas API istemcisi (token, rate limit 50/10sn'e saygı, backoff) |
| `src/WorkcubeWriter.php` | ERP yazıcısı (sqlsrv/dblib otomatik; cari/sipariş/aşama/tahsilat) |
| `src/SyncStore.php` | Eşleme-durum deposu (MySQL veya MSSQL `ikas_entegrasyon`) |
| `bin/depo-tasima.php` | Depoyu MySQL→MSSQL devretme (ortam taşıma, idempotent) |
| `bin/webhook-kaydet.php` | ikas'a webhook kaydı (ileride kullanım için hazır) |
| `public/webhook.php` | Webhook alıcısı (anahtar korumalı; şu an devrede değil) |
| `kurulum/` | ERP sunucusu kurulum talimatı + MSSQL DDL + örnek config |
| `mock/` | Uçtan uca test siparişleri (sahte veriler) |

## Kurulum

- Gizli ayarlar: `kurulum/config.local.ORNEK.php` → proje köküne `config.local.php`
  olarak kopyala ve doldur (Git'e girmez).
- ERP sunucusuna kurulum: `kurulum/KURULUM-SUNUCU.md` (PHP 8.3 NTS x64 + pdo_sqlsrv +
  ODBC 18 + cacert.pem + 1 dk'lık `schtasks` görevi).
- Eşleme deposu: MySQL için tablolar koddaki DDL ile, MSSQL için `kurulum/mssql-kurulum.sql`.

## Günlük işletim

- Log: `var/sync.log` (her koşu bir `Bitti:` satırı) + `ikas_entegrasyon.islem_log` tablosu.
- Sık ayarlar `config.php`'de: `urun_map`, `odendi_stage_map`, `cari_hesap_kodu_map`,
  `kk_tahsilat`, `kargo_stock_id`, `paymethod_map`.

> Not: Bu depo iş mantığı ve ERP şema bilgisi içerir — **private** kalmalı.
