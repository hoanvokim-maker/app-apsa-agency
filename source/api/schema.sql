-- ============================================================
-- APSA — MySQL Schema
-- Chạy file này 1 lần trong phpMyAdmin hoặc MySQL CLI
-- ============================================================

-- ── Bảng QR Events ───────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `qr_events` (
  `id`          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `uid`         VARCHAR(100)    DEFAULT NULL COMMENT 'User identifier (tuỳ chọn)',
  `team`        VARCHAR(100)    DEFAULT NULL COMMENT 'Team hoặc phòng ban',
  `type`        VARCHAR(20)     NOT NULL DEFAULT 'event' COMMENT 'event | link',
  `url`         VARCHAR(2048)   DEFAULT NULL COMMENT 'URL cho loại link QR',
  `name`        VARCHAR(500)    NOT NULL COMMENT 'Tên / nhãn QR',
  `start_time`  DATETIME        DEFAULT NULL,
  `end_time`    DATETIME        DEFAULT NULL,
  `location`    VARCHAR(500)    DEFAULT NULL,
  `description` TEXT            DEFAULT NULL,
  `svg`         MEDIUMTEXT      DEFAULT NULL COMMENT 'SVG vector string',
  `png`         MEDIUMTEXT      DEFAULT NULL COMMENT 'Base64 PNG data URL',
  `created_at`  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_type`    (`type`),
  INDEX `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Nếu bảng đã tồn tại (upgrading) — chạy các lệnh này thủ công ─
-- ALTER TABLE `qr_events` ADD COLUMN `type` VARCHAR(20) NOT NULL DEFAULT 'event' AFTER `team`;
-- ALTER TABLE `qr_events` ADD COLUMN `url`  VARCHAR(2048) DEFAULT NULL AFTER `type`;
-- ALTER TABLE `qr_events` MODIFY COLUMN `uid` VARCHAR(100) DEFAULT NULL;
-- ALTER TABLE `qr_events` DROP INDEX `idx_uid`;

-- ── Bảng Team Members (nhân sự) ────────────────────────────
CREATE TABLE IF NOT EXISTS `team_members` (
  `id`         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(100)  NOT NULL COMMENT 'Tên gọi ngắn (nickname)',
  `full_name`  VARCHAR(200)  DEFAULT NULL COMMENT 'Họ tên đầy đủ',
  `dept`       VARCHAR(100)  DEFAULT NULL COMMENT 'Phòng / vị trí',
  `active`     TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '1=active, 0=inactive',
  `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_name` (`name`),
  INDEX `idx_active` (`active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Seed dữ liệu nhân sự (INSERT IGNORE = an toàn khi chạy lại) ──
INSERT IGNORE INTO `team_members` (`name`, `full_name`, `dept`) VALUES
('Harris',      'Harris Vo',               'Director'),
('Phương',      'Thái Lê Hoàng Phương',    'Video'),
('Anh Kim',     'Phan Lê Anh Kim',         'Account'),
('Anh Thư',     'Nguyễn Trần Anh Thư',     'Account'),
('Nguyên Thảo', 'Lý Nguyễn Nguyên Thảo',  'Account'),
('Nhật Tân',    'Nguyễn Nhật Tân',         'Account Leader'),
('Tiên',        'Ngô Thuỳ Tiên',           'Design'),
('Minh Trí',    'Nguyễn Phan Minh Trí',    'Designer Leader'),
('Thảo Vy',     'Nguyễn Lê Thảo Vy',       'Design'),
('Thảo Trang',  'Đỗ Thảo Trang',           'Admin');

-- ── Bảng Slide Tracking (theo dõi lượt xem slide) ───────────
CREATE TABLE IF NOT EXISTS `slide_tracking` (
  `id`           INT UNSIGNED   NOT NULL AUTO_INCREMENT,
  `project`      VARCHAR(300)   NOT NULL COMMENT 'Tên dự án',
  `user_name`    VARCHAR(200)   NOT NULL COMMENT 'Tên người sử dụng',
  `hospital`     VARCHAR(300)   DEFAULT NULL COMMENT 'Bệnh viện của người sử dụng',
  `ip`           VARCHAR(45)    DEFAULT NULL COMMENT 'Địa chỉ IP (IPv4/IPv6)',
  `started_at`   DATETIME       NOT NULL COMMENT 'Ngày giờ bắt đầu xem',
  `slide`        VARCHAR(100)   DEFAULT NULL COMMENT 'Slide (số hoặc tên)',
  `view_seconds` DECIMAL(10,2)  DEFAULT NULL COMMENT 'Thời gian xem (giây)',
  `created_at`   TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_project`    (`project`(191)),
  INDEX `idx_user`       (`user_name`(191)),
  INDEX `idx_started_at` (`started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Bảng Accounts (tài khoản phần mềm của team) ─────────────
CREATE TABLE IF NOT EXISTS `accounts` (
  `id`          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `platform`    VARCHAR(100)    NOT NULL COMMENT 'Tên nền tảng, vd: Adobe CC',
  `label`       VARCHAR(200)    NOT NULL COMMENT 'Tên hiển thị',
  `username`    VARCHAR(300)    NOT NULL COMMENT 'Email / username đăng nhập',
  `password`    VARCHAR(300)    NOT NULL,
  `people`      JSON            DEFAULT NULL COMMENT 'Danh sách người dùng [{name, color}]',
  `note`        TEXT            DEFAULT NULL,
  `created_at`  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`(191)),
  INDEX `idx_platform` (`platform`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Badminton Championship 2026 ──────────────────────────────
-- Các bảng bên dưới được tự động tạo bởi /badminton/api/badminton-api.php
-- khi gọi lần đầu (không cần chạy tay). Ghi lại đây để tham khảo.
-- Bảng: badminton_posts (bài đăng thông tin & thể lệ trên trang chủ)
-- Bảng: badminton_matches (lịch thi đấu)
-- Bảng: badminton_registrations (danh sách đăng ký tham dự)
-- Chi tiết cột xem trực tiếp trong badminton-api.php.

-- ── CRM: Công ty, Khách hàng & Công nợ ───────────────────────
-- Các bảng bên dưới được tự động tạo (và tự nâng cấp) bởi /api/crm-api.php
-- khi gọi lần đầu — không cần chạy tay. Ghi lại đây để tham khảo.
--
--   crm_companies : id, name (Tên công ty), tax_code (MST), address, note,
--                   active, created_by, deleted_at, timestamps
--   crm_customers : id, company_id → crm_companies.id, name (người liên hệ),
--                   dept, phone, email, note, active, created_by, deleted_at, timestamps
--                   (cột `company` dạng chữ là DI SẢN cũ, chỉ giữ để tham chiếu)
--   crm_invoices  : id, company_id → crm_companies.id  (CÔNG NỢ GẮN VÀO CÔNG TY),
--                   customer_id → crm_customers.id (người liên hệ, tuỳ chọn),
--                   invoice_no, project, amount DECIMAL(16,2), issue_date, due_date,
--                   note, created_by, deleted_at, timestamps
--   crm_payments  : id, invoice_id, pay_date, amount DECIMAL(16,2), method, note,
--                   created_by, created_at
--   crm_audit_log : id, company_id, customer_id, invoice_id, payment_id, entity,
--                   action (create|update|delete|restore|purge|pay), summary, amount,
--                   actor, created_at
--
-- Công nợ còn lại = crm_invoices.amount - SUM(crm_payments.amount) của khoản nợ đó.
-- Trạng thái (unpaid / partial / paid) và số ngày quá hạn tính ở API, không lưu DB.
-- Cảnh báo: nợ còn lại > 40.000.000đ VÀ quá hạn ≥ 90 ngày (vàng) / 180 (cam) / 365 (đỏ).
--
-- Nâng cấp 20/08/2026 (tự chạy trong crm-api.php, idempotent):
--   ALTER TABLE crm_customers ADD company_id ...;  ALTER TABLE crm_invoices ADD company_id ...;
--   ALTER TABLE crm_audit_log ADD company_id ...;  crm_invoices.customer_id → cho phép NULL;
--   tạo công ty từ cột `company` cũ rồi gán company_id cho khách hàng và khoản nợ.
