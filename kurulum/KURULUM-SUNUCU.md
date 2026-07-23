# ikas-sync — ERP sunucusu kurulumu (zamanlanmış görev modeli)

Hedef: eşitlemeyi ERP sunucusunda çalıştırmak. Sunucu ikas'a 1 dakikada bir kendisi
sorar (dışa doğru HTTPS) — **içeri hiçbir port açılmaz, firewall/IIS/webhook gerekmez.**
Mac devreden çıkar; siparişler en geç 1 dk gecikmeyle Workcube'e işlenir.

## 1. PHP kur (yoksa)
- `php -v` çalışıyorsa geç.
- Yoksa: https://windows.php.net/download → PHP 8.3 **NTS x64** zip → `C:\php`'ye aç.
- Microsoft "PHP Drivers for SQL Server" indir → `php_pdo_sqlsrv_83_nts_x64.dll` →
  `C:\php\ext` içine.
- `C:\php\php.ini-production`'ı `php.ini` yap; içine şu satırları ekle:
  ```
  extension_dir = "C:\php\ext"
  extension=pdo_sqlsrv
  extension=curl
  extension=openssl
  extension=mbstring
  ```
- Kontrol: `C:\php\php.exe -m` çıktısında `pdo_sqlsrv` ve `curl` görünmeli.

## 2. Dosyalar
1. `ikas-sync-server.zip`'i `C:\ikas-sync` içine aç.
2. `C:\ikas-sync\kurulum\config.local.SUNUCU.php` dosyasını
   `C:\ikas-sync\config.local.php` olarak kopyala (ayarlar hazır: MSSQL localhost, canlı ortam).

## 3. Veritabanı
SSMS'te `C:\ikas-sync\kurulum\mssql-kurulum.sql`'i çalıştır
(`ikas_entegrasyon` DB'si + 4 tablo oluşur).

## 4. Mac'ten devir — ATLAMA!
**Mac'te** (Erdem): `php ~/ikas-sync/bin/depo-tasima.php`
→ işlenmiş siparişlerin eşleme kayıtları sunucu DB'sine kopyalanır.
Bu adım atlanırsa sistem mevcut siparişleri Workcube'e İKİNCİ KEZ yazar.

## 5. Elle doğrulama (sunucuda)
```
cd C:\ikas-sync
C:\php\php.exe bin\sync.php
```
Beklenen çıktı: `Bitti: 0 yeni, ... N değişmedi, 0 hatalı (ortam: canli → erp_visiott)`
Hata varsa buradan öteye geçme, hatayı ilet.

## 6. Zamanlanmış görev (cron karşılığı)
Yönetici PowerShell'de tek komut:
```powershell
schtasks /Create /TN "ikas-sync" /SC MINUTE /MO 1 /RU SYSTEM /F ^
  /TR "cmd /c C:\php\php.exe C:\ikas-sync\bin\sync.php >> C:\ikas-sync\var\sync.log 2>&1"
```
(1 dakikada bir; rate limit açısından çok rahat — koşu başına 1-2 API isteği.
Üst üste binme derdi yok: programdaki kilit ikinci kopyayı anında sonlandırır.)

Kontrol: `schtasks /Query /TN "ikas-sync"` → "Ready/Running" görünmeli;
1-2 dk sonra `C:\ikas-sync\var\sync.log` dolmaya başlamalı.

## 7. Mac'i devreden çıkar
**Mac'te**: `crontab -l | grep -v ikas-sync | crontab -`
(Önemli: iki taraf aynı anda uzun süre çalışmasın — sunucu görevi doğrulandığı an kaldır.)

## 8. Uçtan uca test
Siteden sipariş ver (veya bir havale siparişinin durumunu değiştir) →
1 dk içinde `var\sync.log`'da "WC sipariş #... açıldı" / "aşama ... → ..." satırı
ve Workcube'de kayıt görülmeli.

## Sorun giderme
- `var\sync.log`: işleme çıktısı (her koşu bir "Bitti:" satırı bırakır).
- `ikas_entegrasyon.islem_log` tablosu: kalıcı günlük (`WHERE seviye IN ('hata','uyari')`).
- ikas erişimi için sunucudan dışa 443 açık olmalı: `curl https://api.myikas.com` yanıt veriyorsa tamam.

## İleride webhook istenirse
Paketteki `public/webhook.php` + `bin/webhook-kaydet.php` hazır bekliyor; dışarıdan
erişilebilir bir HTTPS adresi açıldığı gün 10 dakikada devreye alınır (eski talimat:
IIS ile sadece `public\` yayınla → webhook-kaydet ile ikas'a kaydet). Zamanlanmış
görev o durumda da emniyet taraması olarak kalır.
