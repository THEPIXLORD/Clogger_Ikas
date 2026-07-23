<?php
// ikas webhook alıcısı — ERP sunucusunda internete açılan TEK dosya.
//
// Tasarım: payload'a güvenilmez ve burada İŞ YAPILMAZ. Görev sadece:
//   1) anahtar doğrula, 2) gelen olayı dosyaya logla, 3) HEMEN 200 dön,
//   4) arka planda bin/sync.php'yi tetikle (o zaten her şeyi API'den tazece çeker).
// ikas 200 dışı yanıtlarda 3 denemeden sonra webhook'u bırakır ve hata oranı
// yüksekse endpoint'i bloklar — bu yüzden hata durumunda bile hızlı 200 esastır.

$cfg = require __DIR__ . '/../config.local.php';
$anahtar = $cfg['webhook_anahtar'] ?? '';

if ($anahtar === '' || ($_GET['anahtar'] ?? '') !== $anahtar) {
    http_response_code(403);
    exit('yok');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {   // sağlık kontrolü
    exit('ikas-sync webhook OK');
}

$govde = (string)file_get_contents('php://input');
@file_put_contents(__DIR__ . '/../var/webhook.log',
    date('Y-m-d H:i:s') . "\t" . str_replace(["\r", "\n"], ' ', substr($govde, 0, 1500)) . "\n",
    FILE_APPEND | LOCK_EX);

// Önce yanıtı teslim et...
http_response_code(200);
header('Content-Type: text/plain');
echo 'OK';
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    ignore_user_abort(true);
    if (function_exists('litespeed_finish_request')) litespeed_finish_request();
    while (ob_get_level() > 0) ob_end_flush();
    flush();
}

// ...sonra eşitlemeyi tetikle (flock kilidi eşzamanlı koşuyu zaten engelliyor)
$php = PHP_BINARY ?: 'php';
$sync = dirname(__DIR__) . '/bin/sync.php';
if (stripos(PHP_OS_FAMILY, 'Windows') === 0) {
    pclose(popen('start /B "" ' . escapeshellarg($php) . ' ' . escapeshellarg($sync)
        . ' >> ' . escapeshellarg(dirname(__DIR__) . '/var/sync.log') . ' 2>&1', 'r'));
} else {
    exec(escapeshellarg($php) . ' ' . escapeshellarg($sync)
        . ' >> ' . escapeshellarg(dirname(__DIR__) . '/var/sync.log') . ' 2>&1 &');
}
