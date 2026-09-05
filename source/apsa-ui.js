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

  /* Co chu lay tu trang Cai dat. Doc cache truoc cho khoi nhay, roi cap nhat ngam. */
  function applyCfg(c) {
    if (!c) return;
    var m = { md: 'default', lg: 'large', xl: 'max' };
    for (var i = 0; i < SIZES.length; i++) {
      var v = parseFloat(c[m[SIZES[i].id]]);
      if (v > 0) { SIZES[i].px = v; SIZES[i].hint = v + 'px'; }
    }
  }
  try { applyCfg(JSON.parse(localStorage.getItem('apsa_fs_cfg') || 'null')); } catch (e) {}
  fetch('/api/settings-api.php?action=public', { credentials: 'same-origin' })
    .then(function (r) { return r.ok ? r.json() : null; })
    .then(function (j) {
      if (!j || !j.ok || !j.font_sizes) return;
      var old = JSON.stringify(SIZES.map(function (s) { return s.px; }));
      applyCfg(j.font_sizes);
      try { localStorage.setItem('apsa_fs_cfg', JSON.stringify(j.font_sizes)); } catch (e) {}
      if (JSON.stringify(SIZES.map(function (s) { return s.px; })) !== old) {
        try { apply(current); paintMenu(); } catch (e) {}
      }
    })
    .catch(function () {});

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
    gear:    '<circle cx="12" cy="12" r="3.2"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.6 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.6a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>',
    leave:   '<rect x="3" y="4.5" width="18" height="16" rx="2.5"/><path d="M3 9.5h18M8 2.5v4M16 2.5v4M8.5 14.5l2.2 2.2 4.3-4.3"/>',
    key:     '<circle cx="8.5" cy="8.5" r="4.5"/><path d="M11.8 11.8 21 21M17.5 17.5l2-2M14.8 14.8l2-2"/>',
    shield:  '<path d="M12 2.8 20 5.6v6.1c0 4.9-3.3 8.7-8 9.9-4.7-1.2-8-5-8-9.9V5.6z"/><path d="M9 12l2.2 2.2L15.2 10"/>',
    bell:    '<path d="M18 15.5V10a6 6 0 1 0-12 0v5.5L4 18h16z"/><path d="M9.5 21h5"/>',
    contract: '<path d="M6 3h8l4 4v14H6z"/><path d="M14 3v4h4"/><path d="M9 12.5h6M9 16h4"/>',
    money:   '<path d="M5 3.5h14v17l-2.3-1.6-2.3 1.6-2.4-1.6-2.4 1.6-2.3-1.6L5 20.5z"/><path d="M8.5 8.5h7M8.5 12h7M8.5 15.5h4"/>',
    video:   '<path d="M3 6.5A2.5 2.5 0 0 1 5.5 4h8A2.5 2.5 0 0 1 16 6.5v11A2.5 2.5 0 0 1 13.5 20h-8A2.5 2.5 0 0 1 3 17.5z"/><path d="M16 10.5 21 7.5v9L16 13.5z"/>',
    payroll: '<path d="M3.5 7.5A2 2 0 0 1 5.5 5.5H17V8"/><rect x="3.5" y="7.5" width="17" height="12" rx="2.5"/><circle cx="16.5" cy="13.5" r="1.3"/>',
    spark:   '<path d="M11 3.5 12.6 9l5.4 1.6-5.4 1.6L11 17.7l-1.6-5.5L4 10.6 9.4 9z"/><path d="M18 16.5l.6 1.9 1.9.6-1.9.6-.6 1.9-.6-1.9-1.9-.6 1.9-.6z"/>',
    truck:   '<rect x="2.5" y="7" width="10.5" height="9" rx="1.2"/><path d="M13 10.5h3.6l3.4 3.2V16H13z"/><circle cx="6.5" cy="18" r="1.8"/><circle cx="16.8" cy="18" r="1.8"/>',
    frame:   '<rect x="3.5" y="3.5" width="17" height="17" rx="2"/><rect x="7.5" y="7.5" width="9" height="9" rx="1.5"/>',
    policy:  '<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M7.5 8.5 9 10l2.5-2.5M7.5 15 9 16.5l2.5-2.5M14 8.5h2.5M14 15h2.5"/>',
    task:    '<rect x="4" y="4" width="16" height="16" rx="2.5"/><path d="M8 10.5 9.8 12.3 13.5 8.6M8 16h8"/>',
    chat:    '<path d="M20.5 12a7.5 7.5 0 0 1-10.9 6.7L4 20.5l1.9-5.4A7.5 7.5 0 1 1 20.5 12z"/><path d="M9 11.5h6M9 14.5h4"/>',
    rise:    '<path d="M3 17l6-6 4 4 7-7"/><path d="M14 8h6v6"/>',
    trophy:  '<path d="M7 4h10v5a5 5 0 0 1-10 0z"/><path d="M7 5.5H4.5V7A3.5 3.5 0 0 0 7 10.3M17 5.5h2.5V7A3.5 3.5 0 0 1 17 10.3"/><path d="M12 14v3.5M8.5 20.5h7l-.7-3h-5.6z"/>'
  };

  var NAV = [
    { home: 1, ico: 'home',   name: 'Trang chủ',        url: './index.html' },
    { grp: 'Công việc' },
    { ico: 'task',    name: 'Làm việc',          url: './assignments.html', id: 35 },
    { ico: 'quote',   name: 'Báo giá & Nghiệm thu', url: './quotation.html', id: 32 },
    { ico: 'rate',    name: 'Rate Card',         url: './ratecard.html', id: 26 },
    { grp: 'Khách hàng' },
    { ico: 'company', name: 'Quản lý Công ty',   url: './companies.html', id: 31 },
    { ico: 'people',  name: 'Quản lý Khách hàng', url: './customers.html', id: 29 },
    { ico: 'debt',    name: 'Quản lý Công nợ',   url: './debts.html', id: 30 },
    { ico: 'truck', name: 'Nhà cung cấp',      url: './suppliers.html', id: 95 },
    { ico: 'contract',     name: 'Tủ hợp đồng',     url: './contracts.html', id: 96 },
    { ico: 'money', name: 'Chi phí thực tế', url: './chi-phi.html', id: 97 },
  { ico: 'video', name: 'Duyệt video', url: './videos.html', id: 98 },
  { ico: 'payroll', name: 'Bảng lương', url: './luong.html', id: 99 },
    { grp: 'Nội dung' },
    { ico: 'logo',    name: 'Kho Logos',         url: './logos.html', id: 17 },
    { ico: 'book',    name: 'Brand Guidelines',  url: './brand-guidelines.html', id: 18 },
    { ico: 'album',   name: 'Album gửi khách',   url: './albums.html', id: 34 },
    { ico: 'bulb',    name: 'Inspiration',       url: './inspiration.html', id: 25 },
    { ico: 'image',   name: 'Thư viện ảnh',      url: 'https://imglib.apsa.agency', id: 23 },
    { ico: 'frame',   name: 'Frame Avatar',      url: './frame.html', id: 100 },
    { ico: 'spark',   name: 'Chụp ảnh AI',        url: './aiphoto.html', id: 101 },
    { grp: 'Tiện ích' },
    { ico: 'qr',      name: 'Quản lý Link',         url: './event-qr-generator.html', id: 1 },
    { ico: 'key',     name: 'Accounts nhân viên', url: './accounts.html', id: 90 },
    { ico: 'leave',   name: 'Xin nghỉ phép',      url: './leave.html', id: 91 },
    { ico: 'rise',    name: 'Better Me',          url: './betterme.html', id: 102 },
      { ico: 'policy', name: 'Policy công ty', url: './policy.html', id: 94 },
    { ico: 'shield',  name: 'Quản lý User',      url: './users.html', id: 27 },
    { ico: 'gear',    name: 'Cài đặt hệ thống',  url: './settings.html', id: 92, admin: true },
    { ico: 'chat',    name: 'Thông báo Zalo',    url: './zalo.html', id: 93 },
    { ico: 'trophy',  name: 'Badminton',         url: './badminton/index.html', id: 28 }
  ];

  /* Trang con nào tính là đang ở mục nào */
  window.APSA_NAV = NAV;
  var GLOB = null;   /* APSA1215: thu tu dung chung ca cong ty */

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
    /* ── APSA1215: bang thong bao truot ben phai (kieu macOS) + nut hamburger ── */
