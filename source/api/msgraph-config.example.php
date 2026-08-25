<?php
/**
 * APSA - Cau hinh ket noi Microsoft Graph (lich Outlook)
 * ---------------------------------------------------------------
 * COPY file nay thanh  api/msgraph-config.php  roi dien thong tin.
 * KHONG commit file msgraph-config.php len git (da co trong .gitignore).
 *
 * Cach lay 3 gia tri tenant_id / client_id / client_secret:
 *   xem file  HUONG-DAN-MICROSOFT-ENTRA.md
 */

return array(

    // Bat/tat tinh nang day len lich Outlook.
    // Dat true SAU KHI da dien du 3 gia tri ben duoi.
    'enabled'       => false,

    // Directory (tenant) ID  - Entra > Overview
    'tenant_id'     => '',

    // Application (client) ID - Entra > App registrations > <app cua ban> > Overview
    'client_id'     => '',

    // Client secret VALUE (khong phai Secret ID)
    // Entra > App registrations > Certificates & secrets > New client secret
    'client_secret' => '',

    // Hop thu se nhan su kien nghi phep
    'mailbox'       => 'hello@apsa.agency',

    // Ten mui gio theo chuan Windows ma Outlook hieu
    'timezone'      => 'SE Asia Standard Time',

    // Gio lam viec, dung de tao su kien khi nghi nua ngay
    'am_start'      => '08:30',
    'am_end'        => '12:00',
    'pm_start'      => '13:30',
    'pm_end'        => '17:30',

    // Mau hien thi cua su kien tren lich Outlook
    // preset: none | lightBlue | lightGreen | lightOrange | lightGray
    //         lightYellow | lightTeal | lightPink | lightBrown | lightRed
    'category_color' => 'lightOrange',

    // Co moi nguoi xin nghi vao su kien (ho se nhan thu moi) hay khong
    'invite_requester' => false,
);
