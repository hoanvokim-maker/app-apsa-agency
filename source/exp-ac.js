/* ============================================================
   APSA — Ô "Người nhận" dạng gõ-để-tìm (autocomplete)
   Nạp SAU exp-qr.js. Ghi đè window.expPayeeCell.
   ============================================================ */
(function () {
  'use strict';

  function nrm(s) {
    return String(s || '')
      .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
      .replace(/đ/gi, 'd')
      .toLowerCase().replace(/[^a-z0-9]+/g, ' ').trim();
  }

  var BOX = null, CUR = null, LIST = [], ACT = -1;

  function box() {
    if (BOX) return BOX;
    BOX = document.createElement('div');
    BOX.className = 'exp-ac';
    BOX.addEventListener('mousedown', function (e) { e.preventDefault(); });
    document.body.appendChild(BOX);
    return BOX;
  }

  function all() {
    var out = [], i;
    var S = (window.EXP_PAYEES && EXP_PAYEES.sup)  || [];
    var U = (window.EXP_PAYEES && EXP_PAYEES.user) || [];
    for (i = 0; i < S.length; i++) out.push({ t: 'sup',  o: S[i], k: nrm(S[i].name) });
    for (i = 0; i < U.length; i++) out.push({ t: 'user', o: U[i], k: nrm(U[i].name) });
    return out;
  }

  function match(q) {
    var A = all(), r = [], i, w, ok, j;
    if (!q) return A.slice(0, 40);
    w = q.split(' ').filter(Boolean);
    for (i = 0; i < A.length; i++) {
      ok = true;
      for (j = 0; j < w.length; j++) if (A[i].k.indexOf(w[j]) < 0) { ok = false; break; }
      if (ok) r.push(A[i]);
      if (r.length >= 40) break;
    }
    return r;
  }

  function place(inp) {
    var r = inp.getBoundingClientRect(), b = box();
    b.style.left  = Math.round(r.left) + 'px';
    b.style.top   = Math.round(r.bottom + 2) + 'px';
    b.style.width = Math.max(220, Math.round(r.width)) + 'px';
  }

  function draw() {
    var b = box(), h = '', i, m;
    if (!LIST.length) {
      h = '<div class="exp-ac-empty">Không tìm thấy — gõ tên tự do cũng được (sẽ không có QR)</div>';
    } else {
      for (i = 0; i < LIST.length; i++) {
        m = LIST[i];
        h += '<div class="exp-ac-i' + (i === ACT ? ' on' : '') + '" data-k="' + i + '">' +
               '<span class="exp-ac-n">' + esc(m.o.name) + '</span>' +
               '<span class="exp-ac-t ' + pTag(m)[0] + '">' + pTag(m)[1] + '</span>' +
             '</div>';
      }
    }
    if (canNew()) {
      var qv = (CUR && CUR.value) ? CUR.value.trim() : '';
      h += '<div class="exp-ac-new">+ Th\u00eam m\u1edbi '
         + (qv ? '\u201c' + esc(qv) + '\u201d' : 'nh\u00e0 cung c\u1ea5p / c\u00e1 nh\u00e2n')
         + '\u2026</div>';
    }
    b.innerHTML = h;
    b.classList.add('open');
    var nw = b.querySelector('.exp-ac-new');
    if (nw) nw.addEventListener('click', function () { openNew(CUR); });
    var it = b.querySelectorAll('.exp-ac-i');
    for (i = 0; i < it.length; i++) {
      it[i].addEventListener('click', function () { pick(Number(this.getAttribute('data-k'))); });
    }
    var on = b.querySelector('.exp-ac-i.on');
    if (on && on.scrollIntoView) on.scrollIntoView({ block: 'nearest' });
  }

  function close() {
    if (BOX) { BOX.classList.remove('open'); BOX.innerHTML = ''; }
    CUR = null; LIST = []; ACT = -1;
  }
  window.expAcClose = close;

  function pick(k) {
    var m = LIST[k], inp = CUR;
    if (!m || !inp) return;
    var i = Number(inp.getAttribute('data-i'));
    close();
    window.expPayeeSet(i, m.t + ':' + m.o.id);   // set + markDirty + renderExp
  }

  window.expAcOpen = function (inp) {
    CUR = inp;
    LIST = match(nrm(inp.value));
    ACT  = LIST.length ? 0 : -1;
    place(inp);
    draw();
  };

  window.expAcKey = function (e, inp) {
    if (!BOX || !BOX.classList.contains('open')) {
      if (e.key === 'ArrowDown') { window.expAcOpen(inp); e.preventDefault(); }
      return;
    }
    if (e.key === 'ArrowDown') { ACT = Math.min(ACT + 1, LIST.length - 1); draw(); e.preventDefault(); }
    else if (e.key === 'ArrowUp') { ACT = Math.max(ACT - 1, 0); draw(); e.preventDefault(); }
    else if (e.key === 'Enter') { if (ACT >= 0) { pick(ACT); e.preventDefault(); } }
    else if (e.key === 'Escape') { close(); }
  };

  window.expAcBlur = function (inp) {
    var i = Number(inp.getAttribute('data-i'));
    var v = inp.value.trim();
    setTimeout(function () {
      close();
      var r = (window.EXP && EXP[i]) ? EXP[i] : null;
      if (!r) return;
      if (v === (r.payee_name || '')) return;      // không đổi gì
      if (v === '') { window.expPayeeSet(i, ''); return; }
      var m = match(nrm(v));
      if (m.length === 1 && m[0].k === nrm(v)) { window.expPayeeSet(i, m[0].t + ':' + m[0].o.id); return; }
      // gõ tự do: giữ tên, bỏ liên kết ngân hàng
      r.payee_type = ''; r.payee_id = 0; r.payee_name = v;
      r.bank_name = ''; r.bank_account = ''; r.bank_holder = ''; r.bank_masked = 0;
      if (typeof markDirty === 'function') markDirty();
      if (typeof renderExp === 'function') renderExp();
    }, 120);
  };

  /* ── ghi đè ô Người nhận ─────────────────────────────────── */
  /* ---- Nhan phan loai: [class, chu] ---- */
  function pTag(m) {
    var o = m.o || {};
    if (m.t === 'sup') return (o.kind === 'person') ? ['person', 'Cá nhân'] : ['sup', 'NCC'];
    return (String(o.staff_type) === 'inhouse') ? ['staff', 'Nhân viên'] : ['user', 'Freelancer'];
  }

  /* ---- Tao nguoi nhan moi ---- */
  function canNew() {
    try {
      return typeof api === 'function' && typeof API !== 'undefined'
        && typeof window.expPayeeSet === 'function';
    } catch (e) { return false; }
  }

  function fld(id, label, ph) {
    return '<label class="exp-np-l">' + label + '</label>' +
      '<input class="exp-np-f" id="' + id + '" autocomplete="off"' +
      (ph ? ' placeholder="' + ph + '"' : '') + '>';
  }

  function openNew(inp) {
    if (!inp || !canNew()) return;
    var i = Number(inp.getAttribute('data-i'));
    var guess = String(inp.value || '').trim();
    close();

    var ov = document.createElement('div');
    ov.className = 'exp-np-ov';
    ov.innerHTML = '<div class="exp-np">' +
      '<h3>Tạo người nhận mới</h3>' +
      '<div class="exp-np-k">' +
        '<label><input type="radio" name="expnpk" value="person" checked> Cá nhân</label>' +
        '<label><input type="radio" name="expnpk" value="company"> Nhà cung cấp</label>' +
      '</div>' +
      fld('expNpName', 'Tên người nhận *') +
      '<div class="exp-np-2">' +
        '<div>' + fld('expNpBank', 'Ngân hàng', 'VD: Vietcombank') + '</div>' +
        '<div>' + fld('expNpAcc', 'Số tài khoản') + '</div>' +
      '</div>' +
      fld('expNpHold', 'Chủ tài khoản', 'Để trống sẽ lấy theo tên') +
      '<div class="exp-np-2">' +
        '<div>' + fld('expNpPhone', 'Điện thoại') + '</div>' +
        '<div>' + fld('expNpTax', 'Mã số thuế') + '</div>' +
      '</div>' +
      fld('expNpNote', 'Ghi chú') +
      '<div class="exp-np-msg" id="expNpMsg"></div>' +
      '<div class="exp-np-a">' +
        '<button type="button" class="exp-np-c">Huỷ</button>' +
        '<button type="button" class="exp-np-s">Lưu &amp; chọn</button>' +
      '</div>' +
    '</div>';
    document.body.appendChild(ov);

    var $ = function (id) { return ov.querySelector('#' + id); };
    var v = function (id) { return String($(id).value || '').trim(); };
    var msg = function (t) { $('expNpMsg').textContent = t || ''; };

    $('expNpName').value = guess;
    setTimeout(function () { $(guess ? 'expNpBank' : 'expNpName').focus(); }, 30);

    function shut() { if (ov.parentNode) ov.parentNode.removeChild(ov); }
    ov.addEventListener('mousedown', function (e) { if (e.target === ov) shut(); });
    ov.querySelector('.exp-np-c').addEventListener('click', shut);
    ov.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') shut();
      else if (e.key === 'Enter') { e.preventDefault(); go(); }
    });
    ov.querySelector('.exp-np-s').addEventListener('click', go);

    async function go() {
      var name = v('expNpName');
      if (!name) { msg('Chưa nhập tên người nhận.'); $('expNpName').focus(); return; }
      var btn = ov.querySelector('.exp-np-s');
      btn.disabled = true;
      msg('Đang lưu…');
      try {
        var d = await api(API, 'payee-new', {
          body: {
            kind: ov.querySelector('input[name="expnpk"]:checked').value,
            name: name,
            bank_name: v('expNpBank'), bank_account: v('expNpAcc'), bank_holder: v('expNpHold'),
            phone: v('expNpPhone'), tax_code: v('expNpTax'), note: v('expNpNote')
          }
        });
        var p = d && d.payee;
        if (!p || !p.id) throw new Error('Máy chủ không trả về người nhận');
        var L = (window.EXP_PAYEES && window.EXP_PAYEES.sup) || [];
        for (var k = 0; k < L.length; k++) if (Number(L[k].id) === Number(p.id)) { L.splice(k, 1); break; }
        L.push(p);
        L.sort(function (a, b) { return String(a.name).localeCompare(String(b.name), 'vi'); });
        shut();
        window.expPayeeSet(i, 'sup:' + p.id);
      } catch (e) {
        btn.disabled = false;
        msg(e && e.message ? e.message : 'Không lưu được, thử lại giúp em.');
      }
    }
  }

  window.expPayeeCell = function (r, i) {
    var linked = r.payee_type && r.payee_id;
    return '<input class="exp-payee-in' + (linked ? ' linked' : '') + '" data-i="' + i + '"' +
           ' value="' + esc(r.payee_name || '') + '" placeholder="Gõ tên để tìm…" autocomplete="off"' +
           ' oninput="expAcOpen(this)" onfocus="expAcOpen(this)"' +
           ' onkeydown="expAcKey(event,this)" onblur="expAcBlur(this)" />';
  };

  window.addEventListener('scroll', function () { if (CUR) place(CUR); }, true);
  window.addEventListener('resize', function () { if (CUR) close(); });

  var css = ''
    + '.exp-payee-in{width:100%;background:transparent;border:1px solid var(--line,#333);border-radius:6px;'
    +   'color:inherit;font:inherit;font-size:12px;padding:4px 6px}'
    + '.exp-payee-in.linked{border-color:rgba(255,255,255,.28);font-weight:600}'
    + '.exp-payee-in:focus{outline:none;border-color:var(--acc,#d4f24a)}'
    + '.exp-ac{position:fixed;z-index:10000;display:none;max-height:290px;overflow:auto;'
    +   'background:#14161a;border:1px solid #33363d;border-radius:10px;box-shadow:0 12px 32px rgba(0,0,0,.55);padding:4px}'
    + '.exp-ac.open{display:block}'
    + '.exp-ac-i{display:flex;align-items:center;justify-content:space-between;gap:10px;'
    +   'padding:6px 9px;border-radius:7px;cursor:pointer;font-size:12.5px;color:#e8e8e8}'
    + '.exp-ac-i:hover,.exp-ac-i.on{background:rgba(255,255,255,.09)}'
    + '.exp-ac-n{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}'
    + '.exp-ac-t{flex:none;font-size:9.5px;letter-spacing:.06em;text-transform:uppercase;'
    +   'padding:2px 6px;border-radius:999px;border:1px solid transparent}'
    + '.exp-ac-t.sup{color:#7fb4ff;border-color:rgba(56,148,255,.45);background:rgba(56,148,255,.12)}'
    + '.exp-ac-t.user{color:#f2d14a;border-color:rgba(250,204,21,.45);background:rgba(250,204,21,.12)}'
    + '.exp-ac-empty{padding:10px 10px;font-size:12px;color:#8b8f98;line-height:1.5}';
    css += '.exp-ac-t.person{color:#ffd27a;border-color:rgba(255,210,122,.45);background:rgba(255,210,122,.12)}'
      + '.exp-ac-t.staff{color:#7ee0a6;border-color:rgba(126,224,166,.45);background:rgba(126,224,166,.12)}'
      + '.exp-ac-new{padding:9px 10px;font-size:12.5px;color:var(--green,#dff20d);cursor:pointer;'
      +   'border-top:1px solid #3a3f47;background:#181b20;position:sticky;bottom:0;font-weight:700}'
      + '.exp-ac-new:hover{background:rgba(223,242,13,.12)}'
      + '.exp-np-ov{position:fixed;inset:0;z-index:10001;background:rgba(0,0,0,.6);display:flex;'
      +   'align-items:center;justify-content:center;padding:20px}'
      + '.exp-np{width:100%;max-width:520px;max-height:90vh;overflow:auto;background:#14161a;'
      +   'border:1px solid #333;border-radius:14px;padding:20px 22px;box-shadow:0 20px 60px rgba(0,0,0,.55)}'
      + '.exp-np h3{margin:0 0 14px;font-size:16px}'
      + '.exp-np-k{display:flex;gap:18px;margin-bottom:12px;font-size:13px}'
      + '.exp-np-k label{display:flex;align-items:center;gap:6px;cursor:pointer}'
      + '.exp-np-l{display:block;font-size:11px;letter-spacing:.06em;text-transform:uppercase;'
      +   'color:#8b8f98;margin:10px 0 4px}'
      + '.exp-np-f{width:100%;background:#0f1115;color:inherit;font-family:inherit;font-size:13px;'
      +   'border:1px solid #333;border-radius:8px;padding:8px 10px;box-sizing:border-box}'
      + '.exp-np-f:focus{outline:none;border-color:#666}'
      + '.exp-np-2{display:grid;grid-template-columns:1fr 1fr;gap:0 12px}'
      + '.exp-np-msg{min-height:16px;font-size:12px;color:#ff8a8a;margin-top:10px}'
      + '.exp-np-a{display:flex;gap:10px;justify-content:flex-end;margin-top:6px}'
      + '.exp-np-a button{font-family:inherit;font-size:13px;padding:8px 16px;border-radius:9px;cursor:pointer}'
      + '.exp-np-c{background:none;border:1px solid #444;color:inherit}'
      + '.exp-np-s{background:var(--green,#dff20d);border:1px solid var(--green,#dff20d);color:#0f1115;font-weight:700}'
      + '.exp-np-s[disabled]{opacity:.5;cursor:default}'
      + '@media(max-width:520px){.exp-np-2{grid-template-columns:1fr}}';
  var st = document.createElement('style');
  st.textContent = css;
  document.head.appendChild(st);
})();