'#apsaNoOv{ background:rgba(0,0,0,.45); padding:0; align-items:stretch; justify-content:flex-end; }' +
'#apsaNoOv .nobox{ width:min(400px,92vw); max-height:none; height:100%; border-radius:0;' +
' border:0; border-left:1px solid rgba(255,255,255,.11);' +
' box-shadow:-26px 0 70px rgba(0,0,0,.75); }' +
'#apsaNoOv .nohead{ padding:17px 62px 14px 18px; }' +
'#apsaNoOv .nobody{ flex:1 1 auto; }' +
'#apsaHam{ position:fixed; top:11px; right:14px; z-index:100002; width:38px; height:38px;' +
' border-radius:11px; border:1px solid rgba(255,255,255,.12); background:rgba(11,11,11,.88);' +
' -webkit-backdrop-filter:blur(8px); backdrop-filter:blur(8px); color:#c9c9c9; cursor:pointer;' +
' display:flex; align-items:center; justify-content:center; padding:0;' +
' font-family:"Oxanium",sans-serif; }' +
'#apsaHam:hover{ color:#fff; border-color:rgba(255,255,255,.26); background:rgba(20,20,20,.95); }' +
'#apsaHam svg{ width:18px; height:18px; fill:none; stroke:currentColor; stroke-width:2;' +
' stroke-linecap:round; }' +
'#apsaHam .hdot{ position:absolute; top:-6px; right:-6px; min-width:18px; height:18px; padding:0 5px;' +
' border-radius:9px; background:#ff4d4d; color:#fff; font-size:10px; font-weight:800;' +
' display:none; align-items:center; justify-content:center; line-height:1; }' +
'#apsaHam.has .hdot{ display:flex; }' +
'@media print{ #apsaHam{ display:none !important; } }' +
'@media print{ body{ padding-left:0; } #apsaSide{ display:none !important; } }';
  }

  /* Sắp lại sidebar theo thiết lập trang chủ của user: ghim · thứ tự · đã ẩn */
  function layout(prefs) {
    var order = (GLOB && GLOB.order && GLOB.order.length) ? GLOB.order : ((prefs && prefs.order) || []);
    var hidden = (GLOB && GLOB.hidden && GLOB.hidden.length) ? GLOB.hidden : ((prefs && prefs.hidden) || []);
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

  /* ── APSA1215: nut hamburger mo bang thong bao ben phai ── */
function ensureHam() {
  if (document.getElementById('apsaHam')) return;
  var b = document.createElement('button');
  b.id = 'apsaHam';
  b.type = 'button';
  b.title = 'Thông báo';
  b.setAttribute('aria-label', 'Thông báo');
  b.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg>' +
                '<span class="hdot">0</span>';
  b.addEventListener('click', function (e) {
    e.stopPropagation();
    if (noOv && noOv.classList.contains('open')) closeNotif(); else openNotif();
  });
  document.body.appendChild(b);

  /* Chua cho nut o header, tranh de len nut san co */
  var hd = document.querySelector('header') || document.querySelector('.topbar');
  if (hd) {
    var cs = window.getComputedStyle(hd);
    if (cs.position === 'sticky' || cs.position === 'fixed' || hd.getBoundingClientRect().top < 70) {
      hd.style.paddingRight = (parseFloat(cs.paddingRight || 0) + 46) + 'px';
    }
  }
}

function paintBell() {
  var hb = document.getElementById('apsaHam');
  if (hb) {
    hb.classList.toggle('has', NOTIF.unread > 0);
    var hd = hb.querySelector('.hdot');
    if (hd) hd.textContent = NOTIF.unread > 99 ? '99+' : String(NOTIF.unread);
  }
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
    pullHome();
    pullSideGlobal();          // rồi áp thiết lập riêng của user

    nav.addEventListener('click', function (e) {
      if (e.target.closest && e.target.closest('#apsaBell')) { e.preventDefault(); openNotif(); }
    });
    ensureHam();
    pullNotif();
    clearInterval(noTimer);
    noTimer = setInterval(pullNotif, 60000);      // 1 phút kiểm tra 1 lần
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeNotif(); });
  }

  /* Đọc thiết lập trang chủ (thứ tự · ghim · đã ẩn) — dùng chung cho cả sidebar */
  /* ── APSA1215: thu tu thanh menu dung chung ca cong ty (Admin dat o Cai dat) ── */
