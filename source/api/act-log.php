<?php
/**
 * APSA1854 - Nhat ky hoat dong (activity log).
 * Ghi lai moi thao tac tao / sua / xoa cua nguoi dung tren app.
 */

function al_migrate($pdo) {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `activity_log` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `created_at` DATETIME NOT NULL,
            `user_id` INT NOT NULL DEFAULT 0,
            `user_name` VARCHAR(120) NOT NULL DEFAULT '',
            `area` VARCHAR(32) NOT NULL DEFAULT '',
            `action` VARCHAR(64) NOT NULL DEFAULT '',
            `label` VARCHAR(160) NOT NULL DEFAULT '',
            `quotation_id` INT NOT NULL DEFAULT 0,
            `quo_code` VARCHAR(80) NOT NULL DEFAULT '',
            `target` VARCHAR(255) NOT NULL DEFAULT '',
            `detail` TEXT NULL,
            PRIMARY KEY (`id`),
            KEY `k_time` (`created_at`),
            KEY `k_quo` (`quotation_id`),
            KEY `k_user` (`user_id`),
            KEY `k_area` (`area`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) { /* khong chan luong chinh */ }
}

function al_user($pdo) {
    static $cache = null;
    if ($cache !== null) return $cache;
    $id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
    $nm = '';
    if ($id > 0) {
        try {
            $st = $pdo->prepare("SELECT display_name FROM `app_users` WHERE id = ?");
            $st->execute([$id]);
            $nm = (string)$st->fetchColumn();
        } catch (Throwable $e) { $nm = ''; }
    }
    $cache = array($id, $nm);
    return $cache;
}

/* Ban do action => array(nhom, nhan hien thi) */
function al_actions() {
    return array(
        'save'            => array('quotation', 'Lưu báo giá / dự án'),
        'delete'          => array('quotation', 'Xoá báo giá'),
        'restore'         => array('quotation', 'Khôi phục báo giá'),
        'duplicate'       => array('quotation', 'Nhân bản báo giá'),
        'import-xlsx'     => array('quotation', 'Nhập báo giá từ Excel'),
        'set-status'      => array('quotation', 'Đổi trạng thái dự án'),
        'close-state'     => array('quotation', 'Đổi trạng thái đóng'),
        'project-close'   => array('quotation', 'Đóng dự án'),
        'project-reopen'  => array('quotation', 'Mở lại dự án'),
        'set-priority'    => array('quotation', 'Đổi độ ưu tiên'),
        'pin-toggle'      => array('quotation', 'Ghim / bỏ ghim dự án'),
        'hide-toggle'     => array('quotation', 'Ẩn / hiện báo giá'),
        'co-info'         => array('quotation', 'Sửa thông tin công ty'),
        'upload-file'     => array('quotation', 'Tải file lên'),
        'delete-file'     => array('quotation', 'Xoá file'),
        'comment-add'     => array('quotation', 'Thêm bình luận'),
        'comment-delete'  => array('quotation', 'Xoá bình luận'),
        'share-create'    => array('quotation', 'Tạo link chia sẻ'),
        'share-revoke'    => array('quotation', 'Thu hồi link chia sẻ'),
        'assignees-save'  => array('checklist', 'Lưu phân công / checklist'),
        'assign-status'   => array('checklist', 'Đổi trạng thái công việc'),
        'assign-meta'     => array('checklist', 'Sửa chi tiết công việc'),
        'expenses-save'   => array('expense',   'Lưu chi phí thực tế'),
        'exp-row-save'    => array('expense',   'Lưu dòng chi phí'),
        'exp-row-del'     => array('expense',   'Xoá dòng chi phí'),
        'expenses-import' => array('expense',   'Nhập chi phí hàng loạt'),
        'payee-new'       => array('expense',   'Thêm người nhận tiền'),
        'review-request'  => array('review',    'Gửi duyệt báo giá'),
        'review-decide'   => array('review',    'Duyệt / từ chối báo giá'),
        'review-cancel'   => array('review',    'Huỷ yêu cầu duyệt'),
        'reopen-request'  => array('review',    'Xin mở lại dự án'),
        'reopen-decide'   => array('review',    'Duyệt mở lại dự án'),
        'manage-import'   => array('liq',       'Nhập số liệu nghiệm thu'),
        'manage-verify'   => array('liq',       'Xác nhận nghiệm thu'),
    );
}

