<?php
// Hàm dùng chung: kết nối CSDL, trả JSON, xác thực, phân quyền, nhật ký.

ini_set('session.gc_maxlifetime', (string)(30 * 86400));
session_name('qtnv_sid');
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
session_start();

const MANAGE_ROLES = ['admin'];
const ROLES = ['admin', 'manager', 'employee'];
const CONTRACT_TYPES = ['Thử việc', 'Có thời hạn', 'Không thời hạn', 'Bán thời gian', 'Thực tập'];

/* ---------- CSDL ---------- */
function db(): PDO {
    static $pdo = null;
    if (!$pdo) {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME);
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}
function q(string $sql, array $params = []): PDOStatement {
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

/* ---------- Trả kết quả ---------- */
function out($data, int $code = 200): never {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}
function fail(string $msg, int $code = 400): never {
    out(['error' => $msg], $code);
}
function body(): array {
    static $b = null;
    if ($b === null) {
        $raw = file_get_contents('php://input');
        $b = ($raw === '' || $raw === false) ? [] : json_decode($raw, true);
        if (!is_array($b)) fail('Dữ liệu gửi lên không phải JSON hợp lệ');
    }
    return $b;
}

/* ---------- Kiểm tra dữ liệu ---------- */
function s(array $b, string $k): string { return trim((string)($b[$k] ?? '')); }
function valid_date(?string $d): bool {
    if (!$d || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) return false;
    [$y, $m, $day] = array_map('intval', explode('-', $d));
    return checkdate($m, $day, $y);
}
function valid_time(?string $t): bool { return (bool)preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string)$t); }
function norm_phone(string $p): string { return preg_replace('/[\s.]/', '', $p); }
function valid_phone(string $p): bool { return (bool)preg_match('/^(0|\+84)\d{9}$/', $p); }
function rand_pw(int $n = 8): string {
    $chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $s = '';
    for ($i = 0; $i < $n; $i++) $s .= $chars[random_int(0, strlen($chars) - 1)];
    return $s;
}
function csv_safe($v): string {   // chống CSV injection khi mở bằng Excel
    $v = (string)$v;
    return ($v !== '' && strpos('=+-@', $v[0]) !== false) ? "'" . $v : $v;
}

/* ---------- Người dùng đăng nhập ---------- */
function me(): ?array {
    $id = $_SESSION['uid'] ?? null;
    if (!$id) return null;
    static $cache = [];
    if (!array_key_exists($id, $cache)) {
        $row = q('SELECT u.id, u.username, u.email, u.role, u.status, u.employee_id,
                         e.name AS employee_name, e.phone, e.department_id
                  FROM users u LEFT JOIN employees e ON e.id = u.employee_id
                  WHERE u.id = ?', [$id])->fetch();
        $cache[$id] = $row ?: null;
    }
    return $cache[$id];
}
function me_payload(array $u): array {
    return ['username' => $u['username'], 'email' => $u['email'], 'role' => $u['role'],
            'employee_id' => $u['employee_id'], 'employee_name' => $u['employee_name'], 'phone' => $u['phone']];
}
/** Bắt buộc đăng nhập; nếu truyền danh sách quyền thì chỉ các quyền đó được vào. */
function need(string ...$roles): array {
    $u = me();
    if (!$u || $u['status'] !== 'Hoạt động') {
        $_SESSION = [];
        fail('Chưa đăng nhập hoặc tài khoản đã bị khóa', 401);
    }
    if ($roles && !in_array($u['role'], $roles, true)) fail('Bạn không có quyền thực hiện thao tác này', 403);
    return $u;
}
/** Điều kiện SQL giới hạn dữ liệu theo quyền: admin thấy hết, manager thấy phòng mình, employee thấy chính mình. */
function scope(array $u, string $a = 'e', bool $deptForEmployee = false): array {
    if (in_array($u['role'], MANAGE_ROLES, true)) return ['1=1', []];
    if (!$u['employee_id']) return ['1=0', []];
    if ($u['role'] === 'manager' || $deptForEmployee) return ["$a.department_id = ?", [(int)$u['department_id']]];
    return ["$a.id = ?", [$u['employee_id']]];
}

/* ---------- Nhật ký ---------- */
const AUDIT_VERBS = [
    'login' => 'đăng nhập', 'createEmployee' => 'thêm nhân viên', 'updateEmployee' => 'sửa nhân viên',
    'deleteEmployee' => 'xóa nhân viên', 'toggleLock' => 'khóa/mở khóa', 'setLeave' => 'xử lý đơn nghỉ phép',
    'newLeave' => 'gửi đơn nghỉ phép', 'punch' => 'chấm công', 'reset' => 'reset mật khẩu', 'dep' => 'cập nhật danh mục',
    'edit' => 'chỉnh sửa', 'review' => 'đánh giá nhân viên', 'password' => 'đổi mật khẩu',
    'config' => 'cập nhật cấu hình', 'renewContract' => 'gia hạn hợp đồng', 'user' => 'quản lý tài khoản',
];
function audit(string $action, string $target = '', ?string $desc = null): void {
    $u = me();
    $name = $u['username'] ?? '—';
    $desc ??= $name . ' đã ' . (AUDIT_VERBS[$action] ?? $action) . ($target !== '' ? ' ' . $target : '');
    q('INSERT INTO audit_logs (created_at, username, action, target, ip, description) VALUES (?,?,?,?,?,?)',
      [date('Y-m-d H:i:s'), $name, $action, $target, $_SERVER['REMOTE_ADDR'] ?? '', $desc]);
}

/* ---------- Cấu hình hệ thống ---------- */
function settings(): array {
    static $cfg = null;
    if ($cfg === null) {
        $r = q('SELECT k, v FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
        $cfg = ['tax' => (float)($r['tax_rate'] ?? 5), 'ins' => (float)($r['insurance_rate'] ?? 10.5),
                'announcement' => (string)($r['announcement'] ?? '')];
    }
    return $cfg;
}
