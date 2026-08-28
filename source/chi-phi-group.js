/* ============================================================
   APSA — Chi phí thực tế: gom theo dự án, mở/thu từng dự án
   Nạp SAU script chính của chi-phi.html. Ghi đè window.render.
   ============================================================ */
(function () {
  'use strict';

  var COL = {};
  try { COL = JSON.parse(localStorage.getItem('cp_col') || '{}') || {}; } catch (e) { COL = {}; }
  function save() { try { localStorage.setItem('cp_col', JSON.stringify(COL)); } catch (e) {} }

  function groups(V) {
    var m = {}, out = [], i, r, k, a;
    for (i = 0; i < V.length; i++) {
      r = V[i];
      k = r.code || ('#' + r.quotation_id);
      if (!m[k]) {
        m[k] = { code: k, title: r.title, client: r.client_name, date: r.quotation_date,
                 rows: [], tot: 0, paid: 0 };
        out.push(m[k]);
      }
      m[k].rows.push(r);
      a = amt(r);
      m[k].tot += a;
      if (Number(r.paid) === 1) m[k].paid += a;
    }
    out.forEach(function (g) { g.rows.sort(cmp); });
    var key = function (g) {
      if (SORT === 'amt')  return g.tot;
      if (SORT === 'quo')  return nrm(g.code);
      if (SORT === 'name' || SORT === 'payee' || SORT === 'paid') return (g.date || '') + g.code;
      return (g.date || '') + g.code;
    };
    var d = (SORT === 'name' || SORT === 'payee' || SORT === 'paid') ? -1 : DIR;
    out.sort(function (x, y) { var a = key(x), b = key(y); return a < b ? -1 * d : (a > b ? 1 * d : 0); });
    return out;
  }
  window.cpGroups = groups;

  window.cpToggle = function (code) { if (COL[code]) delete COL[code]; else COL[code] = 1; save(); render(); };
  window.cpAll = function (open) {
    COL = {};
    if (!open) groups(view()).forEach(function (g) { COL[g.code] = 1; });
    save(); render();
  };
  window.cpSelGroup = function (code, on) {
    groups(view()).forEach(function (g) {
      if (g.code !== code) return;
      g.rows.forEach(function (r) { if (on) SEL[r.id] = 1; else delete SEL[r.id]; });
    });
    render();
  };

  function rowHtml(r) {
    var cls = r.payee_type === 'user' ? 'pers' : (r.payee_type === 'sup' ? 'corp' : '');
    if (SEL[r.id]) cls += ' sel';
    var masked = Number(r.bank_masked) === 1;
    var canQr = !masked && r.bank_account;
    return '<tr class="' + cls + '">' +
      '<td><input type="checkbox" class="ck" ' + (SEL[r.id] ? 'checked' : '') + ' onclick="selOne(' + r.id + ',this.checked)" /></td>' +
      '<td style="white-space:nowrap;color:var(--text3)">' + esc(dmy(r.quotation_date)) + '</td>' +
      '<td style="color:var(--text3)">·</td>' +
      '<td><span class="nm">' + esc(r.name) + '</span>' +
        (r.description ? '<div class="sub">' + esc(r.description) + '</div>' : '') + '</td>' +
      '<td>' + (r.payee_name ? esc(r.payee_name) + ' <span class="tag ' + (r.payee_type || 'sup') + '">' +
        (r.payee_type === 'user' ? 'FL' : 'NCC') + '</span>' +
        (r.bank_account ? '<div class="sub">' + esc(r.bank_name || '') + ' · ' + esc(r.bank_account) + '</div>' : '')
        : '<span style="color:var(--text3)">—</span>') + '</td>' +
      '<td class="num">' + fmt(r.qty) + '</td>' +
      '<td style="color:var(--text2)">' + esc(r.unit || '') + '</td>' +
      '<td class="num">' + fmt(r.price) + '</td>' +
      '<td class="num" style="color:var(--text2)">' + (Number(r.vat_percent) || 0) + '</td>' +
      '<td class="num"><b>' + fmt(amt(r)) + '</b></td>' +
      '<td><button class="pbtn' + (Number(r.paid) === 1 ? ' on' : '') + '" onclick="togglePaid(' + r.id + ')">' +
        (Number(r.paid) === 1 ? '✓ Đã trả' : 'Chưa trả') + '</button></td>' +
      '<td style="white-space:nowrap;text-align:right">' +
        '<button class="ib' + (canQr ? '' : ' off') + '" title="' + (canQr ? 'Mã QR chuyển khoản' : (masked ? 'Không có quyền xem STK' : 'Chưa có số tài khoản')) + '" onclick="openQr(' + r.id + ')">▦</button>' +
        '<button class="ib" title="Sửa" onclick="openEdit(' + r.id + ')">✎</button>' +
        '<button class="ib del" title="Xoá" onclick="askDel(' + r.id + ')">🗑</button>' +
      '</td></tr>';
  }

  function grpHtml(g) {
    var open = !COL[g.code];
    var due = g.tot - g.paid;
    var allSel = g.rows.length > 0 && g.rows.every(function (r) { return SEL[r.id]; });
    return '<tr class="grp"><td colspan="12">' +
      '<div class="gh">' +
        '<input type="checkbox" class="ck" ' + (allSel ? 'checked' : '') +
          ' title="Chọn cả dự án" onclick="event.stopPropagation();cpSelGroup(\'' + esc(g.code) + '\',this.checked)" />' +
        '<button class="chev' + (open ? ' open' : '') + '" onclick="cpToggle(\'' + esc(g.code) + '\')" title="' +
          (open ? 'Thu gọn' : 'Mở ra') + '">▸</button>' +
        '<a class="gcode" href="./quotation.html?q=' + encodeURIComponent(g.code) + '" target="_blank" rel="noopener">' + esc(g.code) + '</a>' +
        '<span class="gname">' + esc(g.client || g.title || '') + '</span>' +
        '<span class="gdate">' + esc(dmy(g.date)) + '</span>' +
        '<span class="spacer"></span>' +
        '<span class="gn">' + g.rows.length + ' khoản</span>' +
        (due > 0 ? '<span class="gdue">Chưa trả ' + fmt(due) + ' đ</span>' : (g.tot > 0 ? '<span class="gok">✓ Đã trả đủ</span>' : '')) +
        '<span class="gtot">' + fmt(g.tot) + ' đ</span>' +
      '</div></td></tr>';
  }

  window.render = function () {
    var V = view(), G = groups(V), h = '', i, tot = 0, paid = 0;
    ROWS.forEach(function (x) { var a = amt(x); tot += a; if (Number(x.paid) === 1) paid += a; });
    el('stats').innerHTML =
      '<div class="stat"><div class="k">Tổng chi</div><div class="v">' + fmt(tot) + ' đ</div></div>' +
      '<div class="stat due"><div class="k">Chưa trả</div><div class="v">' + fmt(tot - paid) + ' đ</div></div>' +
      '<div class="stat paid"><div class="k">Đã trả</div><div class="v">' + fmt(paid) + ' đ</div></div>' +
      '<div class="stat"><div class="k">Dự án · Khoản</div><div class="v">' + G.length + ' · ' + ROWS.length + '</div></div>';

    el('fSt').innerHTML =
      '<button class="pill' + (FST === 'all' ? ' on' : '') + '" onclick="setSt(\'all\')">Tất cả</button>' +
      '<button class="pill due' + (FST === 'due' ? ' on' : '') + '" onclick="setSt(\'due\')">Chưa trả</button>' +
      '<button class="pill paid' + (FST === 'paid' ? ' on' : '') + '" onclick="setSt(\'paid\')">Đã trả</button>';
    el('fTy').innerHTML =
      '<button class="pill' + (FTY === 'all' ? ' on' : '') + '" onclick="setTy(\'all\')">Mọi loại</button>' +
      '<button class="pill pers' + (FTY === 'pers' ? ' on' : '') + '" onclick="setTy(\'pers\')">Cá nhân</button>' +
      '<button class="pill corp' + (FTY === 'corp' ? ' on' : '') + '" onclick="setTy(\'corp\')">Công ty</button>';

    if (!G.length) {
      el('view').innerHTML = '<div class="empty"><b>Không có khoản chi nào</b>Đổi bộ lọc hoặc bấm “+ Khoản chi”.</div>';
      syncBulk(); return;
    }

    h = '<table><thead><tr>' +
        '<th style="width:34px"><input type="checkbox" class="ck" id="ckAll" onclick="selAll(this.checked)" /></th>' +
        th('date', 'Ngày') + th('quo', 'Dự án') + th('name', 'Hạng mục') + th('payee', 'Người nhận') +
        '<th class="num">SL</th><th>ĐVT</th><th class="num">Đơn giá</th><th class="num">VAT %</th>' +
        th('amt', 'Thành tiền', 'num') + th('paid', 'Trạng thái') + '<th style="width:86px"></th>' +
        '</tr></thead><tbody>';
    for (i = 0; i < G.length; i++) {
      h += grpHtml(G[i]);
      if (!COL[G[i].code]) h += G[i].rows.map(rowHtml).join('');
    }
    h += '</tbody></table>';
    el('view').innerHTML = h;
    syncBulk();
  };

  /* nút Mở/Thu tất cả */
  function mountBtns() {
    if (document.getElementById('cpAllBtns')) return;
    var sp = document.querySelector('.toolbar .spacer');
    if (!sp) return;
    var w = document.createElement('span');
    w.id = 'cpAllBtns';
    w.style.display = 'inline-flex';
    w.style.gap = '6px';
    w.innerHTML = '<button class="btn sm" onclick="cpAll(true)">⌄ Mở tất cả</button>' +
                  '<button class="btn sm" onclick="cpAll(false)">⌃ Thu gọn tất cả</button>';
    sp.parentNode.insertBefore(w, sp.nextSibling);
  }

  var css = ''
    + 'tbody tr.grp td{background:#111417!important;background-image:none!important;box-shadow:none!important;'
    +   'border-top:1px solid rgba(255,255,255,.12);padding:9px 12px}'
    + 'tbody tr.grp:hover td{background:#161a1e!important}'
    + '.gh{display:flex;align-items:center;gap:11px;flex-wrap:wrap}'
    + '.gh .spacer{flex:1 1 auto}'
    + '.chev{width:22px;height:22px;flex:none;border-radius:6px;border:1px solid var(--border);background:transparent;'
    +   'color:var(--text2);cursor:pointer;font-size:11px;line-height:1;transition:transform .15s,color .15s}'
    + '.chev.open{transform:rotate(90deg);color:var(--green)}'
    + '.chev:hover{color:#fff}'
    + '.gcode{font-weight:800;font-size:13px;color:var(--text);text-decoration:none;letter-spacing:.01em}'
    + '.gcode:hover{color:var(--green)}'
    + '.gname{font-size:12px;color:var(--text2);max-width:420px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}'
    + '.gdate{font-size:11px;color:var(--text3);white-space:nowrap}'
    + '.gn{font-size:11px;color:var(--text3);white-space:nowrap}'
    + '.gdue{font-size:11.5px;font-weight:700;color:var(--gold);white-space:nowrap}'
    + '.gok{font-size:11.5px;font-weight:700;color:var(--ok);white-space:nowrap}'
    + '.gtot{font-size:13px;font-weight:800;font-variant-numeric:tabular-nums;white-space:nowrap;min-width:120px;text-align:right}';
  var st = document.createElement('style'); st.textContent = css; document.head.appendChild(st);

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { setTimeout(mountBtns, 300); });
  else setTimeout(mountBtns, 300);
  setTimeout(function () { mountBtns(); if (typeof ROWS !== 'undefined' && ROWS.length) render(); }, 1200);
})();
