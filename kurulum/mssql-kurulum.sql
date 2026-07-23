-- ikas-sync eşleme/durum deposu — ERP sunucusunda (SSMS'te bir kez çalıştırılır).
-- Mac'teki MySQL deposunun MSSQL karşılığı; tablo/kolon adları birebir aynı.

IF DB_ID('ikas_entegrasyon') IS NULL CREATE DATABASE ikas_entegrasyon;
GO
USE ikas_entegrasyon;
GO

IF OBJECT_ID('sync_state') IS NULL
CREATE TABLE sync_state (
    anahtar    NVARCHAR(50) PRIMARY KEY,
    deger      NVARCHAR(200) NOT NULL,
    guncelleme DATETIME DEFAULT GETDATE()
);

IF OBJECT_ID('musteri') IS NULL
CREATE TABLE musteri (
    ikas_customer_id NVARCHAR(36) NOT NULL,
    hedef_db         NVARCHAR(50) NOT NULL,
    wc_company_id    INT NOT NULL,
    wc_partner_id    INT NULL,
    eposta           NVARCHAR(150) NULL,
    vergi_no         NVARCHAR(20) NULL,
    tip              NVARCHAR(10) NOT NULL DEFAULT 'bireysel',  -- kurumsal | sahis | bireysel
    olusturma        DATETIME DEFAULT GETDATE(),
    PRIMARY KEY (ikas_customer_id, hedef_db)
);

IF OBJECT_ID('siparis') IS NULL
CREATE TABLE siparis (
    ikas_order_id     NVARCHAR(36) NOT NULL,
    hedef_db          NVARCHAR(50) NOT NULL,
    ikas_order_number NVARCHAR(50) NOT NULL,
    wc_order_id       INT NOT NULL,
    wc_company_id     INT NOT NULL,
    odeme_durumu      NVARCHAR(30) NOT NULL,
    siparis_durumu    NVARCHAR(30) NOT NULL,
    wc_stage          INT NOT NULL,
    kk_tahsilat_id    INT NULL,
    tutar             DECIMAL(12,2) NOT NULL,
    veri              NVARCHAR(MAX) NULL,
    olusturma         DATETIME DEFAULT GETDATE(),
    guncelleme        DATETIME DEFAULT GETDATE(),
    PRIMARY KEY (ikas_order_id, hedef_db)
);

IF OBJECT_ID('islem_log') IS NULL
CREATE TABLE islem_log (
    id            INT IDENTITY PRIMARY KEY,
    zaman         DATETIME DEFAULT GETDATE(),
    seviye        NVARCHAR(6) NOT NULL DEFAULT 'bilgi',   -- bilgi | uyari | hata
    ikas_order_id NVARCHAR(36) NULL,
    mesaj         NVARCHAR(MAX) NOT NULL
);
GO

-- Mac'ten devralınan eşitleme imleci ve mevcut sipariş eşlemeleri kurulumdan sonra
-- bin/tasima-notu.md'deki adımla aktarılır (yoksa sistem son 24 saati tarar;
-- mükerrer koruması ORDER eşleme tablosu boşken ORDER_NUMBER kontrolüyle sağlanamaz —
-- o yüzden taşıma adımı ATLANMAMALI, aksi halde mevcut 8 sipariş yeniden yazılır).
