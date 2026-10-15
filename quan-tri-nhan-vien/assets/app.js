'use strict';

/* =============== Cấu hình =============== */
const API_BASE = 'api';   // Nếu Apache chưa bật mod_rewrite, đổi thành 'api/index.php'

/* =============== Tiện ích =============== */
const $ = s => document.querySelector(s);
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const enc = encodeURIComponent;
const money = n => new Intl.NumberFormat('vi-VN').format(Math.round(n)) + ' đ';
const ini = n => n.split(' ').slice(-2).map(w => w[0]).join('');
const debounce = (f, ms) => { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => f(...a), ms); }; };
const today = () => { const d = new Date(); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); };
const tag = (t, c) => `<span class="tag ${c}">${esc(t)}</span>`;
// Các ô trong tbl() là HTML: phần dữ liệu người dùng phải được esc() trước khi truyền vào.
const tbl = (h, r) => `<div class="tw card" style="padding:0"><table><tr>${h.map(x => `<th>${x}</th>`).join('')}</tr>${r.length ? r.map(x => `<tr>${x.map(c => `<td>${c}</td>`).join('')}</tr>`).join('') : `<tr><td colspan="${h.length}" style="color:var(--mute)">Chưa có dữ liệu.</td></tr>`}</table></div>`;
function toast(m) { const t = $('#toast'); t.textContent = m; t.className = 'show'; clearTimeout(t._t); t._t = setTimeout(() => t.className = '', 2600); }

/* =============== Gọi API =============== */
async function http(method, url, data) {
  let res;
  try {
    res = await fetch(API_BASE + url, {
      method, credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest', ...(data !== undefined ? { 'Content-Type': 'application/json' } : {}) },
      body: data !== undefined ? JSON.stringify(data) : undefined,
    });
  } catch { throw { code: 0, msg: 'Không thể kết nối tới máy chủ' }; }
  let j = null;
  try { j = await res.json(); } catch { /* phản hồi không phải JSON */ }
  if (res.status === 401 && ME) { showLogin('Phiên đăng nhập đã hết hạn, vui lòng đăng nhập lại'); throw { code: 401, msg: 'Phiên đăng nhập đã hết hạn' }; }
  if (!res.ok) throw { code: res.status, msg: (j && j.error) || 'Máy chủ trả về lỗi ' + res.status };
  return j;
}
const api = {
  get: u => http('GET', u),
  post: (u, d = {}) => http('POST', u, d),
  put: (u, d = {}) => http('PUT', u, d),
  del: u => http('DELETE', u),
};
const qs = o => {
  const p = new URLSearchParams();
  Object.entries(o).forEach(([k, v]) => { if (v !== '' && v != null) p.set(k, v); });
  const s = p.toString();
  return s ? '?' + s : '';
};

/* =============== Trạng thái & phân quyền menu (server vẫn kiểm tra lại) =============== */
const PAGES = { dashboard: 'Tổng quan', employees: 'Nhân viên', departments: 'Phòng ban', positions: 'Chức vụ', attendance: 'Chấm công', leave: 'Nghỉ phép', payroll: 'Lương', contracts: 'Hợp đồng', reports: 'Báo cáo', users: 'Tài khoản', audit: 'Nhật ký', settings: 'Cài đặt' };
const ACCESS = {
  admin: Object.keys(PAGES),
  manager: ['dashboard', 'employees', 'attendance', 'leave', 'payroll', 'settings'],
  employee: ['dashboard', 'attendance', 'leave', 'payroll', 'settings'],
};
const ROLES = ['admin', 'manager', 'employee'];
let ME = null, LOOK = null, EDIT = null, PROF = null;
const S = { q: '', dep: '', st: '', g: '', d1: '', d2: '', pg: 1, sort: 'id', dir: 'asc', as: '', per: '' };
const ROLE_VN = { admin: 'Quản trị viên', manager: 'Quản lý', employee: 'Nhân viên' };
const canManage = () => ME && ME.role == 'admin';
const lookups = async force => (LOOK = (!force && LOOK) || await api.get('/lookups'));

/* =============== Điều khiển sự kiện bằng data-act / data-chg (không dùng onclick inline) =============== */
const ACT = {}, CHG = {};
async function run(fn) {
  try { await fn(); } catch (e) { if (!e || e.code !== 401) toast((e && e.msg) || 'Có lỗi xảy ra'); }
}
document.addEventListener('click', e => {
  const el = e.target.closest('[data-act]');
  if (!el) return;
  e.preventDefault();
  const f = ACT[el.dataset.act];
  if (f) run(() => f(el.dataset, el));
});
document.addEventListener('change', e => {
  const el = e.target.closest('[data-chg]');
  if (el && CHG[el.dataset.chg]) run(() => CHG[el.dataset.chg](el.dataset, el));
});
document.addEventListener('keydown', e => {
  if (e.key === 'Enter' && (e.target.id === 'u' || e.target.id === 'p')) ACT.login();
});

/* =============== Đăng nhập / điều hướng =============== */
function showLogin(msg) {
  ME = null;
  $('#app').style.display = 'none';
  $('#login').style.display = 'block';
  $('#le').textContent = msg || '';
}
function enter(user) {
  ME = user;
  $('#login').style.display = 'none';
  $('#app').style.display = 'block';
  go('dashboard');
}
ACT.login = async () => {
  const u = $('#u').value.trim(), p = $('#p').value;
  $('#le').textContent = '';
  if (!u || !p) { $('#le').textContent = 'Nhập đầy đủ tài khoản và mật khẩu'; return; }
  try { enter(await api.post('/auth/login', { username: u, password: p, remember: $('#rm').checked })); }
  catch (e) { $('#le').textContent = e.msg; }
};
ACT.logout = async () => { try { await api.post('/auth/logout'); } finally { showLogin(); } };
ACT.forgot = () => toast('Hãy liên hệ Admin để được đặt lại mật khẩu');
ACT.burger = () => $('#nav').classList.toggle('open');
ACT.closeDlg = d => $('#' + d.d).close();
ACT.go = d => go(d.p);

