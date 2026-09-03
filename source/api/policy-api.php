<?php
/**
 * APSA — API module "Policy công ty" (tài liệu nội bộ, dạng đọc)
 * ------------------------------------------------------------------
 * - Mọi nhân viên đăng nhập đều ĐỌC được.
 * - Chỉ Admin mới thêm / sửa / xoá được mục và bài viết.
 *
 * Bảng: policy_sections, policy_docs  (tự tạo khi chạy lần đầu)
 */

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/db-config.php';
require_once __DIR__ . '/session-boot.php';

/* ------------------------------------------------------------------ *
 * Hạ tầng chung
 * ------------------------------------------------------------------ */

function po_out($data, $code = 200)
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function po_fail($msg, $code = 400)
{
    po_out(array('ok' => false, 'error' => $msg), $code);
}

function po_body()
{
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) return array();
    $j = json_decode($raw, true);
    return is_array($j) ? $j : array();
}

function po_pdo()
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    try {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER, DB_PASS,
            array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC)
        );
    } catch (PDOException $e) {
        po_fail('Không kết nối được cơ sở dữ liệu.', 500);
    }
    return $pdo;
}

function po_me()
{
    static $me = null;
    if ($me !== null) return $me;

    $uid = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
    if ($uid <= 0) po_fail('Chưa đăng nhập.', 401);

    $st = po_pdo()->prepare('SELECT * FROM app_users WHERE id = ? LIMIT 1');
    $st->execute(array($uid));
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) po_fail('Chưa đăng nhập.', 401);
    if (isset($row['active']) && (int) $row['active'] === 0) po_fail('Tài khoản đã bị khoá.', 403);

    $me = array(
        'id'   => (int) $row['id'],
        'name' => trim((string) (!empty($row['display_name']) ? $row['display_name'] : $row['username'])),
        'role' => isset($row['role']) ? (string) $row['role'] : '',
    );
    return $me;
}

function po_is_admin()  { $m = po_me(); return strcasecmp($m['role'], 'admin') === 0; }
function po_need_admin(){ if (!po_is_admin()) po_fail('Chỉ Admin mới sửa được tài liệu Policy.', 403); }
/** Quyen chi tiet theo module. $what: view|add|edit|del */
function po_need_cap($mid, $what)
{
    require_once __DIR__ . '/perm.php';
    $ok = false;
    if (function_exists('pm_can')) { pm_init(); $ok = pm_can($mid, $what); }
    if ($ok) return;
    $m  = function_exists('pm_mod') ? pm_mod($mid) : null;
    $lb = array('view' => 'xem', 'add' => 'thêm', 'edit' => 'sửa', 'del' => 'xoá');
    $w  = isset($lb[$what]) ? $lb[$what] : $what;
    po_fail('Bạn không có quyền ' . $w . ' trong mục '
        . ($m ? $m['name'] : 'này') . '. Liên hệ Admin để được cấp quyền.', 403);
}


/** Lọc HTML người soạn dán vào: bỏ script/style/iframe, bỏ mọi on*, chặn javascript: */
function po_clean_html($html)
{
    $html = (string) $html;
    $html = preg_replace('#<\s*(script|style|iframe|object|embed|form)\b[^>]*>.*?<\s*/\s*\1\s*>#is', '', $html);
    $html = preg_replace('#<\s*/?\s*(script|style|iframe|object|embed|form)\b[^>]*>#i', '', $html);
    $html = preg_replace('#\son[a-z]+\s*=\s*"[^"]*"#i', '', $html);
    $html = preg_replace("#\son[a-z]+\s*=\s*'[^']*'#i", '', $html);
    $html = preg_replace('#\son[a-z]+\s*=\s*[^\s>]+#i', '', $html);
    $html = preg_replace('#(href|src)\s*=\s*(["\'])\s*javascript:[^"\']*\2#i', '$1=$2#$2', $html);
    return trim($html);
}

