<?php
// ÖRNEK gizli yapılandırma — kopyalayıp doldurun:
//   Mac/geliştirme : proje köküne config.local.php
//   ERP sunucusu   : proje köküne config.local.php ('depo' => 'mssql', host => 'localhost')
// Gerçek dosya .gitignore'dadır, Git'e girmez.
return [
    'ortam' => 'test',            // test | canli
    'depo'  => 'mysql',           // mysql (geliştirme) | mssql (ERP sunucusu)
    'mssql_depo_db' => 'ikas_entegrasyon',

    'webhook_anahtar' => 'RASTGELE-UZUN-ANAHTAR',   // openssl rand -hex 24

    'ikas' => [
        'client_id'     => 'IKAS-PRIVATE-APP-CLIENT-ID',
        'client_secret' => 'IKAS-PRIVATE-APP-SECRET',
    ],

    // Workcube MSSQL
    'mssql' => [
        'host' => 'erp.ornek.com',   // sunucuda: localhost
        'port' => 1433,
        'user' => 'KULLANICI',
        'pass' => 'SIFRE',
    ],

    // Eşleme deposu MySQL ise (geliştirme)
    'mysql' => [
        'dsn'  => 'mysql:host=127.0.0.1;dbname=ikas_entegrasyon;charset=utf8mb4',
        'user' => 'root',
        'pass' => '',
    ],
];
