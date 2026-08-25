<?php
// ============================================================
// APSA — CRM & Công nợ API   /api/crm-api.php
//
// Cấu trúc (từ 20/08/2026):
//   Công ty (crm_companies)  →  có nhiều Khách hàng liên hệ (crm_customers)
//                            →  có nhiều Khoản nợ    (crm_invoices)  ← công nợ gắn vào CÔNG TY
//   Khoản nợ → có nhiều lần Thanh toán (crm_payments)
//   Mọi thay đổi được ghi vào crm_audit_log.
//
// Xác thực: dùng chung session đăng nhập của app.apsa.agency (APSASESSID).
//
//  ── CÔNG TY ─────────────────────────────────────────────────
//  GET  ?action=companies[&q=&trash=1]
//  POST ?action=company-save     {id?,name,tax_code,address,note,active}
//  POST ?action=company-delete|company-restore|company-purge   {id}
//  GET  ?action=company-debts[&q=]        → tổng công nợ theo công ty + cảnh báo tuổi nợ
//  GET  ?action=company-detail&company_id=  → công ty + liên hệ + khoản nợ + thanh toán
//
//  ── KHÁCH HÀNG (người liên hệ) ──────────────────────────────
//  GET  ?action=customers[&q=&company_id=&trash=1]
//  POST ?action=customer-save    {id?,company_id,name,email,phone,dept,note,active}
//  POST ?action=customer-delete|customer-restore|customer-purge {id}
//
//  ── CÔNG NỢ ─────────────────────────────────────────────────
//  GET  ?action=invoices[&company_id=&status=&q=&from=&to=&trash=1]
//  POST ?action=invoice-save     {id?,company_id,customer_id?,invoice_no,project,amount,issue_date,due_date,note}
//  POST ?action=invoice-delete|invoice-restore|invoice-purge   {id}
//  POST ?action=payment-add      {invoice_id,pay_date,amount,method,note}
//  POST ?action=payment-quick    {company_id,amount,pay_date,method,note}   → tự trừ hoá đơn cũ nhất
//  POST ?action=payment-delete   {id}
//  GET  ?action=audit-log[&company_id=&limit=]
//  GET  ?action=summary[&company_id=]
// ============================================================

@ini_set('display_errors', '0');

require_once __DIR__ . '/db-config.php';

// Session 30 ngày — cấu hình tập trung ở session-boot.php
require_once __DIR__ . '/session-boot.php';

header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

function ok($data)             { echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE); exit; }
function fail($msg, $code=400) { http_response_code($code); echo json_encode(['ok'=>false,'error'=>$msg], JSON_UNESCAPED_UNICODE); exit; }

function body_json() {
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) return [];
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}
function s($v, $len = 255) { return mb_substr(trim((string)($v ?? '')), 0, $len); }
function money($v) {
    if ($v === '' || $v === null) return 0.0;
    $v = str_replace([',', ' ', '.'], ['', '', ''], (string)$v);
    return is_numeric($v) ? round((float)$v, 2) : 0.0;
}
function dateOrNull($v) {
    $v = trim((string)($v ?? ''));
    if ($v === '') return null;
    $d = date_create($v);
    return $d ? $d->format('Y-m-d') : null;
}
function vnd($n) { return number_format((float)$n, 0, ',', '.') . ' đ'; }

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    fail('DB connection failed', 500);
}

// ── Auth ─────────────────────────────────────────────────────
function currentUser($pdo) {
    if (empty($_SESSION['user_id'])) return null;
    try {
        $st = $pdo->prepare("SELECT id, username, display_name, role FROM `app_users` WHERE id = ? AND active = 1");
        $st->execute([$_SESSION['user_id']]);
        $u = $st->fetch();
        return $u ?: null;
    } catch (PDOException $e) { return null; }
}
$ME = currentUser($pdo);
if (!$ME) fail('Unauthorized — vui lòng đăng nhập', 401);
$IS_ADMIN = ($ME['role'] === 'admin');
$WHO      = $ME['display_name'] ?: $ME['username'];

// ── Ngưỡng cảnh báo công nợ ──────────────────────────────────
define('ALERT_MIN_AMOUNT', 40000000);   // 40 triệu
define('ALERT_DAYS_YELLOW', 90);        // ~3 tháng
define('ALERT_DAYS_ORANGE', 180);       // ~6 tháng
define('ALERT_DAYS_RED',    365);       // ~12 tháng

function alertLevel($outstanding, $daysOverdue) {
    if ($outstanding <= ALERT_MIN_AMOUNT) return '';
    if ($daysOverdue >= ALERT_DAYS_RED)    return 'red';
    if ($daysOverdue >= ALERT_DAYS_ORANGE) return 'orange';
    if ($daysOverdue >= ALERT_DAYS_YELLOW) return 'yellow';
    return '';
}

