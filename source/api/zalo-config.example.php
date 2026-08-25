<?php
/**
 * Cấu hình Zalo Bot cho APSA Tools.
 *
 * CÁCH DÙNG
 *   1. Copy file này thành  api/zalo-config.php
 *   2. Điền bot_token (Zalo gửi cho bạn khi tạo bot) và tự đặt secret_token
 *   3. chmod 600 api/zalo-config.php
 *
 * File api/zalo-config.php KHÔNG được commit lên git (đã có trong .gitignore).
 *
 * Cách lấy Bot Token:
 *   - Mở Zalo, tìm OA "Zalo Bot Manager"
 *   - Trong menu khung chat chọn "Tạo bot"
 *   - Đặt tên bắt đầu bằng chữ Bot, ví dụ: "Bot APSA Tools"
 *   - Zalo nhắn Bot Token về cho bạn
 */

return array(

    // Bật/tắt toàn bộ tính năng gửi thông báo qua Zalo
    'enabled' => false,

    // Bot Token Zalo gửi cho bạn. Dạng: 123456789:abcXYZ...
    'bot_token' => '',

    // Khoá bí mật do BẠN tự đặt, 8-256 ký tự, chỉ chữ và số.
    // Zalo sẽ gửi kèm khoá này trong header khi gọi về webhook của app.
    'secret_token' => '',

    // Địa chỉ gốc của app, dùng để tạo link tuyệt đối trong tin nhắn
    'app_url' => 'https://app.apsa.agency/',

    // Khoá bảo vệ endpoint chạy tự động (nhắc việc quá hạn mỗi sáng).
    // Tự đặt một chuỗi ngẫu nhiên dài.
    'cron_key' => '',

    // Các loại thông báo được đẩy sang Zalo.
    // Để null = dùng danh sách mặc định trong api/zalo.php
    'kinds' => null,
);
