<?php
// Điểm vào duy nhất của API: GET/POST/PUT/DELETE /api/<đường-dẫn>
ini_set('display_errors', '0');      // không để lỗi PHP làm hỏng JSON; xem lỗi trong php_error_log
error_reporting(E_ALL);

require __DIR__ . '/config.php';
require __DIR__ . '/lib.php';
require __DIR__ . '/routes.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$method = $_SERVER['REQUEST_METHOD'];

// Lấy phần đường dẫn sau /api (hỗ trợ cả /api/employees lẫn /api/index.php/employees)
$path = rawurldecode((string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
if ($base !== '' && strpos($path, $base) === 0) $path = substr($path, strlen($base));
$path = '/' . trim(preg_replace('#^/index\.php#', '', $path), '/');

try {
    // Chống CSRF: mọi thao tác ghi phải kèm header tự đặt (form của trang khác không gửi được)
    if ($method !== 'GET' && ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'XMLHttpRequest') {
        fail('Thiếu header X-Requested-With', 403);
    }
    if ($method === 'GET') session_write_close();   // nhả khóa session để các request song song không chờ nhau

    foreach ($ROUTES as [$m, $pattern, $handler]) {
        if ($m !== $method) continue;
        $re = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $pattern) . '$#';
        if (preg_match($re, $path, $mm)) {
            $handler(array_filter($mm, 'is_string', ARRAY_FILTER_USE_KEY));
            exit;
        }
    }
    fail('Không tìm thấy endpoint ' . $method . ' ' . $path, 404);
} catch (PDOException $e) {
    error_log('[QTNV] ' . $e->getMessage());
    if ($e->getCode() === '23000') fail('Dữ liệu bị trùng hoặc đang được tham chiếu bởi bản ghi khác', 409);
    fail(APP_DEBUG ? 'Lỗi CSDL: ' . $e->getMessage() : 'Lỗi máy chủ', 500);
} catch (Throwable $e) {
    error_log('[QTNV] ' . $e);
    fail(APP_DEBUG ? 'Lỗi máy chủ: ' . $e->getMessage() : 'Lỗi máy chủ', 500);
}
