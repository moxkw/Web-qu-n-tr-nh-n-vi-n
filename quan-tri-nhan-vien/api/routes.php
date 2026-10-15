<?php
// Bảng route + các handler. Mỗi handler tự kiểm tra quyền bằng need(...).

const LEAVE_TYPES = ['Phép năm', 'Nghỉ ốm', 'Không lương'];

$ROUTES = [
    ['POST', '/auth/login',           'auth_login'],
    ['POST', '/auth/logout',          'auth_logout'],
    ['GET',  '/auth/me',              'auth_me'],
    ['POST', '/auth/change-password', 'auth_change_password'],
    ['PUT',  '/me',                   'me_update'],
    ['GET',  '/lookups',              'lookups'],
    ['GET',  '/dashboard',            'dashboard'],
    ['GET',  '/notifications',        'notifications'],

    ['GET',    '/employees',                  'employees_list'],
    ['GET',    '/employees/export',           'employees_export'],
    ['GET',    '/employees/next-id',          'employees_next_id'],
    ['POST',   '/employees',                  'employees_create'],
    ['GET',    '/employees/{id}',             'employees_get'],
    ['PUT',    '/employees/{id}',             'employees_update'],
    ['DELETE', '/employees/{id}',             'employees_delete'],
    ['POST',   '/employees/{id}/toggle-lock', 'employees_toggle_lock'],
    ['GET',    '/employees/{id}/profile',     'employees_profile'],
    ['POST',   '/employees/{id}/reviews',     'reviews_create'],

    ['GET',    '/departments',      'departments_list'],
    ['POST',   '/departments',      'departments_create'],
    ['PUT',    '/departments/{id}', 'departments_update'],
    ['DELETE', '/departments/{id}', 'departments_delete'],

    ['GET',    '/positions',      'positions_list'],
    ['POST',   '/positions',      'positions_create'],
    ['PUT',    '/positions/{id}', 'positions_update'],
    ['DELETE', '/positions/{id}', 'positions_delete'],

    ['GET',  '/attendance',           'attendance_list'],
    ['POST', '/attendance/check-in',  'attendance_check_in'],
    ['POST', '/attendance/check-out', 'attendance_check_out'],
    ['PUT',  '/attendance/{id}',      'attendance_edit'],

    ['GET',  '/leave',              'leave_list'],
    ['POST', '/leave',              'leave_create'],
    ['POST', '/leave/{id}/approve', 'leave_approve'],
    ['POST', '/leave/{id}/reject',  'leave_reject'],
    ['POST', '/leave/{id}/cancel',  'leave_cancel'],

    ['GET', '/payroll',      'payroll_list'],
    ['PUT', '/payroll/{id}', 'payroll_adjust'],

    ['GET',  '/contracts',                     'contracts_list'],
    ['POST', '/contracts/{id}/renew',          'contracts_renew'],
    ['GET',  '/contracts/{id}/renewals',       'contracts_renewals'],
    ['GET', '/reports',   'reports'],

    ['GET',    '/users',                     'users_list'],
    ['POST',   '/users',                     'users_create'],
    ['PUT',    '/users/{id}',                'users_update'],
    ['POST',   '/users/{id}/toggle-lock',    'users_toggle_lock'],
    ['POST',   '/users/{id}/reset-password', 'users_reset_password'],
    ['DELETE', '/users/{id}',                'users_delete'],

    ['GET', '/audit',    'audit_list'],
    ['GET', '/settings', 'settings_get'],
    ['PUT', '/settings', 'settings_update'],
];

/* ======================= XÁC THỰC ======================= */

function auth_login(): void {
    $b = body();
    $name = s($b, 'username');
    $pw = (string)($b['password'] ?? '');
    if ($name === '' || $pw === '') fail('Nhập đầy đủ tài khoản và mật khẩu');

    $u = q('SELECT * FROM users WHERE username = ? OR email = ? LIMIT 1', [$name, $name])->fetch();
    if (!$u || !password_verify($pw, $u['password_hash'])) {
        usleep(400000);   // làm chậm dò mật khẩu
        fail('Sai tài khoản hoặc mật khẩu', 401);
    }
    if ($u['status'] !== 'Hoạt động') fail('Tài khoản đã bị khóa', 403);

    if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
        q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($pw, PASSWORD_DEFAULT), $u['id']]);
    }
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$u['id'];
    if (!empty($b['remember'])) {   // "Ghi nhớ đăng nhập": giữ cookie 30 ngày
        setcookie(session_name(), session_id(), ['expires' => time() + 30 * 86400, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
    }
    q('UPDATE users SET last_login = ? WHERE id = ?', [date('Y-m-d H:i:s'), $u['id']]);
    audit('login', '', $u['username'] . ' đã đăng nhập');
    out(me_payload(me()));
}
function auth_logout(): void {
    $_SESSION = [];
    session_destroy();
    setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => '/']);
    out(['ok' => true]);
}
function auth_me(): void { out(me_payload(need())); }

function auth_change_password(): void {
    $u = need();
    $b = body();
    $cur = (string)($b['current'] ?? '');
    $new = (string)($b['new'] ?? '');
    $row = q('SELECT password_hash FROM users WHERE id = ?', [$u['id']])->fetch();
    if (!password_verify($cur, $row['password_hash'])) fail('Mật khẩu hiện tại không đúng');
    if (mb_strlen($new) < 6) fail('Mật khẩu mới cần ít nhất 6 ký tự');
    if (strlen($new) > 72) fail('Mật khẩu mới quá dài (tối đa 72 ký tự)');
    q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $u['id']]);
    audit('password', $u['username']);
    out(['ok' => true]);
}
function me_update(): void {   // cập nhật số điện thoại của chính mình
    $u = need();
    if (!$u['employee_id']) fail('Tài khoản chưa liên kết với hồ sơ nhân viên', 409);
    $phone = norm_phone(s(body(), 'phone'));
    if (!valid_phone($phone)) fail('Số điện thoại cần 10 số, bắt đầu bằng 0');
    q('UPDATE employees SET phone = ? WHERE id = ?', [$phone, $u['employee_id']]);
    audit('edit', $u['employee_id']);
    out(['phone' => $phone]);
}

function lookups(): void {
    need();
    out([
        'departments' => q('SELECT id, name FROM departments ORDER BY id')->fetchAll(),
        'positions'   => q('SELECT id, name FROM positions ORDER BY level_no, id')->fetchAll(),
    ]);
}

/* ======================= TỔNG QUAN & THÔNG BÁO ======================= */

