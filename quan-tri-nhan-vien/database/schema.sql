-- =====================================================================
-- Quản trị nhân viên - cơ sở dữ liệu MySQL/MariaDB (XAMPP)
-- CẢNH BÁO: file này XÓA và TẠO LẠI toàn bộ bảng. Chỉ import khi muốn reset dữ liệu.
-- Import: phpMyAdmin -> tab "Import" -> chọn file này -> Go.
-- =====================================================================
SET NAMES utf8mb4;
CREATE DATABASE IF NOT EXISTS quan_tri_nhan_vien CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE quan_tri_nhan_vien;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS audit_logs, reviews, payroll_adjustments, contracts, leave_requests, attendance, users, employees, positions, departments, settings;
SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE settings (
  k VARCHAR(50) NOT NULL PRIMARY KEY,
  v VARCHAR(255) NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE departments (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE positions (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL UNIQUE,
  level_no SMALLINT UNSIGNED NOT NULL,
  min_salary DECIMAL(6,1) NOT NULL,   -- đơn vị: triệu đồng
  max_salary DECIMAL(6,1) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE employees (
  id VARCHAR(20) NOT NULL PRIMARY KEY,          -- mã nhân viên, ví dụ NV001
  name VARCHAR(100) NOT NULL,
  email VARCHAR(150) NOT NULL UNIQUE,
  phone VARCHAR(15) NOT NULL,
  gender ENUM('Nam','Nữ') NOT NULL DEFAULT 'Nam',
  department_id INT UNSIGNED NOT NULL,
  position_id INT UNSIGNED NOT NULL,
  base_salary DECIMAL(6,2) NOT NULL DEFAULT 0,  -- triệu đồng
  allowance DECIMAL(12,0) NOT NULL DEFAULT 1000000,  -- phụ cấp (đồng)
  hire_date DATE NOT NULL,
  status ENUM('Đang làm','Đã khóa') NOT NULL DEFAULT 'Đang làm',
  CONSTRAINT fk_emp_dep FOREIGN KEY (department_id) REFERENCES departments(id),
  CONSTRAINT fk_emp_pos FOREIGN KEY (position_id) REFERENCES positions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) NOT NULL UNIQUE,
  email VARCHAR(150) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('admin','manager','employee') NOT NULL DEFAULT 'employee',
  employee_id VARCHAR(20) NULL,
  status ENUM('Hoạt động','Đã khóa') NOT NULL DEFAULT 'Hoạt động',
  last_login DATETIME NULL,
  CONSTRAINT fk_user_emp FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE attendance (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  employee_id VARCHAR(20) NOT NULL,
  work_date DATE NOT NULL,
  check_in TIME NULL,
  check_out TIME NULL,
  status ENUM('Có mặt','Đi muộn','Vắng') NOT NULL,
  UNIQUE KEY uq_emp_day (employee_id, work_date),
  CONSTRAINT fk_att_emp FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE admin_attendance (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  work_date DATE NOT NULL,
  check_in TIME NULL,
  check_out TIME NULL,
  status ENUM('Có mặt','Đi muộn','Vắng') NOT NULL,
  UNIQUE KEY uq_adm_day (user_id, work_date),
  CONSTRAINT fk_admatt_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE leave_requests (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  employee_id VARCHAR(20) NOT NULL,
  type ENUM('Phép năm','Nghỉ ốm','Không lương') NOT NULL,
  from_date DATE NOT NULL,
  to_date DATE NOT NULL,
  days SMALLINT UNSIGNED NOT NULL,
  reason VARCHAR(255) NOT NULL,
  status ENUM('Chờ duyệt','Đã duyệt','Từ chối','Đã hủy') NOT NULL DEFAULT 'Chờ duyệt',
  created_at DATETIME NOT NULL,
  decided_by VARCHAR(50) NULL,
  decided_at DATETIME NULL,
  CONSTRAINT fk_leave_emp FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=501 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE contracts (
  id VARCHAR(20) NOT NULL PRIMARY KEY,
  employee_id VARCHAR(20) NOT NULL,
  type ENUM('Thử việc','Có thời hạn','Không thời hạn','Bán thời gian','Thực tập') NOT NULL,
  start_date DATE NOT NULL,
  end_date DATE NULL,
  CONSTRAINT fk_con_emp FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE contract_renewals (
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

CREATE TABLE payroll_adjustments (
  employee_id VARCHAR(20) NOT NULL,
  period CHAR(7) NOT NULL,                       -- YYYY-MM
  bonus DECIMAL(12,0) NOT NULL DEFAULT 0,
  deduction DECIMAL(12,0) NOT NULL DEFAULT 0,
  PRIMARY KEY (employee_id, period),
  CONSTRAINT fk_pay_emp FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE reviews (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  employee_id VARCHAR(20) NOT NULL,
  score TINYINT UNSIGNED NOT NULL,
  comment VARCHAR(500) NOT NULL DEFAULT '',
  reviewer_username VARCHAR(50) NOT NULL,
  reviewer_role VARCHAR(20) NOT NULL,
  created_at DATETIME NOT NULL,
  CONSTRAINT fk_rev_emp FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audit_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  created_at DATETIME NOT NULL,
  username VARCHAR(50) NOT NULL,
  action VARCHAR(40) NOT NULL,
  target VARCHAR(255) NOT NULL DEFAULT '',
  ip VARCHAR(45) NOT NULL DEFAULT '',
  description VARCHAR(500) NOT NULL,
  KEY idx_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------- DỮ LIỆU MẪU ----------------------------
INSERT INTO settings (k, v) VALUES ('tax_rate','5'), ('insurance_rate','10.5'), ('announcement','chốt chấm công trước ngày 30');

INSERT INTO departments (name) VALUES
  ('IT'),
  ('Marketing'),
  ('Nhân sự'),
  ('Kế toán'),
  ('Kinh doanh'),
  ('CSKH');

INSERT INTO positions (name, level_no, min_salary, max_salary) VALUES
  ('Thực tập', 1, 5, 8),
  ('Junior', 2, 9, 15),
  ('Middle', 3, 15, 25),
  ('Senior', 4, 25, 40),
  ('Trưởng nhóm', 5, 35, 50),
  ('Quản lý', 6, 45, 70);

INSERT INTO employees (id, name, email, phone, gender, department_id, position_id, base_salary, hire_date, status) VALUES
  ('NV002', 'Trần Thị Bình', 'nv2@congty.vn', '0910007919', 'Nữ', 2, 6, 13, '2021-02-11', 'Đang làm'),
  ('NV003', 'Lê Hoàng Cường', 'nv3@congty.vn', '0910015838', 'Nam', 3, 5, 16, '2022-03-12', 'Đang làm'),
  ('NV004', 'Phạm Minh Đức', 'nv4@congty.vn', '0910023757', 'Nam', 4, 4, 19, '2023-04-13', 'Đang làm'),
  ('NV005', 'Hoàng Thu Hà', 'nv5@congty.vn', '0910031676', 'Nữ', 5, 3, 22, '2024-05-14', 'Đang làm'),
  ('NV006', 'Vũ Quang Huy', 'nv6@congty.vn', '0910039595', 'Nam', 6, 2, 25, '2020-06-15', 'Đang làm'),
  ('NV007', 'Đặng Ngọc Lan', 'nv7@congty.vn', '0910047514', 'Nữ', 1, 1, 28, '2021-07-16', 'Đang làm'),
  ('NV008', 'Bùi Gia Khang', 'nv8@congty.vn', '0910055433', 'Nam', 2, 6, 31, '2022-08-17', 'Đang làm'),
  ('NV009', 'Ngô Thanh Mai', 'nv9@congty.vn', '0910063352', 'Nữ', 3, 5, 34, '2023-09-18', 'Đang làm'),
  ('NV010', 'Dương Tuấn Nam', 'nv10@congty.vn', '0910071271', 'Nam', 4, 4, 12, '2024-01-10', 'Đang làm'),
  ('NV011', 'Đỗ Phương Oanh', 'nv11@congty.vn', '0910079190', 'Nữ', 5, 3, 15, '2020-02-11', 'Đã khóa'),
  ('NV012', 'Lý Quốc Phong', 'nv12@congty.vn', '0910087109', 'Nam', 6, 2, 18, '2021-03-12', 'Đang làm'),
  ('NV013', 'Phan Thùy Quyên', 'nv13@congty.vn', '0910095028', 'Nữ', 1, 1, 21, '2022-04-13', 'Đang làm'),
  ('NV014', 'Võ Anh Sơn', 'nv14@congty.vn', '0910102947', 'Nam', 2, 6, 24, '2023-05-14', 'Đang làm'),
  ('NV015', 'Trịnh Bảo Trâm', 'nv15@congty.vn', '0910110866', 'Nữ', 3, 5, 27, '2024-06-15', 'Đang làm'),
  ('NV016', 'Mai Đức Thành', 'nv16@congty.vn', '0910118785', 'Nam', 4, 4, 30, '2020-07-16', 'Đang làm'),
  ('NV017', 'Cao Hải Yến', 'nv17@congty.vn', '0910126704', 'Nữ', 5, 3, 33, '2021-08-17', 'Đang làm'),
  ('NV018', 'Tạ Việt Anh', 'nv18@congty.vn', '0910134623', 'Nam', 6, 2, 11, '2022-09-18', 'Đang làm'),
  ('NV019', 'Hồ Khánh Linh', 'nv19@congty.vn', '0910142542', 'Nữ', 1, 1, 14, '2023-01-10', 'Đang làm'),
  ('NV020', 'Lưu Minh Tâm', 'nv20@congty.vn', '0910150461', 'Nam', 2, 6, 17, '2024-02-11', 'Đang làm'),
  ('NV021', 'Chu Thế Vinh', 'nv21@congty.vn', '0910158380', 'Nam', 3, 5, 20, '2020-03-12', 'Đang làm'),
  ('NV022', 'Kiều Diệu Hương', 'nv22@congty.vn', '0910166299', 'Nữ', 4, 4, 23, '2021-04-13', 'Đã khóa'),
  ('NV023', 'Đinh Công Danh', 'nv23@congty.vn', '0910174218', 'Nam', 5, 3, 26, '2022-05-14', 'Đang làm'),
  ('NV024', 'Tô Mỹ Duyên', 'nv24@congty.vn', '0910182137', 'Nữ', 6, 2, 29, '2023-06-15', 'Đang làm');

-- Tài khoản demo: admin/admin123; manager, employee / 123456 (đổi ngay khi dùng thật)
-- Admin là tài khoản quản trị, KHÔNG phải nhân viên nên employee_id = NULL
INSERT INTO users (username, email, password_hash, role, employee_id) VALUES
  ('admin', 'admin@congty.vn', '$2y$10$0AMEZoOIWoqeQOSE8DqDiOIkm.KuocrZzWL9IA1etjq9jPoX.lCju', 'admin', NULL),
  ('manager', 'manager@congty.vn', '$2y$10$qnW2NBqvHbXzVpJ.yR3PxOWfa6IEaMYVj0GfE6f/fPE62uUgT0l7S', 'manager', 'NV007'),
  ('employee', 'employee@congty.vn', '$2y$10$wVPbjskSc0OgFAYo0Ou3LuK6OaQZ/ny.z1iE/c1Fz0DFUFmr0G6RW', 'employee', 'NV003');

-- Tài khoản cho các nhân viên còn lại: tên đăng nhập = mã nhân viên viết thường (nv002...), mật khẩu 123456.
-- Nhân viên đã khóa thì tài khoản cũng bị khóa. Hãy đổi mật khẩu khi dùng thật.
INSERT INTO users (username, email, password_hash, role, employee_id, status) VALUES
  ('nv002', 'nv2@congty.vn', '$2y$10$wVPbjskSc0OgFAYo0Ou3LuK6OaQZ/ny.z1iE/c1Fz0DFUFmr0G6RW', 'employee', 'NV002', 'Hoạt động'),
  ('nv004', 'nv4@congty.vn', '$2y$10$wVPbjskSc0OgFAYo0Ou3LuK6OaQZ/ny.z1iE/c1Fz0DFUFmr0G6RW', 'employee', 'NV004', 'Hoạt động'),
  ('nv005', 'nv5@congty.vn', '$2y$10$wVPbjskSc0OgFAYo0Ou3LuK6OaQZ/ny.z1iE/c1Fz0DFUFmr0G6RW', 'employee', 'NV005', 'Hoạt động'),
  ('nv006', 'nv6@congty.vn', '$2y$10$wVPbjskSc0OgFAYo0Ou3LuK6OaQZ/ny.z1iE/c1Fz0DFUFmr0G6RW', 'employee', 'NV006', 'Hoạt động'),
  ('nv008', 'nv8@congty.vn', '$2y$10$wVPbjskSc0OgFAYo0Ou3LuK6OaQZ/ny.z1iE/c1Fz0DFUFmr0G6RW', 'employee', 'NV008', 'Hoạt động'),
  ('nv009', 'nv9@congty.vn', '$2y$10$wVPbjskSc0OgFAYo0Ou3LuK6OaQZ/ny.z1iE/c1Fz0DFUFmr0G6RW', 'employee', 'NV009', 'Hoạt động'),
  ('nv010', 'nv10@congty.vn', '$2y$10$wVPbjskSc0OgFAYo0Ou3LuK6OaQZ/ny.z1iE/c1Fz0DFUFmr0G6RW', 'employee', 'NV010', 'Hoạt động'),
  ('nv011', 'nv11@congty.vn', '$2y$10$wVPbjskSc0OgFAYo0Ou3LuK6OaQZ/ny.z1iE/c1Fz0DFUFmr0G6RW', 'employee', 'NV011', 'Đã khóa'),
  ('nv012', 'nv12@congty.vn', '$2y$10$wVPbjskSc0OgFAYo0Ou3LuK6OaQZ/ny.z1iE/c1Fz0DFUFmr0G6RW', 'employee', 'NV012', 'Hoạt động'),
  ('nv013', 'nv13@congty.vn', '$2y$10$wVPbjskSc0OgFAYo0Ou3LuK6OaQZ/ny.z1iE/c1Fz0DFUFmr0G6RW', 'employee', 'NV013', 'Hoạt động'),
  ('nv014', 'nv14@congty.vn', '$2y$10$wVPbjskSc0OgFAYo0Ou3LuK6OaQZ/ny.z1iE/c1Fz0DFUFmr0G6RW', 'employee', 'NV014', 'Hoạt động'),
  ('nv015', 'nv15@congty.vn', '$2y$10$wVPbjskSc0OgFAYo0Ou3LuK6OaQZ/ny.z1iE/c1Fz0DFUFmr0G6RW', 'employee', 'NV015', 'Hoạt động'),
  ('nv016', 'nv16@congty.vn', '$2y$10$wVPbjskSc0OgFAYo0Ou3LuK6OaQZ/ny.z1iE/c1Fz0DFUFmr0G6RW', 'employee', 'NV016', 'Hoạt động'),
  ('nv017', 'nv17@congty.vn', '$2y$10$wVPbjskSc0OgFAYo0Ou3LuK6OaQZ/ny.z1iE/c1Fz0DFUFmr0G6RW', 'employee', 'NV017', 'Hoạt động'),
  ('nv018', 'nv18@congty.vn', '$2y$10$wVPbjskSc0OgFAYo0Ou3LuK6OaQZ/ny.z1iE/c1Fz0DFUFmr0G6RW', 'employee', 'NV018', 'Hoạt động'),
  ('nv019', 'nv19@congty.vn', '$2y$10$wVPbjskSc0OgFAYo0Ou3LuK6OaQZ/ny.z1iE/c1Fz0DFUFmr0G6RW', 'employee', 'NV019', 'Hoạt động'),
  ('nv020', 'nv20@congty.vn', '$2y$10$wVPbjskSc0OgFAYo0Ou3LuK6OaQZ/ny.z1iE/c1Fz0DFUFmr0G6RW', 'employee', 'NV020', 'Hoạt động'),
  ('nv021', 'nv21@congty.vn', '$2y$10$wVPbjskSc0OgFAYo0Ou3LuK6OaQZ/ny.z1iE/c1Fz0DFUFmr0G6RW', 'employee', 'NV021', 'Hoạt động'),
  ('nv022', 'nv22@congty.vn', '$2y$10$wVPbjskSc0OgFAYo0Ou3LuK6OaQZ/ny.z1iE/c1Fz0DFUFmr0G6RW', 'employee', 'NV022', 'Đã khóa'),
  ('nv023', 'nv23@congty.vn', '$2y$10$wVPbjskSc0OgFAYo0Ou3LuK6OaQZ/ny.z1iE/c1Fz0DFUFmr0G6RW', 'employee', 'NV023', 'Hoạt động'),
  ('nv024', 'nv24@congty.vn', '$2y$10$wVPbjskSc0OgFAYo0Ou3LuK6OaQZ/ny.z1iE/c1Fz0DFUFmr0G6RW', 'employee', 'NV024', 'Hoạt động');

INSERT INTO attendance (employee_id, work_date, check_in, check_out, status) VALUES
  ('NV002', CURDATE(), '08:07', '17:30', 'Có mặt'),
  ('NV004', CURDATE(), NULL, NULL, 'Vắng'),
  ('NV005', CURDATE(), '08:28', '17:30', 'Có mặt'),
  ('NV006', CURDATE(), '08:35', '17:30', 'Đi muộn'),
  ('NV008', CURDATE(), '08:07', '17:30', 'Có mặt'),
  ('NV010', CURDATE(), '08:21', '17:30', 'Có mặt'),
  ('NV011', CURDATE(), NULL, NULL, 'Vắng'),
  ('NV012', CURDATE(), '08:35', '17:30', 'Đi muộn'),
  ('NV013', CURDATE(), '08:00', '17:30', 'Có mặt'),
  ('NV014', CURDATE(), '08:07', '17:30', 'Có mặt'),
  ('NV015', CURDATE(), '08:14', '17:30', 'Có mặt'),
  ('NV016', CURDATE(), '08:21', '17:30', 'Có mặt'),
  ('NV017', CURDATE(), '08:28', '17:30', 'Có mặt'),
  ('NV018', CURDATE(), NULL, NULL, 'Vắng'),
  ('NV019', CURDATE(), '08:00', '17:30', 'Có mặt'),
  ('NV020', CURDATE(), '08:07', '17:30', 'Có mặt'),
  ('NV021', CURDATE(), '08:14', '17:30', 'Có mặt'),
  ('NV022', CURDATE(), '08:21', '17:30', 'Có mặt'),
  ('NV023', CURDATE(), '08:28', '17:30', 'Có mặt'),
  ('NV024', CURDATE(), '08:35', '17:30', 'Đi muộn');

INSERT INTO leave_requests (employee_id, type, from_date, to_date, days, reason, status, created_at) VALUES
  ('NV003', 'Phép năm',    '2026-10-12', '2026-10-14', 3, 'Về quê',        'Chờ duyệt', NOW()),
  ('NV007', 'Nghỉ ốm',     '2026-10-07', '2026-10-08', 2, 'Khám bệnh',     'Chờ duyệt', NOW()),
  ('NV012', 'Phép năm',    '2026-10-20', '2026-10-20', 1, 'Việc gia đình', 'Đã duyệt',  NOW()),
  ('NV019', 'Không lương', '2026-11-02', '2026-11-06', 5, 'Du lịch',       'Chờ duyệt', NOW());

INSERT INTO contracts (id, employee_id, type, start_date, end_date) VALUES
  ('HD002', 'NV002', 'Có thời hạn', '2021-02-11', '2027-02-15'),
  ('HD003', 'NV003', 'Không thời hạn', '2022-03-12', NULL),
  ('HD004', 'NV004', 'Bán thời gian', '2023-04-13', '2027-04-15'),
  ('HD005', 'NV005', 'Thực tập', '2024-05-14', '2027-05-15'),
  ('HD006', 'NV006', 'Thử việc', '2020-06-15', '2027-06-15'),
  ('HD007', 'NV007', 'Có thời hạn', '2021-07-16', '2026-11-20'),
  ('HD008', 'NV008', 'Không thời hạn', '2022-08-17', NULL),
  ('HD009', 'NV009', 'Bán thời gian', '2023-09-18', '2027-09-15'),
  ('HD010', 'NV010', 'Thực tập', '2024-01-10', '2027-01-15'),
  ('HD011', 'NV011', 'Thử việc', '2020-02-11', '2026-11-20'),
  ('HD012', 'NV012', 'Có thời hạn', '2021-03-12', '2027-03-15'),
  ('HD013', 'NV013', 'Không thời hạn', '2022-04-13', NULL),
  ('HD014', 'NV014', 'Bán thời gian', '2023-05-14', '2027-05-15'),
  ('HD015', 'NV015', 'Thực tập', '2024-06-15', '2027-06-15'),
  ('HD016', 'NV016', 'Thử việc', '2020-07-16', '2027-07-15'),
  ('HD017', 'NV017', 'Có thời hạn', '2021-08-17', '2027-08-15'),
  ('HD018', 'NV018', 'Không thời hạn', '2022-09-18', NULL),
  ('HD019', 'NV019', 'Bán thời gian', '2023-01-10', '2027-01-15'),
  ('HD020', 'NV020', 'Thực tập', '2024-02-11', '2027-02-15'),
  ('HD021', 'NV021', 'Thử việc', '2020-03-12', '2027-03-15'),
  ('HD022', 'NV022', 'Có thời hạn', '2021-04-13', '2027-04-15'),
  ('HD023', 'NV023', 'Không thời hạn', '2022-05-14', NULL),
  ('HD024', 'NV024', 'Bán thời gian', '2023-06-15', '2027-06-15');

INSERT INTO payroll_adjustments (employee_id, period, bonus, deduction) VALUES
  ('NV002', DATE_FORMAT(CURDATE(), '%Y-%m'), 500000, 200000),
  ('NV003', DATE_FORMAT(CURDATE(), '%Y-%m'), 1000000, 400000),
  ('NV004', DATE_FORMAT(CURDATE(), '%Y-%m'), 1500000, 0),
  ('NV005', DATE_FORMAT(CURDATE(), '%Y-%m'), 0, 200000),
  ('NV006', DATE_FORMAT(CURDATE(), '%Y-%m'), 500000, 400000),
  ('NV007', DATE_FORMAT(CURDATE(), '%Y-%m'), 1000000, 0),
  ('NV008', DATE_FORMAT(CURDATE(), '%Y-%m'), 1500000, 200000),
  ('NV009', DATE_FORMAT(CURDATE(), '%Y-%m'), 0, 400000),
  ('NV010', DATE_FORMAT(CURDATE(), '%Y-%m'), 500000, 0),
  ('NV011', DATE_FORMAT(CURDATE(), '%Y-%m'), 1000000, 200000),
  ('NV012', DATE_FORMAT(CURDATE(), '%Y-%m'), 1500000, 400000);

INSERT INTO audit_logs (created_at, username, action, target, ip, description) VALUES
  (NOW(), 'admin', 'createEmployee', 'NV024', '127.0.0.1', 'admin đã thêm nhân viên NV024'),
  (NOW(), 'manager', 'setLeave', '503', '127.0.0.1', 'manager đã xử lý đơn nghỉ phép 503');