function pullSideGlobal() {
  fetch('./api/settings-api.php?action=sidebar', { credentials: 'same-origin' })
    .then(function (r) { return r.json(); })
    .then(function (j) {
      if (!j || !j.ok) return;
      GLOB = { order: j.order || [], hidden: j.hidden || [] };
      paint(layout(window.__APSA_HOME_PREFS || null));
    })
    .catch(function () {});
}

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
  window.apsaRefreshNotif = function () { pullNotif(paintNotif); };

  window.apsaSyncSidebar = function (prefs) {
    var v = prefs || window.__APSA_HOME_PREFS;
    if (v) { window.__APSA_HOME_PREFS = v; paint(layout(v)); }
  };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', build);
  else build();
})();

/* ==========================================================================
   APSA v1.2.5
   1) Khoa pop-up: khong dong khi click ra ngoai hoac bam Esc
   2) Thong bao noi (toast) o goc duoi ben trai, nhieu cai xep chong len tren
   ========================================================================== */
(function () {
  'use strict';

  /* ------------------------------------------------------------------ */
  /* 1. KHOA POP-UP                                                      */
  /* ------------------------------------------------------------------ */

  var OVERLAY_RE = /(^|[\s\-_])(mask|modal|overlay|backdrop|scrim|dimmer)([\s\-_]|$)/i;

  function isOverlay(el) {
    if (!el || el.nodeType !== 1 || !el.getAttribute) return false;
    var cls = el.getAttribute('class') || '';
    if (cls && OVERLAY_RE.test(cls)) return true;
    var oc = (el.getAttribute('onclick') || '').replace(/\s+/g, '');
    return oc.indexOf('event.target===this') === 0 || oc.indexOf('closeModalOutside') === 0;
  }

  function overlayVisible() {
    var els = document.querySelectorAll(
      '.mask,[class*="mask"],[class*="modal"],[class*="overlay"],[class*="backdrop"]');
    for (var i = 0; i < els.length; i++) {
      var el = els[i];
      if (!el.getClientRects || !el.getClientRects().length) continue;
      if (el.offsetWidth < 200 || el.offsetHeight < 100) continue;
      var st = window.getComputedStyle(el);
      if (st.display === 'none' || st.visibility === 'hidden') continue;
      if (parseFloat(st.opacity || '1') < 0.05) continue;
      return true;
    }
    return false;
  }

  /* Chan o pha capture -> handler cua tung trang khong bao gio chay */
  document.addEventListener('mousedown', function (e) {
    if (isOverlay(e.target)) e.stopPropagation();
  }, true);

  document.addEventListener('click', function (e) {
    if (isOverlay(e.target)) { e.preventDefault(); e.stopPropagation(); }
  }, true);

  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape' && e.keyCode !== 27) return;
    var a = document.activeElement;
    if (a && (a.tagName === 'SELECT' || a.isContentEditable)) return;
    if (overlayVisible()) e.stopPropagation();
  }, true);

  /* ------------------------------------------------------------------ */
  /* 2. TOAST THONG BAO                                                  */
  /* ------------------------------------------------------------------ */

  var SEEN_KEY  = 'apsa_toast_seen';

  /* ── APSA1214: tieng chuong ngan khi co thong bao moi (WebAudio, khong can file) ── */
  var SOUND_KEY = 'apsa_notif_sound';
  var ntActx = null;

  function ntSoundOn() {
    try { return localStorage.getItem(SOUND_KEY) !== '0'; } catch (e) { return true; }
  }

  function ntDing(force) {
    if (!force && !ntSoundOn()) return;
    try {
      var AC = window.AudioContext || window.webkitAudioContext;
      if (!AC) return;
      if (!ntActx) ntActx = new AC();
      if (ntActx.state === 'suspended') ntActx.resume();
      var t0 = ntActx.currentTime;
      var notes = [[880, 0], [1318.5, 0.13]];
      for (var i = 0; i < notes.length; i++) {
        var f = notes[i][0], d = notes[i][1];
        var o = ntActx.createOscillator(), g = ntActx.createGain();
        o.type = 'sine';
        o.frequency.setValueAtTime(f, t0 + d);
        g.gain.setValueAtTime(0.0001, t0 + d);
        g.gain.exponentialRampToValueAtTime(0.16, t0 + d + 0.02);
        g.gain.exponentialRampToValueAtTime(0.0001, t0 + d + 0.30);
        o.connect(g); g.connect(ntActx.destination);
        o.start(t0 + d); o.stop(t0 + d + 0.34);
      }
    } catch (e) {}
  }
  var POLL_MS   = 45000;
  var LIFE_MS   = 15000;
  var MAX_TOAST = 4;

  var ICONS = {
    mention:          '@',
    reply:            '↩',
    assign:           '✓',
    task_done:        '✓',
    leave_request:    '⚑',
    leave_approved:   '⚑',
    leave_rejected:   '⚑',
    reopen_request:   '↺',
    project_closed:   '■',
    project_reopened: '□'
  };

  function seenLoad() {
    try { var o = JSON.parse(localStorage.getItem(SEEN_KEY) || '{}'); return o && typeof o === 'object' ? o : {}; }
    catch (e) { return {}; }
  }
  function seenSave(o) {
    try {
      var ks = Object.keys(o);
      if (ks.length > 300) { ks.sort(function (a, b) { return a - b; }); for (var i = 0; i < ks.length - 200; i++) delete o[ks[i]]; }
      localStorage.setItem(SEEN_KEY, JSON.stringify(o));
    } catch (e) { /* bo qua */ }
  }

  function css() {
    if (document.getElementById('apsa-nt-css')) return;
    var s = document.createElement('style');
    s.id = 'apsa-nt-css';
    s.textContent =
      '#apsaNtWrap{position:fixed;right:18px;bottom:18px;z-index:99999;display:flex;' +
      'flex-direction:column-reverse;gap:10px;max-width:360px;pointer-events:none}' +
      '.apsa-nt{pointer-events:auto;display:flex;gap:10px;align-items:flex-start;' +
      'background:#14161c;border:1px solid #2a2f3a;border-left:3px solid #39ff88;' +
      'border-radius:10px;padding:11px 12px;box-shadow:0 10px 30px rgba(0,0,0,.45);' +
      'color:#e8ebf0;font-size:12.5px;line-height:1.45;cursor:pointer;' +
      'transform:translateX(120%);opacity:0;transition:transform .28s cubic-bezier(.2,.9,.3,1.2),opacity .28s}' +
      '.apsa-nt.on{transform:translateX(0);opacity:1}' +
      '.apsa-nt.out{transform:translateX(120%);opacity:0}' +
      '.apsa-nt .ti{flex:0 0 22px;height:22px;border-radius:6px;background:#1e2430;display:flex;' +
      'align-items:center;justify-content:center;font-weight:700;color:#39ff88;font-size:12px}' +
      '.apsa-nt .tb{flex:1;min-width:0}' +
      '.apsa-nt .tt{font-weight:700;margin-bottom:2px}' +
      '.apsa-nt .td{color:#9aa3b2;overflow:hidden;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical}' +
      '.apsa-nt .tx{flex:0 0 auto;color:#6b7280;font-size:15px;line-height:1;padding:0 2px;' +
      'background:none;border:0;cursor:pointer}' +
      '.apsa-nt .tx:hover{color:#e8ebf0}';
    document.head.appendChild(s);
  }

  function wrap() {
    var w = document.getElementById('apsaNtWrap');
    if (!w) {
      w = document.createElement('div');
      w.id = 'apsaNtWrap';
      document.body.appendChild(w);
    }
    return w;
  }

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  function markRead(id) {
    try {
      fetch('/api/auth-api.php?action=notif-read', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id: id })
      });
    } catch (e) { /* bo qua */ }
  }

  function show(n) {
    css();
    var w  = wrap();
    var el = document.createElement('div');
    el.className = 'apsa-nt';
    var ic = ICONS[n.kind] || '•';
    el.innerHTML =
      '<div class="ti">' + esc(ic) + '</div>' +
      '<div class="tb"><div class="tt">' + esc(n.title || 'Thông báo') + '</div>' +
      (n.body ? '<div class="td">' + esc(n.body) + '</div>' : '') + '</div>' +
      '<button class="tx" title="Đóng">&times;</button>';

    var gone = false;
    function close(read) {
      if (gone) return;
      gone = true;
      el.classList.add('out');
      setTimeout(function () { if (el.parentNode) el.parentNode.removeChild(el); }, 300);
      if (read) markRead(n.id);
    }

    el.querySelector('.tx').addEventListener('click', function (ev) {
      ev.stopPropagation();
      close(true);
    });
    el.addEventListener('click', function () {
      markRead(n.id);
      if (n.url) location.href = n.url;
      else close(false);
    });

    w.appendChild(el);
    while (w.children.length > MAX_TOAST) w.removeChild(w.firstChild);
    setTimeout(function () { el.classList.add('on'); }, 20);
    setTimeout(function () { close(false); }, LIFE_MS);
  }

  var busy = false;
  function poll(first) {
    if (busy || document.hidden) return;
    busy = true;
    fetch('/api/auth-api.php?action=notif-list&limit=15', { credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (j) {
        busy = false;
        if (!j) return;
        if (j.data && j.data.rows) j = j.data;
        if (!j.rows) return;
        var seen = seenLoad(), fresh = [], i, n;
        for (i = 0; i < j.rows.length; i++) {
          n = j.rows[i];
          if (Number(n.is_read) === 1) { seen[n.id] = 1; continue; }
          if (seen[n.id]) continue;
          seen[n.id] = 1;
          fresh.push(n);
        }
        seenSave(seen);
        if (first && fresh.length > MAX_TOAST) fresh = fresh.slice(0, MAX_TOAST);
        fresh.reverse();
        if (fresh.length && !first) ntDing();
        if (fresh.length && typeof window.apsaRefreshNotif === 'function') window.apsaRefreshNotif();
        /* APSA1215: khong bat toast nua — thong bao gom vao bang ben phai */
        if (typeof window.apsaOnNotif === 'function') { try { window.apsaOnNotif(j); } catch (e) {} }
      })
      .catch(function () { busy = false; });
  }

  function boot() {
    if (!document.body) return;
    setTimeout(function () { poll(true); }, 1500);
    setInterval(function () { poll(false); }, POLL_MS);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) poll(false); });
    window.apsaNotifyToast = show;
    window.apsaNotifyDing  = ntDing;
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();

})();

