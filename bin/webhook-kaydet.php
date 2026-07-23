<?php
// ikas'a webhook kaydı yapar/listeler/siler.
//
// Kullanım:
//   php bin/webhook-kaydet.php liste
//   php bin/webhook-kaydet.php kaydet "https://entegrasyon.visiott.com/webhook.php?anahtar=XXX"
//   php bin/webhook-kaydet.php sil
//
// Kayıtlı scope'lar: sipariş oluşturma + güncelleme (ödeme onayı, iptal vb. update ile gelir).

declare(strict_types=1);
require __DIR__ . '/../src/IkasClient.php';
$cfg = require __DIR__ . '/../config.php';
$ikas = new IkasClient($cfg);

const SCOPES = ['store/order/created', 'store/order/updated'];

$komut = $argv[1] ?? 'liste';

if ($komut === 'kaydet') {
    $url = $argv[2] ?? '';
    if (!str_starts_with($url, 'https://')) {
        fwrite(STDERR, "Endpoint https:// ile başlamalı.\nKullanım: php bin/webhook-kaydet.php kaydet \"https://.../webhook.php?anahtar=XXX\"\n");
        exit(1);
    }
    $girdi = array_map(fn($s) => ['scope' => $s, 'endpoint' => $url], SCOPES);
    $d = $ikas->gql('mutation($i: [WebhookInput!]!) { saveWebhooks(input: $i) { id scope endpoint } }',
        ['i' => $girdi]);
    foreach ($d['saveWebhooks'] ?? [] as $w) {
        echo "KAYDEDİLDİ: {$w['scope']} → {$w['endpoint']}\n";
    }
} elseif ($komut === 'sil') {
    $ikas->gql('mutation($s: [String!]!) { deleteWebhook(scopes: $s) }', ['s' => SCOPES]);
    echo "Silindi: " . implode(', ', SCOPES) . "\n";
}

$d = $ikas->gql('{ listWebhook { id scope endpoint } }');
echo "\nMevcut webhook kayıtları:\n";
if (empty($d['listWebhook'])) echo "  (yok)\n";
foreach ($d['listWebhook'] as $w) echo "  {$w['scope']} → {$w['endpoint']}\n";