function dashboard(): void {
    $u = need();
    [$w, $p] = scope($u, 'e', true);   // employee thấy số liệu phòng mình (không có quỹ lương)
    $s = q("SELECT COUNT(*) AS total,
                   COALESCE(SUM(e.status = 'Đang làm'), 0) AS working,
                   COALESCE(SUM(e.gender = 'Nam'), 0) AS male,
                   COALESCE(SUM(e.gender = 'Nữ'), 0) AS female,
                   COALESCE(SUM(CASE WHEN e.status = 'Đang làm' THEN e.base_salary ELSE 0 END), 0) AS fund
            FROM employees e WHERE $w", $p)->fetch();

    $all = in_array($u['role'], MANAGE_ROLES, true);
    $by = q('SELECT d.name, COUNT(e.id) AS count FROM departments d
             LEFT JOIN employees e ON e.department_id = d.id
             WHERE ' . ($all ? '1=1' : 'd.id = ?') . ' GROUP BY d.id, d.name ORDER BY d.id',
            $all ? [] : [(int)$u['department_id']])->fetchAll();
    foreach ($by as &$r) $r['count'] = (int)$r['count'];
    unset($r);

    [$w2, $p2] = scope($u);
    $pending = (int)q("SELECT COUNT(*) FROM leave_requests l JOIN employees e ON e.id = l.employee_id
                       WHERE l.status = 'Chờ duyệt' AND $w2", $p2)->fetchColumn();

    out([
        'total' => (int)$s['total'], 'working' => (int)$s['working'],
        'male' => (int)$s['male'], 'female' => (int)$s['female'],
        'pending_leave' => $pending, 'departments' => count($by), 'by_department' => $by,
        'fund' => $u['role'] === 'employee' ? null : (int)round((float)$s['fund'] * 1e6),
    ]);
}

function notifications(): void {
    $u = need();
    $items = [];
    [$w, $p] = scope($u);
    $pend = (int)q("SELECT COUNT(*) FROM leave_requests l JOIN employees e ON e.id = l.employee_id
                    WHERE l.status = 'Chờ duyệt' AND $w", $p)->fetchColumn();
    if ($pend > 0) $items[] = $u['role'] === 'employee' ? "Bạn có $pend đơn nghỉ phép đang chờ duyệt" : "$pend đơn nghỉ phép chờ duyệt";

    if (in_array($u['role'], MANAGE_ROLES, true)) {
        $exp = (int)q('SELECT COUNT(*) FROM contracts WHERE end_date IS NOT NULL AND end_date BETWEEN ? AND ?',
                      [date('Y-m-d'), date('Y-m-d', strtotime('+60 days'))])->fetchColumn();
        if ($exp > 0) $items[] = "$exp hợp đồng sắp hết hạn trong 60 ngày";
    }
    $ann = settings()['announcement'];
    if ($ann !== '') $items[] = 'Thông báo từ Admin: ' . $ann;
    if ($u['role'] === 'admin') {
        foreach (q('SELECT description FROM audit_logs ORDER BY id DESC LIMIT 2')->fetchAll(PDO::FETCH_COLUMN) as $d) $items[] = $d;
    }
    out(['items' => $items]);
}

/* ======================= NHÂN VIÊN ======================= */

const EMP_SQL = 'SELECT e.id, e.name, e.email, e.phone, e.gender, e.department_id, d.name AS department,
                        e.position_id, p.name AS position, e.base_salary AS salary, e.hire_date, e.status
                 FROM employees e
                 JOIN departments d ON d.id = e.department_id
                 JOIN positions p ON p.id = e.position_id';

function emp_out(array $r, array $u): array {
    $r['department_id'] = (int)$r['department_id'];
    $r['position_id'] = (int)$r['position_id'];
    $r['salary'] = in_array($u['role'], MANAGE_ROLES, true) ? (float)$r['salary'] : null;   // chỉ admin xem lương
    return $r;
}
function emp_visible(array $u, string $id): array {
    [$w, $p] = scope($u);
    $r = q(EMP_SQL . " WHERE e.id = ? AND $w", array_merge([$id], $p))->fetch();
    if (!$r) fail('Không tìm thấy nhân viên', 404);
    return $r;
}
function emp_filters(array $u): array {
    [$w, $p] = scope($u);
    $c = [$w];
    $g = fn(string $k): string => trim((string)($_GET[$k] ?? ''));
    if (($v = $g('q')) !== '') {
        $like = '%' . addcslashes($v, '%_\\') . '%';
        $c[] = '(e.name LIKE ? OR e.email LIKE ? OR e.id LIKE ?)';
        array_push($p, $like, $like, $like);
    }
    if (($v = $g('department_id')) !== '') { $c[] = 'e.department_id = ?'; $p[] = (int)$v; }
    if (($v = $g('status')) !== '')        { $c[] = 'e.status = ?';        $p[] = $v; }
    if (($v = $g('gender')) !== '')        { $c[] = 'e.gender = ?';        $p[] = $v; }
    if (valid_date($g('from'))) { $c[] = 'e.hire_date >= ?'; $p[] = $g('from'); }
    if (valid_date($g('to')))   { $c[] = 'e.hire_date <= ?'; $p[] = $g('to'); }
    return [implode(' AND ', $c), $p];
}
function emp_order(): string {
    $cols = ['id' => 'e.id', 'name' => 'e.name', 'email' => 'e.email', 'phone' => 'e.phone', 'department' => 'd.name',
             'position' => 'p.name', 'hire_date' => 'e.hire_date', 'status' => 'e.status'];
    $col = $cols[$_GET['sort'] ?? 'id'] ?? 'e.id';
    $dir = strtolower((string)($_GET['dir'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';
    return "ORDER BY $col $dir, e.id";
}

function employees_list(): void {
    $u = need('admin', 'manager');
    [$w, $p] = emp_filters($u);
    $total = (int)q("SELECT COUNT(*) FROM employees e WHERE $w", $p)->fetchColumn();
    $per = max(1, min(100, (int)($_GET['per'] ?? 8)));
    $pages = max(1, (int)ceil($total / $per));
    $page = min(max(1, (int)($_GET['page'] ?? 1)), $pages);
    $off = ($page - 1) * $per;
    $rows = q(EMP_SQL . " WHERE $w " . emp_order() . " LIMIT $per OFFSET $off", $p)->fetchAll();
    out(['items' => array_map(fn($r) => emp_out($r, $u), $rows), 'total' => $total, 'page' => $page, 'pages' => $pages, 'per' => $per]);
}

function employees_export(): void {
    $u = need('admin', 'manager');
    [$w, $p] = emp_filters($u);
    $rows = q(EMP_SQL . " WHERE $w " . emp_order(), $p)->fetchAll();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="nhan-vien.csv"');
    $f = fopen('php://output', 'w');
    fwrite($f, "\xEF\xBB\xBF");   // BOM để Excel đọc đúng tiếng Việt
    fputcsv($f, ['Mã', 'Họ tên', 'Email', 'Điện thoại', 'Phòng ban', 'Chức vụ', 'Ngày vào', 'Trạng thái'], ',', '"', '');
    foreach ($rows as $r) {
        fputcsv($f, array_map('csv_safe', [$r['id'], $r['name'], $r['email'], $r['phone'], $r['department'], $r['position'], $r['hire_date'], $r['status']]), ',', '"', '');
    }
    fclose($f);
    exit;
}

function employees_next_id(): void {
    need('admin');
    $max = (int)q("SELECT MAX(CAST(SUBSTRING(id, 3) AS UNSIGNED)) FROM employees WHERE id REGEXP '^NV[0-9]+$'")->fetchColumn();
    out(['id' => 'NV' . str_pad((string)($max + 1), 3, '0', STR_PAD_LEFT)]);
}

function employees_get(array $a): void {
    $u = need('admin', 'manager');
    out(emp_out(emp_visible($u, $a['id']), $u));
}

function emp_input(array $b): array {
    $d = [
        'name' => s($b, 'name'), 'email' => mb_strtolower(s($b, 'email')), 'phone' => norm_phone(s($b, 'phone')),
        'gender' => s($b, 'gender') ?: 'Nam', 'department_id' => (int)($b['department_id'] ?? 0),
        'position_id' => (int)($b['position_id'] ?? 0), 'salary' => $b['salary'] ?? 0,
    ];
    if ($d['name'] === '' || $d['email'] === '' || $d['phone'] === '') fail('Điền đủ các trường có dấu *');
    if (mb_strlen($d['name']) > 100 || mb_strlen($d['email']) > 150) fail('Họ tên hoặc email quá dài');
    if (!filter_var($d['email'], FILTER_VALIDATE_EMAIL)) fail('Email chưa đúng định dạng');
    if (!valid_phone($d['phone'])) fail('Số điện thoại cần 10 số, bắt đầu bằng 0');
    if (!in_array($d['gender'], ['Nam', 'Nữ'], true)) fail('Giới tính không hợp lệ');
    if (!is_numeric($d['salary']) || $d['salary'] < 0 || $d['salary'] > 9999) fail('Lương cơ bản không hợp lệ');
    if (!q('SELECT 1 FROM departments WHERE id = ?', [$d['department_id']])->fetch()) fail('Phòng ban không tồn tại');
    if (!q('SELECT 1 FROM positions WHERE id = ?', [$d['position_id']])->fetch()) fail('Chức vụ không tồn tại');
    $d['salary'] = round((float)$d['salary'], 2);
    return $d;
}

function employees_create(): void {
    $u = need('admin');
    $b = body();
    $id = s($b, 'id');
    if (!preg_match('/^[A-Za-z0-9_-]{2,20}$/', $id)) fail('Mã nhân viên chỉ gồm chữ, số, "-" hoặc "_" (2–20 ký tự)');
    $d = emp_input($b);
    if (q('SELECT 1 FROM employees WHERE email = ?', [$d['email']])->fetch()) fail('Email đã tồn tại', 409);
    if (q('SELECT 1 FROM employees WHERE id = ?', [$id])->fetch()) fail('Mã nhân viên đã tồn tại', 409);
    $hire = valid_date(s($b, 'hire_date')) ? s($b, 'hire_date') : date('Y-m-d');

    // Tùy chọn: tạo luôn tài khoản đăng nhập (quyền employee) liên kết với hồ sơ này
    $acc = null;
    if (!empty($b['create_account'])) {
        $uname = s($b, 'username') ?: $id;
        if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $uname)) fail('Tên đăng nhập gồm chữ, số, ".", "_", "-" (3–50 ký tự)');
        if (q('SELECT 1 FROM users WHERE username = ? OR email = ?', [$uname, mb_strtolower($d['email'])])->fetch())
            fail('Tên đăng nhập hoặc email đã có tài khoản', 409);
        $acc = ['username' => $uname, 'temp_password' => rand_pw()];
    }

    // Tùy chọn: tạo hợp đồng liên kết với nhân viên (ngày bắt đầu = ngày vào làm)
    $ct = null;
    $ctype = s($b, 'contract_type');
    if ($ctype !== '') {
        if (!in_array($ctype, CONTRACT_TYPES, true)) fail('Loại hợp đồng không hợp lệ');
        $cend = $ctype === 'Không thời hạn' ? '' : s($b, 'contract_end');
        if ($ctype !== 'Không thời hạn') {
            if (!valid_date($cend)) fail('Hợp đồng này cần ngày kết thúc hợp lệ');
            if ($cend < $hire) fail('Ngày kết thúc hợp đồng phải sau hoặc bằng ngày vào làm');
        }
        $ct = ['type' => $ctype, 'start_date' => $hire, 'end_date' => $cend !== '' ? $cend : null];
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        q('INSERT INTO employees (id, name, email, phone, gender, department_id, position_id, base_salary, hire_date)
           VALUES (?,?,?,?,?,?,?,?,?)',
          [$id, $d['name'], $d['email'], $d['phone'], $d['gender'], $d['department_id'], $d['position_id'], $d['salary'], $hire]);
        if ($acc) {
            q('INSERT INTO users (username, email, password_hash, role, employee_id) VALUES (?,?,?,?,?)',
              [$acc['username'], mb_strtolower($d['email']), password_hash($acc['temp_password'], PASSWORD_DEFAULT), 'employee', $id]);
        }
        if ($ct) {
            $n = (int)q("SELECT COALESCE(MAX(CAST(SUBSTRING(id, 3) AS UNSIGNED)), 0) FROM contracts WHERE id REGEXP '^HD[0-9]+\$'")->fetchColumn();
            $ct['id'] = 'HD' . str_pad((string)($n + 1), 3, '0', STR_PAD_LEFT);
            q('INSERT INTO contracts (id, employee_id, type, start_date, end_date) VALUES (?,?,?,?,?)',
              [$ct['id'], $id, $ct['type'], $ct['start_date'], $ct['end_date']]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    audit('createEmployee', $id . ($acc ? ' + tài khoản ' . $acc['username'] : '') . ($ct ? ' + hợp đồng ' . $ct['id'] : ''));
    $res = emp_out(q(EMP_SQL . ' WHERE e.id = ?', [$id])->fetch(), $u);
    if ($acc) $res['account'] = $acc;
    if ($ct) $res['contract'] = $ct;
    out($res, 201);
}

function employees_update(array $a): void {
    $u = need('admin');
    $id = $a['id'];
    if (!q('SELECT 1 FROM employees WHERE id = ?', [$id])->fetch()) fail('Không tìm thấy nhân viên', 404);
    $d = emp_input(body());
    if (q('SELECT 1 FROM employees WHERE email = ? AND id <> ?', [$d['email'], $id])->fetch()) fail('Email đã tồn tại', 409);
    q('UPDATE employees SET name = ?, email = ?, phone = ?, gender = ?, department_id = ?, position_id = ?, base_salary = ? WHERE id = ?',
      [$d['name'], $d['email'], $d['phone'], $d['gender'], $d['department_id'], $d['position_id'], $d['salary'], $id]);
    audit('updateEmployee', $id);
    out(emp_out(q(EMP_SQL . ' WHERE e.id = ?', [$id])->fetch(), $u));
}

function employees_delete(array $a): void {
    $u = need('admin');
    $id = $a['id'];
    if ($id === $u['employee_id']) fail('Không thể xóa hồ sơ nhân viên của chính tài khoản đang đăng nhập', 409);
    if (!q('SELECT 1 FROM employees WHERE id = ?', [$id])->fetch()) fail('Không tìm thấy nhân viên', 404);
    q("UPDATE users SET status = 'Đã khóa' WHERE employee_id = ?", [$id]);   // khóa tài khoản liên kết (FK sẽ gỡ liên kết)
    q('DELETE FROM employees WHERE id = ?', [$id]);
    audit('deleteEmployee', $id);
    out(['ok' => true]);
}

function employees_toggle_lock(array $a): void {
    need('admin');
    $id = $a['id'];
    $e = q('SELECT status FROM employees WHERE id = ?', [$id])->fetch();
    if (!$e) fail('Không tìm thấy nhân viên', 404);
    $new = $e['status'] === 'Đã khóa' ? 'Đang làm' : 'Đã khóa';
    q('UPDATE employees SET status = ? WHERE id = ?', [$new, $id]);
    audit('toggleLock', $id);
    out(['status' => $new]);
}

function employees_profile(array $a): void {
    $u = need('admin', 'manager');
    $e = emp_visible($u, $a['id']);
    $id = $e['id'];
    $att = q("SELECT TIME_FORMAT(check_in, '%H:%i') AS check_in, TIME_FORMAT(check_out, '%H:%i') AS check_out, status
              FROM attendance WHERE employee_id = ? AND work_date = ?", [$id, date('Y-m-d')])->fetch() ?: null;
    $leave = q('SELECT type, from_date, to_date, days, status FROM leave_requests WHERE employee_id = ? ORDER BY id DESC', [$id])->fetchAll();
    $rev = q('SELECT score, comment, reviewer_role AS by_role, created_at FROM reviews WHERE employee_id = ? ORDER BY id DESC', [$id])->fetchAll();
    $e = emp_out($e, $u);
    $net = null;
    if ($e['salary'] !== null) {
        $cfg = settings();
        $net = (int)round($e['salary'] * 1e6 * (1 - ($cfg['tax'] + $cfg['ins']) / 100));
    }
    out(['employee' => $e, 'attendance' => $att, 'leave' => $leave, 'reviews' => $rev, 'net_estimate' => $net]);
}

function reviews_create(array $a): void {
    $u = need('admin', 'manager');
    $e = emp_visible($u, $a['id']);
    $b = body();
    $score = (int)($b['score'] ?? 0);
    $comment = mb_substr(s($b, 'comment'), 0, 500);
    if ($score < 1 || $score > 5) fail('Điểm phải từ 1 đến 5');
    q('INSERT INTO reviews (employee_id, score, comment, reviewer_username, reviewer_role, created_at) VALUES (?,?,?,?,?,?)',
      [$e['id'], $score, $comment, $u['username'], $u['role'], date('Y-m-d H:i:s')]);
    audit('review', $e['id']);
    out(['ok' => true], 201);
}

/* ======================= PHÒNG BAN & CHỨC VỤ ======================= */

function departments_list(): void {
    need('admin');
    $rows = q("SELECT d.id, d.name, COUNT(e.id) AS employees,
                      (SELECT e2.name FROM employees e2 JOIN positions p2 ON p2.id = e2.position_id
                       WHERE e2.department_id = d.id AND e2.status = 'Đang làm'
                       ORDER BY p2.level_no DESC, e2.hire_date, e2.id LIMIT 1) AS head
               FROM departments d LEFT JOIN employees e ON e.department_id = d.id
               GROUP BY d.id, d.name ORDER BY d.id")->fetchAll();
    foreach ($rows as &$r) $r['employees'] = (int)$r['employees'];
    out($rows);
}
function dept_name(array $b, ?int $exceptId = null): string {
    $n = s($b, 'name');
    if ($n === '' || mb_strlen($n) > 100) fail('Tên phòng ban không được trống và tối đa 100 ký tự');
    if (q('SELECT 1 FROM departments WHERE name = ? AND id <> ?', [$n, $exceptId ?? 0])->fetch()) fail('Phòng ban đã tồn tại', 409);
    return $n;
}
function departments_create(): void {
    need('admin');
    $n = dept_name(body());
    q('INSERT INTO departments (name) VALUES (?)', [$n]);
    audit('dep', 'thêm ' . $n);
    out(['id' => (int)db()->lastInsertId(), 'name' => $n], 201);
}
function departments_update(array $a): void {
    need('admin');
    $id = (int)$a['id'];
    if (!q('SELECT 1 FROM departments WHERE id = ?', [$id])->fetch()) fail('Không tìm thấy phòng ban', 404);
    $n = dept_name(body(), $id);
    q('UPDATE departments SET name = ? WHERE id = ?', [$n, $id]);   // nhân viên tham chiếu theo id nên tự đổi tên theo
    audit('dep', 'sửa ' . $n);
    out(['id' => $id, 'name' => $n]);
}
function departments_delete(array $a): void {
    need('admin');
    $id = (int)$a['id'];
    $d = q('SELECT name FROM departments WHERE id = ?', [$id])->fetch();
    if (!$d) fail('Không tìm thấy phòng ban', 404);
    if ((int)q('SELECT COUNT(*) FROM employees WHERE department_id = ?', [$id])->fetchColumn() > 0) {
        fail('Không thể xóa: phòng ban còn nhân viên. Hãy chuyển họ sang phòng ban khác trước', 409);
    }
    q('DELETE FROM departments WHERE id = ?', [$id]);
    audit('dep', 'xóa ' . $d['name']);
    out(['ok' => true]);
}

function positions_list(): void {
    need('admin');
    $rows = q('SELECT p.id, p.name, p.level_no AS level, p.min_salary AS min, p.max_salary AS max, COUNT(e.id) AS employees
               FROM positions p LEFT JOIN employees e ON e.position_id = p.id
               GROUP BY p.id, p.name, p.level_no, p.min_salary, p.max_salary ORDER BY p.level_no, p.id')->fetchAll();
    foreach ($rows as &$r) {
        $r['min'] = (float)$r['min']; $r['max'] = (float)$r['max'];
        $r['level'] = (int)$r['level']; $r['employees'] = (int)$r['employees'];
    }
    out($rows);
}
function pos_range(array $b): array {
    $min = (float)($b['min_salary'] ?? 0);
    $max = (float)($b['max_salary'] ?? 0);
    if (!($min > 0 && $max > $min && $max <= 9999)) fail('Khoảng lương không hợp lệ');
    return [$min, $max];
}
function positions_create(): void {
    need('admin');
    $b = body();
    $n = s($b, 'name');
    if ($n === '' || mb_strlen($n) > 100) fail('Tên chức vụ không được trống và tối đa 100 ký tự');
    if (q('SELECT 1 FROM positions WHERE name = ?', [$n])->fetch()) fail('Tên chức vụ đã tồn tại', 409);
    [$min, $max] = pos_range($b);
    $lv = (int)q('SELECT COALESCE(MAX(level_no), 0) + 1 FROM positions')->fetchColumn();
    q('INSERT INTO positions (name, level_no, min_salary, max_salary) VALUES (?,?,?,?)', [$n, $lv, $min, $max]);
    audit('dep', 'thêm chức vụ ' . $n);
    out(['id' => (int)db()->lastInsertId()], 201);
}
function positions_update(array $a): void {
    need('admin');
    $id = (int)$a['id'];
    $p = q('SELECT name FROM positions WHERE id = ?', [$id])->fetch();
    if (!$p) fail('Không tìm thấy chức vụ', 404);
    $b = body();
    $n = s($b, 'name') !== '' ? s($b, 'name') : $p['name'];
    if (q('SELECT 1 FROM positions WHERE name = ? AND id <> ?', [$n, $id])->fetch()) fail('Tên chức vụ đã tồn tại', 409);
    [$min, $max] = pos_range($b);
    q('UPDATE positions SET name = ?, min_salary = ?, max_salary = ? WHERE id = ?', [$n, $min, $max, $id]);
    audit('dep', 'sửa chức vụ ' . $n);
    out(['ok' => true]);
}
function positions_delete(array $a): void {
    need('admin');
    $id = (int)$a['id'];
    $p = q('SELECT name FROM positions WHERE id = ?', [$id])->fetch();
    if (!$p) fail('Không tìm thấy chức vụ', 404);
    if ((int)q('SELECT COUNT(*) FROM employees WHERE position_id = ?', [$id])->fetchColumn() > 0) {
        fail('Không thể xóa: còn nhân viên giữ chức vụ này', 409);
    }
    q('DELETE FROM positions WHERE id = ?', [$id]);
    $n = 0;   // đánh lại cấp bậc liên tục 1..n
    foreach (q('SELECT id FROM positions ORDER BY level_no, id')->fetchAll(PDO::FETCH_COLUMN) as $pid) {
        q('UPDATE positions SET level_no = ? WHERE id = ?', [++$n, $pid]);
    }
    audit('dep', 'xóa chức vụ ' . $p['name']);
    out(['ok' => true]);
}

/* ======================= CHẤM CÔNG ======================= */

function att_status(string $hhmm): string { return $hhmm > '08:30' ? 'Đi muộn' : 'Có mặt'; }

function attendance_list(): void {
    $u = need();
    [$w, $p] = scope($u);
    $date = valid_date($_GET['date'] ?? '') ? $_GET['date'] : date('Y-m-d');
    $st = trim((string)($_GET['status'] ?? ''));
    $c = ["e.status = 'Đang làm'", $w];
    $params = array_merge([$date], $p);
    if ($st === 'Chưa chấm công') $c[] = 'a.id IS NULL';
    elseif ($st !== '') { $c[] = 'a.status = ?'; $params[] = $st; }
    $rows = q("SELECT e.id AS employee_id, e.name, TIME_FORMAT(a.check_in, '%H:%i') AS check_in,
                      TIME_FORMAT(a.check_out, '%H:%i') AS check_out, COALESCE(a.status, 'Chưa chấm công') AS status
               FROM employees e LEFT JOIN attendance a ON a.employee_id = e.id AND a.work_date = ?
               WHERE " . implode(' AND ', $c) . ' ORDER BY e.id', $params)->fetchAll();
    if ($u['role'] === 'admin') {   // admin xem thêm dòng chấm công của các tài khoản quản trị
        $c2 = ["u.role = 'admin'", "u.status = 'Hoạt động'"];
        $p2 = [$date];
        if ($st === 'Chưa chấm công') $c2[] = 'a.id IS NULL';
        elseif ($st !== '') { $c2[] = 'a.status = ?'; $p2[] = $st; }
        $adm = q("SELECT u.username AS employee_id, CONCAT('Quản trị: ', u.username) AS name,
                         TIME_FORMAT(a.check_in, '%H:%i') AS check_in, TIME_FORMAT(a.check_out, '%H:%i') AS check_out,
                         COALESCE(a.status, 'Chưa chấm công') AS status
                  FROM users u LEFT JOIN admin_attendance a ON a.user_id = u.id AND a.work_date = ?
                  WHERE " . implode(' AND ', $c2) . ' ORDER BY u.id', $p2)->fetchAll();
        foreach ($adm as &$ar) $ar['is_admin'] = true;
        unset($ar);
        $rows = array_merge($adm, $rows);
    }
    foreach ($rows as &$r) {
        $r['hours'] = ($r['check_in'] && $r['check_out']) ? round((strtotime($r['check_out']) - strtotime($r['check_in'])) / 3600, 1) : null;
    }
    out(['date' => $date, 'items' => $rows]);
}

/** Admin không có hồ sơ nhân viên: chấm công lưu ở bảng admin_attendance (gắn với tài khoản). */
function admin_punch(string $kind, array $u): never {
    $today = date('Y-m-d');
    $now = date('H:i');
    $row = q('SELECT id, check_in FROM admin_attendance WHERE user_id = ? AND work_date = ?', [$u['id'], $today])->fetch();
    if ($kind === 'in') {
        if ($row && $row['check_in'] !== null) fail('Hôm nay bạn đã check-in rồi', 409);
        if ($row) q('UPDATE admin_attendance SET check_in = ?, status = ? WHERE id = ?', [$now, att_status($now), $row['id']]);
        else q('INSERT INTO admin_attendance (user_id, work_date, check_in, status) VALUES (?,?,?,?)', [$u['id'], $today, $now, att_status($now)]);
    } else {
        if (!$row || $row['check_in'] === null) fail('Bạn chưa check-in hôm nay', 409);
        q('UPDATE admin_attendance SET check_out = ? WHERE id = ?', [$now, $row['id']]);
    }
    audit('punch', $u['username']);
    out(['time' => $now]);
}

function att_punch(string $kind): void {
    $u = need();
    $eid = $u['employee_id'];
    if (!$eid) {
        if ($u['role'] === 'admin') admin_punch($kind, $u);
        fail('Tài khoản chưa liên kết với hồ sơ nhân viên', 409);
    }
    $emp = q('SELECT status FROM employees WHERE id = ?', [$eid])->fetch();
    if (!$emp || $emp['status'] !== 'Đang làm') fail('Hồ sơ nhân viên đang bị khóa', 403);

    $today = date('Y-m-d');
    $now = date('H:i');
    $row = q('SELECT id, check_in FROM attendance WHERE employee_id = ? AND work_date = ?', [$eid, $today])->fetch();
    if ($kind === 'in') {
        if ($row && $row['check_in'] !== null) fail('Hôm nay bạn đã check-in rồi', 409);
        if ($row) q('UPDATE attendance SET check_in = ?, status = ? WHERE id = ?', [$now, att_status($now), $row['id']]);
        else q('INSERT INTO attendance (employee_id, work_date, check_in, status) VALUES (?,?,?,?)', [$eid, $today, $now, att_status($now)]);
    } else {
        if (!$row || $row['check_in'] === null) fail('Bạn chưa check-in hôm nay', 409);
        q('UPDATE attendance SET check_out = ? WHERE id = ?', [$now, $row['id']]);
    }
    audit('punch', $eid);
    out(['time' => $now]);
}
function attendance_check_in(): void  { att_punch('in'); }
function attendance_check_out(): void { att_punch('out'); }

function attendance_edit(array $a): void {
    $u = need('admin', 'manager');
    $e = emp_visible($u, $a['id']);
    $b = body();
    $date = valid_date(s($b, 'date')) ? s($b, 'date') : date('Y-m-d');
    $t = function (string $k) use ($b): ?string {
        $v = trim((string)($b[$k] ?? ''));
        if ($v === '' || $v === '—') return null;
        if (!valid_time($v)) fail('Giờ phải có dạng HH:MM');
        return $v;
    };
    $in = $t('check_in');
    $out = $t('check_out');
    if ($in === null) $out = null;   // vắng mặt thì không có giờ ra
    if ($out !== null && $out < $in) fail('Giờ ra phải sau giờ vào');
    q('INSERT INTO attendance (employee_id, work_date, check_in, check_out, status) VALUES (?,?,?,?,?)
       ON DUPLICATE KEY UPDATE check_in = VALUES(check_in), check_out = VALUES(check_out), status = VALUES(status)',
      [$e['id'], $date, $in, $out, $in === null ? 'Vắng' : att_status($in)]);
    audit('edit', $e['id']);
    out(['ok' => true]);
}

/* ======================= NGHỈ PHÉP ======================= */

function leave_weekdays(string $from, string $to): int {   // đếm ngày làm việc T2–T6, gồm cả hai đầu
    $n = 0;
    $end = new DateTime($to);
    for ($d = new DateTime($from); $d <= $end; $d->modify('+1 day')) {
        if ((int)$d->format('N') < 6) $n++;
    }
    return $n;
}

function leave_list(): void {
    $u = need('admin', 'manager', 'employee');
    [$w, $p] = scope($u);
    $rows = q("SELECT l.id, l.employee_id, e.name AS employee_name, l.type, l.from_date, l.to_date, l.days, l.reason, l.status
               FROM leave_requests l JOIN employees e ON e.id = l.employee_id
               WHERE $w ORDER BY l.id DESC", $p)->fetchAll();
    foreach ($rows as &$r) {
        $pending = $r['status'] === 'Chờ duyệt';
        $own = $r['employee_id'] === $u['employee_id'];
        $r['id'] = (int)$r['id'];
        $r['days'] = (int)$r['days'];
        $r['can_decide'] = $pending && !$own && in_array($u['role'], ['admin', 'manager'], true);
        $r['can_cancel'] = $pending && $own;
    }
    out($rows);
}

function leave_create(): void {
    $u = need('admin', 'manager', 'employee');
    $eid = $u['employee_id'];
    if (!$eid) fail('Tài khoản chưa liên kết với hồ sơ nhân viên', 409);
    $b = body();
    $type = s($b, 'type');
    $from = s($b, 'from');
    $to = s($b, 'to');
    $reason = s($b, 'reason');
    if (!in_array($type, LEAVE_TYPES, true)) fail('Loại nghỉ không hợp lệ');
    if (!valid_date($from) || !valid_date($to)) fail('Chọn ngày bắt đầu và ngày kết thúc');
    if ($to < $from) fail('Ngày kết thúc phải sau hoặc bằng ngày bắt đầu');
    if ((strtotime($to) - strtotime($from)) / 86400 > 365) fail('Khoảng nghỉ tối đa 365 ngày');
    if ($reason === '') fail('Nhập lý do nghỉ');
    $days = leave_weekdays($from, $to);
    if ($days < 1) fail('Khoảng ngày đã chọn không có ngày làm việc (T2–T6)');
    if (q("SELECT 1 FROM leave_requests WHERE employee_id = ? AND status IN ('Chờ duyệt','Đã duyệt') AND from_date <= ? AND to_date >= ?",
          [$eid, $to, $from])->fetch()) {
        fail('Bạn đã có đơn nghỉ trùng với khoảng ngày này', 409);
    }
    q("INSERT INTO leave_requests (employee_id, type, from_date, to_date, days, reason, status, created_at) VALUES (?,?,?,?,?,?, 'Chờ duyệt', ?)",
      [$eid, $type, $from, $to, $days, mb_substr($reason, 0, 255), date('Y-m-d H:i:s')]);
    $id = (int)db()->lastInsertId();
    audit('newLeave', (string)$id);
    out(['id' => $id, 'days' => $days], 201);
}

function leave_decide(array $a, string $status): void {
    $u = need('admin', 'manager');
    $r = q('SELECT l.id, l.employee_id, l.status, e.department_id FROM leave_requests l
            JOIN employees e ON e.id = l.employee_id WHERE l.id = ?', [(int)$a['id']])->fetch();
    if (!$r) fail('Không tìm thấy đơn nghỉ phép', 404);
    if ($r['status'] !== 'Chờ duyệt') fail('Đơn này đã được xử lý', 409);
    if ($r['employee_id'] === $u['employee_id']) fail('Bạn không thể tự duyệt đơn của chính mình', 403);
    if ($u['role'] === 'manager' && (int)$r['department_id'] !== (int)$u['department_id']) fail('Đơn thuộc phòng ban khác', 403);
    q('UPDATE leave_requests SET status = ?, decided_by = ?, decided_at = ? WHERE id = ?',
      [$status, $u['username'], date('Y-m-d H:i:s'), $r['id']]);
    audit('setLeave', (string)$r['id']);
    out(['status' => $status]);
}
function leave_approve(array $a): void { leave_decide($a, 'Đã duyệt'); }
function leave_reject(array $a): void  { leave_decide($a, 'Từ chối'); }
function leave_cancel(array $a): void {
    $u = need('admin', 'manager', 'employee');
    $r = q('SELECT id, employee_id, status FROM leave_requests WHERE id = ?', [(int)$a['id']])->fetch();
    if (!$r || $r['employee_id'] !== $u['employee_id']) fail('Không tìm thấy đơn nghỉ phép', 404);
    if ($r['status'] !== 'Chờ duyệt') fail('Chỉ hủy được đơn đang chờ duyệt', 409);
    q("UPDATE leave_requests SET status = 'Đã hủy' WHERE id = ?", [$r['id']]);
    audit('setLeave', (string)$r['id']);
    out(['status' => 'Đã hủy']);
}

/* ======================= LƯƠNG ======================= */

function pay_period(): string {
    $p = (string)($_GET['period'] ?? body()['period'] ?? '');
    return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $p) ? $p : date('Y-m');
}

function payroll_list(): void {
    $u = need('admin', 'manager', 'employee');   // admin xem tất cả; manager và employee chỉ xem lương của chính mình
    $period = pay_period();
    $cfg = settings();
    $admin = $u['role'] === 'admin';
    $rows = q("SELECT e.id, e.name, e.base_salary, e.allowance, COALESCE(a.bonus, 0) AS bonus, COALESCE(a.deduction, 0) AS deduction
               FROM employees e LEFT JOIN payroll_adjustments a ON a.employee_id = e.id AND a.period = ?
               WHERE e.status = 'Đang làm' AND " . ($admin ? '1=1' : 'e.id = ?') . ' ORDER BY e.id',
              $admin ? [$period] : [$period, $u['employee_id']])->fetchAll();
    $items = [];
    foreach ($rows as $r) {
        $base = (int)round((float)$r['base_salary'] * 1e6);
        $allow = (int)$r['allowance'];
        $bonus = (int)$r['bonus'];
        $ded = (int)$r['deduction'];
        $tax = (int)round($base * $cfg['tax'] / 100);
        $ins = (int)round($base * $cfg['ins'] / 100);
        $items[] = ['employee_id' => $r['id'], 'name' => $r['name'], 'base' => $base, 'allowance' => $allow, 'bonus' => $bonus,
                    'deduction' => $ded, 'tax' => $tax, 'insurance' => $ins, 'net' => $base + $allow + $bonus - $ded - $tax - $ins];
    }
    out(['period' => $period, 'tax_rate' => $cfg['tax'], 'ins_rate' => $cfg['ins'], 'can_edit' => $admin, 'items' => $items]);
}

function payroll_adjust(array $a): void {
    need('admin');
    $b = body();
    $period = pay_period();
    $bonus = $b['bonus'] ?? null;
    $ded = $b['deduction'] ?? null;
    if (!is_numeric($bonus) || !is_numeric($ded) || $bonus < 0 || $ded < 0 || $bonus > 1e10 || $ded > 1e10) fail('Số tiền không hợp lệ');
    if (!q('SELECT 1 FROM employees WHERE id = ?', [$a['id']])->fetch()) fail('Không tìm thấy nhân viên', 404);
    q('INSERT INTO payroll_adjustments (employee_id, period, bonus, deduction) VALUES (?,?,?,?)
       ON DUPLICATE KEY UPDATE bonus = VALUES(bonus), deduction = VALUES(deduction)',
      [$a['id'], $period, round((float)$bonus), round((float)$ded)]);
    audit('edit', $a['id']);
    out(['ok' => true]);
}

/* ======================= HỢP ĐỒNG & BÁO CÁO ======================= */

function contracts_list(): void {
    need('admin');
    $rows = q('SELECT c.id, c.employee_id, e.name AS employee_name, c.type, c.start_date, c.end_date, e.base_salary,
                      (SELECT COUNT(*) FROM contract_renewals r WHERE r.contract_id = c.id) AS renewals
               FROM contracts c JOIN employees e ON e.id = c.employee_id ORDER BY c.id')->fetchAll();
    $today = date('Y-m-d');
    $soon = date('Y-m-d', strtotime('+60 days'));
    foreach ($rows as &$r) {
        $r['salary'] = (int)round((float)$r['base_salary'] * 1e6);
        unset($r['base_salary']);
        $r['renewals'] = (int)$r['renewals'];
        $r['status'] = !$r['end_date'] ? 'Còn hiệu lực' : ($r['end_date'] < $today ? 'Hết hạn' : ($r['end_date'] <= $soon ? 'Sắp hết hạn' : 'Còn hiệu lực'));
    }
    out($rows);
}

/** Gia hạn hợp đồng: cập nhật ngày kết thúc (và loại HĐ nếu có) + lưu lịch sử. Chỉ admin. */
function contracts_renew(array $a): void {
    need('admin');
    $c = q('SELECT id, employee_id, type, start_date, end_date FROM contracts WHERE id = ?', [$a['id']])->fetch();
    if (!$c) fail('Không tìm thấy hợp đồng', 404);
    $b = body();
    $type = s($b, 'type') ?: $c['type'];
    if (!in_array($type, CONTRACT_TYPES, true)) fail('Loại hợp đồng không hợp lệ');
    if ($c['type'] === 'Không thời hạn' && $type === 'Không thời hạn') fail('Hợp đồng không thời hạn không cần gia hạn', 409);
    $newEnd = null;
    if ($type !== 'Không thời hạn') {
        $newEnd = s($b, 'end_date');
        if (!valid_date($newEnd)) fail('Ngày kết thúc mới không hợp lệ');
        $base = $c['end_date'] ?: $c['start_date'];
        if ($newEnd <= $base) fail('Ngày kết thúc mới phải sau ngày ' . date('d/m/Y', strtotime($base)));
    }
    $note = mb_substr(s($b, 'note'), 0, 255);
    $u = me();
    $pdo = db();
    $pdo->beginTransaction();
    try {
        q('INSERT INTO contract_renewals (contract_id, old_type, new_type, old_end_date, new_end_date, note, renewed_by, renewed_at)
           VALUES (?,?,?,?,?,?,?,?)',
          [$c['id'], $c['type'], $type, $c['end_date'], $newEnd, $note, $u['username'], date('Y-m-d H:i:s')]);
        q('UPDATE contracts SET type = ?, end_date = ? WHERE id = ?', [$type, $newEnd, $c['id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    audit('renewContract', $c['id'] . ' (' . $c['employee_id'] . ')');
    out(['id' => $c['id'], 'type' => $type, 'start_date' => $c['start_date'], 'end_date' => $newEnd, 'old_end_date' => $c['end_date']]);
}

function contracts_renewals(array $a): void {
    need('admin');
    if (!q('SELECT 1 FROM contracts WHERE id = ?', [$a['id']])->fetch()) fail('Không tìm thấy hợp đồng', 404);
    $rows = q('SELECT id, old_type, new_type, old_end_date, new_end_date, note, renewed_by, renewed_at
               FROM contract_renewals WHERE contract_id = ? ORDER BY id DESC', [$a['id']])->fetchAll();
    foreach ($rows as &$r) $r['id'] = (int)$r['id'];
    out($rows);
}

function reports(): void {
    need('admin');
    $t = q("SELECT COUNT(*) AS total, COALESCE(SUM(status <> 'Đang làm'), 0) AS inactive,
                   COALESCE(SUM(CASE WHEN status = 'Đang làm' THEN base_salary ELSE 0 END), 0) AS fund FROM employees")->fetch();
    $att = q("SELECT COUNT(*) AS n, COALESCE(SUM(status <> 'Vắng'), 0) AS present FROM attendance WHERE work_date = ?", [date('Y-m-d')])->fetch();
    $leaveCnt = (int)q('SELECT COUNT(*) FROM leave_requests')->fetchColumn();
    $by = fn(string $sql) => array_map(fn($r) => ['name' => $r['name'], 'count' => (int)$r['count']], q($sql)->fetchAll());
    out([
        'total' => (int)$t['total'],
        'inactive' => (int)$t['inactive'],
        'attendance_rate' => (int)$att['n'] > 0 ? (int)round($att['present'] / $att['n'] * 100) : null,
        'leave_rate' => (int)$t['total'] > 0 ? (int)round($leaveCnt / $t['total'] * 100) : 0,
        'fund' => (int)round((float)$t['fund'] * 1e6),
        'by_position' => $by('SELECT p.name, COUNT(e.id) AS count FROM positions p LEFT JOIN employees e ON e.position_id = p.id GROUP BY p.id, p.name, p.level_no ORDER BY p.level_no'),
        'by_department' => $by('SELECT d.name, COUNT(e.id) AS count FROM departments d LEFT JOIN employees e ON e.department_id = d.id GROUP BY d.id, d.name ORDER BY d.id'),
    ]);
}

/* ======================= TÀI KHOẢN (chỉ admin) ======================= */

function users_list(): void {
    need('admin');
    $rows = q("SELECT id, username, email, role, employee_id, status, DATE_FORMAT(last_login, '%Y-%m-%d %H:%i') AS last_login
               FROM users ORDER BY id")->fetchAll();
    foreach ($rows as &$r) $r['id'] = (int)$r['id'];
    out($rows);
}

function users_create(): void {
    need('admin');
    $b = body();
    $name = s($b, 'username');
    if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $name)) fail('Tên đăng nhập gồm chữ, số, ".", "_", "-" (3–50 ký tự)');
    $role = s($b, 'role') ?: 'employee';
    if (!in_array($role, ROLES, true)) fail('Quyền không hợp lệ');
    $eid = s($b, 'employee_id') ?: null;
    if ($role === 'admin') $eid = null;   // admin không phải nhân viên, không liên kết hồ sơ
    $empRow = null;
    if ($eid !== null) {
        $empRow = q('SELECT email FROM employees WHERE id = ?', [$eid])->fetch();
        if (!$empRow) fail('Mã nhân viên liên kết không tồn tại');
        if (q('SELECT 1 FROM users WHERE employee_id = ?', [$eid])->fetch()) fail('Nhân viên này đã có tài khoản', 409);
    }
    // Email mặc định: lấy theo hồ sơ nhân viên được liên kết
    $email = mb_strtolower(s($b, 'email') ?: ($empRow['email'] ?? $name . '@congty.vn'));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 150) fail('Email chưa đúng định dạng');
    if (q('SELECT 1 FROM users WHERE username = ? OR email = ?', [$name, $email])->fetch()) fail('Tên đăng nhập hoặc email đã tồn tại', 409);
    $pw = s($b, 'password');
    if ($pw === '') $pw = rand_pw();
    elseif (mb_strlen($pw) < 6 || strlen($pw) > 72) fail('Mật khẩu cần 6–72 ký tự');
    q('INSERT INTO users (username, email, password_hash, role, employee_id) VALUES (?,?,?,?,?)',
      [$name, $email, password_hash($pw, PASSWORD_DEFAULT), $role, $eid]);
    audit('user', 'tạo ' . $name);
    out(['id' => (int)db()->lastInsertId(), 'temp_password' => $pw], 201);
}

function users_update(array $a): void {   // đổi quyền
    $u = need('admin');
    $id = (int)$a['id'];
    $role = s(body(), 'role');
    if (!in_array($role, ROLES, true)) fail('Quyền không hợp lệ');
    $t = q('SELECT username, role FROM users WHERE id = ?', [$id])->fetch();
    if (!$t) fail('Không tìm thấy tài khoản', 404);
    if ($id === (int)$u['id'] && $role !== $t['role']) fail('Không thể đổi quyền của chính bạn', 409);
    q('UPDATE users SET role = ?' . ($role === 'admin' ? ', employee_id = NULL' : '') . ' WHERE id = ?', [$role, $id]);
    audit('user', 'đổi quyền ' . $t['username'] . ' → ' . $role);
    out(['ok' => true]);
}

function users_toggle_lock(array $a): void {
    $u = need('admin');
    $id = (int)$a['id'];
    $t = q('SELECT username, status FROM users WHERE id = ?', [$id])->fetch();
    if (!$t) fail('Không tìm thấy tài khoản', 404);
    if ($id === (int)$u['id']) fail('Không thể khóa chính tài khoản của bạn', 409);
    $new = $t['status'] === 'Hoạt động' ? 'Đã khóa' : 'Hoạt động';
    q('UPDATE users SET status = ? WHERE id = ?', [$new, $id]);
    audit('toggleLock', $t['username']);
    out(['status' => $new]);
}

function users_reset_password(array $a): void {
    need('admin');
    $id = (int)$a['id'];
    $t = q('SELECT username FROM users WHERE id = ?', [$id])->fetch();
    if (!$t) fail('Không tìm thấy tài khoản', 404);
    $pw = rand_pw();
    q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($pw, PASSWORD_DEFAULT), $id]);
    audit('reset', $t['username']);
    out(['temp_password' => $pw]);
}

function users_delete(array $a): void {
    $u = need('admin');
    $id = (int)$a['id'];
    $t = q('SELECT username FROM users WHERE id = ?', [$id])->fetch();
    if (!$t) fail('Không tìm thấy tài khoản', 404);
    if ($id === (int)$u['id']) fail('Không thể xóa chính tài khoản của bạn', 409);
    q('DELETE FROM users WHERE id = ?', [$id]);
    audit('user', 'xóa ' . $t['username']);
    out(['ok' => true]);
}

/* ======================= NHẬT KÝ & CẤU HÌNH ======================= */

function audit_list(): void {
    need('admin');
    out(q("SELECT DATE_FORMAT(created_at, '%d/%m/%Y %H:%i') AS time, username AS user, action, target, ip, description
           FROM audit_logs ORDER BY id DESC LIMIT 200")->fetchAll());
}

function settings_get(): void {
    need();
    out(settings());
}

function settings_update(): void {
    need('admin');
    $b = body();
    $tax = $b['tax'] ?? null;
    $ins = $b['ins'] ?? null;
    if (!is_numeric($tax) || !is_numeric($ins) || $tax < 0 || $tax > 100 || $ins < 0 || $ins > 100) fail('Thuế và bảo hiểm phải nằm trong khoảng 0–100%');
    $ann = mb_substr(s($b, 'announcement'), 0, 255);
    $save = fn(string $k, string $v) => q('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', [$k, $v]);
    $save('tax_rate', (string)(float)$tax);
    $save('insurance_rate', (string)(float)$ins);
    $save('announcement', $ann);
    audit('config', 'hệ thống');
    out(['tax' => (float)$tax, 'ins' => (float)$ins, 'announcement' => $ann]);
}
