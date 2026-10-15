# Quản trị nhân viên — PHP + MySQL (XAMPP)

Giao diện giữ nguyên từ file HTML gốc. Dữ liệu giả lập trong trình duyệt được thay bằng REST API viết bằng PHP, lưu trong MySQL/MariaDB.

## Cài đặt trên XAMPP

1. **Yêu cầu:** XAMPP có PHP 8.1 trở lên.
2. Chép cả thư mục `quan-tri-nhan-vien` vào `C:\xampp\htdocs\`.
3. Mở XAMPP Control Panel, bấm **Start** cho **Apache** và **MySQL**.
4. Mở `http://localhost/phpmyadmin` → tab **Import** → chọn `database/schema.sql` → **Import**. File này tự tạo database `quan_tri_nhan_vien`, các bảng và dữ liệu mẫu.
5. Mở `http://localhost/quan-tri-nhan-vien/`.

Tài khoản demo: `admin / admin123`; `manager`, `employee` / `123456`. Mọi nhân viên còn lại đều có tài khoản `nv002`, `nv004`... (tên đăng nhập = mã nhân viên viết thường) với mật khẩu `123456`; nhân viên bị khóa thì tài khoản cũng bị khóa. CSDL cũ: chạy `database/tao_tai_khoan_con_thieu.sql` để tạo tài khoản cho các nhân viên chưa có. Tương tự, `database/tao_hop_dong_con_thieu.sql` tạo hợp đồng cho các nhân viên chưa có hợp đồng.

**Tìm kiếm / sắp xếp / xuất CSV:** mọi màn hình dạng bảng (Nhân viên, Phòng ban, Chức vụ, Chấm công, Nghỉ phép, Lương, Hợp đồng, Tài khoản, Nhật ký) đều có ô tìm kiếm (không phân biệt dấu), bấm tiêu đề cột để sắp xếp tăng/giảm và nút **Xuất CSV** (xuất đúng dữ liệu đang lọc/sắp xếp, mở được bằng Excel). Màn Báo cáo có thêm xuất thống kê.

**Admin chấm công:** admin không phải nhân viên nên giờ check-in/out của admin lưu ở bảng riêng `admin_attendance`, hiển thị ở đầu màn Chấm công (chỉ admin thấy). CSDL cũ: chạy `database/migrate_admin_cham_cong.sql`.

Hệ thống có 3 vai trò: **admin** (toàn quyền, không phải nhân viên nên không có hồ sơ và không hiện trong danh sách nhân viên), **manager** (xem nhân viên thuộc phòng ban của mình, duyệt đơn nghỉ phép của phòng mình), **employee** (chỉ xem dữ liệu của chính mình).

Đã có sẵn CSDL cũ? Chạy `database/migrate_bo_hr_them_gia_han.sql` (sao lưu trước) thay vì import lại `schema.sql`.

Nếu MySQL của bạn có mật khẩu root, sửa `api/config.php` (`DB_USER`, `DB_PASS`).

> `schema.sql` xóa và tạo lại toàn bộ bảng. Chỉ import lại khi muốn reset dữ liệu.

## Cấu trúc

```
quan-tri-nhan-vien/
├── index.html          giao diện (HTML + CSS gốc)
├── assets/app.js       logic giao diện, gọi API bằng fetch
├── api/
│   ├── index.php       điểm vào + bộ định tuyến
│   ├── routes.php      danh sách endpoint và xử lý
│   ├── lib.php         CSDL (PDO), phiên đăng nhập, phân quyền, nhật ký
│   ├── config.php      thông tin kết nối CSDL
│   └── .htaccess       chuyển /api/... về index.php
└── database/schema.sql cấu trúc bảng + dữ liệu mẫu
```

## API

Mọi endpoint nằm dưới `/quan-tri-nhan-vien/api`, trả JSON. Lỗi có dạng `{"error": "..."}` kèm mã HTTP (400, 401, 403, 404, 409, 500). Các thao tác ghi (POST/PUT/DELETE) phải gửi header `X-Requested-With: XMLHttpRequest` và body JSON; phiên đăng nhập dùng cookie.

