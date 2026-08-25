<?php
/* Đổi tên file này thành mail-config.php rồi điền thông tin SMTP thật.
   File mail-config.php KHÔNG được đưa lên GitHub (đã nằm trong .gitignore). */
return [
    'host'      => 'mail.apsa.agency',   // máy chủ SMTP
    'port'      => 587,                  // 587 = TLS · 465 = SSL
    'secure'    => 'tls',                // tls | ssl | none
    'user'      => 'no-reply@apsa.agency',
    'pass'      => 'DIEN_MAT_KHAU_O_DAY',
    'from'      => 'no-reply@apsa.agency',
    'from_name' => 'APSA Tools',
];