async function go(p) {
  S.cur = p;
  $('#nav').classList.remove('open');
  $('#nav').innerHTML = `<b>Nhân sự: ${esc(ME.role)}</b>` + Object.entries(PAGES).filter(([k]) => ACCESS[ME.role].includes(k))
    .map(([k, v]) => `<a href="#" class="${k == p ? 'on' : ''}" data-act="go" data-p="${k}">${v}</a>`).join('');
  $('#title').textContent = PAGES[p];
  $('#view').innerHTML = '<div class="card">Đang tải…</div>';
  try { await R[p](); }
  catch (e) { if (e.code !== 401) $('#view').innerHTML = `<div class="card" style="color:var(--bad)">${esc(e.msg || 'Không tải được dữ liệu')}</div>`; }
  api.get('/notifications').then(n => { $('#bell').textContent = '🔔 ' + n.items.length; }).catch(() => {});
}


/* =============== Bảng dùng chung: tìm kiếm, sắp xếp theo cột, xuất CSV (theo đúng dữ liệu đang hiển thị) =============== */
const FILES = { departments: 'phong-ban', positions: 'chuc-vu', attendance: 'cham-cong', leave: 'nghi-phep', payroll: 'luong', contracts: 'hop-dong', users: 'tai-khoan', audit: 'nhat-ky' };
let GRID = null;
const cellText = h => (new DOMParser().parseFromString(`<body>${h}</body>`, 'text/html')).body.textContent.trim().replace(/\s+/g, ' ');
const cellSel = h => { const d = new DOMParser().parseFromString(`<body>${h}</body>`, 'text/html'); d.querySelectorAll('select').forEach(s => s.replaceWith(s.options[s.selectedIndex] ? s.options[s.selectedIndex].text : '')); return d.body.textContent.trim().replace(/\s+/g, ' '); };
const nrm = s => String(s).normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/đ/g, 'd').replace(/Đ/g, 'D').toLowerCase();
function skey(t) {
  let m = t.match(/^(\d{2})\/(\d{2})\/(\d{4})(.*)$/); if (m) return +(m[3] + m[2] + m[1]);
  m = t.match(/^(-?[\d.,]+)\s*(đ|triệu|lần|%|ngày)?$/);
  if (m) return parseFloat(m[2] == 'đ' ? m[1].replace(/\./g, '') : m[1].replace(',', '.'));
  return t;
}
const gcmp = (a, b) => { const x = skey(a), y = skey(b), nx = typeof x == 'number', ny = typeof y == 'number';
  if (nx && ny) return x - y; if (nx != ny) return nx ? -1 : 1; return String(x).localeCompare(String(y), 'vi'); };
