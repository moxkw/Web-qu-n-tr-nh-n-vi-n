-- =====================================================================
-- Tạo tài khoản cho MỌI nhân viên chưa có tài khoản (dùng cho CSDL đã có dữ liệu).
--   * Tên đăng nhập = mã nhân viên viết thường (NV025 -> nv025)
--   * Email lấy từ hồ sơ nhân viên, quyền: employee
--   * Mật khẩu tạm: 123456  (hãy yêu cầu nhân viên đổi, hoặc reset ở màn hình Tài khoản)
--   * Nhân viên đang bị khóa -> tài khoản cũng ở trạng thái "Đã khóa"
-- Chạy được nhiều lần: nhân viên đã có tài khoản sẽ được bỏ qua.
-- =====================================================================
INSERT INTO users (username, email, password_hash, role, employee_id, status)
SELECT LOWER(e.id), e.email,
       '$2y$10$wVPbjskSc0OgFAYo0Ou3LuK6OaQZ/ny.z1iE/c1Fz0DFUFmr0G6RW',
       'employee', e.id,
       IF(e.status = 'Đang làm', 'Hoạt động', 'Đã khóa')
FROM employees e
WHERE NOT EXISTS (SELECT 1 FROM users u WHERE u.employee_id = e.id)
  AND NOT EXISTS (SELECT 1 FROM users u WHERE u.username = LOWER(e.id) OR u.email = e.email);

-- Kiểm tra: nhân viên nào vẫn chưa có tài khoản (kết quả nên rỗng)
SELECT e.id, e.name FROM employees e
WHERE NOT EXISTS (SELECT 1 FROM users u WHERE u.employee_id = e.id);