/** Rút gọn HTML thành text thuần để tìm kiếm / tóm tắt. */
function po_plain($html, $len = 0)
{
    $t = preg_replace('#<br\s*/?>|</p>|</div>|</li>|</h[1-6]>#i', ' ', (string) $html);
    $t = trim(preg_replace('/\s+/u', ' ', strip_tags($t)));
    if ($len > 0 && mb_strlen($t, 'UTF-8') > $len) $t = mb_substr($t, 0, $len, 'UTF-8') . '…';
    return $t;
}

/* ------------------------------------------------------------------ *
 * Tạo bảng + nội dung mẫu (chạy 1 lần)
 * ------------------------------------------------------------------ */

function po_boot()
{
    static $done = false;
    if ($done) return;
    $done = true;

    $pdo = po_pdo();
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS policy_sections (
            id         INT AUTO_INCREMENT PRIMARY KEY,
            skey       VARCHAR(40)  NOT NULL,
            name       VARCHAR(120) NOT NULL,
            blurb      VARCHAR(255) NOT NULL DEFAULT '',
            sort       INT          NOT NULL DEFAULT 0,
            active     TINYINT(1)   NOT NULL DEFAULT 1,
            created_at DATETIME     NOT NULL,
            updated_at DATETIME     NOT NULL,
            UNIQUE KEY uq_skey (skey)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS policy_docs (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            section_id  INT          NOT NULL,
            title       VARCHAR(200) NOT NULL,
            summary     VARCHAR(400) NOT NULL DEFAULT '',
            body        MEDIUMTEXT   NULL,
            sort        INT          NOT NULL DEFAULT 0,
            pinned      TINYINT(1)   NOT NULL DEFAULT 0,
            active      TINYINT(1)   NOT NULL DEFAULT 1,
            author_id   INT          NULL,
            author_name VARCHAR(190) NOT NULL DEFAULT '',
            created_at  DATETIME     NOT NULL,
            updated_at  DATETIME     NOT NULL,
            INDEX ix_sec (section_id),
            INDEX ix_sort (sort)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $n = (int) $pdo->query('SELECT COUNT(*) FROM policy_sections')->fetchColumn();
    if ($n > 0) return;

    $now  = date('Y-m-d H:i:s');
    $seed = array(
        array('company',   'Công ty',   'Giới thiệu, giá trị cốt lõi, quy trình làm việc chung.'),
        array('hr',        'Nhân sự',   'Chế độ, phép năm, chấm công, quyền lợi nhân viên.'),
        array('education', 'Education', 'Tài liệu đào tạo, onboarding, kiến thức nền.'),
        array('skill',     'Skill',     'Hướng dẫn kỹ năng, công cụ, tips theo từng vị trí.'),
        array('sharing',   'Sharing',   'Bài chia sẻ nội bộ, kinh nghiệm dự án, case study.'),
    );
    $st = $pdo->prepare('INSERT INTO policy_sections (skey, name, blurb, sort, active, created_at, updated_at) VALUES (?,?,?,?,1,?,?)');
    $i = 0;
    foreach ($seed as $s) { $st->execute(array($s[0], $s[1], $s[2], $i * 10, $now, $now)); $i++; }

    $hrId = (int) $pdo->query("SELECT id FROM policy_sections WHERE skey = 'hr' LIMIT 1")->fetchColumn();
    if ($hrId > 0) {
        $body =
            '<h2>Phạm vi áp dụng</h2>' .
            '<p>Chính sách này áp dụng cho nhân sự chính thức gia nhập APSA <b>từ tháng 08/2026</b>. ' .
            'Nhân sự đã làm việc trước mốc này giữ nguyên chế độ cũ là 12 ngày phép/năm.</p>' .
            '<h2>1. Thời gian thử việc</h2>' .
            '<p><b>Hai tháng đầu tiên</b> kể từ ngày vào làm là thời gian thử việc. Trong giai đoạn này nhân viên ' .
            '<b>chưa được hưởng chế độ phép năm</b>. Nghỉ trong thời gian thử việc ghi nhận là nghỉ không lương ' .
            'hoặc loại nghỉ tương ứng, không trừ vào quỹ phép.</p>' .
            '<h2>2. Bắt đầu tính phép</h2>' .
            '<p>Kể từ ngày được duyệt trở thành <b>nhân viên chính thức</b>, nhân viên bắt đầu tích luỹ phép năm. ' .
            'Admin ghi nhận mốc này trong <i>Xin nghỉ phép → Quỹ phép → Lưu mốc</i>.</p>' .
            '<h2>3. Cách tích luỹ</h2>' .
            '<ul>' .
            '<li><b>Mỗi tháng làm việc tương ứng 1 ngày phép.</b></li>' .
            '<li>Tính từ tháng trở thành nhân viên chính thức cho đến hết tháng 12 của năm đó.</li>' .
            '<li>Ví dụ: chính thức từ 01/10 → được 3 ngày phép cho năm đó (tháng 10, 11, 12).</li>' .
            '</ul>' .
            '<h2>4. Cấp phép hằng năm</h2>' .
            '<p>Ngày <b>01/01</b> mỗi năm, quỹ phép được tự động cộng <b>12 ngày mới</b> cho năm đó.</p>' .
            '<h2>5. Ngày phép tồn của năm trước</h2>' .
            '<p>Số ngày phép chưa dùng hết của năm trước được mang sang và sử dụng đến <b>hết ngày 31/03</b> ' .
            'của năm kế tiếp. Sau 31/03, số ngày tồn này <b>tự động hết hạn</b> và không được quy đổi.</p>' .
            '<h2>6. Nguyên tắc trừ quỹ</h2>' .
            '<ul>' .
            '<li>Chỉ những loại nghỉ được đánh dấu <i>Trừ quỹ phép</i> trong Cài đặt hệ thống mới trừ vào phép năm.</li>' .
            '<li>Nghỉ nửa ngày tính 0,5 ngày.</li>' .
            '<li>Ngày nghỉ hằng tuần và ngày lễ không tính vào số ngày phép.</li>' .
            '</ul>' .
            '<h2>7. Trường hợp đặc biệt</h2>' .
            '<p>Admin có thể chỉnh tay quỹ phép của từng người trong từng năm tại ô <i>Quỹ [năm]</i>. ' .
            'Số chỉnh tay sẽ ghi đè công thức tự động cho riêng người đó trong năm đã chọn.</p>';

        $sd = $pdo->prepare(
            'INSERT INTO policy_docs (section_id, title, summary, body, sort, pinned, active, author_id, author_name, created_at, updated_at)
             VALUES (?,?,?,?,?,?,1,NULL,?,?,?)'
        );
        $sd->execute(array(
            $hrId,
            'Chính sách phép năm',
            'Thử việc 2 tháng chưa có phép. Từ khi chính thức, mỗi tháng làm việc cộng 1 ngày. Ngày 1/1 cộng 12 ngày mới; ngày tồn năm trước dùng đến hết 31/03.',
            $body, 0, 1, 'Hệ thống', $now, $now,
        ));
    }
}

/* ------------------------------------------------------------------ *
 * Điều phối
 * ------------------------------------------------------------------ */

po_boot();

$action = isset($_GET['action']) ? $_GET['action'] : '';
$B      = po_body();
$ME     = po_me();
$now    = date('Y-m-d H:i:s');

switch ($action) {

case 'me':
    po_out(array('ok' => true, 'me' => $ME, 'admin' => po_is_admin()));
    break;

/* ---------------- Danh sách mục + số bài ---------------- */
case 'tree':
    $showAll = po_is_admin() && isset($_GET['all']) && $_GET['all'] === '1';
    $sql = 'SELECT s.*, (SELECT COUNT(*) FROM policy_docs d WHERE d.section_id = s.id AND d.active = 1) AS docs
              FROM policy_sections s ' . ($showAll ? '' : 'WHERE s.active = 1 ') . 'ORDER BY s.sort, s.id';
    $rows = array();
    foreach (po_pdo()->query($sql) as $r) {
        $rows[] = array(
            'id'     => (int) $r['id'],
            'skey'   => $r['skey'],
            'name'   => $r['name'],
            'blurb'  => $r['blurb'],
            'sort'   => (int) $r['sort'],
            'active' => (int) $r['active'],
            'docs'   => (int) $r['docs'],
        );
    }
    po_out(array('ok' => true, 'sections' => $rows, 'admin' => po_is_admin()));
    break;

/* ---------------- Bài trong 1 mục ---------------- */
case 'docs':
    $sid = isset($_GET['section']) ? (int) $_GET['section'] : 0;
    if ($sid <= 0) po_fail('Thiếu mục.');
    $sql = 'SELECT id, title, summary, sort, pinned, active, author_name, updated_at
              FROM policy_docs WHERE section_id = ? ' . (po_is_admin() ? '' : 'AND active = 1 ') . '
             ORDER BY pinned DESC, sort, id';
    $st = po_pdo()->prepare($sql);
    $st->execute(array($sid));
    $rows = array();
    foreach ($st as $r) {
        $r['id']     = (int) $r['id'];
        $r['sort']   = (int) $r['sort'];
        $r['pinned'] = (int) $r['pinned'];
        $r['active'] = (int) $r['active'];
        $rows[] = $r;
    }
    po_out(array('ok' => true, 'docs' => $rows));
    break;

/* ---------------- Đọc 1 bài ---------------- */
case 'doc':
    $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    $st = po_pdo()->prepare('SELECT * FROM policy_docs WHERE id = ? LIMIT 1');
    $st->execute(array($id));
    $d = $st->fetch(PDO::FETCH_ASSOC);
    if (!$d) po_fail('Không tìm thấy bài viết.', 404);
    if ((int) $d['active'] === 0 && !po_is_admin()) po_fail('Bài viết đang ẩn.', 403);
    $d['id']         = (int) $d['id'];
    $d['section_id'] = (int) $d['section_id'];
    $d['sort']       = (int) $d['sort'];
    $d['pinned']     = (int) $d['pinned'];
    $d['active']     = (int) $d['active'];
    po_out(array('ok' => true, 'doc' => $d));
    break;

/* ---------------- Tìm kiếm toàn bộ ---------------- */
case 'search':
    $q = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
    if ($q === '') po_out(array('ok' => true, 'hits' => array()));
    $like = '%' . $q . '%';
    $sql = 'SELECT d.id, d.title, d.summary, d.body, s.name AS section_name, s.id AS section_id
              FROM policy_docs d JOIN policy_sections s ON s.id = d.section_id
             WHERE (d.title LIKE ? OR d.summary LIKE ? OR d.body LIKE ?) ' . (po_is_admin() ? '' : 'AND d.active = 1 ') . '
             ORDER BY d.pinned DESC, d.updated_at DESC LIMIT 40';
    $st = po_pdo()->prepare($sql);
    $st->execute(array($like, $like, $like));
    $hits = array();
    foreach ($st as $r) {
        $hits[] = array(
            'id'           => (int) $r['id'],
            'title'        => $r['title'],
            'section_id'   => (int) $r['section_id'],
            'section_name' => $r['section_name'],
            'snippet'      => po_plain($r['summary'] !== '' ? $r['summary'] : $r['body'], 180),
        );
    }
    po_out(array('ok' => true, 'hits' => $hits));
    break;

/* ---------------- Admin: lưu bài ---------------- */
case 'doc-save':
    $id      = isset($B['id'])         ? (int) $B['id'] : 0;
    po_need_cap(94, $id > 0 ? 'edit' : 'add');
    $sid     = isset($B['section_id']) ? (int) $B['section_id'] : 0;
    $title   = isset($B['title'])      ? trim((string) $B['title']) : '';
    $summary = isset($B['summary'])    ? trim((string) $B['summary']) : '';
    $body    = po_clean_html(isset($B['body']) ? $B['body'] : '');
    $sort    = isset($B['sort'])       ? (int) $B['sort'] : 0;
    $pinned  = (isset($B['pinned'])    && $B['pinned']) ? 1 : 0;
    $active  = (isset($B['active'])    && !$B['active']) ? 0 : 1;

    if ($title === '') po_fail('Vui lòng nhập tiêu đề.');
    if ($sid <= 0)     po_fail('Vui lòng chọn mục.');
    if ($summary === '') $summary = po_plain($body, 200);

    if ($id > 0) {
        $st = po_pdo()->prepare(
            'UPDATE policy_docs SET section_id=?, title=?, summary=?, body=?, sort=?, pinned=?, active=?, updated_at=? WHERE id=?'
        );
        $st->execute(array($sid, $title, $summary, $body, $sort, $pinned, $active, $now, $id));
    } else {
        $st = po_pdo()->prepare(
            'INSERT INTO policy_docs (section_id, title, summary, body, sort, pinned, active, author_id, author_name, created_at, updated_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)'
        );
        $st->execute(array($sid, $title, $summary, $body, $sort, $pinned, $active, $ME['id'], $ME['name'], $now, $now));
        $id = (int) po_pdo()->lastInsertId();
    }
    po_out(array('ok' => true, 'id' => $id, 'message' => 'Đã lưu tài liệu.'));
    break;

/* ---------------- Admin: xoá bài ---------------- */
case 'doc-delete':
    po_need_cap(94, 'del');
    $id = isset($B['id']) ? (int) $B['id'] : 0;
    if ($id <= 0) po_fail('Thiếu bài viết.');
    po_pdo()->prepare('DELETE FROM policy_docs WHERE id = ?')->execute(array($id));
    po_out(array('ok' => true, 'message' => 'Đã xoá tài liệu.'));
    break;

/* ---------------- Admin: lưu mục ---------------- */
case 'section-save':
    $id     = isset($B['id'])     ? (int) $B['id'] : 0;
    po_need_cap(94, $id > 0 ? 'edit' : 'add');
    $name   = isset($B['name'])   ? trim((string) $B['name']) : '';
    $blurb  = isset($B['blurb'])  ? trim((string) $B['blurb']) : '';
    $sort   = isset($B['sort'])   ? (int) $B['sort'] : 0;
    $active = (isset($B['active']) && !$B['active']) ? 0 : 1;
    if ($name === '') po_fail('Vui lòng nhập tên mục.');

    if ($id > 0) {
        $st = po_pdo()->prepare('UPDATE policy_sections SET name=?, blurb=?, sort=?, active=?, updated_at=? WHERE id=?');
        $st->execute(array($name, $blurb, $sort, $active, $now, $id));
    } else {
        $key = strtolower(preg_replace('/[^a-z0-9]+/i', '-', po_plain($name)));
        if ($key === '' || $key === '-') $key = 'muc';
        $key = substr($key . '-' . substr(md5($name . microtime(true)), 0, 5), 0, 40);
        $st = po_pdo()->prepare('INSERT INTO policy_sections (skey, name, blurb, sort, active, created_at, updated_at) VALUES (?,?,?,?,?,?,?)');
        $st->execute(array($key, $name, $blurb, $sort, $active, $now, $now));
        $id = (int) po_pdo()->lastInsertId();
    }
    po_out(array('ok' => true, 'id' => $id, 'message' => 'Đã lưu mục.'));
    break;

/* ---------------- Admin: xoá mục (phải trống) ---------------- */
case 'section-delete':
    po_need_cap(94, 'del');
    $id = isset($B['id']) ? (int) $B['id'] : 0;
    if ($id <= 0) po_fail('Thiếu mục.');
    $st = po_pdo()->prepare('SELECT COUNT(*) FROM policy_docs WHERE section_id = ?');
    $st->execute(array($id));
    if ((int) $st->fetchColumn() > 0) po_fail('Mục này còn tài liệu — xoá hoặc chuyển tài liệu đi trước đã.');
    po_pdo()->prepare('DELETE FROM policy_sections WHERE id = ?')->execute(array($id));
    po_out(array('ok' => true, 'message' => 'Đã xoá mục.'));
    break;

default:
    po_fail('Thao tác không hợp lệ.', 404);
}
