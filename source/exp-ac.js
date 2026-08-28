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
               '<span class="exp-ac-t ' + m.t + '">' + (m.t === 'sup' ? 'NCC' : 'Freelancer') + '</span>' +
             '</div>';
      }
    }
    b.innerHTML = h;
    b.classList.add('open');
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
  var st = document.createElement('style');
  st.textContent = css;
  document.head.appendChild(st);
})();