function al_log($pdo, $area, $action, $label, $qid = 0, $target = '', $detail = null) {
    try {
        al_migrate($pdo);
        $u = al_user($pdo);
        $qid = (int)$qid;
        $code = '';
        if ($qid > 0) {
            $st = $pdo->prepare("SELECT code FROM `quotations` WHERE id = ?");
            $st->execute([$qid]);
            $code = (string)$st->fetchColumn();
        }
        $ins = $pdo->prepare("INSERT INTO `activity_log`
            (created_at, user_id, user_name, area, action, label, quotation_id, quo_code, target, detail)
            VALUES (NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $ins->execute(array(
            $u[0], mb_substr($u[1], 0, 120), mb_substr($area, 0, 32), mb_substr($action, 0, 64),
            mb_substr($label, 0, 160), $qid, mb_substr($code, 0, 80),
            mb_substr((string)$target, 0, 255),
            $detail === null ? null : mb_substr((string)$detail, 0, 4000)
        ));
    } catch (Throwable $e) { /* nhat ky khong duoc lam hong nghiep vu */ }
}

function al_qid($B, $act, $area) {
    foreach (array('quotation_id', 'qid') as $k) {
        if (isset($B[$k]) && (int)$B[$k] > 0) return (int)$B[$k];
        if (isset($_GET[$k]) && (int)$_GET[$k] > 0) return (int)$_GET[$k];
    }
    if (in_array($area, array('quotation', 'review', 'liq'), true)) {
        if (isset($B['id']) && (int)$B['id'] > 0) return (int)$B['id'];
        if (isset($_GET['id']) && (int)$_GET['id'] > 0) return (int)$_GET['id'];
    }
    return 0;
}

function al_detail($B) {
    if (!is_array($B) || !$B) return null;
    $skip = array('password', 'token', 'proof', 'file', 'base64', 'html', 'body');
    $out = array();
    foreach ($B as $k => $v) {
        if (in_array($k, $skip, true)) continue;
        if (is_array($v)) { $out[] = $k . ': ' . count($v) . ' mục'; }
        else {
            $s = trim((string)$v);
            if ($s === '') continue;
            if (mb_strlen($s) > 60) $s = mb_substr($s, 0, 60) . '…';
            $out[] = $k . ': ' . $s;
        }
        if (count($out) >= 12) break;
    }
    return $out ? implode(' · ', $out) : null;
}

/* Goi trong q_ok(): tu dong ghi nhat ky theo action dang chay */
function al_auto() {
    $act = isset($GLOBALS['action']) ? (string)$GLOBALS['action'] : '';
    if ($act === '') return;
    $map = al_actions();
    if (!isset($map[$act])) return;
    if (!isset($GLOBALS['pdo']) || !($GLOBALS['pdo'] instanceof PDO)) return;
    $B = (isset($GLOBALS['B']) && is_array($GLOBALS['B'])) ? $GLOBALS['B'] : array();
    $area = $map[$act][0];
    al_log($GLOBALS['pdo'], $area, $act, $map[$act][1], al_qid($B, $act, $area), '', al_detail($B));
}

/* Dung cho cac API khac: truyen nhom + ban do nhan rieng */
function al_auto2($area, $labels) {
    $act = isset($GLOBALS['action']) ? (string)$GLOBALS['action'] : '';
    if ($act === '' || !isset($labels[$act])) return;
    if (!isset($GLOBALS['pdo']) || !($GLOBALS['pdo'] instanceof PDO)) return;
    $B = array();
    foreach (array('B', 'IN', 'in', 'body') as $k) {
        if (isset($GLOBALS[$k]) && is_array($GLOBALS[$k]) && $GLOBALS[$k]) { $B = $GLOBALS[$k]; break; }
    }
    if (!$B && is_array($_POST) && $_POST) $B = $_POST;
    if (!$B && is_array($_GET)) { $B = $_GET; unset($B['action']); }
    al_log($GLOBALS['pdo'], $area, $act, $labels[$act], al_qid($B, $act, $area), '', al_detail($B));
}

function al_pay_labels() {
    return array(
        'paid'      => 'Đánh dấu đã thanh toán',
        'unpaid'    => 'Bỏ đánh dấu đã thanh toán',
        'req'       => 'Gửi yêu cầu thanh toán',
        'proof'     => 'Tải chứng từ thanh toán',
        'proof-del' => 'Xoá chứng từ thanh toán',
    );
}

function al_rc_labels() {
    return array(
        'create'     => 'Thêm hạng mục rate card',
        'update'     => 'Sửa hạng mục rate card',
        'delete'     => 'Xoá hạng mục rate card',
        'restore'    => 'Khôi phục hạng mục rate card',
        'purge'      => 'Xoá vĩnh viễn hạng mục rate card',
        'reseed'     => 'Nạp lại dữ liệu rate card',
        'terms'      => 'Sửa điều khoản rate card',
        'upload-zip' => 'Tải gói rate card lên',
        'delete-zip' => 'Xoá gói rate card',
    );
}