// ── Tạo bảng nếu chưa có ─────────────────────────────────────
$pdo->exec("CREATE TABLE IF NOT EXISTS `crm_companies` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(300) NOT NULL COMMENT 'Tên công ty',
  `tax_code`   VARCHAR(60)  DEFAULT NULL COMMENT 'Mã số thuế',
  `address`    VARCHAR(500) DEFAULT NULL,
  `note`       TEXT         DEFAULT NULL,
  `active`     TINYINT(1)   NOT NULL DEFAULT 1,
  `created_by` VARCHAR(120) DEFAULT NULL,
  `deleted_at` DATETIME     DEFAULT NULL,
  `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_name`    (`name`(100)),
  INDEX `idx_deleted` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$pdo->exec("CREATE TABLE IF NOT EXISTS `crm_customers` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(200) NOT NULL COMMENT 'Tên người liên hệ',
  `dept`       VARCHAR(200) DEFAULT NULL COMMENT 'Bộ phận / chức vụ',
  `company`    VARCHAR(300) DEFAULT NULL COMMENT 'CŨ — tên công ty dạng chữ, giữ để tham chiếu',
  `phone`      VARCHAR(60)  DEFAULT NULL,
  `email`      VARCHAR(200) DEFAULT NULL,
  `note`       TEXT         DEFAULT NULL,
  `active`     TINYINT(1)   NOT NULL DEFAULT 1,
  `created_by` VARCHAR(120) DEFAULT NULL,
  `deleted_at` DATETIME     DEFAULT NULL,
  `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_name`    (`name`(100)),
  INDEX `idx_deleted` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$pdo->exec("CREATE TABLE IF NOT EXISTS `crm_invoices` (
  `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `customer_id` INT UNSIGNED  DEFAULT NULL COMMENT 'Người liên hệ (tuỳ chọn)',
  `invoice_no`  VARCHAR(100)  DEFAULT NULL,
  `project`     VARCHAR(300)  DEFAULT NULL,
  `amount`      DECIMAL(16,2) NOT NULL DEFAULT 0,
  `issue_date`  DATE          DEFAULT NULL,
  `due_date`    DATE          DEFAULT NULL,
  `note`        TEXT          DEFAULT NULL,
  `created_by`  VARCHAR(120)  DEFAULT NULL,
  `deleted_at`  DATETIME      DEFAULT NULL,
  `created_at`  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_customer` (`customer_id`),
  INDEX `idx_due`      (`due_date`),
  INDEX `idx_deleted`  (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$pdo->exec("CREATE TABLE IF NOT EXISTS `crm_payments` (
  `id`         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `invoice_id` INT UNSIGNED  NOT NULL,
  `pay_date`   DATE          DEFAULT NULL,
  `amount`     DECIMAL(16,2) NOT NULL DEFAULT 0,
  `method`     VARCHAR(60)   DEFAULT NULL,
  `note`       VARCHAR(500)  DEFAULT NULL,
  `created_by` VARCHAR(120)  DEFAULT NULL,
  `created_at` TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_invoice` (`invoice_id`),
  INDEX `idx_paydate` (`pay_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$pdo->exec("CREATE TABLE IF NOT EXISTS `crm_audit_log` (
  `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `customer_id` INT UNSIGNED  DEFAULT NULL,
  `invoice_id`  INT UNSIGNED  DEFAULT NULL,
  `payment_id`  INT UNSIGNED  DEFAULT NULL,
  `entity`      VARCHAR(20)   NOT NULL,
  `action`      VARCHAR(40)   NOT NULL,
  `summary`     VARCHAR(500)  DEFAULT NULL,
  `amount`      DECIMAL(16,2) DEFAULT NULL,
  `actor`       VARCHAR(120)  DEFAULT NULL,
  `created_at`  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_customer` (`customer_id`),
  INDEX `idx_created`  (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// ── Nâng cấp cấu trúc + chuyển dữ liệu cũ (chạy 1 lần, idempotent) ──
function hasColumn($pdo, $table, $col) {
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        $st->execute([$table, $col]);
        return (int)$st->fetchColumn() > 0;
    } catch (PDOException $e) { return true; }
}

if (!hasColumn($pdo, 'crm_customers', 'company_id')) {
    $pdo->exec("ALTER TABLE `crm_customers` ADD COLUMN `company_id` INT UNSIGNED DEFAULT NULL AFTER `id`,
                ADD INDEX `idx_company_id` (`company_id`)");
}
if (!hasColumn($pdo, 'crm_invoices', 'company_id')) {
    $pdo->exec("ALTER TABLE `crm_invoices` ADD COLUMN `company_id` INT UNSIGNED DEFAULT NULL AFTER `id`,
                ADD INDEX `idx_company_id` (`company_id`)");
}
if (!hasColumn($pdo, 'crm_audit_log', 'company_id')) {
    $pdo->exec("ALTER TABLE `crm_audit_log` ADD COLUMN `company_id` INT UNSIGNED DEFAULT NULL AFTER `id`,
                ADD INDEX `idx_company_id` (`company_id`)");
}
// crm_invoices.customer_id trước đây NOT NULL → cho phép NULL (khoản nợ có thể không gắn người liên hệ)
try { $pdo->exec("ALTER TABLE `crm_invoices` MODIFY COLUMN `customer_id` INT UNSIGNED DEFAULT NULL"); } catch (PDOException $e) {}

// Khách hàng chưa có company_id → tạo/ghép công ty từ cột `company` cũ
$need = (int)$pdo->query("SELECT COUNT(*) FROM crm_customers WHERE company_id IS NULL")->fetchColumn();
if ($need > 0) {
    $rows = $pdo->query("SELECT id, company FROM crm_customers WHERE company_id IS NULL")->fetchAll();
    $findByName = $pdo->prepare("SELECT id FROM crm_companies WHERE name = ? LIMIT 1");
    $insCompany = $pdo->prepare("INSERT INTO crm_companies (name, created_by) VALUES (?, 'migrate')");
    $setCompany = $pdo->prepare("UPDATE crm_customers SET company_id = ? WHERE id = ?");
    $cache = [];
    foreach ($rows as $r) {
        $cname = trim((string)$r['company']);
        if ($cname === '') $cname = '(Chưa phân loại)';
        if (!isset($cache[$cname])) {
            $findByName->execute([$cname]);
            $cid = $findByName->fetchColumn();
            if (!$cid) { $insCompany->execute([$cname]); $cid = (int)$pdo->lastInsertId(); }
            $cache[$cname] = (int)$cid;
        }
        $setCompany->execute([$cache[$cname], $r['id']]);
    }
}
// Khoản nợ chưa có company_id → lấy theo công ty của người liên hệ
$pdo->exec("UPDATE crm_invoices i
              JOIN crm_customers c ON c.id = i.customer_id
               SET i.company_id = c.company_id
             WHERE i.company_id IS NULL AND c.company_id IS NOT NULL");
// Lịch sử cũ → gắn company_id theo khách hàng
$pdo->exec("UPDATE crm_audit_log a
              JOIN crm_customers c ON c.id = a.customer_id
               SET a.company_id = c.company_id
             WHERE a.company_id IS NULL AND c.company_id IS NOT NULL");

// ── Helper nghiệp vụ ─────────────────────────────────────────
/** Ghi lịch sử thay đổi. Lỗi ghi log không bao giờ chặn nghiệp vụ. */
function audit($pdo, $actor, $entity, $action, $summary, $opts = []) {
    try {
        $st = $pdo->prepare("INSERT INTO crm_audit_log
            (company_id, customer_id, invoice_id, payment_id, entity, action, summary, amount, actor)
            VALUES (?,?,?,?,?,?,?,?,?)");
        $st->execute([
            !empty($opts['company_id'])  ? (int)$opts['company_id']  : null,
            !empty($opts['customer_id']) ? (int)$opts['customer_id'] : null,
            !empty($opts['invoice_id'])  ? (int)$opts['invoice_id']  : null,
            !empty($opts['payment_id'])  ? (int)$opts['payment_id']  : null,
            $entity, $action, mb_substr((string)$summary, 0, 500),
            isset($opts['amount']) ? (float)$opts['amount'] : null,
            $actor,
        ]);
    } catch (PDOException $e) { /* bỏ qua */ }
}

function invLabel($inv) {
    $t = trim((string)($inv['invoice_no'] ?? ''));
    if ($t === '') $t = trim((string)($inv['project'] ?? ''));
    if ($t === '') $t = 'HĐ #' . ($inv['id'] ?? '?');
    return $t;
}

/** Bổ sung các trường tính toán cho 1 hoá đơn */
function decorate(&$inv, $today) {
    $amount = (float)$inv['amount'];
    $paid   = (float)$inv['paid'];
    $remain = round($amount - $paid, 2);
    if ($remain < 0) $remain = 0.0;
    $inv['amount']    = $amount;
    $inv['paid']      = $paid;
    $inv['remaining'] = $remain;
    if ($remain <= 0.009 && $amount > 0)      $status = 'paid';
    elseif ($paid > 0)                        $status = 'partial';
    else                                      $status = 'unpaid';
    $overdue = 0;
    if ($status !== 'paid' && !empty($inv['due_date'])) {
        $d1 = date_create($inv['due_date']); $d2 = date_create($today);
        if ($d1 && $d2 && $d1 < $d2) $overdue = (int)$d1->diff($d2)->days;
    }
    $inv['status']       = $status;
    $inv['days_overdue'] = $overdue;
    $inv['is_overdue']   = ($overdue > 0);
}

function alertConfig() {
    return [
        'min_amount' => ALERT_MIN_AMOUNT,
        'yellow'     => ALERT_DAYS_YELLOW,
        'orange'     => ALERT_DAYS_ORANGE,
        'red'        => ALERT_DAYS_RED,
    ];
}

// ── SỔ NỢ ĐƠN GIẢN ───────────────────────────────────────────
// Mỗi dòng là 1 lần Nợ (debt) hoặc Trừ nợ (credit) của 1 người thuộc 1 công ty.
// Số dư = tổng debt − tổng credit. Không có hạn thanh toán.
$pdo->exec("CREATE TABLE IF NOT EXISTS `crm_debt_entries` (
  `id`           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `company_id`   INT UNSIGNED  NOT NULL,
  `customer_id`  INT UNSIGNED  NOT NULL COMMENT 'Nhân viên / người liên hệ của công ty',
  `project`      VARCHAR(300)  DEFAULT NULL COMMENT 'Tên dự án',
  `project_code` VARCHAR(60)   DEFAULT NULL COMMENT 'Mã báo giá nếu chọn từ gợi ý',
  `kind`         VARCHAR(8)    NOT NULL DEFAULT 'debt' COMMENT 'debt = ghi nợ | credit = trừ nợ',
  `amount`       DECIMAL(16,2) NOT NULL DEFAULT 0,
  `entry_date`   DATE          NOT NULL,
  `note`         VARCHAR(500)  DEFAULT NULL,
  `created_by`   VARCHAR(120)  DEFAULT NULL,
  `created_at`   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_company`  (`company_id`),
  INDEX `idx_customer` (`customer_id`),
  INDEX `idx_date`     (`entry_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// Chuyển dữ liệu công nợ cũ sang sổ nợ — chỉ chạy 1 lần, khi sổ còn rỗng
$__n = (int)$pdo->query("SELECT COUNT(*) FROM `crm_debt_entries`")->fetchColumn();
if ($__n === 0) {
    $old = $pdo->query("SELECT COUNT(*) FROM `crm_invoices` WHERE deleted_at IS NULL")->fetchColumn();
    if ((int)$old > 0) {
        // Hoá đơn cũ → ghi nợ. Người liên hệ trống thì lấy người đầu tiên của công ty.
        $pdo->exec("INSERT INTO `crm_debt_entries`
              (company_id, customer_id, project, kind, amount, entry_date, note, created_by, created_at)
            SELECT i.company_id,
                   COALESCE(i.customer_id,
                            (SELECT c.id FROM `crm_customers` c
                              WHERE c.company_id = i.company_id AND c.deleted_at IS NULL
                              ORDER BY c.id ASC LIMIT 1)),
                   i.project, 'debt', i.amount,
                   COALESCE(i.issue_date, DATE(i.created_at)),
                   CONCAT('Chuyển từ công nợ cũ', IFNULL(CONCAT(' · ', i.invoice_no), '')),
                   i.created_by, i.created_at
              FROM `crm_invoices` i
             WHERE i.deleted_at IS NULL
               AND i.company_id IS NOT NULL
               AND COALESCE(i.customer_id,
                            (SELECT c.id FROM `crm_customers` c
                              WHERE c.company_id = i.company_id AND c.deleted_at IS NULL
                              ORDER BY c.id ASC LIMIT 1)) IS NOT NULL");
        // Thanh toán cũ → trừ nợ
        $pdo->exec("INSERT INTO `crm_debt_entries`
              (company_id, customer_id, project, kind, amount, entry_date, note, created_by, created_at)
            SELECT i.company_id,
                   COALESCE(i.customer_id,
                            (SELECT c.id FROM `crm_customers` c
                              WHERE c.company_id = i.company_id AND c.deleted_at IS NULL
                              ORDER BY c.id ASC LIMIT 1)),
                   i.project, 'credit', p.amount,
                   COALESCE(p.pay_date, DATE(p.created_at)),
                   'Chuyển từ thanh toán cũ', p.created_by, p.created_at
              FROM `crm_payments` p
              JOIN `crm_invoices` i ON i.id = p.invoice_id
             WHERE i.deleted_at IS NULL
               AND i.company_id IS NOT NULL
               AND COALESCE(i.customer_id,
                            (SELECT c.id FROM `crm_customers` c
                              WHERE c.company_id = i.company_id AND c.deleted_at IS NULL
                              ORDER BY c.id ASC LIMIT 1)) IS NOT NULL");
    }
}

$action = $_GET['action'] ?? '';
$B      = body_json();
$today  = date('Y-m-d');

switch ($action) {

// ════════════════ SỔ NỢ ĐƠN GIẢN ════════════════
// Trang chính: nhóm theo công ty, trong đó là từng người còn nợ
case 'debt-board': {
    $q    = trim((string)($_GET['q'] ?? ''));
    $all  = !empty($_GET['all']);          // all=1 → kể cả người đã hết nợ
    $args = [];
    $where = '';
    if ($q !== '') {
        $where = " AND (co.name LIKE ? OR c.name LIKE ? OR e.project LIKE ? OR e.project_code LIKE ?)";
        $like = '%' . $q . '%';
        array_push($args, $like, $like, $like, $like);
    }
    $sql = "SELECT co.id AS company_id, co.name AS company_name,
                   c.id  AS customer_id, c.name AS customer_name, c.dept, c.phone, c.email,
                   SUM(CASE WHEN e.kind = 'debt'   THEN e.amount ELSE 0 END) AS total_debt,
                   SUM(CASE WHEN e.kind = 'credit' THEN e.amount ELSE 0 END) AS total_paid,
                   COUNT(*)          AS entry_count,
                   MAX(e.entry_date) AS last_date
              FROM `crm_debt_entries` e
              JOIN `crm_companies` co ON co.id = e.company_id
              JOIN `crm_customers` c  ON c.id  = e.customer_id
             WHERE co.deleted_at IS NULL AND c.deleted_at IS NULL" . $where . "
          GROUP BY co.id, co.name, c.id, c.name, c.dept, c.phone, c.email
          ORDER BY co.name ASC, c.name ASC";
    $st = $pdo->prepare($sql);
    $st->execute($args);

    $byCompany = [];
    $grand = 0.0; $people = 0;
    foreach ($st->fetchAll() as $r) {
        $bal = round((float)$r['total_debt'] - (float)$r['total_paid'], 2);
        if (!$all && abs($bal) < 0.01) continue;      // hết nợ thì ẩn
        $cid = (int)$r['company_id'];
        if (!isset($byCompany[$cid])) {
            $byCompany[$cid] = ['company_id' => $cid, 'company_name' => $r['company_name'],
                                'balance' => 0.0, 'people' => []];
        }
        $byCompany[$cid]['people'][] = [
            'customer_id'   => (int)$r['customer_id'],
            'customer_name' => $r['customer_name'],
            'dept'          => $r['dept'],
            'phone'         => $r['phone'],
            'email'         => $r['email'],
            'total_debt'    => round((float)$r['total_debt'], 2),
            'total_paid'    => round((float)$r['total_paid'], 2),
            'balance'       => $bal,
            'entry_count'   => (int)$r['entry_count'],
            'last_date'     => $r['last_date'],
        ];
        $byCompany[$cid]['balance'] = round($byCompany[$cid]['balance'] + $bal, 2);
        $grand += $bal; $people++;
    }
    $rows = array_values($byCompany);
    usort($rows, fn($a, $b) => $b['balance'] <=> $a['balance']);
    ok(['rows' => $rows, 'totals' => [
        'outstanding'   => round($grand, 2),
        'people_count'  => $people,
        'company_count' => count($rows),
    ]]);
}

// Lịch sử Nợ / Trừ nợ của 1 người
case 'debt-entries': {
    $cus = (int)($_GET['customer_id'] ?? 0);
    if (!$cus) fail('Thiếu mã nhân viên');
    $st = $pdo->prepare(
        "SELECT e.*, co.name AS company_name, c.name AS customer_name
           FROM `crm_debt_entries` e
           JOIN `crm_companies` co ON co.id = e.company_id
           JOIN `crm_customers` c  ON c.id  = e.customer_id
          WHERE e.customer_id = ?
       ORDER BY e.entry_date DESC, e.id DESC");
    $st->execute([$cus]);
    $rows = $st->fetchAll();
    $debt = 0.0; $paid = 0.0;
    foreach ($rows as &$r) {
        $r['id']     = (int)$r['id'];
        $r['amount'] = round((float)$r['amount'], 2);
        if ($r['kind'] === 'credit') $paid += $r['amount']; else $debt += $r['amount'];
    }
    unset($r);
    ok(['rows' => $rows, 'totals' => [
        'total_debt' => round($debt, 2),
        'total_paid' => round($paid, 2),
        'balance'    => round($debt - $paid, 2),
    ]]);
}

// Thêm 1 giao dịch — ngày lấy tự động ở server
case 'debt-add': {
    $company  = (int)($B['company_id'] ?? 0);
    $customer = (int)($B['customer_id'] ?? 0);
    $kind     = (($B['kind'] ?? 'debt') === 'credit') ? 'credit' : 'debt';
    $amount   = money($B['amount'] ?? 0);
    if (!$company)        fail('Vui lòng chọn công ty');
    if (!$customer)       fail('Vui lòng chọn nhân viên');
    if ($amount <= 0)     fail('Số tiền phải lớn hơn 0');

    $chk = $pdo->prepare("SELECT id FROM `crm_customers` WHERE id = ? AND company_id = ? AND deleted_at IS NULL");
    $chk->execute([$customer, $company]);
    if (!$chk->fetchColumn()) fail('Nhân viên không thuộc công ty đã chọn');

    $st = $pdo->prepare(
        "INSERT INTO `crm_debt_entries`
           (company_id, customer_id, project, project_code, kind, amount, entry_date, note, created_by)
         VALUES (?,?,?,?,?,?,?,?,?)");
    $st->execute([
        $company, $customer,
        s($B['project'] ?? '', 300) ?: null,
        s($B['project_code'] ?? '', 60) ?: null,
        $kind, $amount,
        dateOrNull($B['entry_date'] ?? '') ?: $today,      // mặc định hôm nay
        s($B['note'] ?? '', 500) ?: null,
        $WHO,
    ]);
    ok(['id' => (int)$pdo->lastInsertId(),
        'message' => $kind === 'credit' ? 'Đã trừ nợ ' . vnd($amount) : 'Đã ghi nợ ' . vnd($amount)]);
}

// Sửa 1 giao dịch đã ghi
case 'debt-update': {
    $id       = (int)($B['id'] ?? 0);
    $company  = (int)($B['company_id'] ?? 0);
    $customer = (int)($B['customer_id'] ?? 0);
    $kind     = (($B['kind'] ?? 'debt') === 'credit') ? 'credit' : 'debt';
    $amount   = money($B['amount'] ?? 0);
    if (!$id)         fail('Thiếu mã giao dịch');
    if (!$company)    fail('Vui lòng chọn công ty');
    if (!$customer)   fail('Vui lòng chọn nhân viên');
    if ($amount <= 0) fail('Số tiền phải lớn hơn 0');

    $cur = $pdo->prepare("SELECT id FROM `crm_debt_entries` WHERE id = ?");
    $cur->execute([$id]);
    if (!$cur->fetchColumn()) fail('Không tìm thấy giao dịch', 404);

    $chk = $pdo->prepare("SELECT id FROM `crm_customers` WHERE id = ? AND company_id = ? AND deleted_at IS NULL");
    $chk->execute([$customer, $company]);
    if (!$chk->fetchColumn()) fail('Nhân viên không thuộc công ty đã chọn');

    $st = $pdo->prepare(
        "UPDATE `crm_debt_entries`
            SET company_id = ?, customer_id = ?, project = ?, project_code = ?,
                kind = ?, amount = ?, entry_date = ?, note = ?
          WHERE id = ?");
    $st->execute([
        $company, $customer,
        s($B['project'] ?? '', 300) ?: null,
        s($B['project_code'] ?? '', 60) ?: null,
        $kind, $amount,
        dateOrNull($B['entry_date'] ?? '') ?: $today,
        s($B['note'] ?? '', 500) ?: null,
        $id,
    ]);
    ok(['id' => $id, 'message' => 'Đã cập nhật giao dịch']);
}

case 'debt-delete': {
    $id = (int)($B['id'] ?? 0);
    if (!$id) fail('Thiếu mã giao dịch');
    $pdo->prepare("DELETE FROM `crm_debt_entries` WHERE id = ?")->execute([$id]);
    ok(['message' => 'Đã xoá giao dịch']);
}

// Gợi ý tên / mã dự án lấy từ báo giá
case 'project-suggest': {
    $q = trim((string)($_GET['q'] ?? ''));
    if (mb_strlen($q) < 1) ok([]);
    $like = '%' . $q . '%';
    $st = $pdo->prepare(
        "SELECT code, title FROM `quotations`
          WHERE deleted_at IS NULL AND (code LIKE ? OR title LIKE ?)
       ORDER BY quotation_date DESC, id DESC LIMIT 12");
    $st->execute([$like, $like]);
    ok($st->fetchAll());
}

// ════════════════ CÔNG TY ════════════════
case 'companies': {
    $trash = !empty($_GET['trash']);
    $q     = trim((string)($_GET['q'] ?? ''));
    $sql = "SELECT co.*,
              (SELECT COUNT(*) FROM crm_customers c WHERE c.company_id = co.id AND c.deleted_at IS NULL) AS contact_count,
              (SELECT COUNT(*) FROM crm_invoices i WHERE i.company_id = co.id AND i.deleted_at IS NULL) AS invoice_count,
              (SELECT COALESCE(SUM(i.amount),0) FROM crm_invoices i WHERE i.company_id = co.id AND i.deleted_at IS NULL) AS total_amount,
              (SELECT COALESCE(SUM(p.amount),0) FROM crm_payments p
                 JOIN crm_invoices i2 ON i2.id = p.invoice_id
                WHERE i2.company_id = co.id AND i2.deleted_at IS NULL) AS total_paid
            FROM crm_companies co
            WHERE co.deleted_at IS " . ($trash ? "NOT NULL" : "NULL");
    $params = [];
    if ($q !== '') {
        $sql .= " AND (co.name LIKE :q OR co.tax_code LIKE :q OR co.address LIKE :q)";
        $params[':q'] = '%' . $q . '%';
    }
    $sql .= " ORDER BY co.name ASC";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['id']            = (int)$r['id'];
        $r['active']        = (int)$r['active'];
        $r['contact_count'] = (int)$r['contact_count'];
        $r['invoice_count'] = (int)$r['invoice_count'];
        $r['total_amount']  = (float)$r['total_amount'];
        $r['total_paid']    = (float)$r['total_paid'];
        $out = round($r['total_amount'] - $r['total_paid'], 2);
        $r['outstanding']   = $out > 0 ? $out : 0.0;
    }
    unset($r);
    ok($rows);
}

case 'company-save': {
    $id   = (int)($B['id'] ?? 0);
    $name = s($B['name'] ?? '', 300);
    if ($name === '') fail('Vui lòng nhập tên công ty');
    $tax     = s($B['tax_code'] ?? '', 60);
    $address = s($B['address'] ?? '', 500);
    $note    = mb_substr((string)($B['note'] ?? ''), 0, 5000);
    $active  = isset($B['active']) ? (int)(bool)$B['active'] : 1;

    // Không cho trùng tên công ty
    $chk = $pdo->prepare("SELECT id FROM crm_companies WHERE name = ? AND deleted_at IS NULL AND id <> ? LIMIT 1");
    $chk->execute([$name, $id]);
    if ($chk->fetch()) fail('Đã có công ty tên "' . $name . '"', 409);

    if ($id) {
        $st = $pdo->prepare("UPDATE crm_companies SET name=?, tax_code=?, address=?, note=?, active=? WHERE id=?");
        $st->execute([$name, $tax, $address, $note, $active, $id]);
        audit($pdo, $WHO, 'company', 'update', 'Cập nhật công ty ' . $name, ['company_id' => $id]);
        ok(['id' => $id, 'message' => 'Đã cập nhật công ty']);
    }
    $st = $pdo->prepare("INSERT INTO crm_companies (name, tax_code, address, note, active, created_by) VALUES (?,?,?,?,?,?)");
    $st->execute([$name, $tax, $address, $note, $active, $WHO]);
    $newId = (int)$pdo->lastInsertId();
    audit($pdo, $WHO, 'company', 'create', 'Thêm công ty ' . $name, ['company_id' => $newId]);
    ok(['id' => $newId, 'message' => 'Đã thêm công ty']);
}

case 'company-delete': {
    $id = (int)($B['id'] ?? 0);
    if (!$id) fail('id is required');
    $st = $pdo->prepare("SELECT name FROM crm_companies WHERE id = ?");
    $st->execute([$id]); $co = $st->fetch();
    $pdo->prepare("UPDATE crm_companies SET deleted_at = NOW() WHERE id = ?")->execute([$id]);
    if ($co) audit($pdo, $WHO, 'company', 'delete', 'Xoá công ty ' . $co['name'], ['company_id' => $id]);
    ok(['message' => 'Đã chuyển vào thùng rác']);
}

case 'company-restore': {
    $id = (int)($B['id'] ?? 0);
    if (!$id) fail('id is required');
    $pdo->prepare("UPDATE crm_companies SET deleted_at = NULL WHERE id = ?")->execute([$id]);
    audit($pdo, $WHO, 'company', 'restore', 'Khôi phục công ty', ['company_id' => $id]);
    ok(['message' => 'Đã khôi phục']);
}

case 'company-purge': {
    if (!$IS_ADMIN) fail('Chỉ Admin mới xoá vĩnh viễn được', 403);
    $id = (int)($B['id'] ?? 0);
    if (!$id) fail('id is required');
    $n = (int)$pdo->query("SELECT COUNT(*) FROM crm_invoices WHERE company_id = " . $id)->fetchColumn();
    if ($n > 0) fail('Công ty còn ' . $n . ' khoản nợ — xoá khoản nợ trước.');
    $m = (int)$pdo->query("SELECT COUNT(*) FROM crm_customers WHERE company_id = " . $id)->fetchColumn();
    if ($m > 0) fail('Công ty còn ' . $m . ' người liên hệ — xoá hoặc chuyển họ sang công ty khác trước.');
    $pdo->prepare("DELETE FROM crm_companies WHERE id = ?")->execute([$id]);
    ok(['message' => 'Đã xoá vĩnh viễn']);
}

// ════════════════ KHÁCH HÀNG (người liên hệ) ════════════════
case 'customers': {
    $trash = !empty($_GET['trash']);
    $cid   = (int)($_GET['company_id'] ?? 0);
    $q     = trim((string)($_GET['q'] ?? ''));
    $sql = "SELECT c.*, co.name AS company_name, co.tax_code AS company_tax_code
              FROM crm_customers c
              LEFT JOIN crm_companies co ON co.id = c.company_id
             WHERE c.deleted_at IS " . ($trash ? "NOT NULL" : "NULL");
    $params = [];
    if ($cid) { $sql .= " AND c.company_id = :cid"; $params[':cid'] = $cid; }
    if ($q !== '') {
        $sql .= " AND (c.name LIKE :q OR c.email LIKE :q OR c.phone LIKE :q OR c.dept LIKE :q OR co.name LIKE :q)";
        $params[':q'] = '%' . $q . '%';
    }
    $sql .= " ORDER BY co.name ASC, c.name ASC";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['id']         = (int)$r['id'];
        $r['company_id'] = $r['company_id'] === null ? null : (int)$r['company_id'];
        $r['active']     = (int)$r['active'];
    }
    unset($r);
    ok($rows);
}

case 'customer-save': {
    $id      = (int)($B['id'] ?? 0);
    $name    = s($B['name'] ?? '', 200);
    if ($name === '') fail('Vui lòng nhập tên khách hàng');
    $companyId = (int)($B['company_id'] ?? 0);
    if (!$companyId) fail('Vui lòng chọn công ty của khách hàng');
    $chk = $pdo->prepare("SELECT name FROM crm_companies WHERE id = ? AND deleted_at IS NULL");
    $chk->execute([$companyId]);
    $co = $chk->fetch();
    if (!$co) fail('Công ty không tồn tại', 404);

    $dept   = s($B['dept'] ?? '', 200);
    $phone  = s($B['phone'] ?? '', 60);
    $email  = s($B['email'] ?? '', 200);
    $note   = mb_substr((string)($B['note'] ?? ''), 0, 5000);
    $active = isset($B['active']) ? (int)(bool)$B['active'] : 1;

    if ($id) {
        $st = $pdo->prepare("UPDATE crm_customers SET company_id=?, name=?, dept=?, company=?, phone=?, email=?, note=?, active=? WHERE id=?");
        $st->execute([$companyId, $name, $dept, $co['name'], $phone, $email, $note, $active, $id]);
        audit($pdo, $WHO, 'customer', 'update', 'Cập nhật khách hàng ' . $name . ' (' . $co['name'] . ')',
            ['company_id' => $companyId, 'customer_id' => $id]);
        ok(['id' => $id, 'message' => 'Đã cập nhật khách hàng']);
    }
    $st = $pdo->prepare("INSERT INTO crm_customers (company_id, name, dept, company, phone, email, note, active, created_by) VALUES (?,?,?,?,?,?,?,?,?)");
    $st->execute([$companyId, $name, $dept, $co['name'], $phone, $email, $note, $active, $WHO]);
    $newId = (int)$pdo->lastInsertId();
    audit($pdo, $WHO, 'customer', 'create', 'Thêm khách hàng ' . $name . ' (' . $co['name'] . ')',
        ['company_id' => $companyId, 'customer_id' => $newId]);
    ok(['id' => $newId, 'message' => 'Đã thêm khách hàng']);
}

case 'customer-delete': {
    $id = (int)($B['id'] ?? 0);
    if (!$id) fail('id is required');
    $st = $pdo->prepare("SELECT name, company_id FROM crm_customers WHERE id = ?");
    $st->execute([$id]); $c = $st->fetch();
    $pdo->prepare("UPDATE crm_customers SET deleted_at = NOW() WHERE id = ?")->execute([$id]);
    if ($c) audit($pdo, $WHO, 'customer', 'delete', 'Xoá khách hàng ' . $c['name'],
        ['company_id' => $c['company_id'], 'customer_id' => $id]);
    ok(['message' => 'Đã chuyển vào thùng rác']);
}

case 'customer-restore': {
    $id = (int)($B['id'] ?? 0);
    if (!$id) fail('id is required');
    $pdo->prepare("UPDATE crm_customers SET deleted_at = NULL WHERE id = ?")->execute([$id]);
    ok(['message' => 'Đã khôi phục']);
}

case 'customer-purge': {
    if (!$IS_ADMIN) fail('Chỉ Admin mới xoá vĩnh viễn được', 403);
    $id = (int)($B['id'] ?? 0);
    if (!$id) fail('id is required');
    $pdo->prepare("UPDATE crm_invoices SET customer_id = NULL WHERE customer_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM crm_customers WHERE id = ?")->execute([$id]);
    ok(['message' => 'Đã xoá vĩnh viễn']);
}

// ════════════════ CÔNG NỢ ════════════════
case 'invoices': {
    $trash  = !empty($_GET['trash']);
    $coid   = (int)($_GET['company_id'] ?? 0);
    $status = trim((string)($_GET['status'] ?? ''));
    $q      = trim((string)($_GET['q'] ?? ''));
    $from   = dateOrNull($_GET['from'] ?? '');
    $to     = dateOrNull($_GET['to'] ?? '');

    $sql = "SELECT i.*, co.name AS company_name, c.name AS customer_name, c.dept AS customer_dept,
                   COALESCE((SELECT SUM(p.amount) FROM crm_payments p WHERE p.invoice_id = i.id),0) AS paid
              FROM crm_invoices i
              LEFT JOIN crm_companies co ON co.id = i.company_id
              LEFT JOIN crm_customers c  ON c.id  = i.customer_id
             WHERE i.deleted_at IS " . ($trash ? "NOT NULL" : "NULL");
    $params = [];
    if ($coid) { $sql .= " AND i.company_id = :coid"; $params[':coid'] = $coid; }
    if ($from) { $sql .= " AND i.issue_date >= :from"; $params[':from'] = $from; }
    if ($to)   { $sql .= " AND i.issue_date <= :to";   $params[':to'] = $to; }
    if ($q !== '') {
        $sql .= " AND (i.invoice_no LIKE :q OR i.project LIKE :q OR i.note LIKE :q OR co.name LIKE :q OR c.name LIKE :q)";
        $params[':q'] = '%' . $q . '%';
    }
    $sql .= " ORDER BY (i.due_date IS NULL), i.due_date ASC, i.id DESC";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll();

    $ids = [];
    foreach ($rows as $r) $ids[] = (int)$r['id'];
    $payMap = [];
    if ($ids) {
        $in = implode(',', $ids);
        $ps = $pdo->query("SELECT id, invoice_id, pay_date, amount, method, note, created_by, created_at
                             FROM crm_payments WHERE invoice_id IN ($in) ORDER BY pay_date ASC, id ASC");
        foreach ($ps->fetchAll() as $p) {
            $p['id']     = (int)$p['id'];
            $p['amount'] = (float)$p['amount'];
            $payMap[(int)$p['invoice_id']][] = $p;
        }
    }

    $out = [];
    foreach ($rows as $r) {
        $r['id']          = (int)$r['id'];
        $r['company_id']  = $r['company_id']  === null ? null : (int)$r['company_id'];
        $r['customer_id'] = $r['customer_id'] === null ? null : (int)$r['customer_id'];
        decorate($r, $today);
        $r['payments'] = $payMap[$r['id']] ?? [];
        if ($status !== '' && $status !== 'all') {
            if ($status === 'overdue') { if (!$r['is_overdue']) continue; }
            elseif ($status === 'open') { if ($r['status'] === 'paid') continue; }
            elseif ($r['status'] !== $status) continue;
        }
        $out[] = $r;
    }
    ok($out);
}

case 'invoice-save': {
    $id   = (int)($B['id'] ?? 0);
    $coid = (int)($B['company_id'] ?? 0);
    if (!$coid) fail('Vui lòng chọn công ty');
    $chk = $pdo->prepare("SELECT id, name FROM crm_companies WHERE id = ?");
    $chk->execute([$coid]);
    $co = $chk->fetch();
    if (!$co) fail('Công ty không tồn tại', 404);

    $cust = (int)($B['customer_id'] ?? 0);
    if ($cust) {
        $cc = $pdo->prepare("SELECT id FROM crm_customers WHERE id = ? AND company_id = ?");
        $cc->execute([$cust, $coid]);
        if (!$cc->fetch()) $cust = 0;   // người liên hệ không thuộc công ty này → bỏ qua
    }

    $invoiceNo = s($B['invoice_no'] ?? '', 100);
    $project   = s($B['project'] ?? '', 300);
    $amount    = money($B['amount'] ?? 0);
    if ($amount <= 0) fail('Giá trị khoản nợ phải lớn hơn 0');
    $issue = dateOrNull($B['issue_date'] ?? '');
    $due   = dateOrNull($B['due_date'] ?? '');
    $note  = mb_substr((string)($B['note'] ?? ''), 0, 5000);

    $label = invLabel(['id' => $id, 'invoice_no' => $invoiceNo, 'project' => $project]);

    if ($id) {
        $oldSt = $pdo->prepare("SELECT amount, invoice_no, project, due_date FROM crm_invoices WHERE id = ?");
        $oldSt->execute([$id]);
        $old = $oldSt->fetch() ?: [];

        $st = $pdo->prepare("UPDATE crm_invoices SET company_id=?, customer_id=?, invoice_no=?, project=?, amount=?, issue_date=?, due_date=?, note=? WHERE id=?");
        $st->execute([$coid, $cust ?: null, $invoiceNo, $project, $amount, $issue, $due, $note, $id]);

        $chg = [];
        if ($old && abs((float)$old['amount'] - $amount) > 0.009) $chg[] = 'giá trị ' . vnd($old['amount']) . ' → ' . vnd($amount);
        if ($old && (string)$old['due_date'] !== (string)$due)     $chg[] = 'hạn TT ' . ($old['due_date'] ?: '—') . ' → ' . ($due ?: '—');
        audit($pdo, $WHO, 'invoice', 'update',
            'Sửa khoản nợ ' . $label . ($chg ? ' (' . implode(', ', $chg) . ')' : ''),
            ['company_id' => $coid, 'customer_id' => $cust, 'invoice_id' => $id, 'amount' => $amount]);
        ok(['id' => $id, 'message' => 'Đã cập nhật khoản nợ']);
    }
    $st = $pdo->prepare("INSERT INTO crm_invoices (company_id, customer_id, invoice_no, project, amount, issue_date, due_date, note, created_by) VALUES (?,?,?,?,?,?,?,?,?)");
    $st->execute([$coid, $cust ?: null, $invoiceNo, $project, $amount, $issue, $due, $note, $WHO]);
    $newId = (int)$pdo->lastInsertId();
    audit($pdo, $WHO, 'invoice', 'create',
        'Thêm khoản nợ ' . invLabel(['id' => $newId, 'invoice_no' => $invoiceNo, 'project' => $project]) . ' — ' . vnd($amount) . ' (' . $co['name'] . ')',
        ['company_id' => $coid, 'customer_id' => $cust, 'invoice_id' => $newId, 'amount' => $amount]);
    ok(['id' => $newId, 'message' => 'Đã thêm khoản nợ']);
}

case 'invoice-delete': {
    $id = (int)($B['id'] ?? 0);
    if (!$id) fail('id is required');
    $st = $pdo->prepare("SELECT id, company_id, customer_id, invoice_no, project, amount FROM crm_invoices WHERE id = ?");
    $st->execute([$id]); $inv = $st->fetch();
    $pdo->prepare("UPDATE crm_invoices SET deleted_at = NOW() WHERE id = ?")->execute([$id]);
    if ($inv) audit($pdo, $WHO, 'invoice', 'delete',
        'Xoá khoản nợ ' . invLabel($inv) . ' — ' . vnd($inv['amount']),
        ['company_id' => $inv['company_id'], 'customer_id' => $inv['customer_id'], 'invoice_id' => $id, 'amount' => $inv['amount']]);
    ok(['message' => 'Đã chuyển vào thùng rác']);
}

case 'invoice-restore': {
    $id = (int)($B['id'] ?? 0);
    if (!$id) fail('id is required');
    $st = $pdo->prepare("SELECT id, company_id, invoice_no, project, amount FROM crm_invoices WHERE id = ?");
    $st->execute([$id]); $inv = $st->fetch();
    $pdo->prepare("UPDATE crm_invoices SET deleted_at = NULL WHERE id = ?")->execute([$id]);
    if ($inv) audit($pdo, $WHO, 'invoice', 'restore',
        'Khôi phục khoản nợ ' . invLabel($inv) . ' — ' . vnd($inv['amount']),
        ['company_id' => $inv['company_id'], 'invoice_id' => $id, 'amount' => $inv['amount']]);
    ok(['message' => 'Đã khôi phục']);
}

case 'invoice-purge': {
    if (!$IS_ADMIN) fail('Chỉ Admin mới xoá vĩnh viễn được', 403);
    $id = (int)($B['id'] ?? 0);
    if (!$id) fail('id is required');
    $st = $pdo->prepare("SELECT id, company_id, invoice_no, project, amount FROM crm_invoices WHERE id = ?");
    $st->execute([$id]); $inv = $st->fetch();
    $pdo->prepare("DELETE FROM crm_payments WHERE invoice_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM crm_invoices WHERE id = ?")->execute([$id]);
    if ($inv) audit($pdo, $WHO, 'invoice', 'purge',
        'Xoá vĩnh viễn khoản nợ ' . invLabel($inv) . ' — ' . vnd($inv['amount']),
        ['company_id' => $inv['company_id'], 'amount' => $inv['amount']]);
    ok(['message' => 'Đã xoá vĩnh viễn khoản nợ và lịch sử thanh toán']);
}

// ════════════════ THANH TOÁN ════════════════
case 'payment-add': {
    $iid = (int)($B['invoice_id'] ?? 0);
    if (!$iid) fail('invoice_id is required');
    $st = $pdo->prepare("SELECT amount, COALESCE((SELECT SUM(p.amount) FROM crm_payments p WHERE p.invoice_id = crm_invoices.id),0) AS paid
                           FROM crm_invoices WHERE id = ? AND deleted_at IS NULL");
    $st->execute([$iid]);
    $inv = $st->fetch();
    if (!$inv) fail('Khoản nợ không tồn tại', 404);

    $amount = money($B['amount'] ?? 0);
    if ($amount <= 0) fail('Số tiền thanh toán phải lớn hơn 0');
    $remain = round((float)$inv['amount'] - (float)$inv['paid'], 2);
    if ($amount > $remain + 0.009) fail('Số tiền vượt quá công nợ còn lại (' . vnd($remain) . ')');

    $pay    = dateOrNull($B['pay_date'] ?? '') ?: date('Y-m-d');
    $method = s($B['method'] ?? '', 60);
    $note   = s($B['note'] ?? '', 500);

    $ins = $pdo->prepare("INSERT INTO crm_payments (invoice_id, pay_date, amount, method, note, created_by) VALUES (?,?,?,?,?,?)");
    $ins->execute([$iid, $pay, $amount, $method, $note, $WHO]);
    $pid = (int)$pdo->lastInsertId();

    $mi = $pdo->prepare("SELECT id, company_id, customer_id, invoice_no, project FROM crm_invoices WHERE id = ?");
    $mi->execute([$iid]); $minv = $mi->fetch() ?: ['id' => $iid, 'company_id' => null, 'customer_id' => null];
    audit($pdo, $WHO, 'payment', 'pay',
        'Ghi nhận thanh toán ' . vnd($amount) . ' cho ' . invLabel($minv) . ($method ? ' (' . $method . ')' : ''),
        ['company_id' => $minv['company_id'], 'customer_id' => $minv['customer_id'],
         'invoice_id' => $iid, 'payment_id' => $pid, 'amount' => $amount]);
    ok(['id' => $pid, 'message' => 'Đã ghi nhận thanh toán']);
}

case 'payment-quick': {
    $coid = (int)($B['company_id'] ?? 0);
    if (!$coid) fail('Vui lòng chọn công ty');
    $amount = money($B['amount'] ?? 0);
    if ($amount <= 0) fail('Số tiền thanh toán phải lớn hơn 0');

    $pay    = dateOrNull($B['pay_date'] ?? '') ?: date('Y-m-d');
    $method = s($B['method'] ?? '', 60);
    $note   = s($B['note'] ?? '', 500);

    $cs = $pdo->prepare("SELECT name FROM crm_companies WHERE id = ? AND deleted_at IS NULL");
    $cs->execute([$coid]);
    $co = $cs->fetch();
    if (!$co) fail('Công ty không tồn tại', 404);

    $st = $pdo->prepare("SELECT i.id, i.invoice_no, i.project, i.amount, i.due_date,
                                COALESCE((SELECT SUM(p.amount) FROM crm_payments p WHERE p.invoice_id = i.id),0) AS paid
                           FROM crm_invoices i
                          WHERE i.company_id = ? AND i.deleted_at IS NULL
                          ORDER BY (i.due_date IS NULL), i.due_date ASC, (i.issue_date IS NULL), i.issue_date ASC, i.id ASC");
    $st->execute([$coid]);
    $invs = $st->fetchAll();

    $open = []; $totalRemain = 0.0;
    foreach ($invs as $i) {
        $rem = round((float)$i['amount'] - (float)$i['paid'], 2);
        if ($rem > 0.009) { $i['remaining'] = $rem; $open[] = $i; $totalRemain += $rem; }
    }
    if (!$open) fail('Công ty này không còn khoản nợ nào để trừ');
    if ($amount > round($totalRemain, 2) + 0.009) fail('Số tiền vượt quá tổng công nợ còn lại (' . vnd($totalRemain) . ')');

    $left = $amount; $alloc = [];
    $ins = $pdo->prepare("INSERT INTO crm_payments (invoice_id, pay_date, amount, method, note, created_by) VALUES (?,?,?,?,?,?)");
    $pdo->beginTransaction();
    try {
        foreach ($open as $i) {
            if ($left <= 0.009) break;
            $take = round(min($left, $i['remaining']), 2);
            $ins->execute([(int)$i['id'], $pay, $take, $method, $note, $WHO]);
            $alloc[] = [
                'invoice_id' => (int)$i['id'],
                'payment_id' => (int)$pdo->lastInsertId(),
                'label'      => invLabel($i),
                'amount'     => $take,
                'closed'     => ($take >= $i['remaining'] - 0.009),
            ];
            $left = round($left - $take, 2);
        }
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        fail('Không ghi được thanh toán: ' . $e->getMessage(), 500);
    }

    $parts = [];
    foreach ($alloc as $a) $parts[] = $a['label'] . ' ' . vnd($a['amount']);
    audit($pdo, $WHO, 'payment', 'pay',
        'Ghi nhận thanh toán ' . vnd($amount) . ' cho ' . $co['name']
        . ' — tự trừ: ' . implode(', ', $parts) . ($method ? ' (' . $method . ')' : ''),
        ['company_id' => $coid, 'amount' => $amount]);

    ok([
        'allocated'   => $alloc,
        'amount'      => $amount,
        'invoice_cnt' => count($alloc),
        'message'     => 'Đã ghi nhận ' . vnd($amount) . ' vào ' . count($alloc) . ' khoản nợ',
    ]);
}

case 'payment-delete': {
    $id = (int)($B['id'] ?? 0);
    if (!$id) fail('id is required');
    $st = $pdo->prepare("SELECT p.amount, p.pay_date, i.id AS invoice_id, i.company_id, i.invoice_no, i.project
                           FROM crm_payments p LEFT JOIN crm_invoices i ON i.id = p.invoice_id
                          WHERE p.id = ?");
    $st->execute([$id]); $pay = $st->fetch();
    $pdo->prepare("DELETE FROM crm_payments WHERE id = ?")->execute([$id]);
    if ($pay) audit($pdo, $WHO, 'payment', 'delete',
        'Xoá thanh toán ' . vnd($pay['amount']) . ' của ' . invLabel($pay),
        ['company_id' => $pay['company_id'], 'invoice_id' => $pay['invoice_id'], 'amount' => $pay['amount']]);
    ok(['message' => 'Đã xoá lần thanh toán']);
}

// ════════ TỔNG CÔNG NỢ THEO CÔNG TY (trang debts.html) ════════
case 'company-debts':
case 'customer-debts': {   // giữ tên cũ để trang cache cũ không vỡ
    $q = trim((string)($_GET['q'] ?? ''));

    $sql = "SELECT co.id, co.name, co.tax_code, co.address, co.note, co.active,
              (SELECT COUNT(*) FROM crm_customers c WHERE c.company_id = co.id AND c.deleted_at IS NULL) AS contact_count,
              (SELECT COUNT(*) FROM crm_invoices i WHERE i.company_id = co.id AND i.deleted_at IS NULL) AS invoice_count,
              (SELECT COALESCE(SUM(i.amount),0) FROM crm_invoices i WHERE i.company_id = co.id AND i.deleted_at IS NULL) AS total_amount,
              (SELECT COALESCE(SUM(p.amount),0) FROM crm_payments p
                 JOIN crm_invoices i2 ON i2.id = p.invoice_id
                WHERE i2.company_id = co.id AND i2.deleted_at IS NULL) AS total_paid,
              (SELECT COALESCE(SUM(GREATEST(i.amount - COALESCE((SELECT SUM(p2.amount) FROM crm_payments p2 WHERE p2.invoice_id = i.id),0),0)),0)
                 FROM crm_invoices i
                WHERE i.company_id = co.id AND i.deleted_at IS NULL
                  AND i.due_date IS NOT NULL AND i.due_date < '" . $today . "') AS overdue_amount,
              (SELECT MIN(i.due_date) FROM crm_invoices i
                WHERE i.company_id = co.id AND i.deleted_at IS NULL
                  AND i.due_date IS NOT NULL AND i.due_date < '" . $today . "'
                  AND i.amount > COALESCE((SELECT SUM(p2.amount) FROM crm_payments p2 WHERE p2.invoice_id = i.id),0)) AS oldest_due,
              (SELECT MAX(p.pay_date) FROM crm_payments p
                 JOIN crm_invoices i3 ON i3.id = p.invoice_id
                WHERE i3.company_id = co.id AND i3.deleted_at IS NULL) AS last_payment_date,
              (SELECT COUNT(*) FROM crm_invoices i WHERE i.company_id = co.id AND i.deleted_at IS NULL
                  AND i.amount > COALESCE((SELECT SUM(p2.amount) FROM crm_payments p2 WHERE p2.invoice_id = i.id),0)) AS open_count
            FROM crm_companies co
            WHERE co.deleted_at IS NULL";
    $params = [];
    if ($q !== '') {
        $sql .= " AND (co.name LIKE :q OR co.tax_code LIKE :q)";
        $params[':q'] = '%' . $q . '%';
    }
    $sql .= " ORDER BY co.name ASC";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll();

    $out = [];
    foreach ($rows as $r) {
        $r['id']             = (int)$r['id'];
        $r['active']         = (int)$r['active'];
        $r['contact_count']  = (int)$r['contact_count'];
        $r['invoice_count']  = (int)$r['invoice_count'];
        $r['open_count']     = (int)$r['open_count'];
        $r['total_amount']   = (float)$r['total_amount'];
        $r['total_paid']     = (float)$r['total_paid'];
        $r['overdue_amount'] = (float)$r['overdue_amount'];
        $o = round($r['total_amount'] - $r['total_paid'], 2);
        $r['outstanding']    = $o > 0 ? $o : 0.0;

        $days = 0;
        if (!empty($r['oldest_due'])) {
            $d1 = date_create($r['oldest_due']); $d2 = date_create($today);
            if ($d1 && $d2 && $d1 < $d2) $days = (int)$d1->diff($d2)->days;
        }
        $r['days_overdue']   = $days;
        $r['months_overdue'] = $days > 0 ? floor($days / 30) : 0;
        $r['alert']          = alertLevel($r['outstanding'], $days);
        if (!empty($_GET['debt_only']) && $r['outstanding'] <= 0.009) continue;
        $out[] = $r;
    }

    ok(['companies' => $out, 'customers' => $out, 'alert_config' => alertConfig(), 'today' => $today]);
}

// ════════ CHI TIẾT 1 CÔNG TY (trang debt-detail.html) ════════
case 'company-detail':
case 'customer-detail': {
    $coid = (int)($_GET['company_id'] ?? 0);
    if (!$coid && !empty($_GET['customer_id'])) {   // link cũ ?customer=ID → suy ra công ty
        $cs = $pdo->prepare("SELECT company_id FROM crm_customers WHERE id = ?");
        $cs->execute([(int)$_GET['customer_id']]);
        $coid = (int)$cs->fetchColumn();
    }
    if (!$coid) fail('company_id is required');

    $st = $pdo->prepare("SELECT * FROM crm_companies WHERE id = ?");
    $st->execute([$coid]);
    $co = $st->fetch();
    if (!$co) fail('Công ty không tồn tại', 404);
    $co['id'] = (int)$co['id'];

    $st = $pdo->prepare("SELECT id, name, dept, phone, email, note, active FROM crm_customers
                          WHERE company_id = ? AND deleted_at IS NULL ORDER BY name ASC");
    $st->execute([$coid]);
    $contacts = $st->fetchAll();
    foreach ($contacts as &$ct) { $ct['id'] = (int)$ct['id']; $ct['active'] = (int)$ct['active']; }
    unset($ct);

    $st = $pdo->prepare("SELECT i.*, c.name AS customer_name,
                                COALESCE((SELECT SUM(p.amount) FROM crm_payments p WHERE p.invoice_id = i.id),0) AS paid
                           FROM crm_invoices i
                           LEFT JOIN crm_customers c ON c.id = i.customer_id
                          WHERE i.company_id = ? AND i.deleted_at IS NULL
                          ORDER BY (i.due_date IS NULL), i.due_date ASC, i.id DESC");
    $st->execute([$coid]);
    $invoices = $st->fetchAll();

    $ids = [];
    foreach ($invoices as $r) $ids[] = (int)$r['id'];
    $payMap = []; $payFlat = [];
    if ($ids) {
        $in = implode(',', $ids);
        $ps = $pdo->query("SELECT p.*, i.invoice_no, i.project
                             FROM crm_payments p JOIN crm_invoices i ON i.id = p.invoice_id
                            WHERE p.invoice_id IN ($in)
                            ORDER BY p.pay_date DESC, p.id DESC");
        foreach ($ps->fetchAll() as $pRow) {
            $pRow['id']         = (int)$pRow['id'];
            $pRow['invoice_id'] = (int)$pRow['invoice_id'];
            $pRow['amount']     = (float)$pRow['amount'];
            $payMap[$pRow['invoice_id']][] = $pRow;
            $payFlat[] = $pRow;
        }
    }

    $totalAmount = 0.0; $totalPaid = 0.0; $overdueAmt = 0.0; $overdueCnt = 0; $maxDays = 0;
    $outInv = [];
    foreach ($invoices as $r) {
        $r['id']          = (int)$r['id'];
        $r['company_id']  = (int)$r['company_id'];
        $r['customer_id'] = $r['customer_id'] === null ? null : (int)$r['customer_id'];
        decorate($r, $today);
        $r['payments'] = $payMap[$r['id']] ?? [];
        $totalAmount  += $r['amount'];
        $totalPaid    += $r['paid'];
        if ($r['is_overdue'] && $r['remaining'] > 0.009) {
            $overdueAmt += $r['remaining'];
            $overdueCnt++;
            if ($r['days_overdue'] > $maxDays) $maxDays = $r['days_overdue'];
        }
        $outInv[] = $r;
    }
    $outstanding = round($totalAmount - $totalPaid, 2);
    if ($outstanding < 0) $outstanding = 0.0;

    ok([
        'company'  => $co,
        'customer' => $co,       // tương thích ngược với trang cũ
        'contacts' => $contacts,
        'invoices' => $outInv,
        'payments' => $payFlat,
        'totals'   => [
            'contact_count'  => count($contacts),
            'invoice_count'  => count($outInv),
            'payment_count'  => count($payFlat),
            'total_amount'   => round($totalAmount, 2),
            'total_paid'     => round($totalPaid, 2),
            'outstanding'    => $outstanding,
            'overdue_amount' => round($overdueAmt, 2),
            'overdue_count'  => $overdueCnt,
            'days_overdue'   => $maxDays,
            'alert'          => alertLevel($outstanding, $maxDays),
        ],
        'alert_config' => alertConfig(),
    ]);
}

// ════════════════ LỊCH SỬ THAY ĐỔI ════════════════
case 'audit-log': {
    $coid  = (int)($_GET['company_id'] ?? 0);
    $limit = (int)($_GET['limit'] ?? 100);
    if ($limit < 1)   $limit = 100;
    if ($limit > 500) $limit = 500;

    $sql = "SELECT a.*, co.name AS company_name, c.name AS customer_name
              FROM crm_audit_log a
              LEFT JOIN crm_companies co ON co.id = a.company_id
              LEFT JOIN crm_customers c  ON c.id  = a.customer_id";
    $params = [];
    if ($coid) { $sql .= " WHERE a.company_id = :coid"; $params[':coid'] = $coid; }
    $sql .= " ORDER BY a.id DESC LIMIT " . $limit;
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['id']     = (int)$r['id'];
        $r['amount'] = $r['amount'] === null ? null : (float)$r['amount'];
    }
    unset($r);
    ok($rows);
}

// ════════════════ TỔNG HỢP ════════════════
case 'summary': {
    $coid  = (int)($_GET['company_id'] ?? 0);
    $where = "i.deleted_at IS NULL" . ($coid ? " AND i.company_id = " . $coid : "");
    $row = $pdo->query("SELECT
            COUNT(*) AS invoice_count,
            COALESCE(SUM(i.amount),0) AS total_amount,
            COALESCE(SUM(COALESCE((SELECT SUM(p.amount) FROM crm_payments p WHERE p.invoice_id = i.id),0)),0) AS total_paid
          FROM crm_invoices i WHERE $where")->fetch();

    $od = $pdo->query("SELECT
            COUNT(*) AS cnt,
            COALESCE(SUM(GREATEST(i.amount - COALESCE((SELECT SUM(p.amount) FROM crm_payments p WHERE p.invoice_id = i.id),0),0)),0) AS amt
          FROM crm_invoices i
         WHERE $where AND i.due_date IS NOT NULL AND i.due_date < '" . $today . "'
           AND i.amount > COALESCE((SELECT SUM(p.amount) FROM crm_payments p WHERE p.invoice_id = i.id),0)")->fetch();

    $coCount   = (int)$pdo->query("SELECT COUNT(*) FROM crm_companies WHERE deleted_at IS NULL")->fetchColumn();
    $custCount = (int)$pdo->query("SELECT COUNT(*) FROM crm_customers WHERE deleted_at IS NULL")->fetchColumn();

    $total = (float)$row['total_amount'];
    $paid  = (float)$row['total_paid'];
    ok([
        'company_count'  => $coCount,
        'customer_count' => $custCount,
        'invoice_count'  => (int)$row['invoice_count'],
        'total_amount'   => $total,
        'total_paid'     => $paid,
        'outstanding'    => round($total - $paid, 2),
        'overdue_count'  => (int)$od['cnt'],
        'overdue_amount' => (float)$od['amt'],
    ]);
}

case 'me':
    ok(['id' => (int)$ME['id'], 'display_name' => $ME['display_name'], 'role' => $ME['role']]);

default:
    fail('Unknown action', 404);
}
