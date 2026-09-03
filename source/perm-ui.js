/* =====================================================================
 *  perm-ui.js  —  Phan quyen theo VI TRI, chi tiet toi tung MODULE
 *  Gan them tab "Phan quyen" vao trang settings.html
 *  v1.6.27
 * ===================================================================*/
(function () {
  'use strict';

  var API = 'api/settings-api.php';
  var Q = String.fromCharCode(63);
  var D = null;          /* { groups, mods, positions, users, rules } */
  var LOADED = false;
  var CURPOS = null;     /* vi tri dang chon */

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

  /* ---- tra cuu ---- */
  function grpOf(key) {
    for (var i = 0; i < D.groups.length; i++) if (D.groups[i].key === key) return D.groups[i];
    return null;
  }
  function modsOf(key) {
    var out = [];
    for (var i = 0; i < D.mods.length; i++) if (D.mods[i].grp === key) out.push(D.mods[i]);
    return out;
  }
  function ruleOf(scope, skey, k) {
    var r = (D.rules && D.rules[scope]) ? D.rules[scope] : {};
    var b = r[skey] || {};
    return (b[k] === undefined || b[k] === null) ? null : Number(b[k]);
  }
  /* Muc that su ap dung cho 1 module, theo dung thu tu cua perm.php */
  function effMod(scope, skey, m) {
    var g = grpOf(m.grp);
    if (!g) return 0;
    if (g.adminOnly) return 0;
    var chain = scope === 'user' ? [['user', skey], ['pos', posOfUser(skey)]] : [['pos', skey]];
    for (var i = 0; i < chain.length; i++) {
      if (!chain[i][1]) continue;
      var a = ruleOf(chain[i][0], chain[i][1], 'm:' + m.id);
      if (a !== null) return a;
      var b = ruleOf(chain[i][0], chain[i][1], m.grp);
      if (b !== null) return b;
    }
    return Number(g.def || 0);
  }
  function posOfUser(id) {
    for (var i = 0; i < D.users.length; i++) {
      if (String(D.users[i].id) === String(id)) return D.users[i].pos || '-';
    }
    return '-';
  }
  function lvText(v) {
    for (var i = 0; i < LV.length; i++) if (LV[i].v === v) return LV[i].t;
    return '?';
  }

  /* ---- ve 1 the select ---- */
  function selHtml(scope, skey, kind, k, cur, dflt) {
    var h = '<select class="pmsel" data-scope="' + scope + '" data-skey="' + esc(skey) +
      '" data-kind="' + kind + '" data-k="' + esc(k) + '">';
    h += '<option value=""' + (cur === null ? ' selected' : '') + '>— ' +
      esc(dflt) + ' —</option>';
    for (var i = 0; i < LV.length; i++) {
      h += '<option value="' + LV[i].v + '"' + (cur === LV[i].v ? ' selected' : '') + '>' +
        LV[i].t + '</option>';
    }
    return h + '</select>';
  }

  /* ---- ve ma tran cho 1 doi tuong (1 vi tri hoac 1 user) ---- */
  function matrixHtml(scope, skey) {
    if (!skey) return '<div class="pmna">Chọn một đối tượng để bắt đầu.</div>';
    var h = '';
    for (var i = 0; i < D.groups.length; i++) {
      var g = D.groups[i];
      var ms = modsOf(g.key);
      if (g.adminOnly) {
        h += '<div class="pmgrp locked"><div class="pmgh"><b>' + esc(g.name) +
          '</b><span class="pmtag">Chỉ Admin</span></div>' +
          '<div class="pmnote">' + esc(g.note || '') + '</div></div>';
        continue;
      }
      var gcur = ruleOf(scope, skey, g.key);
      h += '<div class="pmgrp"><div class="pmgh"><b>' + esc(g.name) + '</b>' +
        '<span class="pmsp"></span>' +
        '<span class="pmgl">Cả nhóm:</span>' +
        selHtml(scope, skey, 'g', g.key, gcur, 'Mặc định · ' + lvText(Number(g.def || 0))) +
        '</div>';
      if (g.note) h += '<div class="pmnote">' + esc(g.note) + '</div>';
      if (ms.length) {
        h += '<div class="pmmods">';
        for (var j = 0; j < ms.length; j++) {
          var m = ms[j];
          var mcur = ruleOf(scope, skey, 'm:' + m.id);
          var base = 'Theo nhóm · ' + lvText(gcur !== null ? gcur : effMod(scope, skey, m));
          h += '<div class="pmmod"><span class="pmmn">' + esc(m.name) + '</span>' +
            selHtml(scope, skey, 'm', 'm:' + m.id, mcur, base) + '</div>';
        }
        h += '</div>';
      }
      h += '</div>';
    }
    return h;
  }

  /* ---- gom du lieu de luu ---- */
  function collect(scope, skey) {
    var out = {};
    var all = document.querySelectorAll('.pmsel[data-scope="' + scope + '"][data-skey="' +
      cssq(skey) + '"]');
    for (var i = 0; i < all.length; i++) {
      var s = all[i];
      if (s.value === '') continue;
      out[s.getAttribute('data-k')] = Number(s.value);
    }
    /* Nhom phu thuoc: mo nhom A thi nhom A can cung phai mo it nhat "chi xem" */
    for (var g = 0; g < D.groups.length; g++) {
      var gr = D.groups[g];
      var lv = out[gr.key];
      if (lv === undefined || lv < 1) continue;
      var needs = gr.needs || [];
      for (var n = 0; n < needs.length; n++) {
        if (out[needs[n]] === undefined || out[needs[n]] < 1) out[needs[n]] = 1;
      }
    }
    return out;
  }
  function cssq(s) { return String(s).replace(/"/g, '\\"'); }

  /* ---- bulk set ---- */
  window.pmBulk = function (scope, skey, v) {
    var all = document.querySelectorAll('.pmsel[data-scope="' + scope + '"][data-skey="' +
      cssq(skey) + '"][data-kind="g"]');
    for (var i = 0; i < all.length; i++) all[i].value = String(v);
    var ms = document.querySelectorAll('.pmsel[data-scope="' + scope + '"][data-skey="' +
      cssq(skey) + '"][data-kind="m"]');
    for (var k = 0; k < ms.length; k++) ms[k].value = '';
  };

  /* ---- chon vi tri ---- */
  window.pmPickPos = function (key) {
    CURPOS = key;
    render();
  };

  window.pmSavePos = async function () {
    if (!CURPOS) return;
    try {
      await call('perm-save', { scope: 'pos', key: CURPOS, vals: collect('pos', CURPOS) });
      say('Đã lưu phân quyền cho vị trí này', 'ok');
      await load(true);
    } catch (e) { say('Không lưu được: ' + e.message, 'err'); }
  };

  window.pmSaveUser = async function () {
    var id = el('pmUser').value;
    if (!id) return;
    try {
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

  window.pmUserBox = function () {
    var box = el('pmUserBox');
    if (!box) return;
    var id = el('pmUser').value;
    if (!id) { box.innerHTML = '<div class="pmna">Chọn một người để đặt quyền riêng, khác với vị trí của họ.</div>'; return; }
    var u = null;
    for (var i = 0; i < D.users.length; i++) if (String(D.users[i].id) === String(id)) u = D.users[i];
    if (!u) { box.innerHTML = ''; return; }
    if (u.admin) {
      box.innerHTML = '<div class="pmna"><b>' + esc(u.name || u.username) +
        '</b> là Admin — luôn có toàn quyền, không cần đặt riêng.</div>';
      return;
    }
    var has = D.rules && D.rules.user && D.rules.user[String(u.id)];
    box.innerHTML = '<div class="pmwho"><b>' + esc(u.name || u.username) + '</b> · vị trí <b>' +
      esc(u.pos || '—') + '</b>' +
      (has ? ' <span class="pmtag">đang dùng quyền riêng</span>' : '') + '</div>' +
      bulkHtml('user', String(u.id)) +
      matrixHtml('user', String(u.id)) +
      '<div class="pmacts"><button class="btn" onclick="pmSaveUser()">Lưu quyền riêng</button>' +
      '<button class="btn dg" onclick="pmResetUser()">Bỏ quyền riêng</button></div>';
  };

  function bulkHtml(scope, skey) {
    return '<div class="pmbulk">Đặt nhanh cả bảng: ' +
      '<button class="btn sm" onclick="pmBulk(\'' + scope + '\',\'' + cssq(skey) + '\',0)">Không vào được</button>' +
      '<button class="btn sm" onclick="pmBulk(\'' + scope + '\',\'' + cssq(skey) + '\',1)">Chỉ xem</button>' +
      '<button class="btn sm" onclick="pmBulk(\'' + scope + '\',\'' + cssq(skey) + '\',2)">Toàn quyền</button>' +
      '</div>';
  }

  function render() {
    var pane = el('p-perm');
    if (!pane || !D) return;
    if (!CURPOS && D.positions.length) CURPOS = D.positions[0].key;

    var h = '<div class="card"><h2>Phân quyền theo vị trí</h2>' +
      '<div class="hint">Chọn một vị trí rồi bật/tắt từng module. Đặt ở mức <b>Cả nhóm</b> để áp cho toàn bộ module bên trong, ' +
      'hoặc đặt riêng từng module để ghi đè. Admin luôn có toàn quyền.</div>' +
      '<div class="pmpos">';
    for (var i = 0; i < D.positions.length; i++) {
      var p = D.positions[i];
      var n = countRules('pos', p.key);
      h += '<button class="pmchip' + (p.key === CURPOS ? ' on' : '') +
        '" onclick="pmPickPos(\'' + cssq(p.key) + '\')">' + esc(p.label) +
        (n ? '<i>' + n + '</i>' : '') + '</button>';
    }
    h += '</div>';

    if (CURPOS) {
      h += bulkHtml('pos', CURPOS) + matrixHtml('pos', CURPOS) +
        '<div class="pmacts"><button class="btn" onclick="pmSavePos()">Lưu phân quyền vị trí này</button></div>';
    }
    h += '</div>';

    h += '<div class="card"><h2>Quyền riêng theo từng người</h2>' +
      '<div class="hint">Dùng khi một người cần quyền khác với vị trí của họ. Quyền riêng luôn thắng quyền của vị trí.</div>' +
      '<label class="lb">Chọn nhân sự</label><select id="pmUser" onchange="pmUserBox()">' +
      '<option value="">— mặc định theo vị trí —</option>';
    for (var u = 0; u < D.users.length; u++) {
      var uu = D.users[u];
      var mark = (D.rules && D.rules.user && D.rules.user[String(uu.id)]) ? ' — có quyền riêng' : '';
      h += '<option value="' + uu.id + '">' + esc(uu.name || uu.username) +
        (uu.pos ? ' · ' + esc(uu.pos) : '') + esc(mark) + '</option>';
    }
    h += '</select><div id="pmUserBox"></div></div>';

    pane.innerHTML = h;
    window.pmUserBox();
  }

  function countRules(scope, skey) {
    var r = (D.rules && D.rules[scope] && D.rules[scope][skey]) ? D.rules[scope][skey] : null;
    if (!r) return 0;
    var n = 0;
    for (var k in r) if (Object.prototype.hasOwnProperty.call(r, k)) n++;
    return n;
  }

  async function load(keep) {
    var pane = el('p-perm');
    if (!pane) return;
    if (!keep) pane.innerHTML = '<div class="card"><div class="hint">Đang tải phân quyền…</div></div>';
    try {
      D = await call('perm-get');
      if (!D.mods) D.mods = [];
      render();
      LOADED = true;
    } catch (e) {
      pane.innerHTML = '<div class="card"><div class="hint">Không tải được phân quyền: ' +
        esc(e.message) + '</div></div>';
    }
  }

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
      try { document.body.classList[n === 'perm' ? 'add' : 'remove']('pm-wide'); } catch (e) { }
      return out;
    };

    var css = document.createElement('style');
    css.textContent =
      'body.pm-wide #p-perm .card{max-width:1180px}' +
      '#p-perm .pmpos{display:flex;flex-wrap:wrap;gap:8px;margin:14px 0 4px}' +
      '#p-perm .pmchip{background:var(--bg2,#141414);border:1px solid var(--line,#333);' +
      'color:var(--text2,#9aa3b2);border-radius:20px;padding:6px 14px;font:inherit;font-size:12.5px;' +
      'cursor:pointer;display:inline-flex;align-items:center;gap:6px}' +
      '#p-perm .pmchip:hover{border-color:#666;color:var(--text,#e8ebf0)}' +
      '#p-perm .pmchip.on{background:var(--green,#dff20d);border-color:var(--green,#dff20d);' +
      'color:#0f1115;font-weight:700}' +
      '#p-perm .pmchip i{font-style:normal;font-size:10.5px;background:rgba(255,255,255,.12);' +
      'border-radius:9px;padding:1px 6px}' +
      '#p-perm .pmchip.on i{background:rgba(0,0,0,.18)}' +
      '#p-perm .pmbulk{margin:16px 0 10px;font-size:12px;color:var(--text3,#888);' +
      'display:flex;align-items:center;gap:8px;flex-wrap:wrap}' +
      '#p-perm .btn.sm{padding:4px 10px;font-size:11.5px}' +
      '#p-perm .pmgrp{border:1px solid var(--line,#333);border-radius:10px;padding:12px 14px;' +
      'margin-bottom:10px;background:rgba(255,255,255,.02)}' +
      '#p-perm .pmgrp.locked{opacity:.5}' +
      '#p-perm .pmgh{display:flex;align-items:center;gap:10px;font-size:13.5px}' +
      '#p-perm .pmsp{flex:1}' +
      '#p-perm .pmgl{font-size:11.5px;color:var(--text3,#888)}' +
      '#p-perm .pmnote{font-size:11.5px;color:var(--text3,#888);margin-top:4px}' +
      '#p-perm .pmmods{display:grid;grid-template-columns:repeat(auto-fill,minmax(370px,1fr));' +
      'gap:6px 18px;margin-top:10px;padding-top:10px;border-top:1px dashed var(--line,#333)}' +
      '#p-perm .pmmod{display:flex;align-items:center;gap:10px;font-size:12.5px}' +
      '#p-perm .pmmn{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}' +
      '#p-perm .pmsel{background:var(--bg2,#141414);color:inherit;font-family:inherit;' +
      'font-size:12px;border:1px solid var(--line,#333);border-radius:7px;padding:5px 8px;min-width:158px}' +
      '#p-perm .pmtag{font-size:10.5px;border:1px solid #fdba74;color:#fdba74;border-radius:20px;' +
      'padding:2px 8px}' +
      '#p-perm .pmacts{display:flex;gap:10px;align-items:center;margin-top:16px;flex-wrap:wrap}' +
      '#p-perm .pmna{font-size:12.5px;color:var(--text3,#888);padding:10px 2px}' +
      '#p-perm .pmwho{font-size:13px;margin:14px 0 4px}' +
      '#p-perm .btn.dg{background:#ff4d4d;color:#fff;border-color:#ff4d4d}' +
      '#p-perm .btn.dg:hover{background:#ff6b6b}' +
      '#p-perm .lb{display:block;margin:16px 0 6px;font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:var(--text3,#888)}' +
      '#p-perm select#pmUser{width:100%;max-width:520px;background:var(--bg2,#141414);color:inherit;font-family:inherit;font-size:13px;border:1px solid var(--line,#333);border-radius:8px;padding:9px 10px}';
    document.head.appendChild(css);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mount);
  else mount();
})();
