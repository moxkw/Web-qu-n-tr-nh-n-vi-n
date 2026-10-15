-- =====================================================================
-- Tạo hợp đồng cho MỌI nhân viên chưa có hợp đồng (dùng cho CSDL đã có dữ liệu).
--   * Mã hợp đồng tự tăng tiếp theo HD hiện có (HD015, HD016...)
--   * Loại: Có thời hạn, bắt đầu = ngày vào làm, kết thúc = 1 năm kể từ hôm nay
--     (muốn đổi loại/thời hạn thì dùng chức năng Gia hạn ở menu Hợp đồng)
-- Chạy được nhiều lần: nhân viên đã có hợp đồng sẽ được bỏ qua.
-- =====================================================================
SET @n := (SELECT COALESCE(MAX(CAST(SUBSTRING(id, 3) AS UNSIGNED)), 0) FROM contracts WHERE id REGEXP '^HD[0-9]+$');

INSERT INTO contracts (id, employee_id, type, start_date, end_date)
SELECT CONCAT('HD', LPAD(@n := @n + 1, 3, '0')), e.id, 'Có thời hạn', e.hire_date, DATE_ADD(CURDATE(), INTERVAL 1 YEAR)
FROM employees e
WHERE NOT EXISTS (SELECT 1 FROM contracts c WHERE c.employee_id = e.id)
ORDER BY e.id;

-- Kiểm tra: nhân viên nào vẫn chưa có hợp đồng (kết quả nên rỗng)
SELECT e.id, e.name FROM employees e
WHERE NOT EXISTS (SELECT 1 FROM contracts c WHERE c.employee_id = e.id);
