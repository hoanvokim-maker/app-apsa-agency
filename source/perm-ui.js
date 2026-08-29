/* perm-ui.js — tab "Phan quyen" trong settings.html
 * Nap SAU script chinh cua settings.html (can ham tab(), toast(), api()).
 * v1
 */
(function () {
  'use strict';

  var Q = String.fromCharCode(63);
  var API = 'api/settings-api.php';
  var D = null;          // du lieu tu perm-get
  var LOADED = false;
  var LV = [
    { v: 0, t: 'Không vào được' },
    { v: 1, t: 'Chỉ xem' },
    { v: 2, t: 'Toàn quyền' }
  ];

  function esc(s) {
    return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }
  function el(id) { return document.getElementById(id); }
  function say(m, kind) {
    if (typeof window.toast === 'function') window.toast(m, kind);
    else console.log(m);
  }
  async function call(action, body) {
    var o = { credentials: 'same-origin' };
    if (body) {
      o.method = 'POST';
      o.headers = { 'Content-Type': 'application/json' };
      o.body = JSON.stringify(body);
    }
    var r = await fetch(API + Q + 'action=' + action, o);
    var j = await r.json().catch(function () { return {}; });
    if (!r.ok || j.ok === false) throw new Error(j.error || ('HTTP ' + r.status));
    return j;
  }

  /* muc quyen hien tai cua 1 doi tuong (chua co luat => mac dinh cua nhom) */
  function lvOf(scope, key, g) {
    var r = (D.rules && D.rules[scope]) ? D.rules[scope] : {};
    if (r && r[key] && r[key][g.key] !== undefined) return Number(r[key][g.key]);
    return Number(g.def);
  }
  function isSet(scope, key) {
    var r = (D.rules && D.rules[scope]) ? D.rules[scope] : {};
    return !!(r && r[key]);
  }

  function selHtml(scope, key, g, cur) {
    var dis = g.adminOnly ? ' disabled' : '';
    var h = '<select class="pmsel" data-scope="' + scope + '" data-key="' + esc(key) +
      '" data-g="' + g.key + '"' + dis + '>';
    for (var i = 0; i < LV.length; i++) {
      h += '<option value="' + LV[i].v + '"' + (Number(cur) === LV[i].v ? ' selected' : '') +
        '>' + LV[i].t + '</option>';
    }
    return h + '</select>';
  }

  function matrixHtml() {
    var gs = D.groups, ps = D.positions;
    var h = '<div class="pmwrap"><table class="pmtbl"><thead><tr>' +
      '<th class="pmg">Nhóm module</th>';
    for (var j = 0; j < ps.length; j++) h += '<th>' + esc(ps[j].label) + '</th>';
    h += '</tr></thead><tbody>';
    for (var i = 0; i < gs.length; i++) {
      var g = gs[i];
      h += '<tr' + (g.adminOnly ? ' class="pmadmin"' : '') + '><td class="pmg">' +
        '<b>' + esc(g.name) + '</b><span>' + esc(g.note) + '</span>' +
        (g.needs && g.needs.length ? '<i>cần có quyền xem: ' + esc(needNames(g.needs)) + '</i>' : '') +
        '</td>';
      for (var k = 0; k < ps.length; k++) {
        h += '<td>' + (g.adminOnly
          ? '<span class="pmna">Chỉ Admin</span>'
          : selHtml('pos', ps[k].key, g, lvOf('pos', ps[k].key, g))) + '</td>';
      }
      h += '</tr>';
    }
    h += '</tbody></table></div>';
    return h;
  }

  function needNames(keys) {
    var out = [];
    for (var i = 0; i < keys.length; i++) {
      for (var j = 0; j < D.groups.length; j++) {
        if (D.groups[j].key === keys[i]) out.push(D.groups[j].name);
      }
    }
    return out.join(', ');
  }

  function userHtml() {
    var h = '<div class="pmuser"><div class="fg"><label>Chọn nhân sự cần đặt quyền riêng</label>' +
      '<select id="pmUser"><option value="">— mặc định theo vị trí —</option>';
    for (var i = 0; i < D.users.length; i++) {
      var u = D.users[i];
      if (u.admin) continue;
      h += '<option value="' + u.id + '">' + esc(u.name || u.username) +
        (u.pos ? ' · ' + esc(u.pos) : '') + (isSet('user', String(u.id)) ? ' — có quyền riêng' : '') +
        '</option>';
    }
    h += '</select></div><div id="pmUserBox" class="pmubox"></div></div>';
    return h;
  }

  function renderUserBox() {
    var box = el('pmUserBox');
    var id = el('pmUser').value;
    if (!id) { box.innerHTML = '<div class="hint">Chọn một người để đặt quyền riêng, khác với vị trí của họ.</div>'; return; }
    var u = null;
    for (var i = 0; i < D.users.length; i++) if (String(D.users[i].id) === String(id)) u = D.users[i];
    if (!u) { box.innerHTML = ''; return; }
    var pos = u.pos || '-';
    var h = '<div class="pmurow"><b>' + esc(u.name || u.username) + '</b> · vị trí <b>' + esc(pos) + '</b>' +
      (isSet('user', id) ? ' <span class="pmtag">đang dùng quyền riêng</span>'
                         : ' <span class="pmtag off">đang theo vị trí</span>') + '</div>';
    h += '<div class="pmugrid">';
    for (var k = 0; k < D.groups.length; k++) {
      var g = D.groups[k];
      var cur = isSet('user', id) ? lvOf('user', id, g) : lvOf('pos', pos, g);
      h += '<div class="fg"><label>' + esc(g.name) + '</label>' +
        (g.adminOnly ? '<div class="pmna">Chỉ Admin</div>' : selHtml('user', id, g, cur)) + '</div>';
    }
    h += '</div>';
    h += '<div class="pmacts">' +
      '<button class="btn primary" onclick="pmSaveUser()">Lưu quyền riêng cho người này</button>' +
      '<button class="btn" onclick="pmResetUser()">Bỏ quyền riêng, dùng theo vị trí</button></div>';
    box.innerHTML = h;
  }

  function paneHtml() {
    return '<div class="card">' +
      '<h2>Phân quyền hệ thống</h2>' +
      '<div class="hint">Các module liên quan nhau được gom chung một nhóm để quyền không bị xung đột — ' +
      'ví dụ <b>Chi phí thực tế</b> đọc dữ liệu của <b>Báo giá</b> nên nằm chung nhóm. ' +
      'Admin luôn có toàn quyền. Quyền được chặn cả ở giao diện lẫn ở API.</div>' +
      matrixHtml() +
      '<div class="pmacts"><button class="btn primary" onclick="pmSaveAll()">Lưu phân quyền theo vị trí</button>' +
      '<span class="hint" id="pmMsg"></span></div>' +
      '</div>' +
      '<div class="card"><h2>Quyền riêng theo từng người</h2>' +
      '<div class="hint">Dùng khi một người cần quyền khác với vị trí của họ. Quyền riêng luôn thắng quyền của vị trí.</div>' +
      userHtml() + '</div>';
  }

  /* ---- rang buoc phu thuoc: nhom A can nhom B thi B toi thieu "Chi xem" ---- */
  function fixNeeds(scope, key) {
    var changed = [];
    for (var i = 0; i < D.groups.length; i++) {
      var g = D.groups[i];
      if (!g.needs || !g.needs.length) continue;
      var cur = readSel(scope, key, g.key);
      if (cur === null || cur <= 0) continue;
      for (var j = 0; j < g.needs.length; j++) {
        var nk = g.needs[j];
        var nv = readSel(scope, key, nk);
        if (nv !== null && nv < 1) { writeSel(scope, key, nk, 1); changed.push(nameOf(nk)); }
      }
    }
    return changed;
  }
  function nameOf(k) {
    for (var i = 0; i < D.groups.length; i++) if (D.groups[i].key === k) return D.groups[i].name;
    return k;
  }
  function sel(scope, key, g) {
    return document.querySelector('.pmsel[data-scope="' + scope + '"][data-key="' + key + '"][data-g="' + g + '"]');
  }
  function readSel(scope, key, g) { var s = sel(scope, key, g); return s ? Number(s.value) : null; }
  function writeSel(scope, key, g, v) { var s = sel(scope, key, g); if (s) s.value = String(v); }

  function collect(scope, key) {
    var out = {};
    var all = document.querySelectorAll('.pmsel[data-scope="' + scope + '"][data-key="' + key + '"]');
    for (var i = 0; i < all.length; i++) out[all[i].getAttribute('data-g')] = Number(all[i].value);
    return out;
  }

  window.pmSaveAll = async function () {
    try {
      var msg = el('pmMsg');
      var fixed = [];
      for (var i = 0; i < D.positions.length; i++) {
        var k = D.positions[i].key;
        fixed = fixed.concat(fixNeeds('pos', k));
      }
      for (var j = 0; j < D.positions.length; j++) {
        var pk = D.positions[j].key;
        await call('perm-save', { scope: 'pos', key: pk, vals: collect('pos', pk) });
      }
      if (msg) msg.textContent = 'Đã lưu lúc ' + new Date().toLocaleTimeString('vi-VN');
      say('Đã lưu phân quyền theo vị trí' + (fixed.length ? ' (tự mở quyền xem cho: ' + fixed.join(', ') + ')' : ''), 'ok');
      await load(true);
    } catch (e) { say('Không lưu được: ' + e.message, 'err'); }
  };

  window.pmSaveUser = async function () {
    var id = el('pmUser').value;
    if (!id) return;
    try {
      fixNeeds('user', id);
      await call('perm-save', { scope: 'user', key: id, vals: collect('user', id) });
      say('Đã lưu quyền riêng cho người này', 'ok');
      await load(true);
    } catch (e) { say('Không lưu được: ' + e.message, 'err'); }
  };

  window.pmResetUser = async function () {
    var id = el('pmUser').value;
    if (!id) return;
    try {
      await call('perm-reset', { scope: 'user', key: id });
      say('Đã bỏ quyền riêng, người này dùng quyền theo vị trí', 'ok');
      await load(true);
    } catch (e) { say('Không bỏ được: ' + e.message, 'err'); }
  };

  async function load(keepUser) {
    var pane = el('p-perm');
    if (!pane) return;
    var keep = keepUser && el('pmUser') ? el('pmUser').value : '';
    pane.innerHTML = '<div class="card"><div class="hint">Đang tải phân quyền…</div></div>';
    try {
      D = await call('perm-get');
      pane.innerHTML = paneHtml();
      var u = el('pmUser');
      if (u) {
        if (keep) u.value = keep;
        u.addEventListener('change', renderUserBox);
        renderUserBox();
      }
      LOADED = true;
    } catch (e) {
      pane.innerHTML = '<div class="card"><div class="hint">Không tải được phân quyền: ' + esc(e.message) + '</div></div>';
    }
  }

  /* ---- gan tab vao trang ---- */
  function mount() {
    var tabs = document.querySelector('.tabs');
    var wrap = el('wrap');
    if (!tabs || !wrap || el('p-perm')) return;

    var b = document.createElement('button');
    b.className = 'tab';
    b.setAttribute('data-p', 'perm');
    b.textContent = 'Phân quyền';
    b.onclick = function () { if (typeof window.tab === 'function') window.tab('perm'); };
    var after = tabs.querySelector('.tab[data-p="position"]');
    if (after && after.nextSibling) tabs.insertBefore(b, after.nextSibling);
    else tabs.appendChild(b);

    var p = document.createElement('div');
    p.className = 'pane';
    p.id = 'p-perm';
    wrap.appendChild(p);

    var _tab = window.tab;
    window.tab = function (n) {
      var out = _tab ? _tab.apply(this, arguments) : undefined;
      if (n === 'perm' && !LOADED) load(false);
      return out;
    };

    var css = document.createElement('style');
    css.textContent =
      '.pmwrap{overflow-x:auto;margin-top:14px}' +
      '.pmtbl{width:100%;border-collapse:collapse;font-size:13px;min-width:820px}' +
      '.pmtbl th,.pmtbl td{border:1px solid var(--line,#333);padding:8px 10px;text-align:left;vertical-align:top}' +
      '.pmtbl thead th{font-size:10px;letter-spacing:.08em;text-transform:uppercase;color:var(--text3,#888);' +
      'background:var(--bg2,#141414);white-space:nowrap}' +
      '.pmtbl td.pmg,.pmtbl th.pmg{min-width:230px}' +
      '.pmtbl td.pmg b{display:block;font-size:13px}' +
      '.pmtbl td.pmg span{display:block;font-size:10.5px;color:var(--text3,#888);line-height:1.45;margin-top:3px}' +
      '.pmtbl td.pmg i{display:block;font-size:10.5px;color:#fdba74;font-style:normal;margin-top:4px}' +
      '.pmtbl tr.pmadmin td{opacity:.6}' +
      '.pmsel{width:100%;min-width:128px;background:var(--bg2,#141414);color:inherit;font-family:inherit;' +
      'font-size:12.5px;border:1px solid var(--line,#333);border-radius:7px;padding:6px 8px}' +
      '.pmna{font-size:11px;color:var(--text3,#888)}' +
      '.pmacts{display:flex;gap:10px;align-items:center;margin-top:16px;flex-wrap:wrap}' +
      '.pmubox{margin-top:14px}' +
      '.pmurow{font-size:13px;margin-bottom:12px}' +
      '.pmtag{font-size:10.5px;border:1px solid #fdba74;color:#fdba74;border-radius:20px;padding:2px 8px;margin-left:6px}' +
      '.pmtag.off{border-color:var(--line,#333);color:var(--text3,#888)}' +
      '.pmugrid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:12px}';
    document.head.appendChild(css);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mount);
  else mount();
})();

/* ---- tab Phan quyen can nhieu chieu ngang hon cac tab khac ---- */
(function () {
  'use strict';
  var css = document.createElement('style');
  css.textContent = 'body.pm-wide #p-perm .card{max-width:1560px}';
  (document.head || document.documentElement).appendChild(css);
  function hook() {
    var _t = window.tab;
    window.tab = function (n) {
      var out = _t ? _t.apply(this, arguments) : undefined;
      try { document.body.classList[n === 'perm' ? 'add' : 'remove']('pm-wide'); } catch (e) {}
      return out;
    };
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', hook);
  else hook();
})();
