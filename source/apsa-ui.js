/* ══════════ APSA · UI dùng chung cho mọi trang ══════════
   1) Cỡ chữ theo từng user (Mặc định 12.5 / To 14 / Tối đa 15)
   2) Menu Cài đặt gắn vào chip tên user ở góc phải
   Nạp trong <head> sau theme.css để cỡ chữ áp trước khi vẽ, không bị nháy. */
(function () {
  'use strict';

  var KEY   = 'apsa_fs';
  var SIZES = [
    { id: 'md', px: 12.5, label: 'Mặc định', hint: '12.5px' },
    { id: 'lg', px: 14,   label: 'To',       hint: '14px'   },
    { id: 'xl', px: 15,   label: 'Tối đa',   hint: '15px'   }
  ];
  var DEFAULT = 'md';

  function sizeOf(id) {
    for (var i = 0; i < SIZES.length; i++) if (SIZES[i].id === id) return SIZES[i];
    return SIZES[0];
  }

  /* ── Áp cỡ chữ ngay lập tức ── */
  function apply(id) {
    var s  = sizeOf(id);
    var r  = document.documentElement;
    r.setAttribute('data-fs', s.id);
    r.style.setProperty('--fs',    s.px + 'px');
    r.style.setProperty('--fs-sm', (s.px - 1)   + 'px');
    r.style.setProperty('--fs-xs', (s.px - 2)   + 'px');
    r.style.setProperty('--fs-lg', (s.px + 1.5) + 'px');
  }

  var current = DEFAULT;
  try { current = localStorage.getItem(KEY) || DEFAULT; } catch (e) {}
  apply(current);                                  // chạy trước khi <body> hiện ra

  /* ── Lưu ── */
  function setSize(id, push) {
    current = sizeOf(id).id;
    apply(current);
    try { localStorage.setItem(KEY, current); } catch (e) {}
    paintMenu();
    if (push !== false) savePrefs();
  }

  var PKEY = 'ui';                                  // tách khỏi pref 'home' của trang chủ

  function savePrefs() {
    fetch('./api/auth-api.php?action=prefs-save', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ key: PKEY, value: { font_size: current } })
    }).catch(function () {});                       // hỏng thì vẫn còn localStorage
  }

  function pullPrefs() {
    fetch('./api/auth-api.php?action=prefs-get&key=' + PKEY, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        var v = (j && j.ok && j.data) ? j.data.value : null;
        if (v && v.font_size && v.font_size !== current) setSize(v.font_size, false);
      })
      .catch(function () {});
  }

  /* ── Menu gắn vào chip tên user ── */
  var menu = null;

  function paintMenu() {
    if (!menu) return;
    var b = menu.querySelectorAll('.fsopt');
    for (var i = 0; i < b.length; i++) {
      b[i].classList.toggle('on', b[i].getAttribute('data-id') === current);
    }
  }

  function closeMenu() {
    if (menu) menu.classList.remove('open');
  }

  function buildMenu(chip) {
    var wrap = document.createElement('div');
    wrap.className = 'umenu';
    wrap.id = 'apsaUserMenu';

    var h = '<div class="umhead">Cỡ chữ</div>';
    for (var i = 0; i < SIZES.length; i++) {
      h += '<button type="button" class="fsopt" data-id="' + SIZES[i].id + '">' +
             '<span>' + SIZES[i].label + '</span><em>' + SIZES[i].hint + '</em>' +
           '</button>';
    }
    h += '<div class="umsep"></div>' +
         '<button type="button" class="umlog" id="apsaLogBtn">' +
           '<span>Nhật ký cập nhật</span><em id="apsaVerTag">v…</em></button>' +
         '<div class="umsep"></div>' +
         '<button type="button" class="umout" id="apsaLogoutBtn">Đăng xuất</button>';
    wrap.innerHTML = h;
    chip.appendChild(wrap);
    menu = wrap;

    wrap.addEventListener('click', function (e) {
      var opt = e.target.closest ? e.target.closest('.fsopt') : null;
      if (opt) { setSize(opt.getAttribute('data-id')); e.stopPropagation(); return; }
      if (e.target.closest && e.target.closest('#apsaLogBtn')) {
        e.stopPropagation(); closeMenu(); openLog(); return;
      }
      if (e.target.closest && e.target.closest('#apsaLogoutBtn')) {
        e.stopPropagation();
        fetch('./api/auth-api.php?action=logout', { method: 'POST', credentials: 'same-origin' })
          .then(function () { location.href = './login.html'; });
      }
    });
    paintMenu();
  }


  /* ══════════ NHẬT KÝ CẬP NHẬT ══════════
     Nội dung nằm ở /changelog.json — thêm dòng mới chỉ cần sửa file đó,
     không phải đụng tới file JS này. */
  var LOG = null, logOv = null;

  function logCss() {
    return '' +
    '#apsaLogOv{ position:fixed; inset:0; z-index:100000; display:none;' +
      ' background:rgba(0,0,0,.72); -webkit-backdrop-filter:blur(6px); backdrop-filter:blur(6px);' +
      ' align-items:center; justify-content:center; padding:24px;' +
      ' font-family:"Oxanium",-apple-system,BlinkMacSystemFont,sans-serif; }' +
    '#apsaLogOv.open{ display:flex; }' +
    '#apsaLogOv .lgbox{ width:min(680px,100%); max-height:min(78vh,760px); display:flex; flex-direction:column;' +
      ' background:#0b0b0b; border:1px solid rgba(255,255,255,.10); border-radius:16px;' +
      ' box-shadow:0 30px 90px rgba(0,0,0,.7); overflow:hidden; }' +
    '#apsaLogOv .lghead{ display:flex; align-items:center; gap:11px; padding:17px 20px;' +
      ' border-bottom:1px solid rgba(255,255,255,.09); flex:0 0 auto; }' +
    '#apsaLogOv .lgttl{ font-family:"Orbitron",sans-serif; font-size:13.5px; font-weight:800;' +
      ' letter-spacing:1.2px; text-transform:uppercase; color:#fff; }' +
    '#apsaLogOv .lgver{ font-family:"Orbitron",sans-serif; font-size:11px; font-weight:800;' +
      ' color:#000; background:#dff20d; border-radius:20px; padding:3px 10px; }' +
    '#apsaLogOv .lgx{ margin-left:auto; width:30px; height:30px; border-radius:9px; cursor:pointer;' +
      ' border:1px solid rgba(255,255,255,.10); background:transparent; color:#9a9a9a; font-size:14px;' +
      ' display:flex; align-items:center; justify-content:center; }' +
    '#apsaLogOv .lgx:hover{ color:#fff; background:rgba(255,255,255,.07); }' +
    '#apsaLogOv .lgbody{ overflow-y:auto; padding:6px 20px 20px; }' +
    '#apsaLogOv .lgrel{ margin-top:16px; }' +
    '#apsaLogOv .lgrhead{ display:flex; align-items:center; gap:9px; margin:0 0 4px; }' +
    '#apsaLogOv .lgrv{ font-family:"Orbitron",sans-serif; font-size:12px; font-weight:800; color:#dff20d; }' +
    '#apsaLogOv .lgrd{ font-size:11px; color:#5e5e5e; font-weight:700; }' +
    '#apsaLogOv .lgline{ flex:1; height:1px; background:rgba(255,255,255,.09); }' +
    '#apsaLogOv .lgnote{ font-size:11.5px; color:#5e5e5e; margin:0 0 9px; }' +
    '#apsaLogOv .lgitem{ display:flex; gap:11px; padding:7px 0; border-bottom:1px solid rgba(255,255,255,.05); }' +
    '#apsaLogOv .lgitem:last-child{ border-bottom:none; }' +
    '#apsaLogOv .lgdot{ flex:0 0 6px; width:6px; height:6px; border-radius:50%;' +
      ' background:#dff20d; opacity:.55; margin-top:6px; }' +
    '#apsaLogOv .lgtxt{ font-size:12.5px; line-height:1.55; color:#d6d6d6; }' +
    '#apsaLogOv .lgday{ display:block; font-size:10.5px; color:#5e5e5e; font-weight:700; margin-top:2px; }' +
    '#apsaLogOv .lgempty{ padding:26px 0; text-align:center; color:#5e5e5e; font-size:12.5px; }';
  }

  function dmy(v) {
    var p = String(v || '').slice(0, 10).split('-');
    return p.length === 3 ? (p[2] + '/' + p[1] + '/' + p[0]) : (v || '');
  }
  function esc(v) {
    return String(v == null ? '' : v).replace(/[&<>"]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
    });
  }

  function ensureLogUi() {
    if (logOv) return logOv;
    var st = document.createElement('style');
    st.id = 'apsa-log-css';
    st.textContent = logCss();
    document.head.appendChild(st);

    var ov = document.createElement('div');
    ov.id = 'apsaLogOv';
    ov.innerHTML =
      '<div class="lgbox">' +
        '<div class="lghead">' +
          '<span class="lgttl">Nhật ký cập nhật</span>' +
          '<span class="lgver" id="apsaLogVer">v1.1</span>' +
          '<button type="button" class="lgx" id="apsaLogX">✕</button>' +
        '</div>' +
        '<div class="lgbody" id="apsaLogBody"><div class="lgempty">Đang tải…</div></div>' +
      '</div>';
    document.body.appendChild(ov);
    ov.addEventListener('click', function (e) {
      if (e.target === ov || (e.target.closest && e.target.closest('#apsaLogX'))) closeLog();
    });
    logOv = ov;
    return ov;
  }

  function paintLog() {
    var body = document.getElementById('apsaLogBody');
    if (!body) return;
    if (!LOG || !LOG.releases || !LOG.releases.length) {
      body.innerHTML = '<div class="lgempty">Chưa có ghi nhận thay đổi nào.</div>';
      return;
    }
    var v = document.getElementById('apsaLogVer');
    if (v) v.textContent = 'v' + (LOG.version || '1.1');
    var h = '';
    for (var i = 0; i < LOG.releases.length; i++) {
      var r = LOG.releases[i];
      h += '<div class="lgrel"><div class="lgrhead">' +
             '<span class="lgrv">Phiên bản ' + esc(r.v) + '</span>' +
             '<span class="lgrd">' + dmy(r.date) + '</span>' +
             '<span class="lgline"></span></div>';
      if (r.note) h += '<p class="lgnote">' + esc(r.note) + '</p>';
      var it = r.items || [];
      for (var j = 0; j < it.length; j++) {
        h += '<div class="lgitem"><span class="lgdot"></span><div class="lgtxt">' +
               esc(it[j].t) +
               (it[j].d ? '<span class="lgday">' + dmy(it[j].d) + '</span>' : '') +
             '</div></div>';
      }
      h += '</div>';
    }
    body.innerHTML = h;
  }

  function loadLog(then) {
    if (LOG) { if (then) then(); return; }
    fetch('/changelog.json?t=' + Math.floor(Date.now() / 60000), { cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (j) { LOG = j; tagVersion(); if (then) then(); })
      .catch(function () { LOG = { version: '1.1', releases: [] }; if (then) then(); });
  }

  function tagVersion() {
    var t = document.getElementById('apsaVerTag');
    if (t && LOG && LOG.version) t.textContent = 'v' + LOG.version;
  }

  function openLog() {
    ensureLogUi();
    logOv.classList.add('open');
    paintLog();
    loadLog(paintLog);
  }
  function closeLog() { if (logOv) logOv.classList.remove('open'); }

  /* Tìm chip user; trang nào không có thì tự gắn nút bánh răng vào header */
  function findChip() {
    var c = document.querySelector('.who');
    if (c) return c;
    /* Trang chủ vẽ lại #apsaUserBadge sau khi auth xong nên không gắn menu vào trong,
       mà đặt một chip riêng ngay cạnh. */
    var badge = document.getElementById('apsaUserBadge');
    var h = badge ? badge.parentNode : document.querySelector('header');
    if (!h) return null;
    var b = document.createElement('div');
    b.className = 'who';
    b.innerHTML = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" ' +
                  'stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="3"/>' +
                  '<path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.6 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.6h.09A1.65 1.65 0 0 0 10.6 3V3a2 2 0 1 1 4 0v.09A1.65 1.65 0 0 0 16 4.6a1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9v.09A1.65 1.65 0 0 0 21 10.6h0a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1Z"/></svg>' +
                  '<span class="ulabel">Hiển thị</span>';
    if (badge && badge.nextSibling) h.insertBefore(b, badge.nextSibling);
    else h.appendChild(b);
    return b;
  }

  function init() {
    var chip = findChip();
    if (!chip) return;
    chip.classList.add('has-menu');
    chip.setAttribute('title', 'Cài đặt hiển thị');
    buildMenu(chip);

    chip.addEventListener('click', function (e) {
      if (e.target.closest && e.target.closest('.umenu')) return;
      e.stopPropagation();
      menu.classList.toggle('open');
    });
    document.addEventListener('click', closeMenu);
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { closeMenu(); closeLog(); } });

    pullPrefs();
    loadLog();
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();

  window.apsaSetFontSize = setSize;
})();