| Nhóm | Endpoint | Quyền |
|---|---|---|
| Đăng nhập | `POST /auth/login`, `POST /auth/logout`, `GET /auth/me`, `POST /auth/change-password`, `PUT /me` (đổi SĐT) | mọi vai trò |
| Tổng quan | `GET /dashboard`, `GET /notifications`, `GET /lookups`, `GET /settings` | mọi vai trò |
| Nhân viên | `GET /employees` (lọc: `q`, `department_id`, `status`, `gender`, `from`, `to`; `sort`, `dir`, `page`, `per`), `GET /employees/export` (CSV), `GET /employees/{id}`, `GET /employees/{id}/profile` | admin, manager (manager chỉ thấy phòng mình) |
| | `POST /employees`, `PUT /employees/{id}`, `DELETE /employees/{id}`, `POST /employees/{id}/toggle-lock`, `GET /employees/next-id` | admin |
| | `POST /employees/{id}/reviews` | admin, manager |
| | Body `POST /employees` có thêm (tùy chọn): `create_account`, `username`, `hire_date`, `contract_type`, `contract_end` để tạo luôn tài khoản và hợp đồng liên kết | admin |
| Phòng ban, chức vụ | `GET/POST /departments`, `PUT/DELETE /departments/{id}`; `GET/POST /positions`, `PUT/DELETE /positions/{id}` | admin |
| Chấm công | `GET /attendance?date=&status=`, `POST /attendance/check-in`, `POST /attendance/check-out` | mọi vai trò (employee chỉ thấy mình, manager thấy phòng mình) |
| | `PUT /attendance/{mã_nhân_viên}` | admin, manager |
| Nghỉ phép | `GET /leave`, `POST /leave`, `POST /leave/{id}/cancel` | admin (xem và duyệt tất cả), manager (xem và duyệt đơn của phòng mình, không tự duyệt đơn của mình), employee (gửi/hủy đơn của mình) |
| | `POST /leave/{id}/approve`, `POST /leave/{id}/reject` | admin, manager (không tự duyệt đơn của mình) |
| Lương | `GET /payroll?period=YYYY-MM` | admin (tất cả), manager và employee (chỉ lương của chính mình) |
| | `PUT /payroll/{mã_nhân_viên}` (thưởng, khấu trừ) | admin |
| Hợp đồng, báo cáo | `GET /contracts`, `GET /reports` | admin |
| | `POST /contracts/{id}/renew` (body: `end_date`, tùy chọn `type`, `note`), `GET /contracts/{id}/renewals` (lịch sử gia hạn) | admin |
| Tài khoản | `GET/POST /users`, `PUT /users/{id}`, `POST /users/{id}/toggle-lock`, `POST /users/{id}/reset-password`, `DELETE /users/{id}` | admin |
| Quản trị | `GET /audit`, `PUT /settings` | admin |

Ví dụ gọi thử bằng curl:

```bash
curl -c cj.txt -H "X-Requested-With: XMLHttpRequest" -H "Content-Type: application/json" \
     -d '{"username":"admin","password":"admin123"}' http://localhost/quan-tri-nhan-vien/api/auth/login
curl -b cj.txt "http://localhost/quan-tri-nhan-vien/api/employees?page=1&per=5"
```

## Xử lý sự cố

- **Giao diện báo "Máy chủ trả về lỗi 404"**: Apache chưa áp dụng `.htaccess`. Mở `assets/app.js`, đổi `API_BASE = 'api'` thành `API_BASE = 'api/index.php'`.
- **Lỗi kết nối CSDL**: kiểm tra MySQL đã Start, đã import `schema.sql`, và `api/config.php` đúng user/mật khẩu. Chi tiết lỗi PHP nằm trong `C:\xampp\php\logs\php_error_log`.
- **Chạy trên PHP cũ hơn 8.1**: không hỗ trợ (mã dùng kiểu trả về `never`).

## Khác biệt so với bản giả lập

- Mật khẩu được băm bằng bcrypt; mật khẩu tạm khi tạo/reset tài khoản do máy chủ sinh ngẫu nhiên và chỉ hiện một lần.
- Phân quyền được kiểm tra ở máy chủ, không chỉ ẩn nút trên giao diện. Ví dụ employee chỉ đọc được chấm công, nghỉ phép và lương của chính mình.
- Chấm công thật: check-in/check-out theo giờ máy chủ, đi muộn nếu sau 08:30; nhân viên chưa chấm công hiện "Chưa chấm công" thay vì dữ liệu mẫu.
- Gửi đơn nghỉ phép có hộp thoại chọn loại nghỉ, từ ngày, đến ngày; số ngày tính theo ngày làm việc T2–T6 và chặn đơn trùng khoảng ngày.
- Trang Lương có chọn kỳ lương (tháng); thưởng và khấu trừ lưu theo từng kỳ.
- Tìm kiếm, lọc, sắp xếp và phân trang danh sách nhân viên chạy trên máy chủ; xuất CSV theo đúng bộ lọc đang chọn.
- Thông báo và số trên chuông lấy từ dữ liệu thật (đơn chờ duyệt, hợp đồng sắp hết hạn trong 60 ngày, thông báo chung do admin nhập ở Cài đặt).
- Giới tính của dữ liệu mẫu được sửa cho khớp với tên.
- Nút "Quên mật khẩu?" chỉ nhắc liên hệ Admin, vì chưa có chức năng gửi email.

## Trước khi đưa lên môi trường thật

- Đổi mật khẩu các tài khoản demo và xóa dòng điền sẵn `admin/admin123` trong `index.html`.
- Đặt `APP_DEBUG = false` trong `api/config.php`; tạo user MySQL riêng thay vì `root` không mật khẩu.
- Dùng HTTPS và thêm `'secure' => true` vào cookie phiên trong `api/lib.php`.
