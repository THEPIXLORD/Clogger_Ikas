<?php
// ikas Admin API istemcisi — token yönetimi, rate limit ve yeniden deneme ile GraphQL çağrısı.
// Rate limit kuralları sert: 50 istek/10 sn; hata oranı %25'i aşarsa 1 SAAT BLOK.
// Bu yüzden her istek arasında bekleme var ve 429/5xx'te agresif geri çekilme yapılır.

class IkasClient
{
    private array $cfg;
    private string $tokenDosya;
    private float $sonIstek = 0;

    public function __construct(array $cfg)
    {
        $this->cfg = $cfg;
        $this->tokenDosya = dirname(__DIR__) . '/var/token.json';
    }

    /** Geçerli access token döner; süresi dolmuşsa (5 dk paylı) yeniler. */
    public function token(): string
    {
        if (is_file($this->tokenDosya)) {
            $t = json_decode((string)file_get_contents($this->tokenDosya), true);
            if ($t && ($t['expires_at'] ?? 0) > time() + 300) {
                return $t['access_token'];
            }
        }
        $body = http_build_query([
            'grant_type'    => 'client_credentials',
            'client_id'     => $this->cfg['ikas']['client_id'],
            'client_secret' => $this->cfg['ikas']['client_secret'],
        ]);
        [$kod, $yanit] = $this->http($this->cfg['ikas']['token_url'], $body,
            ['Content-Type: application/x-www-form-urlencoded']);
        $t = json_decode($yanit, true);
        if ($kod !== 200 || empty($t['access_token'])) {
            throw new RuntimeException("ikas token alınamadı (HTTP $kod): " . substr($yanit, 0, 300));
        }
        $t['expires_at'] = time() + (int)($t['expires_in'] ?? 14400);
        file_put_contents($this->tokenDosya, json_encode($t));
        chmod($this->tokenDosya, 0600);
        return $t['access_token'];
    }

    /** GraphQL sorgusu çalıştırır; data döner, GraphQL hatasında istisna atar. */
    public function gql(string $sorgu, array $degiskenler = []): array
    {
        $govde = json_encode(['query' => $sorgu, 'variables' => (object)$degiskenler]);
        $deneme = 0;
        while (true) {
            $deneme++;
            [$kod, $yanit] = $this->http($this->cfg['ikas']['graphql_url'], $govde, [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->token(),
            ]);
            if ($kod === 429 || $kod >= 500) {           // rate limit / sunucu hatası → geri çekil
                if ($deneme >= 4) throw new RuntimeException("ikas API HTTP $kod ($deneme deneme)");
                sleep($kod === 429 ? 12 : 3 * $deneme);   // 429'da 10 sn'lik pencerenin dolmasını bekle
                continue;
            }
            if ($kod === 401 && $deneme === 1) {          // token geçersiz kalmış olabilir → tazele
                @unlink($this->tokenDosya);
                continue;
            }
            $d = json_decode($yanit, true);
            if ($kod !== 200 || !is_array($d)) {
                throw new RuntimeException("ikas API HTTP $kod: " . substr($yanit, 0, 300));
            }
            if (!empty($d['errors'])) {
                throw new RuntimeException('ikas GraphQL hatası: ' . json_encode($d['errors'], JSON_UNESCAPED_UNICODE));
            }
            return $d['data'] ?? [];
        }
    }

    /** İstekler arası asgari aralığı koruyarak HTTP POST yapar. */
    private function http(string $url, string $govde, array $basliklar): array
    {
        $aralik = ($this->cfg['ikas']['istek_araligi_ms'] ?? 250) / 1000;
        $bekle = $this->sonIstek + $aralik - microtime(true);
        if ($bekle > 0) usleep((int)($bekle * 1_000_000));
        $this->sonIstek = microtime(true);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $govde,
            CURLOPT_HTTPHEADER => $basliklar, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60, CURLOPT_CONNECTTIMEOUT => 15,
        ]);
        $yanit = curl_exec($ch);
        if ($yanit === false) {
            throw new RuntimeException('ikas API bağlantı hatası: ' . curl_error($ch));
        }
        $kod = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        return [$kod, (string)$yanit];
    }
}