/* ══════════ APSA · Sidebar dọc dùng chung ══════════
   Rail 52px luôn hiện (chỉ icon) — rê chuột thì trượt ra 212px hiện tên.
   Nội dung trang lùi sang phải đúng 52px, phần mở rộng đè lên nên không nhảy layout.
   CSS nhúng luôn trong file này để chỉ phải deploy 1 file. */
(function () {
  'use strict';

  /* Không gắn ở trang đăng nhập / trang khách xem album */
  var path = (location.pathname || '').toLowerCase();
  if (/login|share|view-album|public/.test(path)) return;

  var I = {
    home:    '<path d="M3 10.5 12 3l9 7.5V20a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1z"/>',
    work:    '<rect x="3" y="3" width="7.5" height="7.5" rx="1.5"/><rect x="13.5" y="3" width="7.5" height="7.5" rx="1.5"/><rect x="3" y="13.5" width="7.5" height="7.5" rx="1.5"/><rect x="13.5" y="13.5" width="7.5" height="7.5" rx="1.5"/>',
    quote:   '<path d="M14 3H6a1 1 0 0 0-1 1v16a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V8z"/><path d="M14 3v5h5"/><path d="M8.5 13h7M8.5 16.5h4.5"/>',
    rate:    '<rect x="2.5" y="5.5" width="19" height="13" rx="2"/><circle cx="12" cy="12" r="2.8"/><path d="M6 9v6M18 9v6"/>',
    company: '<path d="M3 21h18"/><rect x="4" y="8" width="7" height="13" rx="1"/><rect x="13" y="3" width="7" height="18" rx="1"/><path d="M6.5 11.5h2M6.5 15h2M15.5 6.5h2M15.5 10h2M15.5 13.5h2"/>',
    people:  '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20.5a6.5 6.5 0 0 1 13 0"/><path d="M16 5.2a3.5 3.5 0 0 1 0 6.6M17.5 14.5a6.5 6.5 0 0 1 4 6"/>',
    debt:    '<rect x="2.5" y="5" width="19" height="14" rx="2.5"/><path d="M2.5 10h19"/><path d="M6 15h4"/>',
    logo:    '<path d="M20.6 12.6 12.5 20.7a2 2 0 0 1-2.8 0l-6.4-6.4a2 2 0 0 1 0-2.8L11.4 3.4a2 2 0 0 1 1.4-.6h5.4a2 2 0 0 1 2 2v5.4a2 2 0 0 1-.6 1.4Z"/><circle cx="16.2" cy="7.8" r="1.4"/>',
    book:    '<path d="M4 4.5A1.5 1.5 0 0 1 5.5 3H19v16H5.5A1.5 1.5 0 0 0 4 20.5z"/><path d="M4 19.5A1.5 1.5 0 0 1 5.5 18H19v3H5.5A1.5 1.5 0 0 1 4 19.5z"/><path d="M8 7.5h7"/>',
    album:   '<rect x="2.5" y="6.5" width="19" height="14" rx="2.5"/><path d="M7 6.5 8.5 3.5h7L17 6.5"/><circle cx="12" cy="13.5" r="3.6"/>',
    bulb:    '<path d="M9.2 17.5a6.5 6.5 0 1 1 5.6 0"/><path d="M9.5 17.5h5v2a2.5 2.5 0 0 1-5 0z"/><path d="M10.5 21.5h3"/>',
    image:   '<rect x="2.5" y="4.5" width="19" height="15" rx="2.5"/><circle cx="8.2" cy="9.7" r="1.8"/><path d="M3 16.5 8.5 11l4 4 3-2.5 5 4.5"/>',
    ai:      '<path d="M11 2.5c1.3 5.6 2.6 6.9 8.2 8.2-5.6 1.3-6.9 2.6-8.2 8.2-1.3-5.6-2.6-6.9-8.2-8.2 5.6-1.3 6.9-2.6 8.2-8.2Z"/><path d="M18 15.5c.5 2.6 1.1 3.2 3.7 3.7-2.6.5-3.2 1.1-3.7 3.7-.5-2.6-1.1-3.2-3.7-3.7 2.6-.5 3.2-1.1 3.7-3.7Z"/>',
    qr:      '<rect x="3" y="3" width="7" height="7" rx="1.2"/><rect x="14" y="3" width="7" height="7" rx="1.2"/><rect x="3" y="14" width="7" height="7" rx="1.2"/><path d="M14 14h3v3h-3zM18.5 18.5H21V21h-2.5zM14 21h1.5M21 14v1.5"/>',
    key:     '<circle cx="8.5" cy="8.5" r="4.5"/><path d="M11.8 11.8 21 21M17.5 17.5l2-2M14.8 14.8l2-2"/>',
    shield:  '<path d="M12 2.8 20 5.6v6.1c0 4.9-3.3 8.7-8 9.9-4.7-1.2-8-5-8-9.9V5.6z"/><path d="M9 12l2.2 2.2L15.2 10"/>',
    bell:    '<path d="M18 15.5V10a6 6 0 1 0-12 0v5.5L4 18h16z"/><path d="M9.5 21h5"/>',
    trophy:  '<path d="M7 4h10v5a5 5 0 0 1-10 0z"/><path d="M7 5.5H4.5V7A3.5 3.5 0 0 0 7 10.3M17 5.5h2.5V7A3.5 3.5 0 0 1 17 10.3"/><path d="M12 14v3.5M8.5 20.5h7l-.7-3h-5.6z"/>'
  };

  var NAV = [
    { home: 1, ico: 'home',   name: 'Trang chủ',        url: './index.html' },
    { grp: 'Công việc' },
    { ico: 'work',    name: 'Làm việc',          url: './assignments.html', id: 35 },
    { ico: 'quote',   name: 'Báo giá & Nghiệm thu', url: './quotation.html', id: 32 },
    { ico: 'rate',    name: 'Rate Card',         url: './ratecard.html', id: 26 },
    { grp: 'Khách hàng' },
    { ico: 'company', name: 'Quản lý Công ty',   url: './companies.html', id: 31 },
    { ico: 'people',  name: 'Quản lý Khách hàng', url: './customers.html', id: 29 },
    { ico: 'debt',    name: 'Quản lý Công nợ',   url: './debts.html', id: 30 },
    { grp: 'Nội dung' },
    { ico: 'logo',    name: 'Kho Logos',         url: './logos.html', id: 17 },
    { ico: 'book',    name: 'Brand Guidelines',  url: './brand-guidelines.html', id: 18 },
    { ico: 'album',   name: 'Album gửi khách',   url: './albums.html', id: 34 },
    { ico: 'bulb',    name: 'Inspiration',       url: './inspiration.html', id: 25 },
    { ico: 'image',   name: 'Thư viện ảnh',      url: 'https://imglib.apsa.agency', id: 23 },
    { ico: 'ai',      name: 'AI Studio',         url: 'https://ai.apsa.agency', id: 24 },
    { grp: 'Tiện ích' },
    { ico: 'qr',      name: 'Tạo mã QR',         url: './event-qr-generator.html', id: 1 },
    { ico: 'key',     name: 'Accounts nhân viên', url: './accounts.html', id: 90 },
    { ico: 'shield',  name: 'Quản lý User',      url: './users.html', id: 27 },
    { ico: 'trophy',  name: 'Badminton',         url: './badminton/index.html', id: 28 }
  ];

  /* Trang con nào tính là đang ở mục nào */
  var ALIAS = { 'debt-detail.html': 'debts.html', 'qr-tool.html': 'event-qr-generator.html' };

  function css() {
    return '' +
    'body{ padding-left:52px; }' +
    /* Nhiều trang có sẵn quy tắc cho nav / nav a — phải khoá lại bằng #apsaSide */
    '#apsaSide{ position:fixed !important; left:0; top:0; bottom:0; width:52px; z-index:150;' +
      ' margin:0; padding:0; gap:0; background:#070707;' +
      ' border-right:1px solid rgba(255,255,255,.08);' +
      ' display:flex !important; flex-direction:column !important;' +
      ' align-items:stretch !important; justify-content:flex-start !important;' +
      ' box-sizing:border-box; overflow:hidden; text-align:left; direction:ltr;' +
      ' font-family:"Oxanium",-apple-system,BlinkMacSystemFont,sans-serif;' +
      ' transition:width .18s cubic-bezier(.4,0,.2,1); }' +
    '#apsaSide *{ box-sizing:border-box; }' +
    '#apsaSide:hover{ width:214px; box-shadow:16px 0 40px rgba(0,0,0,.7); }' +
    '#apsaSide .as-txt{ opacity:0; white-space:nowrap; transition:opacity .13s; }' +
    '#apsaSide:hover .as-txt{ opacity:1; }' +
    '#apsaSide .as-brand{ display:flex; align-items:center; gap:11px; width:100%;' +
      ' height:56px; flex:0 0 56px; padding:0 12px; margin:0; border-radius:0;' +
      ' background:transparent; text-decoration:none;' +
      ' border-bottom:1px solid rgba(255,255,255,.08); }' +
    '#apsaSide .as-mark{ width:27px; height:27px; flex:0 0 27px; border-radius:8px;' +
      ' background:#dff20d; color:#000; display:flex; align-items:center; justify-content:center;' +
      ' font-family:"Orbitron",sans-serif; font-weight:900; font-size:13px; letter-spacing:-.5px; }' +
    '#apsaSide .as-brand .as-txt{ font-family:"Orbitron",sans-serif; font-weight:800;' +
      ' font-size:12px; color:#fff; letter-spacing:.6px; }' +
    '#apsaSide .as-scroll{ flex:1 1 auto; width:100%; align-self:stretch; display:block;' +
      ' overflow-y:auto; overflow-x:hidden; padding:7px 0 16px;' +
      ' scrollbar-width:none; -ms-overflow-style:none; }' +
    '#apsaSide .as-scroll::-webkit-scrollbar{ width:0; height:0; }' +
    '#apsaSide .as-grp{ height:26px; width:100%; display:flex; align-items:center;' +
      ' padding:0 14px; margin:0; position:relative; }' +
    '#apsaSide .as-grp::before{ content:""; position:absolute; left:15px; right:15px; top:50%;' +
      ' height:1px; background:rgba(255,255,255,.10); transition:opacity .13s; }' +
    '#apsaSide:hover .as-grp::before{ opacity:0; }' +
    '#apsaSide .as-gl{ font-size:9.5px; letter-spacing:1px; text-transform:uppercase;' +
      ' color:#5e5e5e; font-weight:800; opacity:0; transition:opacity .13s; white-space:nowrap; }' +
    '#apsaSide:hover .as-gl{ opacity:1; }' +
    '#apsaSide .as-grp.pin .as-gl{ color:#dff20d; }' +
    '#apsaSide .as-item{ display:flex; align-items:center; gap:13px; width:100%;' +
      ' height:38px; flex:0 0 38px; padding:0 14px; margin:0; border-radius:0;' +
      ' background:transparent; color:#8d8d8d; text-decoration:none;' +
      ' font-size:12.5px; font-weight:600; position:relative;' +
      ' transition:color .13s, background .13s; }' +
    '#apsaSide .as-item svg{ width:19px; height:19px; flex:0 0 19px; display:block;' +
      ' fill:none; stroke:currentColor; stroke-width:1.7;' +
      ' stroke-linecap:round; stroke-linejoin:round; }' +
    '#apsaSide .as-item:hover{ color:#fff; background:rgba(255,255,255,.055); }' +
    '#apsaSide .as-item.on{ color:#dff20d; background:rgba(223,242,13,.09); }' +
    '#apsaSide .as-item.on::before{ content:""; position:absolute; left:0; top:7px; bottom:7px;' +
      ' width:3px; border-radius:0 3px 3px 0; background:#dff20d; }' +
    '@media (max-width:760px){ body{ padding-left:46px; } #apsaSide{ width:46px; }' +
      ' #apsaSide .as-brand{ padding:0 9px; } #apsaSide .as-item{ padding:0 11px; } }' +
    '#apsaSide .as-bell{ position:relative; }' +
    '#apsaSide .as-bell .as-dot{ position:absolute; left:26px; top:7px; min-width:16px; height:16px;' +
      ' padding:0 4px; border-radius:9px; background:#ff4d4d; color:#fff; font-size:9.5px; font-weight:800;' +
      ' display:none; align-items:center; justify-content:center; line-height:1; }' +
    '#apsaSide .as-bell.has .as-dot{ display:flex; }' +
    '#apsaNoOv{ position:fixed; inset:0; z-index:100001; display:none;' +
      ' background:rgba(0,0,0,.6); align-items:flex-start; justify-content:flex-start;' +
      ' padding:64px 0 0 66px; font-family:"Oxanium",sans-serif; }' +
    '#apsaNoOv.open{ display:flex; }' +
    '#apsaNoOv .nobox{ width:min(430px,92vw); max-height:min(70vh,620px); display:flex; flex-direction:column;' +
      ' background:#0b0b0b; border:1px solid rgba(255,255,255,.11); border-radius:15px;' +
      ' box-shadow:0 26px 70px rgba(0,0,0,.75); overflow:hidden; }' +
    '#apsaNoOv .nohead{ display:flex; align-items:center; gap:10px; padding:14px 16px;' +
      ' border-bottom:1px solid rgba(255,255,255,.09); }' +
    '#apsaNoOv .nottl{ font-family:"Orbitron",sans-serif; font-size:12px; font-weight:800; letter-spacing:1.1px; color:#fff; }' +
    '#apsaNoOv .noall{ margin-left:auto; background:none; border:none; color:#8d8d8d; cursor:pointer;' +
      ' font-family:inherit; font-size:11px; font-weight:700; }' +
    '#apsaNoOv .noall:hover{ color:#dff20d; }' +
    '#apsaNoOv .nobody{ overflow-y:auto; }' +
    '#apsaNoOv .noitem{ display:flex; gap:10px; width:100%; text-align:left; background:transparent;' +
      ' border:none; border-bottom:1px solid rgba(255,255,255,.055); padding:12px 16px; cursor:pointer;' +
      ' font-family:inherit; }' +
    '#apsaNoOv .noitem:hover{ background:rgba(255,255,255,.045); }' +
    '#apsaNoOv .noitem.unread{ background:rgba(223,242,13,.045); }' +
    '#apsaNoOv .noav{ flex:0 0 26px; width:26px; height:26px; border-radius:50%; margin-top:2px;' +
      ' display:flex; align-items:center; justify-content:center; font-size:11px; font-weight:800;' +
      ' background:rgba(223,242,13,.16); color:#dff20d; text-transform:uppercase; }' +
    '#apsaNoOv .notx{ flex:1; min-width:0; }' +
    '#apsaNoOv .noti{ font-size:12.5px; font-weight:600; color:#e8e8e8; line-height:1.45; }' +
    '#apsaNoOv .nobd{ font-size:11.5px; color:#8d8d8d; margin-top:2px; overflow:hidden;' +
      ' display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; }' +
    '#apsaNoOv .nowh{ font-size:10.5px; color:#5e5e5e; font-weight:700; margin-top:3px; }' +
    '#apsaNoOv .noempty{ padding:32px 16px; text-align:center; color:#5e5e5e; font-size:12.5px; }' +
    '@media print{ body{ padding-left:0; } #apsaSide{ display:none !important; } }';
  }

  /* Sắp lại sidebar theo thiết lập trang chủ của user: ghim · thứ tự · đã ẩn */
  function layout(prefs) {
    var order  = (prefs && prefs.order)  || [];
    var hidden = (prefs && prefs.hidden) || [];
    var pinned = (prefs && prefs.pinned) || [];
    var custom = (prefs && prefs.custom) || [];

    var pos = {}, i;
    for (i = 0; i < order.length; i++) pos[order[i]] = i;
    var rank  = function (n) { return (n.id != null && pos[n.id] != null) ? pos[n.id] : 9000 + n._i; };
    var isHid = function (n) { return n.id != null && hidden.indexOf(n.id) >= 0; };
    var isPin = function (n) { return n.id != null && pinned.indexOf(n.id) >= 0; };

    var groups = [], cur = null, k = 0, home = null;
    for (i = 0; i < NAV.length; i++) {
      var n = NAV[i];
      if (n.home) { home = n; continue; }
      if (n.grp)  { cur = { grp: n.grp, items: [] }; groups.push(cur); continue; }
      n._i = k++;
      if (!cur) { cur = { grp: '', items: [] }; groups.push(cur); }
      cur.items.push(n);
    }

    // Công cụ user tự thêm ở trang chủ cũng lên sidebar
    var extra = [];
    for (i = 0; i < custom.length; i++) {
      var t = custom[i];
      if (!t || t.id == null || !t.url) continue;
      extra.push({ ico: 'work', name: t.name || ('Công cụ ' + t.id), url: t.url, id: t.id, _i: k++ });
    }
    if (extra.length) groups.push({ grp: 'Tự thêm', items: extra });

    var pin = [], g, m;
    for (g = 0; g < groups.length; g++) {
      var keep = [];
      for (m = 0; m < groups[g].items.length; m++) {
        var it = groups[g].items[m];
        if (isHid(it)) continue;
        if (isPin(it)) pin.push(it); else keep.push(it);
      }
      keep.sort(function (a, b) { return rank(a) - rank(b); });
      groups[g].items = keep;
    }
    pin.sort(function (a, b) { return rank(a) - rank(b); });

    var out = [];
    if (home) out.push(home);
    out.push({ bell: 1 });
    if (pin.length) {
      out.push({ grp: 'Thường dùng', pin: 1 });
      for (i = 0; i < pin.length; i++) out.push(pin[i]);
    }
    for (g = 0; g < groups.length; g++) {
      if (!groups[g].items.length) continue;
      out.push({ grp: groups[g].grp });
      for (m = 0; m < groups[g].items.length; m++) out.push(groups[g].items[m]);
    }
    return out;
  }

  /* ══════════ CHUÔNG THÔNG BÁO ══════════ */
  var NOTIF = { rows: [], unread: 0 }, noOv = null, noTimer = null;

  function noWhen(v) {
    var d = new Date(String(v).replace(' ', 'T'));
    if (isNaN(d)) return v || '';
    var m = Math.floor((Date.now() - d.getTime()) / 60000);
    if (m < 1) return 'vừa xong';
    if (m < 60) return m + ' phút trước';
    if (m < 1440) return Math.floor(m / 60) + ' giờ trước';
    var p = function (n) { return String(n).padStart(2, '0'); };
    return p(d.getDate()) + '/' + p(d.getMonth() + 1) + ' ' + p(d.getHours()) + ':' + p(d.getMinutes());
  }
  function noEsc(v) {
    return String(v == null ? '' : v).replace(/[&<>"]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
    });
  }
  function noIni(v) {
    var t = String(v || '?').trim().split(/\s+/);
    return t[t.length - 1].charAt(0);
  }

  function paintBell() {
    var b = document.getElementById('apsaBell');
    if (!b) return;
    b.classList.toggle('has', NOTIF.unread > 0);
    var d = b.querySelector('.as-dot');
    if (d) d.textContent = NOTIF.unread > 99 ? '99+' : String(NOTIF.unread);
  }

  function paintNotif() {
    var body = document.getElementById('apsaNoBody');
    if (!body) return;
    if (!NOTIF.rows.length) {
      body.innerHTML = '<div class="noempty">Chưa có thông báo nào.</div>';
      return;
    }
    body.innerHTML = NOTIF.rows.map(function (n) {
      return '<button type="button" class="noitem' + (n.is_read ? '' : ' unread') + '" data-id="' + n.id +
             '" data-url="' + noEsc(n.url || '') + '">' +
        '<span class="noav">' + noEsc(noIni(n.actor)) + '</span>' +
        '<span class="notx">' +
          '<span class="noti">' + noEsc(n.title) + '</span>' +
          (n.body ? '<span class="nobd">' + noEsc(n.body) + '</span>' : '') +
          '<span class="nowh">' + noWhen(n.created_at) + '</span>' +
        '</span></button>';
    }).join('');
  }

  function pullNotif(then) {
    fetch('./api/auth-api.php?action=notif-list&limit=25', { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (j && j.ok && j.data) { NOTIF = j.data; paintBell(); if (then) then(); }
      })
      .catch(function () {});
  }

  function markRead(payload) {
    return fetch('./api/auth-api.php?action=notif-read', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    }).catch(function () {});
  }

  function ensureNoUi() {
    if (noOv) return noOv;
    var ov = document.createElement('div');
    ov.id = 'apsaNoOv';
    ov.innerHTML =
      '<div class="nobox">' +
        '<div class="nohead"><span class="nottl">Thông báo</span>' +
          '<button type="button" class="noall" id="apsaNoAll">Đánh dấu đã đọc hết</button></div>' +
        '<div class="nobody" id="apsaNoBody"></div>' +
      '</div>';
    document.body.appendChild(ov);
    ov.addEventListener('click', function (e) {
      if (e.target === ov) { closeNotif(); return; }
      if (e.target.closest && e.target.closest('#apsaNoAll')) {
        markRead({ all: 1 }).then(function () {
          NOTIF.rows.forEach(function (n) { n.is_read = 1; });
          NOTIF.unread = 0; paintBell(); paintNotif();
        });
        return;
      }
      var it = e.target.closest ? e.target.closest('.noitem') : null;
      if (!it) return;
      var id = Number(it.getAttribute('data-id')), url = it.getAttribute('data-url');
      markRead({ id: id }).then(function () { if (url) location.href = url; });
      if (!url) { it.classList.remove('unread'); NOTIF.unread = Math.max(0, NOTIF.unread - 1); paintBell(); }
    });
    noOv = ov;
    return ov;
  }

  function openNotif() {
    ensureNoUi();
    noOv.classList.add('open');
    paintNotif();
    pullNotif(paintNotif);
  }
  function closeNotif() { if (noOv) noOv.classList.remove('open'); }

  function here() {
    var f = (location.pathname || '/').toLowerCase().replace(/^\/+/, '');
    if (f === '' || f.slice(-1) === '/') f += 'index.html';
    if (f.indexOf('badminton/') === 0) return 'badminton/index.html';
    return ALIAS[f] || f;
  }

  function paint(list) {
    var nav = document.getElementById('apsaSide');
    if (!nav) return;
    var cur = here();
    var h = '<a class="as-brand" href="./index.html" title="APSA Tools">' +
              '<span class="as-mark">A</span><span class="as-txt">APSA TOOLS</span></a>' +
            '<div class="as-scroll">';
    for (var i = 0; i < list.length; i++) {
      var n = list[i];
      if (n.bell) {
        h += '<a class="as-item as-bell" id="apsaBell" href="javascript:void(0)" title="Thông báo">' +
               '<svg viewBox="0 0 24 24" aria-hidden="true">' + I.bell + '</svg>' +
               '<span class="as-dot">0</span>' +
               '<span class="as-txt">Thông báo</span></a>';
        continue;
      }
      if (n.grp) {
        h += '<div class="as-grp' + (n.pin ? ' pin' : '') + '">' +
             '<span class="as-gl">' + (n.pin ? '★ ' : '') + n.grp + '</span></div>';
        continue;
      }
      var u  = String(n.url).toLowerCase().replace(/^\.\//, '');
      var on = !/^https?:/.test(u) && u === cur;
      h += '<a class="as-item' + (on ? ' on' : '') + '" href="' + n.url + '" title="' + n.name + '">' +
             '<svg viewBox="0 0 24 24" aria-hidden="true">' + (I[n.ico] || I.work) + '</svg>' +
             '<span class="as-txt">' + n.name + '</span></a>';
    }
    h += '</div>';
    nav.innerHTML = h;
  }

  function build() {
    if (document.getElementById('apsaSide')) return;

    var st = document.createElement('style');
    st.id = 'apsa-side-css';
    st.textContent = css();
    document.head.appendChild(st);

    var nav = document.createElement('nav');
    nav.id = 'apsaSide';
    nav.className = 'apsa-side';
    nav.setAttribute('aria-label', 'Điều hướng APSA');
    document.body.insertBefore(nav, document.body.firstChild);

    paint(layout(null));   // vẽ ngay bố cục mặc định (kèm chuông)
    pullHome();          // rồi áp thiết lập riêng của user

    nav.addEventListener('click', function (e) {
      if (e.target.closest && e.target.closest('#apsaBell')) { e.preventDefault(); openNotif(); }
    });
    pullNotif();
    clearInterval(noTimer);
    noTimer = setInterval(pullNotif, 60000);      // 1 phút kiểm tra 1 lần
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeNotif(); });
  }

  /* Đọc thiết lập trang chủ (thứ tự · ghim · đã ẩn) — dùng chung cho cả sidebar */
  function pullHome() {
    fetch('./api/auth-api.php?action=prefs-get&key=home', { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        var v = (j && j.ok && j.data) ? j.data.value : null;
        if (v && typeof v === 'object') { window.__APSA_HOME_PREFS = v; paint(layout(v)); }
      })
      .catch(function () {});
  }

  /* Trang chủ gọi lại sau khi user ghim / kéo thả để sidebar đổi theo ngay */
  window.apsaSyncSidebar = function (prefs) {
    var v = prefs || window.__APSA_HOME_PREFS;
    if (v) { window.__APSA_HOME_PREFS = v; paint(layout(v)); }
  };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', build);
  else build();
})();