/* ═══════════ APSA v1.2.16 · Combo box: gõ để tìm trong dropdown ═══════════
   Nâng cấp <select data-combo> thành ô gõ được + gợi ý.
   Thẻ <select> gốc vẫn nằm nguyên trong DOM (chỉ ẩn đi) nên mọi đoạn code
   cũ đọc/ghi .value và bắt sự kiện change vẫn chạy y như trước. */
(function () {
  'use strict';

  var path = (location.pathname || '').toLowerCase();
  if (/login|share|view-album|public/.test(path)) return;

  function css() {
    return '' +
      '.apsa-cb{ position:relative; display:block; }' +
      '.apsa-cb > select.apsa-cb-raw{ position:absolute !important; left:0; top:0;' +
      ' width:100% !important; height:100% !important; opacity:0 !important;' +
      ' pointer-events:none !important; }' +
      '.apsa-cb > input.apsa-cb-inp{ width:100%; padding-right:30px; }' +
      '.apsa-cb > .apsa-cb-ar{ position:absolute; right:11px; top:50%; width:12px; height:12px;' +
      ' margin-top:-6px; pointer-events:none; opacity:.75;' +
      ' background:no-repeat center/12px url("data:image/svg+xml;charset=utf-8,' +
      '%3Csvg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 24 24\' fill=\'none\'' +
      ' stroke=\'%239a9a9a\' stroke-width=\'2.2\' stroke-linecap=\'round\' stroke-linejoin=\'round\'' +
      '%3E%3Cpath d=\'M6 9l6 6 6-6\'/%3E%3C/svg%3E"); }' +
      '.apsa-cb-list{ display:none; position:absolute; left:0; right:0; top:calc(100% + 5px);' +
      ' z-index:400; max-height:262px; overflow-y:auto; padding:5px;' +
      ' background:#0e0e0e; border:1px solid rgba(255,255,255,.14); border-radius:11px;' +
      ' box-shadow:0 18px 44px rgba(0,0,0,.7); font-family:"Oxanium",sans-serif; }' +
      '.apsa-cb.open .apsa-cb-list{ display:block; }' +
      '.apsa-cb-it{ padding:8px 10px; border-radius:8px; font-size:13px; color:#d6d6d6;' +
      ' cursor:pointer; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }' +
      '.apsa-cb-it:hover{ background:rgba(255,255,255,.06); color:#fff; }' +
      '.apsa-cb-it.on{ background:rgba(223,242,13,.14); color:#eaff6b; }' +
      '.apsa-cb-it b{ color:#dff20d; font-weight:700; }' +
      '.apsa-cb-no{ padding:12px 10px; font-size:12.5px; color:#5e5e5e; text-align:center; }';
  }

  function injectCss() {
    if (document.getElementById('apsa-cb-css')) return;
    var s = document.createElement('style');
    s.id = 'apsa-cb-css';
    s.textContent = css();
    document.head.appendChild(s);
  }

  /* Bỏ dấu tiếng Việt để gõ "phuong loan" vẫn ra "Phương Loan" */
  function flat(v) {
    return String(v == null ? '' : v)
      .toLowerCase()
      .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
      .replace(/đ/g, 'd');
  }

  function esc(v) {
    return String(v == null ? '' : v).replace(/[&<>"]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
    });
  }

  /* Tô đậm đoạn khớp */
  function mark(text, q) {
    if (!q) return esc(text);
    var i = flat(text).indexOf(q);
    if (i < 0) return esc(text);
    return esc(text.slice(0, i)) + '<b>' + esc(text.slice(i, i + q.length)) + '</b>' + esc(text.slice(i + q.length));
  }

  function build(sel) {
    if (sel.__apsaCb) return;
    sel.__apsaCb = true;
    injectCss();

    var wrap = document.createElement('div');
    wrap.className = 'apsa-cb';
    sel.parentNode.insertBefore(wrap, sel);
    wrap.appendChild(sel);
    sel.classList.add('apsa-cb-raw');
    sel.setAttribute('tabindex', '-1');

    var inp = document.createElement('input');
    inp.type = 'text';
    inp.className = 'apsa-cb-inp';
    inp.autocomplete = 'off';
    inp.spellcheck = false;
    inp.placeholder = sel.getAttribute('data-ph') || 'Gõ để tìm…';
    if (sel.disabled) inp.disabled = true;
    wrap.appendChild(inp);

    var ar = document.createElement('span');
    ar.className = 'apsa-cb-ar';
    wrap.appendChild(ar);

    var list = document.createElement('div');
    list.className = 'apsa-cb-list';
    wrap.appendChild(list);

    var rows = [], cur = -1;

    function labelOf() {
      var o = sel.options[sel.selectedIndex];
      return o ? o.text : '';
    }

    function paint(q) {
      var all = [].slice.call(sel.options);
      rows = q ? all.filter(function (o) { return flat(o.text).indexOf(q) >= 0; }) : all;
      cur = rows.length ? 0 : -1;
      list.innerHTML = rows.length
        ? rows.map(function (o, i) {
            return '<div class="apsa-cb-it' + (i === cur ? ' on' : '') + '" data-i="' + i + '">' + mark(o.text, q) + '</div>';
          }).join('')
        : '<div class="apsa-cb-no">Không tìm thấy</div>';
    }

    function highlight() {
      var els = list.querySelectorAll('.apsa-cb-it');
      for (var i = 0; i < els.length; i++) els[i].classList.toggle('on', i === cur);
      if (cur >= 0 && els[cur]) {
        var el = els[cur], lt = list.scrollTop, lb = lt + list.clientHeight;
        if (el.offsetTop < lt) list.scrollTop = el.offsetTop;
        else if (el.offsetTop + el.offsetHeight > lb) list.scrollTop = el.offsetTop + el.offsetHeight - list.clientHeight;
      }
    }

    function open(q) {
      paint(q || '');
      wrap.classList.add('open');
      highlight();
    }

    function close(restore) {
      wrap.classList.remove('open');
      if (restore !== false) inp.value = labelOf();
    }

    function pick(i) {
      var o = rows[i];
      if (!o) return;
      sel.value = o.value;
      inp.value = o.text;
      close(false);
      sel.dispatchEvent(new Event('change', { bubbles: true }));
    }

    inp.addEventListener('focus', function () { inp.select(); open(''); });
    inp.addEventListener('input', function () { open(flat(inp.value)); });
    inp.addEventListener('blur', function () { setTimeout(function () { close(true); }, 140); });

    inp.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        if (!wrap.classList.contains('open')) { open(''); return; }
        if (!rows.length) return;
        cur += (e.key === 'ArrowDown') ? 1 : -1;
        if (cur < 0) cur = rows.length - 1;
        if (cur >= rows.length) cur = 0;
        highlight();
      } else if (e.key === 'Enter') {
        if (wrap.classList.contains('open') && cur >= 0) { e.preventDefault(); pick(cur); }
      } else if (e.key === 'Escape') {
        if (wrap.classList.contains('open')) { e.stopPropagation(); close(true); }
      }
    });

    list.addEventListener('mousedown', function (e) {
      var it = e.target.closest ? e.target.closest('.apsa-cb-it') : null;
      if (!it) return;
      e.preventDefault();
      pick(Number(it.getAttribute('data-i')));
    });

    ar.addEventListener('mousedown', function (e) { e.preventDefault(); inp.focus(); });

    /* Trang tự nạp lại danh sách option hoặc đổi .value thì ô gõ phải theo */
    inp.value = labelOf();
    try {
      new MutationObserver(function () {
        inp.disabled = sel.disabled;
        if (!wrap.classList.contains('open')) inp.value = labelOf();
      }).observe(sel, { childList: true, subtree: true, attributes: true });
    } catch (e) {}
    sel.addEventListener('change', function () {
      if (!wrap.classList.contains('open')) inp.value = labelOf();
    });
    setInterval(function () {
      if (!wrap.classList.contains('open') && document.activeElement !== inp && inp.value !== labelOf()) {
        inp.value = labelOf();
      }
    }, 700);
  }

  function scan() {
    var els = document.querySelectorAll('select[data-combo]');
    for (var i = 0; i < els.length; i++) build(els[i]);
  }

  function boot() {
    scan();
    setTimeout(scan, 900);
    setTimeout(scan, 2500);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();

  window.apsaCombo = build;
})();

