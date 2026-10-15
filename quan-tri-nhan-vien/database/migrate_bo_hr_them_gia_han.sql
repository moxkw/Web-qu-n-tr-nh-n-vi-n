-- =====================================================================
-- Nâng cấp CSDL ĐÃ CÓ DỮ LIỆU (không cần chạy nếu import schema.sql mới)
--   1) Bỏ vai trò HR
--   2) Admin không phải nhân viên: gỡ hồ sơ nhân viên gắn với admin
--   3) Thêm bảng lịch sử gia hạn hợp đồng
-- Hãy sao lưu database trước khi chạy.
-- =====================================================================

-- 1) Tài khoản hr cũ -> hạ xuống employee (admin có thể đổi lại quyền ở màn hình Tài khoản)
UPDATE users SET role = 'employee' WHERE role = 'hr';
UPDATE reviews SET reviewer_role = 'admin' WHERE reviewer_role = 'hr';
ALTER TABLE users
  MODIFY role ENUM('admin','manager','employee') NOT NULL DEFAULT 'employee';

-- 2) Xóa hồ sơ nhân viên đang gắn với tài khoản admin (kéo theo hợp đồng, chấm công,
--    nghỉ phép, lương... của hồ sơ đó). Cột users.employee_id tự về NULL nhờ khóa ngoại.
DELETE FROM employees
 WHERE id IN (SELECT eid FROM (SELECT employee_id AS eid FROM users
                               WHERE role = 'admin' AND employee_id IS NOT NULL) AS t);
UPDATE users SET employee_id = NULL WHERE role = 'admin';

-- 3) Lịch sử gia hạn hợp đồng
CREATE TABLE IF NOT EXISTS contract_renewals (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  contract_id VARCHAR(20) NOT NULL,
  old_type VARCHAR(20) NOT NULL,
  new_type VARCHAR(20) NOT NULL,
  old_end_date DATE NULL,
  new_end_date DATE NULL,
  note VARCHAR(255) NOT NULL DEFAULT '',
  renewed_by VARCHAR(50) NOT NULL,
  renewed_at DATETIME NOT NULL,
  KEY idx_ren_contract (contract_id),
  CONSTRAINT fk_ren_con FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
