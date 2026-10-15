-- =====================================================================
-- Nâng cấp CSDL ĐÃ CÓ DỮ LIỆU: cho phép tài khoản admin check-in / check-out.
-- Admin không phải nhân viên nên giờ chấm công của admin lưu ở bảng riêng.
-- (Không cần chạy nếu import schema.sql mới.)
-- =====================================================================
CREATE TABLE IF NOT EXISTS admin_attendance (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  work_date DATE NOT NULL,
  check_in TIME NULL,
  check_out TIME NULL,
  status ENUM('Có mặt','Đi muộn','Vắng') NOT NULL,
  UNIQUE KEY uq_adm_day (user_id, work_date),
  CONSTRAINT fk_admatt_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