/* ── APSA1222: khoa click ra ngoai de dong pop-up (ap dung moi trang) ──
   Chi chan dung lop nen (backdrop) cua hop thoai: phan tu position:fixed
   phu gan kin man hinh, boc mot hop noi dung va co nut bam ben trong.
   Bo qua: thanh menu / bang thong bao cua apsa-ui (id bat dau bang "apsa")
           va cac lop phu khong co gi de bam (vd trinh xem anh) -> van dong duoc. */
(function () {
  'use strict';

  function interactive(el) {
    return el.querySelector('button, a, input, select, textarea, [onclick], [contenteditable="true"]') !== null;
  }

  /* APSA1223: lop phu chi de XEM (anh / video / nhung) thi khong khoa.
     Nhan dien: co danh dau data-apsa-free, hoac ten lop/id la lb|lightbox|viewer,
     hoac ben trong khong co o nhap lieu ma co mot anh/video chiem phan lon dien tich. */
  function isViewer(el) {
    if (el.hasAttribute && el.hasAttribute('data-apsa-free')) return true;
    var tag = ' ' + String(el.className || '') + ' ' + String(el.id || '') + ' ';
    if (/(^|[\s_-])(lb|lightbox|viewer)([\s_-]|$)/i.test(tag)) return true;
    if (el.querySelector('input, select, textarea, [contenteditable="true"]')) return false;
    var m = el.querySelector('img, video, iframe');
    if (!m) return false;
    var mr = m.getBoundingClientRect(), er = el.getBoundingClientRect();
    if (!er.width || !er.height) return false;
    return (mr.width * mr.height) >= (er.width * er.height) * 0.25;
  }
  function isBackdrop(el) {
    if (!el || el.nodeType !== 1) return false;
    if (el === document.body || el === document.documentElement) return false;
    if (el.id && el.id.indexOf('apsa') === 0) return false;
    var cs;
    try { cs = window.getComputedStyle(el); } catch (e) { return false; }
    if (cs.position !== 'fixed') return false;
    if (cs.display === 'none' || cs.visibility === 'hidden') return false;
    var r = el.getBoundingClientRect();
    if (r.width < window.innerWidth * 0.9 || r.height < window.innerHeight * 0.9) return false;
    if (!el.firstElementChild) return false;
    if (isViewer(el)) return false;                 /* APSA1223: trinh xem anh -> van bam ra ngoai de dong */
    return interactive(el);
  }

  function guard(e) {
    if (typeof e.button === 'number' && e.button !== 0) return;
    if (!isBackdrop(e.target)) return;
    e.preventDefault();
    e.stopPropagation();
    if (e.stopImmediatePropagation) e.stopImmediatePropagation();
  }

  var ev = ['mousedown', 'mouseup', 'click', 'dblclick'];
  for (var i = 0; i < ev.length; i++) document.addEventListener(ev[i], guard, true);

  window.apsaModalLockOutside = true;
})();