function grid(h, r) {
  GRID = { h, rows: r, txt: r.map(x => x.map(cellSel)), q: '', sc: -1, dir: 1, file: FILES[S.cur] || 'du-lieu' };
  queueMicrotask(gdraw);   // vẽ dữ liệu ngay sau khi bảng được gắn vào trang (không cần click)
  return `<div class="tools"><input class="gq" type="search" placeholder="Tìm kiếm trong bảng…" aria-label="Tìm kiếm trong bảng"><span id="gc" style="color:var(--mute);align-self:center"></span><span style="flex:1"></span><button class="btn ghost" data-act="gcsv">Xuất CSV</button></div>` +
    `<div class="tw card" style="padding:0"><table><tr>${h.map((x, i) => x ? `<th data-act="gsort" data-c="${i}">${x}<i class="gar"></i></th>` : '<th></th>').join('')}</tr><tbody id="gb"></tbody></table></div>`;
}
function gview() {
  const G = GRID, q = nrm(G.q.trim());
  let ix = G.rows.map((_, i) => i).filter(i => !q || G.txt[i].some((t, c) => G.h[c] && nrm(t).includes(q)));
  if (G.sc >= 0) ix.sort((a, b) => G.dir * gcmp(G.txt[a][G.sc], G.txt[b][G.sc]));
  return ix;
}
function gdraw() {
  const G = GRID; if (!G || !$('#gb')) return;
  const ix = gview();
  $('#gb').innerHTML = ix.length ? ix.map(i => `<tr>${G.rows[i].map(c => `<td>${c}</td>`).join('')}</tr>`).join('') : `<tr><td colspan="${G.h.length}" style="color:var(--mute)">${G.rows.length ? 'Không tìm thấy kết quả phù hợp.' : 'Chưa có dữ liệu.'}</td></tr>`;
  $('#gc').textContent = ix.length + ' / ' + G.rows.length + ' dòng';
  document.querySelectorAll('#view th[data-c]').forEach(th => { th.querySelector('.gar').textContent = +th.dataset.c == G.sc ? (G.dir > 0 ? ' ▲' : ' ▼') : ''; });
}
const csvCell = v => { v = String(v ?? ''); if (/^[=+\-@\t\r]/.test(v) && !/^-?[\d.,]+$/.test(v)) v = "'" + v; return /[",\r\n]/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v; };
function csvDownload(rows, name) {
  const blob = new Blob(['\ufeff' + rows.map(r => r.map(csvCell).join(',')).join('\r\n')], { type: 'text/csv;charset=utf-8' });
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = name + '-' + new Date().toISOString().slice(0, 10) + '.csv';
  document.body.appendChild(a); a.click(); a.remove();
  setTimeout(() => URL.revokeObjectURL(a.href), 1000);
}
document.addEventListener('input', e => { if (e.target.classList.contains('gq') && GRID) { GRID.q = e.target.value; gdraw(); } });
ACT.gsort = d => { const c = +d.c; if (GRID.sc == c) GRID.dir = -GRID.dir; else { GRID.sc = c; GRID.dir = 1; } gdraw(); };
ACT.gcsv = () => {
  const G = GRID, cols = G.h.map((x, i) => x ? i : -1).filter(i => i >= 0), ix = gview();
  csvDownload([cols.map(i => cellText(G.h[i])), ...ix.map(i => cols.map(c => G.txt[i][c]))], G.file + (S.cur == 'payroll' && S.per ? '-' + S.per : ''));
  toast('Đã xuất ' + ix.length + ' dòng ra file CSV');
};
ACT.repCsv = async () => {
  const d = await api.get('/reports');
  csvDownload([['Chỉ số', 'Giá trị'], ['Tổng nhân viên', d.total], ['Đã khóa / nghỉ', d.inactive], ['Tỷ lệ đi làm hôm nay (%)', d.attendance_rate ?? ''], ['Tỷ lệ nghỉ phép (%)', d.leave_rate], ['Quỹ lương (đ)', Math.round(d.fund)],
    [], ['Theo chức vụ', 'Số người'], ...d.by_position.map(x => [x.name, x.count]), [], ['Theo phòng ban', 'Số người'], ...d.by_department.map(x => [x.name, x.count])], 'bao-cao');
  toast('Đã xuất báo cáo ra file CSV');
};

/* =============== Các trang =============== */
const R = {
  async dashboard() {
    const d = await api.get('/dashboard');
    const mx = Math.max(1, ...d.by_department.map(x => x.count));
    $('#view').innerHTML = `<div class="grid">${[[d.total, 'Tổng nhân viên'], [d.working, 'Đang làm việc'], [d.pending_leave, 'Đơn nghỉ chờ duyệt'], [d.departments, 'Phòng ban']].filter(k => k[0] != null)
      .map(k => `<div class="card kpi"><div class="n">${k[0]}</div><div class="l">${k[1]}</div></div>`).join('')}</div>
    <div class="two"><div class="card"><b>Nhân viên theo phòng ban</b>${d.by_department.map(x => `<div class="bar"><span>${esc(x.name)}</span><i style="width:${x.count / mx * 100}%"></i><span style="width:auto">${x.count}</span></div>`).join('')}</div>
    <div class="card"><b>Quỹ lương tháng</b><div class="kpi"><div class="n" style="font-size:22px">${d.fund == null ? '—' : money(d.fund)}</div></div><hr style="border:0;border-top:1px solid var(--line)"><b>Giới tính</b><div style="color:var(--mute)">Nam ${d.male} · Nữ ${d.female}</div></div></div>`;
  },

  async employees() {
    const L = await lookups(true);
    Object.assign(S, { q: '', dep: '', st: '', g: '', d1: '', d2: '', pg: 1, sort: 'id', dir: 'asc' });
    $('#view').innerHTML = `<div class="tools">
      <input id="sq" placeholder="Tìm tên, email, mã…" aria-label="Tìm kiếm">
      ${ME.role == 'admin' ? `<select data-chg="empf" data-k="dep" aria-label="Phòng ban"><option value="">Tất cả phòng ban</option>${L.departments.map(d => `<option value="${d.id}">${esc(d.name)}</option>`).join('')}</select>` : ''}
      <select data-chg="empf" data-k="st" aria-label="Trạng thái"><option value="">Mọi trạng thái</option><option>Đang làm</option><option>Đã khóa</option></select>
      <select data-chg="empf" data-k="g" aria-label="Giới tính"><option value="">Mọi giới tính</option><option>Nam</option><option>Nữ</option></select>
      <input type="date" style="max-width:150px" data-chg="empf" data-k="d1" aria-label="Vào làm từ ngày">
      <input type="date" style="max-width:150px" data-chg="empf" data-k="d2" aria-label="Vào làm đến ngày">
      <span style="flex:1"></span><button class="btn ghost" data-act="exportEmp">Xuất CSV</button>${canManage() ? '<button class="btn" data-act="addEmp">Thêm nhân viên</button>' : ''}
    </div><div id="empList"></div>`;
    $('#sq').oninput = debounce(e => { S.q = e.target.value; S.pg = 1; run(loadEmp); }, 250);
    await loadEmp();
  },

  async departments() {
    const rows = await api.get('/departments');
    $('#view').innerHTML = `<div class="tools"><button class="btn" data-act="depAdd">Thêm phòng ban</button></div>` +
      grid(['Phòng ban', 'Số nhân viên', 'Trưởng phòng', 'Trạng thái', ''], rows.map(d => [esc(d.name), d.employees, esc(d.head || '—'), tag('Hoạt động', 't-ok'),
        `<button class="btn sm ghost" data-act="depEdit" data-id="${d.id}" data-name="${esc(d.name)}">Sửa</button> <button class="btn sm bad" data-act="depDel" data-id="${d.id}">Xóa</button>`]));
  },

  async positions() {
    const rows = await api.get('/positions');
    $('#view').innerHTML = `<div class="tools"><button class="btn" data-act="posAdd">Thêm chức vụ</button></div>` +
      grid(['Chức vụ', 'Cấp bậc', 'Lương tối thiểu', 'Lương tối đa', 'Số người', 'Trạng thái', ''], rows.map(p => [esc(p.name), p.level, p.min + ' triệu', p.max + ' triệu', p.employees, tag('Hoạt động', 't-ok'),
        `<button class="btn sm ghost" data-act="posEdit" data-id="${p.id}" data-min="${p.min}" data-max="${p.max}">Sửa</button> <button class="btn sm bad" data-act="posDel" data-id="${p.id}">Xóa</button>`]));
  },

  async attendance() {
    const r = await api.get('/attendance' + qs({ status: S.as }));
    const c = { 'Có mặt': 't-ok', 'Đi muộn': 't-wait', 'Vắng': 't-bad', 'Chưa chấm công': 't-mute' };
    const dmy = r.date.split('-').reverse().join('/');
    $('#view').innerHTML = `<div class="tools"><select data-chg="attf" aria-label="Trạng thái"><option value="">Mọi trạng thái</option>${Object.keys(c).map(k => `<option ${S.as == k ? 'selected' : ''}>${k}</option>`).join('')}</select><span style="flex:1"></span>${ME.employee_id || ME.role == 'admin' ? '<button class="btn ghost" data-act="punch" data-k="out">Check-out</button><button class="btn" data-act="punch" data-k="in">Check-in</button>' : ''}</div>` +
      grid(['Mã NV', 'Nhân viên', 'Ngày', 'Vào', 'Ra', 'Số giờ', 'Trạng thái', ''], r.items.map(a => [esc(a.employee_id), esc(a.name), dmy, a.check_in || '—', a.check_out || '—', a.hours == null ? '—' : a.hours.toFixed(1), tag(a.status, c[a.status]),
        ME.role == 'employee' || a.is_admin ? '' : `<button class="btn sm ghost" data-act="attEdit" data-id="${esc(a.employee_id)}" data-in="${a.check_in || '—'}" data-out="${a.check_out || '—'}">Sửa</button>`]));
  },

  async leave() {
    const L = await api.get('/leave');
    const c = { 'Chờ duyệt': 't-wait', 'Đã duyệt': 't-ok', 'Từ chối': 't-bad', 'Đã hủy': 't-mute' };
    $('#view').innerHTML = `<div class="tools">${ME.employee_id ? '<button class="btn" data-act="newLeave">Gửi đơn nghỉ phép</button>' : ''}</div>` +
      grid(['Mã NV', 'Nhân viên', 'Loại nghỉ', 'Từ', 'Đến', 'Số ngày', 'Lý do', 'Trạng thái', ''], L.map(l => [esc(l.employee_id), esc(l.employee_name), esc(l.type), l.from_date, l.to_date, l.days, esc(l.reason), tag(l.status, c[l.status]),
        l.can_decide ? `<button class="btn sm" data-act="leaveDo" data-op="approve" data-id="${l.id}">Duyệt</button> <button class="btn sm ghost" data-act="leaveDo" data-op="reject" data-id="${l.id}">Từ chối</button>`
          : l.can_cancel ? `<button class="btn sm ghost" data-act="leaveDo" data-op="cancel" data-id="${l.id}">Hủy đơn</button>` : '']));
  },

  async payroll() {
    const r = await api.get('/payroll' + qs({ period: S.per }));
    S.per = r.period;
    const fmt = n => String(n).replace('.', ',');
    $('#view').innerHTML = `<div class="card" style="margin-bottom:12px;color:var(--mute)">Thực nhận = lương cơ bản + phụ cấp + thưởng − khấu trừ − thuế − bảo hiểm (bảo hiểm ${fmt(r.ins_rate)}%, thuế ${fmt(r.tax_rate)}% tính trên lương cơ bản)</div>
      <div class="tools"><input type="month" value="${esc(r.period)}" data-chg="period" aria-label="Kỳ lương"></div>` +
      grid(['Mã NV', 'Nhân viên', 'Lương cơ bản', 'Phụ cấp', 'Thưởng', 'Khấu trừ', 'Thuế', 'Bảo hiểm', 'Thực nhận', ''], r.items.map(x => [esc(x.employee_id), esc(x.name), money(x.base), money(x.allowance), money(x.bonus), money(x.deduction), money(x.tax), money(x.insurance), `<b>${money(x.net)}</b>`,
        r.can_edit ? `<button class="btn sm ghost" data-act="payAdj" data-id="${esc(x.employee_id)}" data-bo="${x.bonus}" data-de="${x.deduction}">Chỉnh</button>` : '']));
  },

  async contracts() {
    const rows = await api.get('/contracts');
    const c = { 'Còn hiệu lực': 't-ok', 'Sắp hết hạn': 't-bad', 'Hết hạn': 't-mute' };
    $('#view').innerHTML = grid(['Mã HĐ', 'Mã NV', 'Nhân viên', 'Loại', 'Bắt đầu', 'Kết thúc', 'Lương', 'Trạng thái', 'Gia hạn', ''], rows.map(x => [esc(x.id), esc(x.employee_id), esc(x.employee_name), esc(x.type), x.start_date, x.end_date || '—', money(x.salary), tag(x.status, c[x.status]), x.renewals ? x.renewals + ' lần' : '—',
      (x.type == 'Không thời hạn' ? '' : `<button class="btn sm" data-act="ctRenew" data-id="${esc(x.id)}" data-end="${x.end_date || ''}" data-start="${x.start_date}">Gia hạn</button> `) +
      (x.renewals ? `<button class="btn sm ghost" data-act="ctHist" data-id="${esc(x.id)}">Lịch sử</button>` : '')]));
  },

  async users() {
    const rows = await api.get('/users');
    $('#view').innerHTML = `<div class="tools"><button class="btn" data-act="uNew">Tạo tài khoản</button></div>` +
      grid(['Username', 'Email', 'Quyền', 'Nhân viên', 'Trạng thái', 'Đăng nhập cuối', ''], rows.map(a => [esc(a.username), esc(a.email),
        `<select data-chg="role" data-id="${a.id}" style="width:auto" aria-label="Quyền">${ROLES.map(r => `<option ${r == a.role ? 'selected' : ''}>${r}</option>`).join('')}</select>`,
        esc(a.employee_id || '—'), tag(a.status, a.status == 'Hoạt động' ? 't-ok' : 't-mute'), a.last_login || '—',
        `<button class="btn sm ghost" data-act="uLock" data-id="${a.id}">${a.status == 'Hoạt động' ? 'Khóa' : 'Mở khóa'}</button> <button class="btn sm ghost" data-act="uReset" data-id="${a.id}">Reset mật khẩu</button> <button class="btn sm bad" data-act="uDel" data-id="${a.id}">Xóa</button>`]));
  },

  async reports() {
    const d = await api.get('/reports');
    const bar = (a, m) => a.map(x => `<div class="bar"><span>${esc(x.name)}</span><i style="width:${x.count / m * 100}%"></i><span style="width:auto">${x.count}</span></div>`).join('');
    const mx = a => Math.max(1, ...a.map(x => x.count));
    $('#view').innerHTML = `<div class="tools"><button class="btn ghost" data-act="exportEmp" data-all="1">Xuất DS nhân viên (CSV)</button><button class="btn ghost" data-act="repCsv">Xuất thống kê (CSV)</button><button class="btn" data-act="print">In báo cáo</button></div>
      <div class="grid">${[[d.total, 'Tổng nhân viên'], [d.inactive, 'Đã khóa / nghỉ'], [d.attendance_rate == null ? '—' : d.attendance_rate + '%', 'Tỷ lệ đi làm hôm nay'], [d.leave_rate + '%', 'Tỷ lệ nghỉ phép'], [money(d.fund), 'Quỹ lương']]
      .map(k => `<div class="card kpi"><div class="n" style="font-size:22px">${k[0]}</div><div class="l">${k[1]}</div></div>`).join('')}</div>
      <div class="two" style="grid-template-columns:1fr 1fr"><div class="card"><b>Theo chức vụ</b>${bar(d.by_position, mx(d.by_position))}</div><div class="card"><b>Theo phòng ban</b>${bar(d.by_department, mx(d.by_department))}</div></div>`;
  },

  async audit() {
    const rows = await api.get('/audit');
    $('#view').innerHTML = grid(['Thời gian', 'Người dùng', 'Hành động', 'Đối tượng', 'IP', 'Mô tả'], rows.map(a => [a.time, esc(a.user), esc(a.action), esc(a.target), esc(a.ip), esc(a.description)]));
  },

  async settings() {
    const cfg = await api.get('/settings');
    const ro = (id, label, v) => `<label for="${id}">${label}</label><input id="${id}" class="ro" value="${esc(v || '—')}" readonly aria-readonly="true">`;
    $('#view').innerHTML = `<div class="two" style="grid-template-columns:1fr 1fr">${ME.role == 'admin' ? `<div class="card"><b>Cấu hình hệ thống</b>
      <label for="c_t">Thuế (%)</label><input id="c_t" type="number" min="0" max="100" step="0.1" value="${cfg.tax}">
      <label for="c_i">Bảo hiểm (%)</label><input id="c_i" type="number" min="0" max="100" step="0.1" value="${cfg.ins}">
      <label for="c_a">Thông báo chung</label><input id="c_a" maxlength="255" value="${esc(cfg.announcement)}"><br><br>
      <button class="btn" data-act="saveCfg">Lưu cấu hình</button></div>` : ''}
      <div class="card"><b>Thông tin cá nhân</b>
        ${ME.employee_id ? ro('i_n', 'Họ tên', ME.employee_name) + ro('i_c', 'Mã nhân viên', ME.employee_id) : ro('i_n', 'Tên tài khoản', ME.username)}
        ${ro('i_e', 'Email', ME.email)}${ro('i_r', 'Quyền', ROLE_VN[ME.role] || ME.role)}
        ${ME.employee_id ? `<label for="mp">Số điện thoại</label><input id="mp" value="${esc(ME.phone || '')}" inputmode="tel" maxlength="15"><br><br><button class="btn" data-act="myPhone">Cập nhật số điện thoại</button>`
          : '<p style="color:var(--mute);margin:12px 0 0">Tài khoản quản trị không gắn với hồ sơ nhân viên nên không có số điện thoại cá nhân.</p>'}</div>
      <div class="card"><b>Đổi mật khẩu</b><label for="o">Mật khẩu hiện tại</label><input id="o" type="password" autocomplete="current-password"><label for="n">Mật khẩu mới</label><input id="n" type="password" autocomplete="new-password"><label for="n2">Nhập lại mật khẩu mới</label><input id="n2" type="password" autocomplete="new-password"><div class="err" id="pe"></div><button class="btn" data-act="chPw">Đổi mật khẩu</button></div></div>`;
  },
};

/* =============== Nhân viên: danh sách, thêm/sửa, chi tiết =============== */
async function loadEmp() {
  const r = await api.get('/employees' + qs({ q: S.q, department_id: S.dep, status: S.st, gender: S.g, from: S.d1, to: S.d2, sort: S.sort, dir: S.dir, page: S.pg, per: 8 }));
  S.pg = r.page;
  const edit = canManage();
  const cols = [['id', 'Mã'], ['name', 'Họ tên'], ['email', 'Email'], ['phone', 'Điện thoại'], ['department', 'Phòng ban'], ['position', 'Chức vụ'], ['hire_date', 'Ngày vào'], ['status', 'Trạng thái']];
  const rows = r.items.map(e => `<tr><td>${esc(e.id)}</td><td><span class="av">${esc(ini(e.name))}</span><a href="#" data-act="detail" data-id="${esc(e.id)}" style="color:var(--pri)">${esc(e.name)}</a></td><td>${esc(e.email)}</td><td>${esc(e.phone)}</td><td>${esc(e.department)}</td><td>${esc(e.position)}</td><td>${e.hire_date}</td><td>${tag(e.status, e.status == 'Đang làm' ? 't-ok' : 't-mute')}</td><td>${edit ? `<button class="btn sm ghost" data-act="editEmp" data-id="${esc(e.id)}">Sửa</button> <button class="btn sm ghost" data-act="lock" data-id="${esc(e.id)}">${e.status == 'Đã khóa' ? 'Mở khóa' : 'Khóa'}</button> <button class="btn sm bad" data-act="del" data-id="${esc(e.id)}">Xóa</button>` : ''}</td></tr>`).join('');
  $('#empList').innerHTML = `<div class="tw card" style="padding:0"><table><tr>${cols.map(([k, v]) => `<th data-act="sort" data-k="${k}">${v}${S.sort == k ? (S.dir == 'asc' ? ' ↑' : ' ↓') : ''}</th>`).join('')}<th></th></tr>
    ${rows || '<tr><td colspan="9" style="color:var(--mute)">Không có nhân viên khớp bộ lọc. Hãy bỏ bớt bộ lọc hoặc thêm nhân viên mới.</td></tr>'}</table></div>
    <div class="pg"><button class="btn ghost sm" ${r.page < 2 ? 'disabled' : ''} data-act="page" data-d="-1">Trước</button>Trang ${r.page}/${r.pages} (${r.total} kết quả)<button class="btn ghost sm" ${r.page >= r.pages ? 'disabled' : ''} data-act="page" data-d="1">Sau</button></div>`;
}
CHG.empf = (d, el) => { S[d.k] = el.value; S.pg = 1; return loadEmp(); };
ACT.sort = d => { if (S.sort == d.k) S.dir = S.dir == 'asc' ? 'desc' : 'asc'; else { S.sort = d.k; S.dir = 'asc'; } return loadEmp(); };
ACT.page = d => { S.pg += +d.d; return loadEmp(); };
ACT.exportEmp = d => {
  const f = d.all ? {} : { q: S.q, department_id: S.dep, status: S.st, gender: S.g, from: S.d1, to: S.d2, sort: S.sort, dir: S.dir };
  location.href = API_BASE + '/employees/export' + qs(f);
};
ACT.lock = async d => { await api.post(`/employees/${enc(d.id)}/toggle-lock`); toast('Đã cập nhật trạng thái'); return loadEmp(); };
ACT.del = async d => {
  if (!confirm('Xóa nhân viên ' + d.id + '?')) return;
  await api.del(`/employees/${enc(d.id)}`);
  toast('Đã xóa nhân viên');
  return loadEmp();
};

async function openForm(id) {
  const L = await lookups();
  EDIT = id || null;
  $('#dt').textContent = id ? 'Sửa nhân viên' : 'Thêm nhân viên';
  $('#f_dep').innerHTML = L.departments.map(d => `<option value="${d.id}">${esc(d.name)}</option>`).join('');
  $('#f_pos').innerHTML = L.positions.map(p => `<option value="${p.id}">${esc(p.name)}</option>`).join('');
  $('#f_id').disabled = !!id;
  if (id) {
    const e = await api.get(`/employees/${enc(id)}`);
    $('#f_id').value = e.id; $('#f_name').value = e.name; $('#f_email').value = e.email; $('#f_phone').value = e.phone;
    $('#f_dep').value = e.department_id; $('#f_pos').value = e.position_id; $('#f_g').value = e.gender; $('#f_sal').value = e.salary;
  } else {
    $('#f_id').value = (await api.get('/employees/next-id')).id;
    ['name', 'email', 'phone'].forEach(k => $('#f_' + k).value = '');
    $('#f_g').value = 'Nam'; $('#f_sal').value = 15;
  }
  $('#f_acc').checked = false;
  $('#f_acc_row').style.display = id ? 'none' : 'flex';   // chỉ hiện khi thêm mới
  ['hire', 'ctype', 'cend'].forEach(k => $('#f_' + k).parentElement.style.display = id ? 'none' : '');   // hợp đồng chỉ tạo khi thêm mới
  $('#f_ct_box').style.display = id ? 'none' : '';
  if (!id) {
    const t = new Date(); t.setMonth(t.getMonth() + 2);
    $('#f_hire').value = today(); $('#f_ctype').value = 'Thử việc'; $('#f_cend').disabled = false;
    $('#f_cend').value = t.getFullYear() + '-' + String(t.getMonth() + 1).padStart(2, '0') + '-' + String(t.getDate()).padStart(2, '0');
  }
  $('#fe').textContent = '';
  $('#dlg').showModal();
}
ACT.addEmp = () => openForm();
ACT.editEmp = d => openForm(d.id);
ACT.saveEmp = async () => {
  const v = k => $('#f_' + k).value.trim();
  const e = { id: v('id'), name: v('name'), email: v('email'), phone: v('phone'), department_id: +v('dep'), position_id: +v('pos'), gender: v('g'), salary: +v('sal') || 0 };
  let m = '';
  if (!e.id || !e.name || !e.email || !e.phone) m = 'Điền đủ các trường có dấu *';
  else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(e.email)) m = 'Email chưa đúng định dạng';
  else if (!/^(0|\+84)\d{9}$/.test(e.phone.replace(/[\s.]/g, ''))) m = 'Số điện thoại cần 10 số, bắt đầu bằng 0';
  if (!m && !EDIT && v('ctype') && v('ctype') != 'Không thời hạn' && !v('cend')) m = 'Chọn ngày kết thúc hợp đồng';
  if (m) { $('#fe').textContent = m; return; }
  try {
    let res = null;
    if (EDIT) await api.put(`/employees/${enc(EDIT)}`, e);
    else res = await api.post('/employees', { ...e, create_account: $('#f_acc').checked, hire_date: v('hire'), contract_type: v('ctype'), contract_end: v('cend') });
    $('#dlg').close();
    toast(EDIT ? 'Đã cập nhật nhân viên' : 'Thêm nhân viên thành công' + (res && res.contract ? ' (hợp đồng ' + res.contract.id + ')' : ''));
    if (res && res.account) alert('Đã tạo tài khoản \"' + res.account.username + '\" cho ' + e.name + '.\nMật khẩu tạm: ' + res.account.temp_password + '\n(Chỉ hiển thị một lần, hãy gửi cho nhân viên.)');
    await loadEmp();
  } catch (x) { $('#fe').textContent = x.msg || 'Không thể kết nối tới máy chủ'; }
};

ACT.detail = async d => {
  PROF = await api.get(`/employees/${enc(d.id)}/profile`);
  showTab('Cá nhân');
  $('#dd').showModal();
};
function showTab(k) {
  const p = PROF, e = p.employee;
  const T = {
    'Cá nhân': `<p>Họ tên: ${esc(e.name)}</p><p>Giới tính: ${esc(e.gender)}</p><p>Email: ${esc(e.email)}</p><p>Điện thoại: ${esc(e.phone)}</p>`,
    'Công việc': `<p>Mã: ${esc(e.id)}</p><p>Phòng ban: ${esc(e.department)}</p><p>Chức vụ: ${esc(e.position)}</p><p>Ngày vào làm: ${e.hire_date}</p><p>Trạng thái: ${esc(e.status)}</p>`,
    'Chấm công': p.attendance ? `<p>Hôm nay: ${p.attendance.check_in || '—'} – ${p.attendance.check_out || '—'} (${esc(p.attendance.status)})</p>` : '<p>Hôm nay chưa có dữ liệu chấm công.</p>',
    'Nghỉ phép': p.leave.map(l => `<p>${esc(l.type)}: ${l.from_date} → ${l.to_date}, ${l.days} ngày (${esc(l.status)})</p>`).join('') || '<p>Chưa có đơn nghỉ phép.</p>',
    ...(p.net_estimate == null ? {} : { 'Lương': `<p>Lương cơ bản: ${money(e.salary * 1e6)}</p><p>Thực nhận ước tính: ${money(p.net_estimate)}</p>` }),
    'Đánh giá': (p.reviews.map(r => `<p>${r.score}/5 — ${esc(r.comment)} (${esc(r.by_role)})</p>`).join('') || '<p>Chưa có đánh giá.</p>') + `<button class="btn sm" data-act="review" data-id="${esc(e.id)}">Thêm đánh giá</button>`,
  };
  $('#dd').innerHTML = '<h3 style="margin-top:0">' + esc(e.name) + '</h3><div class="tools">' + Object.keys(T).map(t => `<button class="btn sm ${t == k ? '' : 'ghost'}" data-act="tab" data-k="${t}">${t}</button>`).join('') + '</div>' + T[k] + '<br><button class="btn ghost" data-act="closeDlg" data-d="dd">Đóng</button>';
}
ACT.tab = d => showTab(d.k);
ACT.review = async d => {
  const s = +prompt('Điểm đánh giá (1-5)?', '4');
  if (!(s >= 1 && s <= 5)) { toast('Điểm phải từ 1 đến 5'); return; }
  const c = prompt('Nhận xét?', 'Hoàn thành tốt công việc');
  if (c === null) return;
  await api.post(`/employees/${enc(d.id)}/reviews`, { score: s, comment: c });
  toast('Đã lưu đánh giá');
  PROF = await api.get(`/employees/${enc(d.id)}/profile`);
  showTab('Đánh giá');
};

/* =============== Phòng ban & chức vụ =============== */
ACT.depAdd = async () => {
  const n = (prompt('Tên phòng ban mới?') || '').trim();
  if (!n) return;
  await api.post('/departments', { name: n });
  await lookups(true); toast('Đã thêm phòng ban'); return R.departments();
};
ACT.depEdit = async d => {
  const n = (prompt('Tên phòng ban mới?', d.name) || '').trim();
  if (!n || n == d.name) return;
  await api.put('/departments/' + d.id, { name: n });
  await lookups(true); toast('Đã cập nhật phòng ban'); return R.departments();
};
ACT.depDel = async d => {
  if (!confirm('Xóa phòng ban này?')) return;
  await api.del('/departments/' + d.id);
  await lookups(true); toast('Đã xóa phòng ban'); return R.departments();
};
ACT.posAdd = async () => {
  const n = (prompt('Tên chức vụ mới?') || '').trim();
  if (!n) return;
  const a = +prompt('Lương tối thiểu (triệu)?', '10'), b = +prompt('Lương tối đa (triệu)?', '20');
  if (!(a > 0 && b > a)) { toast('Khoảng lương không hợp lệ'); return; }
  await api.post('/positions', { name: n, min_salary: a, max_salary: b });
  await lookups(true); toast('Đã thêm chức vụ'); return R.positions();
};
ACT.posEdit = async d => {
  const a = +prompt('Lương tối thiểu (triệu)?', d.min), b = +prompt('Lương tối đa (triệu)?', d.max);
  if (!(a > 0 && b > a)) { toast('Khoảng lương không hợp lệ'); return; }
  await api.put('/positions/' + d.id, { min_salary: a, max_salary: b });
  toast('Đã cập nhật chức vụ'); return R.positions();
};
ACT.posDel = async d => {
  if (!confirm('Xóa chức vụ này?')) return;
  await api.del('/positions/' + d.id);
  await lookups(true); toast('Đã xóa chức vụ'); return R.positions();
};

/* =============== Chấm công & nghỉ phép =============== */
CHG.attf = (d, el) => { S.as = el.value; return R.attendance(); };
ACT.punch = async d => {
  const r = await api.post('/attendance/check-' + d.k);
  toast((d.k == 'in' ? 'Đã check-in lúc ' : 'Đã check-out lúc ') + r.time);
  return R.attendance();
};
ACT.attEdit = async d => {
  const i = prompt('Giờ vào (HH:MM, hoặc — nếu vắng)', d.in), o = prompt('Giờ ra (HH:MM)', d.out);
  if (i === null || o === null) return;
  await api.put('/attendance/' + enc(d.id), { check_in: i, check_out: o });
  toast('Đã cập nhật chấm công');
  return R.attendance();
};

ACT.newLeave = () => {
  $('#l_type').value = 'Phép năm';
  $('#l_from').value = $('#l_to').value = today();
  $('#l_reason').value = 'Việc riêng';
  $('#le2').textContent = '';
  $('#ld').showModal();
};
ACT.saveLeave = async () => {
  const b = { type: $('#l_type').value, from: $('#l_from').value, to: $('#l_to').value, reason: $('#l_reason').value.trim() };
  try { await api.post('/leave', b); }
  catch (x) { $('#le2').textContent = x.msg; return; }
  $('#ld').close();
  toast('Đã gửi đơn nghỉ phép');
  return R.leave();
};
ACT.leaveDo = async d => {
  await api.post(`/leave/${d.id}/${d.op}`);
  toast({ approve: 'Đã duyệt đơn nghỉ phép', reject: 'Đã từ chối đơn nghỉ phép', cancel: 'Đã hủy đơn nghỉ phép' }[d.op]);
  return R.leave();
};

/* =============== Lương, tài khoản, cài đặt =============== */
CHG.period = (d, el) => { S.per = el.value; return R.payroll(); };
ACT.payAdj = async d => {
  const b = +prompt('Thưởng (triệu đồng)?', +d.bo / 1e6), x = +prompt('Khấu trừ (triệu đồng)?', +d.de / 1e6);
  if (!(b >= 0 && x >= 0)) { toast('Số tiền không hợp lệ'); return; }
  await api.put('/payroll/' + enc(d.id), { period: S.per, bonus: Math.round(b * 1e6), deduction: Math.round(x * 1e6) });
  toast('Đã cập nhật bảng lương');
  return R.payroll();
};

ACT.ctRenew = async d => {
  const base = new Date((d.end || d.start) + 'T00:00:00');
  base.setFullYear(base.getFullYear() + 1);
  const def = base.getFullYear() + '-' + String(base.getMonth() + 1).padStart(2, '0') + '-' + String(base.getDate()).padStart(2, '0');
  const end = (prompt('Gia hạn hợp đồng ' + d.id + '\nHiện hết hạn: ' + (d.end || '—') + '\nNgày kết thúc MỚI (YYYY-MM-DD):', def) || '').trim();
  if (!end) return;
  const note = (prompt('Ghi chú gia hạn (có thể để trống):', '') || '').trim();
  const r = await api.post('/contracts/' + enc(d.id) + '/renew', { end_date: end, note });
  toast('Đã gia hạn ' + r.id + ' đến ' + r.end_date);
  return R.contracts();
};
ACT.ctHist = async d => {
  const rows = await api.get('/contracts/' + enc(d.id) + '/renewals');
  alert('Lịch sử gia hạn ' + d.id + ':\n' + rows.map(r => r.renewed_at.slice(0, 16) + ' · ' + (r.old_end_date || '—') + ' → ' + (r.new_end_date || 'không thời hạn') + ' · ' + r.renewed_by + (r.note ? ' · ' + r.note : '')).join('\n'));
};
ACT.uNew = async () => {
  const u = (prompt('Tên đăng nhập mới?') || '').trim();
  if (!u) return;
  const eid = (prompt('Mã nhân viên liên kết (VD: NV001)?\nĐể trống nếu không liên kết.') || '').trim();
  const r = await api.post('/users', { username: u, employee_id: eid });
  alert('Đã tạo tài khoản "' + u + '".\nMật khẩu tạm: ' + r.temp_password + '\n(Chỉ hiển thị một lần, hãy gửi cho người dùng.)');
  return R.users();
};
CHG.ctype = (d, el) => {
  const none = !el.value || el.value == 'Không thời hạn';
  $('#f_cend').disabled = none; if (none) $('#f_cend').value = '';
};
CHG.role = async (d, el) => {
  try { await api.put('/users/' + d.id, { role: el.value }); toast('Đã đổi quyền'); }
  catch (e) { toast(e.msg); await R.users(); }
};
ACT.uLock = async d => { await api.post(`/users/${d.id}/toggle-lock`); return R.users(); };
ACT.uReset = async d => {
  if (!confirm('Đặt lại mật khẩu tài khoản này?')) return;
  const r = await api.post(`/users/${d.id}/reset-password`);
  alert('Mật khẩu tạm: ' + r.temp_password + '\n(Chỉ hiển thị một lần, hãy gửi cho người dùng.)');
};
ACT.uDel = async d => {
  if (!confirm('Xóa tài khoản này?')) return;
  await api.del('/users/' + d.id);
  toast('Đã xóa tài khoản');
  return R.users();
};

ACT.saveCfg = async () => {
  await api.put('/settings', { tax: +$('#c_t').value, ins: +$('#c_i').value, announcement: $('#c_a').value.trim() });
  toast('Đã lưu cấu hình');
};
ACT.myPhone = async () => {
  const r = await api.put('/me', { phone: $('#mp').value });
  ME.phone = r.phone;
  $('#mp').value = r.phone;
  toast('Đã cập nhật thông tin cá nhân');
};
ACT.chPw = async () => {
  const o = $('#o').value, n = $('#n').value, m = $('#pe');
  m.textContent = !o ? 'Nhập mật khẩu hiện tại' : n.length < 6 ? 'Mật khẩu mới cần ít nhất 6 ký tự' : n != $('#n2').value ? 'Nhập lại mật khẩu chưa khớp' : '';
  if (m.textContent) return;
  try { await api.post('/auth/change-password', { current: o, new: n }); }
  catch (x) { m.textContent = x.msg; return; }
  ['o', 'n', 'n2'].forEach(k => $('#' + k).value = '');
  toast('Đã đổi mật khẩu');
};

ACT.print = () => window.print();
ACT.notif = async () => {
  const n = await api.get('/notifications');
  $('#nd').innerHTML = '<h3 style="margin-top:0">Thông báo</h3>' + (n.items.length ? n.items.map(x => `<p style="border-bottom:1px solid var(--line);padding-bottom:6px">${esc(x)}</p>`).join('') : '<p style="color:var(--mute)">Không có thông báo mới.</p>') + '<button class="btn" data-act="closeDlg" data-d="nd">Đóng</button>';
  $('#bell').textContent = '🔔 ' + n.items.length;
  $('#nd').showModal();
};

/* =============== Khởi động: khôi phục phiên đăng nhập nếu còn =============== */
(async () => { try { enter(await api.get('/auth/me')); } catch { showLogin(); } })();
