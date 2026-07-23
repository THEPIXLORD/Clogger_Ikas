<?php
// Eşleme/durum deposu: ikas↔Workcube kimlik eşlemeleri, eşitleme imleci ve işlem günlüğü.
// İki depo desteklenir (config 'depo'):
//   'mysql' — geliştirme (Mac, MAMP)
//   'mssql' — ERP sunucusu (ikas_entegrasyon DB'si; sqlsrv veya dblib sürücüsü otomatik seçilir)

class SyncStore
{
    private PDO $db;
    private bool $mssql = false;

    public function __construct(array $cfg)
    {
        $ops = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
        if (($cfg['depo'] ?? 'mysql') === 'mssql') {
            $this->mssql = true;
            $m = $cfg['mssql'];
            $depoDb = $cfg['mssql_depo_db'] ?? 'ikas_entegrasyon';
            $adaylar = [
                'sqlsrv' => "sqlsrv:Server={$m['host']},{$m['port']};Database=$depoDb;TrustServerCertificate=1",
                'dblib'  => "dblib:host={$m['host']}:{$m['port']};dbname=$depoDb;charset=UTF-8",
            ];
            foreach ($adaylar as $surucu => $dsn) {
                if (!in_array($surucu, PDO::getAvailableDrivers(), true)) continue;
                $this->db = new PDO($dsn, $m['user'], $m['pass'], $ops);
                return;
            }
            throw new RuntimeException('MSSQL PDO sürücüsü yok (sqlsrv veya dblib gerekli)');
        }
        $this->db = new PDO($cfg['mysql']['dsn'], $cfg['mysql']['user'], $cfg['mysql']['pass'], $ops);
    }

    private function simdi(): string { return $this->mssql ? 'GETDATE()' : 'NOW()'; }

    public function stateAl(string $anahtar, ?string $varsayilan = null): ?string
    {
        $st = $this->db->prepare('SELECT deger FROM sync_state WHERE anahtar = ?');
        $st->execute([$anahtar]);
        $v = $st->fetchColumn();
        return $v === false ? $varsayilan : $v;
    }

    public function stateYaz(string $anahtar, string $deger): void
    {
        $st = $this->db->prepare('UPDATE sync_state SET deger = ?, guncelleme = ' . $this->simdi() . ' WHERE anahtar = ?');
        $st->execute([$deger, $anahtar]);
        if ($st->rowCount() === 0) {
            $this->db->prepare('INSERT INTO sync_state (anahtar, deger) VALUES (?,?)')
                     ->execute([$anahtar, $deger]);
        }
    }

    public function musteriBul(string $ikasCustomerId, string $hedefDb): ?array
    {
        $st = $this->db->prepare('SELECT * FROM musteri WHERE ikas_customer_id = ? AND hedef_db = ?');
        $st->execute([$ikasCustomerId, $hedefDb]);
        return $st->fetch() ?: null;
    }

    public function musteriKaydet(string $ikasCustomerId, string $hedefDb, int $companyId,
                                  ?int $partnerId, ?string $eposta, ?string $vergiNo, string $tip): void
    {
        $st = $this->db->prepare('UPDATE musteri SET wc_company_id = ?, wc_partner_id = ?
                                   WHERE ikas_customer_id = ? AND hedef_db = ?');
        $st->execute([$companyId, $partnerId, $ikasCustomerId, $hedefDb]);
        if ($st->rowCount() === 0) {
            $this->db->prepare('INSERT INTO musteri
                    (ikas_customer_id, hedef_db, wc_company_id, wc_partner_id, eposta, vergi_no, tip)
                 VALUES (?,?,?,?,?,?,?)')
                 ->execute([$ikasCustomerId, $hedefDb, $companyId, $partnerId, $eposta, $vergiNo, $tip]);
        }
    }

    public function siparisBul(string $ikasOrderId, string $hedefDb): ?array
    {
        $st = $this->db->prepare('SELECT * FROM siparis WHERE ikas_order_id = ? AND hedef_db = ?');
        $st->execute([$ikasOrderId, $hedefDb]);
        return $st->fetch() ?: null;
    }

    public function siparisKaydet(array $s): void
    {
        $this->db->prepare('INSERT INTO siparis
                (ikas_order_id, hedef_db, ikas_order_number, wc_order_id, wc_company_id,
                 odeme_durumu, siparis_durumu, wc_stage, tutar, veri)
             VALUES (?,?,?,?,?,?,?,?,?,?)')
             ->execute([$s['ikas_order_id'], $s['hedef_db'], $s['ikas_order_number'],
                        $s['wc_order_id'], $s['wc_company_id'], $s['odeme_durumu'],
                        $s['siparis_durumu'], $s['wc_stage'], $s['tutar'],
                        json_encode($s['veri'], JSON_UNESCAPED_UNICODE)]);
    }

    public function siparisDurumGuncelle(string $ikasOrderId, string $hedefDb,
                                         string $odeme, string $durum, int $stage): void
    {
        $this->db->prepare('UPDATE siparis SET odeme_durumu = ?, siparis_durumu = ?, wc_stage = ?,
                                   guncelleme = ' . $this->simdi() . '
                             WHERE ikas_order_id = ? AND hedef_db = ?')
             ->execute([$odeme, $durum, $stage, $ikasOrderId, $hedefDb]);
    }

    public function kkTahsilatIsaretle(string $ikasOrderId, string $hedefDb, int $tahsilatId): void
    {
        $this->db->prepare('UPDATE siparis SET kk_tahsilat_id = ?, guncelleme = ' . $this->simdi() . '
                             WHERE ikas_order_id = ? AND hedef_db = ?')
             ->execute([$tahsilatId, $ikasOrderId, $hedefDb]);
    }

    public function log(string $seviye, string $mesaj, ?string $ikasOrderId = null): void
    {
        $this->db->prepare('INSERT INTO islem_log (seviye, mesaj, ikas_order_id) VALUES (?,?,?)')
             ->execute([$seviye, $mesaj, $ikasOrderId]);
        fwrite(STDERR, sprintf("[%s] %s%s\n", strtoupper($seviye),
            $ikasOrderId ? "($ikasOrderId) " : '', $mesaj));
    }
}
