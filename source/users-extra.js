/* APSA — họ tên đầy đủ + thông tin ngân hàng cho trang Quản lý User.
   Nạp sau script chính của users.html. Chỉ Admin thấy được STK của người khác. */
(function () {
  var API  = './api/auth-api.php';
  var MAP  = {};          // id -> { full_name, bank_* , can_see_bank }
  var ADMIN = false, MEID = 0, BUILT = false;

  function el(id) { return document.getElementById(id); }
  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }
  function say(m, k) { if (typeof toast === 'function') toast(m, k); }

  function call(action, body) {
    var cfg = { credentials: 'same-origin', cache: 'no-store', method: body ? 'POST' : 'GET' };
    if (body) { cfg.headers = { 'Content-Type': 'application/json' }; cfg.body = JSON.stringify(body); }
    return fetch(API + '?action=' + action, cfg)
      .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Dữ liệu không hợp lệ.' }; }); })
      .then(function (j) { if (!j || !j.ok) throw new Error((j && j.error) || 'Có lỗi xảy ra.'); return j.data; });
  }

  /* ── các ô nhập thêm vào modal ────────────────────────────── */
  function build() {
    if (BUILT) return;
    var note = el('f_note');
    if (!note) return;
    BUILT = true;

    var host = note.parentElement;               // .form-group của Ghi chú
    var box  = document.createElement('div');
    box.id = 'xBankBox';
    box.innerHTML =
      '<div style="border-top:1px solid rgba(255,255,255,.08);margin:18px 0 14px"></div>' +
      '<div style="font-size:11px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:#5e5e5e;margin-bottom:10px">' +
        'Họ tên &amp; tài khoản nhận lương</div>' +
      grp('Họ và tên đầy đủ', '<input type="text" id="x_full" placeholder="theo CCCD — dùng khi chuyển khoản" autocomplete="off" />') +
      '<div id="xBankFields">' +
        grp('Ngân hàng', '<input type="text" id="x_bank" placeholder="vd: Vietcombank" autocomplete="off" />') +
        grp('Số tài khoản', '<input type="text" id="x_acc" placeholder="chỉ số, không dấu cách" autocomplete="off" inputmode="numeric" />') +
        grp('Chủ tài khoản', '<input type="text" id="x_holder" placeholder="tên in trên thẻ / sổ" autocomplete="off" />') +
        grp('Chi nhánh', '<input type="text" id="x_branch" placeholder="tuỳ chọn" autocomplete="off" />') +
      '</div>' +
      '<div id="xBankLock" style="display:none;font-size:12px;color:#5e5e5e;line-height:1.55">' +
        'Chỉ Admin và chính chủ mới xem/sửa được số tài khoản.</div>';
    var acts = document.querySelector("#modalOverlay .modal-actions");
    if (acts && acts.parentNode) acts.parentNode.insertBefore(box, acts);
    else host.parentNode.insertBefore(box, host.nextSibling);

    /* dùng lại đúng class của form hiện có nếu có */
    var cls = host.className;
    Array.prototype.forEach.call(box.querySelectorAll('.xg'), function (g) { if (cls) g.className = cls; });
  }
  function grp(label, inner) {
    return '<div class="xg" style="margin-bottom:14px">' +
      '<label style="display:block;font-size:11px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:#5e5e5e;margin-bottom:6px">' +
      esc(label) + '</label>' + inner + '</div>';
  }

  function setVals(x) {
    build();
    if (!el('x_full')) return;
    x = x || {};
    el('x_full').value   = x.full_name    || '';
    el('x_bank').value   = x.bank_name    || '';
    el('x_acc').value    = x.bank_account || '';
    el('x_holder').value = x.bank_holder  || '';
    el('x_branch').value = x.bank_branch  || '';
    var open = (x.can_see_bank === undefined) ? true : !!Number(x.can_see_bank);
    if (el('xBankFields')) el('xBankFields').style.display = open ? '' : 'none';
    if (el('xBankLock'))   el('xBankLock').style.display   = open ? 'none' : '';
  }

  function curId() {
    var f = el('editId');
    return f ? (Number(f.value) || 0) : 0;
  }
  function idByUsername(u) {
    if (!u || typeof users === 'undefined' || !users) return 0;
    for (var i = 0; i < users.length; i++) if (users[i].username === u) return Number(users[i].id) || 0;
    return 0;
  }

  /* ── modal mở ra thì đổ dữ liệu ───────────────────────────── */
  function onModalOpen() {
    build();
    var un = el('f_username');
    if (!un) return;
    var id = curId();
    if (id) {                             // đang sửa người có sẵn
      setVals(MAP[String(id)] || null);
      window.__xUser = un.value;
    } else {                              // tạo mới
      setVals(null);
      window.__xUser = '';
    }
  }

  function watchModal() {
    var ov = el('modalOverlay');
    if (!ov) return;
    var was = ov.classList.contains('open');
    new MutationObserver(function () {
      var now = ov.classList.contains('open');
      if (now && !was) onModalOpen();
      was = now;
    }).observe(ov, { attributes: true, attributeFilter: ['class'] });
  }

  /* ── lưu — gọi từ users.html sau khi lưu tài khoản xong ───── */
  window.saveExtra = function (id) {
    if (!el('x_full')) return Promise.resolve();
    id = Number(id) || 0;
    if (!id) id = curId();
    if (!id) id = idByUsername(el('f_username') ? el('f_username').value : '');
    if (!id) return Promise.resolve();
    var body = {
      id: id,
      full_name:    el('x_full').value.trim(),
      bank_name:    el('x_bank').value.trim(),
      bank_account: el('x_acc').value.trim(),
      bank_holder:  el('x_holder').value.trim(),
      bank_branch:  el('x_branch').value.trim()
    };
    return call('staff-extra-save', body)
      .then(function () { return refresh(); })
      .catch(function (e) { say(e.message, 'err'); });
  };

  /* ── cột trong bảng ───────────────────────────────────────── */
  function paint() {
    var tb = document.querySelector('table');
    if (!tb) return;

    var hr = tb.querySelector('thead tr');
    if (hr && !hr.querySelector('.xth')) {
      var th = document.createElement('th');
      th.className = 'xth';
      th.style.minWidth = '210px';
      th.textContent = 'Họ tên & ngân hàng';
      if (hr.children.length > 1) hr.insertBefore(th, hr.children[1]);
      else hr.appendChild(th);
    }

    Array.prototype.forEach.call(tb.querySelectorAll('tbody tr'), function (tr) {
      if (tr.querySelector('.xtd')) return;
      var btn = tr.querySelector('[onclick*="deleteUser("]');
      var id  = 0;
      if (btn) {
        var m = /deleteUser\(\s*(\d+)/.exec(btn.getAttribute('onclick') || '');
        if (m) id = Number(m[1]);
      }
      var x = MAP[String(id)] || {};
      var td = document.createElement('td');
      td.className = 'xtd';
      var bank = '';
      if (Number(x.can_see_bank)) {
        if (x.bank_name || x.bank_account) {
          bank = '<div style="font-size:11.5px;color:#5e5e5e;margin-top:3px">'
               + esc(x.bank_name || '') + (x.bank_account ? ' · ' + esc(x.bank_account) : '') + '</div>';
        }
      } else if (x.full_name !== undefined) {
        bank = '<div style="font-size:11.5px;color:#3d3d3d;margin-top:3px">••• ẩn</div>';
      }
      td.innerHTML = (x.full_name ? '<div style="font-weight:600">' + esc(x.full_name) + '</div>'
                                  : '<span style="color:#3d3d3d">—</span>') + bank;
      if (tr.children.length > 1) tr.insertBefore(td, tr.children[1]);
      else tr.appendChild(td);
    });
  }

  function refresh() {
    return call('staff-extra').then(function (d) {
      MAP   = d.extra || {};
      ADMIN = !!Number(d.is_admin);
      MEID  = Number(d.me) || 0;
      paint();
    }).catch(function () { /* im lặng — không chặn trang */ });
  }

  /* loadUsers vẽ lại bảng -> vẽ thêm cột sau mỗi lần */
  function hook() {
    if (typeof window.loadUsers !== 'function') return false;
    var orig = window.loadUsers;
    window.loadUsers = function () {
      var r = orig.apply(this, arguments);
      if (r && typeof r.then === 'function') r.then(function () { setTimeout(paint, 0); });
      else setTimeout(paint, 0);
      return r;
    };
    return true;
  }

  function boot() {
    build();
    watchModal();
    if (!hook()) setTimeout(hook, 800);
    refresh();
    setTimeout(paint, 1200);
    setTimeout(paint, 2500);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
