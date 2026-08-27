/* ══════════════════════════════════════════════════════════════════
   APSA — Rate Card / sheet VFR (v2)
   • Giá bán theo số lượng:  < 10  |  10 – 50  |  > 50
       (dùng lại 3 cột sẵn có: basic / standard / premium)
   • Nhiều nhà cung cấp, mỗi bên một bảng đơn giá theo số lượng
   • Thông số kỹ thuật + link thư mục file sản xuất trên SharePoint
   Nạp sau script chính của ratecard.html và ghi đè các hàm của sheet VFR.
   ══════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';

  var VAPI = './api/vfr-api.php';
  var BAPI = './api/brand-api.php';
  var QL   = ['&lt; 10', '10 – 50', '&gt; 50'];
  var QLT  = ['< 10', '10 – 50', '> 50'];

  var IS_ADMIN = false;
  var SUP      = [];     // [{id,name,contact,note,active}]
  var SUPPLY   = {};     // item_id -> [{supplier_id,supplier_name,p1,p2,p3,note}]
  var editRows = [];     // các dòng NCC đang sửa trong modal
  var pendProd = null;   // {url,name,id,dir} — file sản xuất đang chọn
  var pendSpecs = '';
  var metaDone = false;

  /* ── tiện ích ────────────────────────────────────────────── */
  function vapi(path, opts) {
    opts = opts || {};
    opts.credentials = 'same-origin';
    return fetch(VAPI + path, opts).then(function (r) {
      return r.json().catch(function () { return { ok: false, error: 'Máy chủ trả về dữ liệu không hợp lệ' }; });
    }).then(function (j) {
      if (!j || !j.ok) throw new Error((j && j.error) || 'Lỗi không xác định');
      return j;
    });
  }
  function vpost(path, obj) {
    return vapi(path, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(obj) });
  }
  function bapi(path, opts) {
    opts = opts || {};
    opts.credentials = 'same-origin';
    return fetch(BAPI + path, opts).then(function (r) {
      return r.json().catch(function () { return { ok: false, error: 'Máy chủ trả về dữ liệu không hợp lệ' }; });
    }).then(function (j) {
      if (!j || !j.ok) throw new Error((j && j.error) || 'Lỗi không xác định');
      return j;
    });
  }
  function el(id) { return document.getElementById(id); }
  function nz(v) { var n = parseFloat(v || 0); return n ? String(n) : ''; }
  function n0(v) { return parseFloat(v || 0) || 0; }

  /* ── CSS bổ sung ─────────────────────────────────────────── */
  function injectCss() {
    if (el('vfr2-css')) return;
    var s = document.createElement('style');
    s.id = 'vfr2-css';
    s.textContent = [
      '.specs-line{font-size:11.5px;color:var(--text3);margin-top:4px;white-space:pre-line;line-height:1.5}',
      '.specs-line b{color:var(--text2);font-weight:700}',
      '.sup-cell{font-size:12px;white-space:nowrap}',
      '.sup-cell .s{display:block;color:var(--text2);line-height:1.6}',
      '.sup-cell .s b{color:var(--text);font-weight:600}',
      '.sup-cell .none{color:var(--text3)}',
      '.supt{width:100%;border-collapse:collapse;font-size:12.5px}',
      '.supt th{text-align:left;font-size:10.5px;text-transform:uppercase;letter-spacing:.05em;color:var(--text3);padding:4px 5px;font-weight:700}',
      '.supt td{padding:3px 4px;vertical-align:middle}',
      '.supt input,.supt select{width:100%;background:var(--bg2);border:1px solid rgba(255,255,255,.1);border-radius:7px;color:var(--text);padding:7px 8px;font-family:inherit;font-size:12.5px;outline:none}',
      '.supt input:focus,.supt select:focus{border-color:rgba(255,255,255,.28)}',
      '.supt .xr{background:none;border:none;color:var(--text3);cursor:pointer;font-size:15px;padding:2px 6px;line-height:1}',
      '.supt .xr:hover{color:#ff6b6b}',
      '.sup-empty{font-size:12.5px;color:var(--text3);padding:8px 2px}',
      '.sup-acts{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}',
      '.sp-row{display:flex;align-items:center;gap:10px;padding:9px 11px;border-bottom:1px solid rgba(255,255,255,.07);cursor:pointer}',
      '.sp-row:last-child{border-bottom:none}',
      '.sp-row:hover{background:var(--bg2)}',
      '.sp-ic{width:28px;height:28px;flex:0 0 28px;border-radius:7px;display:grid;place-items:center;font-size:9px;font-weight:800;background:var(--bg3,#141414);border:1px solid rgba(255,255,255,.1);color:var(--text2)}',
      '.sp-ic.dir{color:var(--green);border-color:rgba(223,242,13,.3)}',
      '.sp-nm{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-weight:600;font-size:13px}',
      '.sp-sz{color:var(--text3);font-size:11.5px;white-space:nowrap}',
      '#spList{max-height:44vh;overflow:auto;border:1px solid rgba(255,255,255,.1);border-radius:10px}',
      '.sp-crumbs{display:flex;flex-wrap:wrap;gap:3px;font-size:12.5px;margin:10px 0;align-items:center}',
      '.sp-crumbs a{cursor:pointer;color:var(--text2);padding:4px 8px;border-radius:6px;font-weight:600}',
      '.sp-crumbs a:hover{background:var(--bg2);color:var(--text)}',
      '.sp-crumbs a.cur{color:var(--green)}',
      '.sp-crumbs span{color:var(--text3)}',
      '.sp-prog{height:4px;border-radius:3px;background:var(--bg2);margin-top:10px;overflow:hidden;display:none}',
      '.sp-prog i{display:block;height:100%;width:0;background:var(--green);transition:width .2s}',
      '.smt{width:100%;border-collapse:collapse;font-size:13px}',
      '.smt td{padding:7px 4px;border-bottom:1px solid rgba(255,255,255,.07)}',
      '.smt .nm{font-weight:600}',
      '.smt .ct{color:var(--text3);font-size:11.5px}',
      '.smt .off{opacity:.45}',
      '.prodchip{display:inline-flex;align-items:center;gap:8px;max-width:100%}',
      '.prodchip a{color:var(--green);text-decoration:none;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-weight:600}',
      '.prodchip a:hover{text-decoration:underline}'
    ].join('\n');
    document.head.appendChild(s);
  }

  /* ── dựng thêm phần cho modal thêm/sửa ───────────────────── */
  function buildModal() {
    var priceDiv = el('vfrPriceDiv'), priceRow = el('vfrPriceRow'), zipRow = el('vfrZipRow');
    if (!priceDiv || !priceRow || !zipRow) return;

    var lbl = priceDiv.querySelector('.divider-label');
    if (lbl) lbl.textContent = 'Giá bán theo số lượng (VND, giá NET)';

    priceRow.innerHTML =
      '<div class="fg"><label>' + QL[0] + ' cái</label><input type="number" id="itQ1" min="0" step="1000" /></div>' +
      '<div class="fg"><label>' + QL[1] + ' cái</label><input type="number" id="itQ2" min="0" step="1000" /></div>' +
      '<div class="fg"><label>' + QL[2] + ' cái</label><input type="number" id="itQ3" min="0" step="1000" /></div>';

    // Thông số — đặt ngay trước khối giá
    var spec = document.createElement('div');
    spec.className = 'fg';
    spec.id = 'vfrSpecRow';
    spec.innerHTML = '<label>Thông số kỹ thuật</label>' +
      '<textarea id="itSpecs" rows="3" placeholder="VD: Mica trong 5mm · in UV 4 màu · 200×300mm · kèm hộp giấy"></textarea>';
    priceDiv.parentNode.insertBefore(spec, priceDiv);

    // Nhà cung cấp — sau khối giá bán
    var sd = document.createElement('div');
    sd.className = 'divider';
    sd.id = 'vfrSupDiv';
    sd.innerHTML = '<div class="divider-label">Nhà cung cấp &amp; đơn giá theo số lượng</div>';
    var sb = document.createElement('div');
    sb.className = 'fg';
    sb.id = 'vfrSupBox';
    priceRow.parentNode.insertBefore(sd, priceRow.nextSibling);
    sd.parentNode.insertBefore(sb, sd.nextSibling);

    // File sản xuất — dùng lại ô cũ
    var zl = zipRow.querySelector('label');
    if (zl) zl.textContent = 'File sản xuất (SharePoint)';

    // Modal chọn file trên SharePoint
    var pick = document.createElement('div');
    pick.className = 'ov';
    pick.id = 'spOv';
    pick.innerHTML =
      '<div class="modal">' +
        '<button class="x" onclick="vfrPickClose()">✕</button>' +
        '<h2>Chọn thư mục / file sản xuất</h2>' +
        '<div class="sub">Kho SharePoint của APSA — thư mục <b>VFR</b>. Bấm vào thư mục để đi vào, bấm vào file để chọn.</div>' +
        '<div class="sp-crumbs" id="spCrumbs"></div>' +
        '<div id="spList"></div>' +
        '<div class="sp-prog" id="spProg"><i></i></div>' +
        '<div class="sup-acts">' +
          '<button class="zipbtn" onclick="vfrPickFolder()">✓ Chọn thư mục đang mở</button>' +
          '<button class="zipbtn" onclick="vfrPickUpload()">⬆ Tải file lên thư mục này</button>' +
          '<button class="zipbtn" onclick="vfrPickNewDir()">＋ Thư mục mới</button>' +
        '</div>' +
      '</div>';
    document.body.appendChild(pick);

    var fi = document.createElement('input');
    fi.type = 'file'; fi.id = 'spFile'; fi.multiple = true; fi.style.display = 'none';
    document.body.appendChild(fi);
    fi.addEventListener('change', function () {
      var fs = Array.prototype.slice.call(this.files || []);
      this.value = '';
      if (fs.length) spUpload(fs);
    });

    // Modal quản lý nhà cung cấp
    var mgr = document.createElement('div');
    mgr.className = 'ov';
    mgr.id = 'smOv';
    mgr.innerHTML =
      '<div class="modal narrow">' +
        '<button class="x" onclick="vfrSupMgrClose()">✕</button>' +
        '<h2>Danh sách nhà cung cấp</h2>' +
        '<div class="sub">Thêm một lần, dùng lại cho mọi sản phẩm VFR.</div>' +
        '<div id="smList"></div>' +
        '<div class="row2" style="margin-top:14px">' +
          '<div class="fg"><label>Tên nhà cung cấp</label><input type="text" id="smName" placeholder="VD: Xưởng An Phát" /></div>' +
          '<div class="fg"><label>Liên hệ</label><input type="text" id="smContact" placeholder="VD: A. Tuấn — 09xx" /></div>' +
        '</div>' +
        '<button class="btn" onclick="vfrSupAdd()">＋ Thêm nhà cung cấp</button>' +
      '</div>';
    document.body.appendChild(mgr);
  }

  /* ── dữ liệu ─────────────────────────────────────────────── */
  function ensureMeta() {
    if (metaDone) return Promise.resolve();
    return vapi('?action=me').then(function (j) {
      IS_ADMIN = !!j.admin;
      metaDone = true;
    }).catch(function () { metaDone = true; });
  }

  function loadSuppliers() {
    return vapi('?action=suppliers').then(function (j) { SUP = j.rows || []; }).catch(function () {});
  }

  function loadSupply() {
    if (!IS_ADMIN) { SUPPLY = {}; return Promise.resolve(); }
    return vapi('?action=supply&sheet=vfr').then(function (j) { SUPPLY = j.map || {}; }).catch(function () { SUPPLY = {}; });
  }

  function ensureVfrData() {
    return ensureMeta()
      .then(function () { return IS_ADMIN ? Promise.all([loadSuppliers(), loadSupply()]) : null; })
      .then(function () { render(); });
  }

  /* ── bảng VFR ────────────────────────────────────────────── */
  function bestCost(it) {
    var rows = SUPPLY[String(it.id)] || [];
    var best = null, who = '';
    rows.forEach(function (r) {
      var v = n0(r.p1);
      if (v > 0 && (best === null || v < best)) { best = v; who = r.supplier_name; }
    });
    return { v: best, who: who, n: rows.length };
  }

  function profitTd(sell, cost) {
    if (!sell || cost === null || !cost) return '<td class="profit-cell flat">—</td>';
    var d = sell - cost;
    var pct = sell > 0 ? Math.round(d / sell * 1000) / 10 : null;
    var cls = d > 0 ? 'up' : (d < 0 ? 'down' : 'flat');
    return '<td class="profit-cell ' + cls + '">' + (d > 0 ? '+' : '') + fmtVnd(d) + ' đ' +
      (pct === null ? '' : '<span class="pct">' + (d > 0 ? '+' : '') + pct + '% biên</span>') + '</td>';
  }

  function supTd(it) {
    var rows = SUPPLY[String(it.id)] || [];
    if (!rows.length) return '<td class="sup-cell"><span class="none">— chưa có —</span></td>';
    var h = rows.slice(0, 3).map(function (r) {
      var ps = [r.p1, r.p2, r.p3].map(function (x) { return n0(x) ? fmtVnd(n0(x)) : '—'; }).join(' / ');
      return '<span class="s"><b>' + esc(r.supplier_name || '?') + '</b> · ' + ps + '</span>';
    }).join('');
    if (rows.length > 3) h += '<span class="s">+' + (rows.length - 3) + ' NCC khác</span>';
    return '<td class="sup-cell">' + h + '</td>';
  }

  function prodTd(it) {
    if (it.prod_url) {
      return '<td class="zip-cell"><span class="prodchip">' +
        '<a href="' + esc(it.prod_url) + '" target="_blank" rel="noopener" onclick="event.stopPropagation()" title="' + esc(it.prod_name || '') + '">' +
        (parseInt(it.prod_dir || 0, 10) ? '▤ ' : '') + esc(it.prod_name || 'Mở trên SharePoint') + '</a></span></td>';
    }
    return '<td class="zip-cell"><button class="zipbtn" onclick="event.stopPropagation();openEdit(' + it.id + ')">⬆ Gắn file sản xuất</button></td>';
  }

  window.vfrRowHtml = function (it) {
    var p1 = n0(it.basic), p2 = n0(it.standard), p3 = n0(it.premium);
    var noPrice = !p1 && !p2 && !p3;
    var bc = bestCost(it);

    var h = '<tr class="' + (noPrice ? 'pending' : '') + '" onclick="openEdit(' + it.id + ')">' +
      '<td class="no-cell">' + esc(it.no_label || '') + '</td>' +
      '<td class="item-cell"><div class="en">' + esc(it.item_en) +
        (noPrice ? '<span class="badge-pending">CHƯA CÓ GIÁ</span>' : '') + '</div>' +
        (it.item_vn ? '<div class="vn">' + esc(it.item_vn) + '</div>' : '') + '</td>' +
      '<td class="desc-cell">' + (it.desc_en ? esc(it.desc_en) : '') +
        (it.desc_vn ? '<div class="vn">' + esc(it.desc_vn) + '</div>' : '') +
        (it.specs ? '<div class="specs-line"><b>Thông số:</b> ' + esc(it.specs) + '</div>' : '') + '</td>' +
      '<td class="unit-cell">' + esc([it.unit_en, it.unit_vn].filter(Boolean).join(' / ')) + '</td>' +
      priceCell(p1) + priceCell(p2) + priceCell(p3);

    if (IS_ADMIN) {
      h += '<td class="price-cell ' + (bc.v === null ? 'zero' : '') + '">' +
             (bc.v === null ? '—' : fmtVnd(bc.v) + ' đ') + '</td>' +
           profitTd(p1, bc.v) +
           supTd(it);
    }

    h += prodTd(it) +
      '<td class="notes-cell">' + esc(it.notes_en || '') +
        (it.notes_vn ? '<div class="vn" style="font-style:italic">' + esc(it.notes_vn) + '</div>' : '') + '</td>' +
      '<td class="actions-cell">' +
        '<span class="act" title="Sửa" onclick="event.stopPropagation();openEdit(' + it.id + ')">✎</span>' +
        '<span class="act del" title="Xoá" onclick="event.stopPropagation();delItem(' + it.id + ')">🗑</span>' +
      '</td></tr>';
    return h;
  };

  window.vfrTableHtml = function (rows) {
    var s1 = 0, s2 = 0, s3 = 0, sc = 0, withFile = 0;
    rows.forEach(function (r) {
      s1 += n0(r.basic); s2 += n0(r.standard); s3 += n0(r.premium);
      var b = bestCost(r); if (b.v) sc += b.v;
      if (r.prod_url) withFile++;
    });

    var head = '<th>#</th><th>Sản phẩm</th><th>Mô tả / Thông số</th><th>ĐVT</th>' +
      '<th style="text-align:right">' + QL[0] + '</th>' +
      '<th style="text-align:right">' + QL[1] + '</th>' +
      '<th style="text-align:right">' + QL[2] + '</th>' +
      (IS_ADMIN ? '<th style="text-align:right">Giá vốn (' + QL[0] + ')</th><th style="text-align:right">Lợi nhuận</th><th>Nhà cung cấp</th>' : '') +
      '<th>File sản xuất</th><th>Ghi chú</th><th></th>';

    var foot = '';
    if (rows.length > 1) {
      foot = '<tfoot><tr><td colspan="4" style="text-align:right;color:var(--text2);font-weight:700">Tổng cộng</td>' +
        '<td class="price-cell">' + fmtVnd(s1) + ' đ</td>' +
        '<td class="price-cell">' + fmtVnd(s2) + ' đ</td>' +
        '<td class="price-cell">' + fmtVnd(s3) + ' đ</td>' +
        (IS_ADMIN ? '<td class="price-cell">' + fmtVnd(sc) + ' đ</td>' + profitTd(s1, sc) + '<td></td>' : '') +
        '<td colspan="3"></td></tr></tfoot>';
    }

    return '<div class="cat-section">' +
      '<div class="cat-head">' +
        '<div class="cat-code">▣</div>' +
        '<div class="cat-name">VFR Products<span class="vn">Sản phẩm VFR — giá bán theo số lượng' +
          (IS_ADMIN ? ', đơn giá nhà cung cấp' : '') + ' và file sản xuất trên SharePoint</span></div>' +
        '<div class="cat-count">' + rows.length + ' sản phẩm · ' + withFile + ' có file</div>' +
      '</div>' +
      '<div class="tbl-wrap"><table class="rc">' +
        '<thead><tr>' + head + '</tr></thead>' +
        '<tbody>' + rows.map(vfrRowHtml).join('') + '</tbody>' + foot +
      '</table></div></div>';
  };

  /* ── chế độ form VFR ─────────────────────────────────────── */
  window.applyVfrMode = function () {
    var v = el('itSheet').value === VFR;
    el('catFg').style.display = v ? 'none' : 'block';
    if (v) {
      el('newCatRow').style.display = 'none';
      el('newCatVnRow').style.display = 'none';
    } else { onCatSelectChange(); }

    el('stdPriceDiv').style.display = v ? 'none' : 'flex';
    el('stdPriceRow').style.display = v ? 'none' : 'grid';
    el('vfrPriceDiv').style.display = v ? 'flex' : 'none';
    el('vfrPriceRow').style.display = v ? 'grid' : 'none';
    el('vfrZipRow').style.display   = v ? 'block' : 'none';
    if (el('vfrSpecRow')) el('vfrSpecRow').style.display = v ? 'block' : 'none';
    if (el('vfrSupDiv')) el('vfrSupDiv').style.display = (v && IS_ADMIN) ? 'flex' : 'none';
    if (el('vfrSupBox')) el('vfrSupBox').style.display = (v && IS_ADMIN) ? 'block' : 'none';

    el('itemDivLabel').textContent = v ? 'Sản phẩm' : 'Hạng mục';
    el('lbItemEn').textContent = v ? 'Tên sản phẩm (EN) *' : 'Tên hạng mục (EN) *';
    el('lbItemVn').textContent = v ? 'Tên sản phẩm (VN)' : 'Tên hạng mục (VN)';
    el('itItemEn').placeholder = v ? 'VD: Acrylic award trophy' : 'VD: LED screen P3';
    el('itItemVn').placeholder = v ? 'VD: Kỷ niệm chương mica' : 'VD: Màn hình LED P3';
    el('itTitle').textContent = (editing ? 'Sửa ' : 'Thêm ') + (v ? 'sản phẩm VFR' : 'hạng mục');
    el('itSaveBtn').textContent = v ? '✓ Lưu sản phẩm' : '✓ Lưu hạng mục';
    el('itDelBtn').textContent = v ? '🗑 Xoá sản phẩm' : '🗑 Xoá hạng mục';

    if (v) { renderSupBox(); renderProdBox(); }
  };

  /* ── khối nhà cung cấp trong modal ───────────────────────── */
  function supOptions(sel) {
    var seen = {};
    editRows.forEach(function (r) { if (r.supplier_id && r.supplier_id !== sel) seen[r.supplier_id] = 1; });
    var o = '<option value="">— chọn nhà cung cấp —</option>';
    SUP.forEach(function (s) {
      if (seen[s.id]) return;
      if (!s.active && s.id !== sel) return;
      o += '<option value="' + s.id + '"' + (s.id === sel ? ' selected' : '') + '>' + esc(s.name) + '</option>';
    });
    return o;
  }

  function renderSupBox() {
    var box = el('vfrSupBox');
    if (!box) return;
    if (!IS_ADMIN) { box.innerHTML = ''; return; }

    var body = editRows.map(function (r, i) {
      return '<tr>' +
        '<td><select onchange="vfrSupSet(' + i + ',\'supplier_id\',this.value)">' + supOptions(r.supplier_id) + '</select></td>' +
        '<td><input type="number" min="0" step="1000" value="' + (r.p1 || '') + '" oninput="vfrSupSet(' + i + ',\'p1\',this.value)" /></td>' +
        '<td><input type="number" min="0" step="1000" value="' + (r.p2 || '') + '" oninput="vfrSupSet(' + i + ',\'p2\',this.value)" /></td>' +
        '<td><input type="number" min="0" step="1000" value="' + (r.p3 || '') + '" oninput="vfrSupSet(' + i + ',\'p3\',this.value)" /></td>' +
        '<td><input type="text" value="' + esc(r.note || '') + '" placeholder="ghi chú" oninput="vfrSupSet(' + i + ',\'note\',this.value)" /></td>' +
        '<td><button class="xr" title="Bỏ dòng này" onclick="vfrSupDel(' + i + ')">✕</button></td>' +
      '</tr>';
    }).join('');

    box.innerHTML =
      (editRows.length
        ? '<table class="supt"><thead><tr><th style="width:26%">Nhà cung cấp</th><th>' + QL[0] + '</th><th>' + QL[1] + '</th><th>' + QL[2] + '</th><th style="width:22%">Ghi chú</th><th style="width:28px"></th></tr></thead><tbody>' + body + '</tbody></table>'
        : '<div class="sup-empty">Chưa gắn nhà cung cấp nào cho sản phẩm này.</div>') +
      '<div class="sup-acts">' +
        '<button class="zipbtn" onclick="vfrSupAddRow()">＋ Thêm nhà cung cấp</button>' +
        '<button class="zipbtn" onclick="vfrSupMgr()">⚙ Quản lý danh sách NCC</button>' +
      '</div>';
  }

  window.vfrSupSet = function (i, k, v) {
    if (!editRows[i]) return;
    editRows[i][k] = (k === 'supplier_id') ? (parseInt(v, 10) || 0) : (k === 'note' ? v : v);
    if (k === 'supplier_id') renderSupBox();
  };
  window.vfrSupDel = function (i) { editRows.splice(i, 1); renderSupBox(); };
  window.vfrSupAddRow = function () {
    if (!SUP.filter(function (s) { return s.active; }).length) { vfrSupMgr(); return; }
    editRows.push({ supplier_id: 0, p1: '', p2: '', p3: '', note: '' });
    renderSupBox();
  };

  /* ── quản lý danh sách nhà cung cấp ──────────────────────── */
  window.vfrSupMgr = function () {
    el('smOv').classList.add('open');
    renderSupMgr();
  };
  window.vfrSupMgrClose = function () {
    el('smOv').classList.remove('open');
    renderSupBox();
  };
  function renderSupMgr() {
    var box = el('smList');
    if (!SUP.length) { box.innerHTML = '<div class="sup-empty">Chưa có nhà cung cấp nào.</div>'; return; }
    box.innerHTML = '<table class="smt"><tbody>' + SUP.map(function (s) {
      return '<tr class="' + (s.active ? '' : 'off') + '">' +
        '<td><div class="nm">' + esc(s.name) + (s.active ? '' : ' <span class="ct">(đã ẩn)</span>') + '</div>' +
        (s.contact ? '<div class="ct">' + esc(s.contact) + '</div>' : '') + '</td>' +
        '<td style="width:34px;text-align:right"><button class="xr" title="Xoá" onclick="vfrSupRemove(' + s.id + ')">✕</button></td>' +
      '</tr>';
    }).join('') + '</tbody></table>';
  }
  window.vfrSupAdd = function () {
    var nm = el('smName').value.trim();
    if (!nm) { toast('Nhập tên nhà cung cấp.', 'err'); return; }
    vpost('?action=supplier-save', { name: nm, contact: el('smContact').value.trim() })
      .then(function () {
        el('smName').value = ''; el('smContact').value = '';
        return loadSuppliers();
      })
      .then(function () { renderSupMgr(); toast('Đã thêm nhà cung cấp ✓', 'ok'); })
      .catch(function (e) { toast('Lỗi: ' + e.message, 'err'); });
  };
  window.vfrSupRemove = function (id) {
    ask('Xoá nhà cung cấp này? Nếu đang được dùng, hệ thống sẽ ẩn đi thay vì xoá hẳn.').then(function (yes) {
      if (!yes) return;
      vpost('?action=supplier-delete', { id: id })
        .then(function () { return loadSuppliers(); })
        .then(function () { renderSupMgr(); toast('Xong ✓', 'ok'); })
        .catch(function (e) { toast('Lỗi: ' + e.message, 'err'); });
    });
  };

  /* ── file sản xuất trên SharePoint ───────────────────────── */
  function renderProdBox() {
    var box = el('vfrZipBox');
    if (!box) return;
    if (pendProd && pendProd.url) {
      box.innerHTML = '<div class="zipbox"><span class="prodchip">' +
        '<a href="' + esc(pendProd.url) + '" target="_blank" rel="noopener">' +
        (pendProd.dir ? '▤ ' : '') + esc(pendProd.name) + '</a></span>' +
        '<button class="zipbtn" onclick="vfrPickOpen()">↻ Đổi</button>' +
        '<button class="zipbtn" onclick="vfrProdClear()">✕ Gỡ</button></div>';
    } else {
      box.innerHTML = '<div class="zipbox">' +
        '<button class="zipbtn" onclick="vfrPickOpen()">📁 Chọn từ SharePoint</button>' +
        '<span class="hint">Chọn thư mục hoặc file trong kho VFR trên SharePoint — tải file mới lên ngay tại đây cũng được.</span></div>';
    }
  }
  window.vfrProdClear = function () { pendProd = null; renderProdBox(); };

  var spPath = [];   // [{id,name}]
  window.vfrPickOpen = function () {
    el('spOv').classList.add('open');
    spPath = [];
    spLoad('');
  };
  window.vfrPickClose = function () { el('spOv').classList.remove('open'); };

  function spCrumbs() {
    var h = '';
    for (var i = 0; i < spPath.length; i++) {
      if (i) h += '<span>/</span>';
      h += '<a class="' + (i === spPath.length - 1 ? 'cur' : '') + '" onclick="vfrPickUp(' + i + ')">' + esc(spPath[i].name) + '</a>';
    }
    el('spCrumbs').innerHTML = h;
  }
  window.vfrPickUp = function (i) { spPath = spPath.slice(0, i + 1); spLoad(spPath[i].id); };

  function spLoad(id) {
    el('spList').innerHTML = '<div class="sup-empty" style="padding:18px">Đang tải…</div>';
    bapi('?action=list&root=vfr' + (id ? '&id=' + encodeURIComponent(id) : ''))
      .then(function (j) {
        if (!spPath.length) spPath = [{ id: j.folder.id, name: j.folder.name }];
        else spPath[spPath.length - 1] = { id: j.folder.id, name: j.folder.name };
        spCrumbs();
        var its = j.items || [];
        if (!its.length) { el('spList').innerHTML = '<div class="sup-empty" style="padding:18px">Thư mục trống.</div>'; return; }
        el('spList').innerHTML = its.map(function (x, i) {
          var lb = x.is_dir ? '▤' : ((x.ext || '').toUpperCase().slice(0, 4) || '•');
          return '<div class="sp-row" onclick="vfrPickHit(' + i + ')">' +
            '<div class="sp-ic ' + (x.is_dir ? 'dir' : '') + '">' + esc(lb) + '</div>' +
            '<div class="sp-nm">' + esc(x.name) + '</div>' +
            '<div class="sp-sz">' + (x.is_dir ? x.count + ' mục' : fmtSize(x.size)) + '</div></div>';
        }).join('');
        el('spList')._items = its;
      })
      .catch(function (e) {
        el('spList').innerHTML = '<div class="sup-empty" style="padding:18px">Không đọc được kho SharePoint: ' + esc(e.message) + '</div>';
      });
  }

  window.vfrPickHit = function (i) {
    var its = el('spList')._items || [];
    var x = its[i];
    if (!x) return;
    if (x.is_dir) { spPath.push({ id: x.id, name: x.name }); spLoad(x.id); return; }
    pendProd = { url: x.web_url, name: x.name, id: x.id, dir: 0 };
    vfrPickClose();
    renderProdBox();
    toast('Đã chọn: ' + x.name, 'ok');
  };

  window.vfrPickFolder = function () {
    if (!spPath.length) return;
    var cur = spPath[spPath.length - 1];
    bapi('?action=list&root=vfr&id=' + encodeURIComponent(cur.id)).then(function (j) {
      pendProd = { url: j.folder.web_url, name: j.folder.name, id: j.folder.id, dir: 1 };
      vfrPickClose();
      renderProdBox();
      toast('Đã chọn thư mục: ' + j.folder.name, 'ok');
    }).catch(function (e) { toast('Lỗi: ' + e.message, 'err'); });
  };

  window.vfrPickNewDir = function () {
    var nm = window.prompt('Tên thư mục mới:');
    if (!nm) return;
    var cur = spPath[spPath.length - 1];
    bapi('?action=mkdir&root=vfr', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ parent: cur.id, name: nm })
    }).then(function () { spLoad(cur.id); toast('Đã tạo thư mục ✓', 'ok'); })
      .catch(function (e) { toast('Lỗi: ' + e.message, 'err'); });
  };

  window.vfrPickUpload = function () { el('spFile').click(); };

  function spUpload(files) {
    var cur = spPath[spPath.length - 1];
    var prog = el('spProg'), bar2 = prog.querySelector('i');
    prog.style.display = 'block';
    var i = 0;
    function next() {
      if (i >= files.length) {
        prog.style.display = 'none';
        bar2.style.width = '0';
        spLoad(cur.id);
        toast('Đã tải lên ✓', 'ok');
        return;
      }
      var f = files[i++];
      bapi('?action=up-begin&root=vfr', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ parent: cur.id, name: f.name, size: f.size })
      }).then(function (j) {
        var key = j.key, chunk = j.chunk || 3932160, start = 0;
        function step() {
          if (start >= f.size) return Promise.resolve();
          var end = Math.min(start + chunk, f.size);
          bar2.style.width = Math.round(start / f.size * 100) + '%';
          return bapi('?action=up-chunk&root=vfr&key=' + encodeURIComponent(key) + '&start=' + start, {
            method: 'POST', headers: { 'Content-Type': 'application/octet-stream' }, body: f.slice(start, end)
          }).then(function (r) {
            start = r.done ? f.size : (r.next || end);
            return r.done ? null : step();
          });
        }
        return step();
      }).then(next).catch(function (e) {
        prog.style.display = 'none';
        toast('Lỗi tải lên: ' + e.message, 'err');
      });
    }
    next();
  }

  /* ── mở form ─────────────────────────────────────────────── */
  window.openAdd = function () {
    editing = null;
    editRows = [];
    pendProd = null;
    el('itDelBtn').style.display = 'none';
    ['itCatCode','itCatEn','itCatVn','itItemEn','itItemVn','itDescEn','itDescVn','itUnitEn','itUnitVn','itNotesEn','itNotesVn']
      .forEach(function (id) { el(id).value = ''; });
    ['itBasic','itStandard','itPremium'].forEach(function (id) { if (el(id)) el(id).value = ''; });
    ['itQ1','itQ2','itQ3'].forEach(function (id) { if (el(id)) el(id).value = ''; });
    if (el('itSpecs')) el('itSpecs').value = '';
    el('itSheet').value = inTrash ? 'event' : curSheet;
    fillCatSelect(el('itSheet').value, null);
    applyVfrMode();
    el('itOv').classList.add('open');
    setTimeout(function () { el('itItemEn').focus(); }, 60);
  };

  window.openEdit = function (id) {
    var it = items.find(function (x) { return x.id == id; });
    if (!it) return;
    editing = it;
    el('itDelBtn').style.display = 'block';
    el('itSheet').value = it.sheet_key;
    fillCatSelect(it.sheet_key, it.cat_code);
    el('itCatCode').value = it.cat_code || '';
    el('itCatEn').value = it.cat_en || '';
    el('itCatVn').value = it.cat_vn || '';
    el('itItemEn').value = it.item_en || '';
    el('itItemVn').value = it.item_vn || '';
    el('itDescEn').value = it.desc_en || '';
    el('itDescVn').value = it.desc_vn || '';
    el('itUnitEn').value = it.unit_en || '';
    el('itUnitVn').value = it.unit_vn || '';
    if (el('itBasic')) el('itBasic').value = nz(it.basic);
    if (el('itStandard')) el('itStandard').value = nz(it.standard);
    if (el('itPremium')) el('itPremium').value = nz(it.premium);
    if (el('itQ1')) el('itQ1').value = nz(it.basic);
    if (el('itQ2')) el('itQ2').value = nz(it.standard);
    if (el('itQ3')) el('itQ3').value = nz(it.premium);
    if (el('itSpecs')) el('itSpecs').value = it.specs || '';
    el('itNotesEn').value = it.notes_en || '';
    el('itNotesVn').value = it.notes_vn || '';

    pendProd = it.prod_url ? { url: it.prod_url, name: it.prod_name || 'SharePoint', id: it.prod_id || '', dir: parseInt(it.prod_dir || 0, 10) } : null;
    editRows = (SUPPLY[String(it.id)] || []).map(function (r) {
      return { supplier_id: r.supplier_id, p1: n0(r.p1) || '', p2: n0(r.p2) || '', p3: n0(r.p3) || '', note: r.note || '' };
    });

    applyVfrMode();
    el('itOv').classList.add('open');
  };

  /* ── lưu ─────────────────────────────────────────────────── */
  window.submitIt = function () {
    var itemEn = el('itItemEn').value.trim();
    if (!itemEn) { toast('Tên hạng mục (EN) là bắt buộc.', 'err'); return; }

    var vfrMode = el('itSheet').value === VFR;
    var catCode = '', catEn = '', catVn = '';
    if (!vfrMode) {
      var catSel = el('itCatSelect').value;
      if (catSel === '__new__') {
        catCode = el('itCatCode').value.trim().toUpperCase();
        catEn = el('itCatEn').value.trim();
        catVn = el('itCatVn').value.trim();
        if (!catCode || !catEn) { toast('Danh mục mới cần Mã + Tên (EN).', 'err'); return; }
      } else {
        var ex = catsForSheet(el('itSheet').value).find(function (c) { return c.code === catSel; });
        catCode = catSel; catEn = ex ? ex.en : ''; catVn = ex ? ex.vn : '';
      }
    }

    var rows = editRows.filter(function (r) { return r.supplier_id > 0; });
    var bestP1 = null;
    rows.forEach(function (r) { var v = n0(r.p1); if (v > 0 && (bestP1 === null || v < bestP1)) bestP1 = v; });

    askName(false).then(function (name) {
      var payload = {
        sheet_key: el('itSheet').value,
        cat_code: catCode, cat_en: catEn, cat_vn: catVn,
        item_en: itemEn, item_vn: el('itItemVn').value.trim(),
        desc_en: el('itDescEn').value.trim(), desc_vn: el('itDescVn').value.trim(),
        unit_en: el('itUnitEn').value.trim(), unit_vn: el('itUnitVn').value.trim(),
        basic:    vfrMode ? (el('itQ1').value || 0) : (el('itBasic').value || 0),
        standard: vfrMode ? (el('itQ2').value || 0) : (el('itStandard').value || 0),
        premium:  vfrMode ? (el('itQ3').value || 0) : (el('itPremium').value || 0),
        notes_en: el('itNotesEn').value.trim(), notes_vn: el('itNotesVn').value.trim(),
        updated_by: name
      };
      if (vfrMode) payload.cost_price = bestP1 === null ? 0 : bestP1;

      var btn = el('itSaveBtn');
      btn.disabled = true; btn.textContent = 'Đang lưu…';

      var p;
      if (editing) {
        payload.id = editing.id;
        p = api('?action=update', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) })
              .then(function () { return editing.id; });
      } else {
        p = api('?action=create', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) })
              .then(function (d) { return d.id; });
      }

      return p.then(function (id) {
        if (!vfrMode) return null;
        var jobs = [vpost('?action=extra-save', {
          item_id: id,
          specs: el('itSpecs') ? el('itSpecs').value.trim() : '',
          prod_url: pendProd ? pendProd.url : '',
          prod_name: pendProd ? pendProd.name : '',
          prod_id: pendProd ? pendProd.id : '',
          prod_dir: pendProd ? (pendProd.dir ? 1 : 0) : 0
        })];
        if (IS_ADMIN) jobs.push(vpost('?action=supply-save', { item_id: id, rows: rows }));
        return Promise.all(jobs);
      }).then(function () {
        toast('Đã lưu ✓', 'ok');
        closeIt();
        curSheet = payload.sheet_key; inTrash = false;
        return load();
      }).catch(function (e) {
        toast('Lỗi: ' + e.message, 'err');
      }).then(function () {
        btn.disabled = false;
        btn.textContent = vfrMode ? '✓ Lưu sản phẩm' : '✓ Lưu hạng mục';
      });
    });
  };

  /* ── nối vào vòng đời trang ──────────────────────────────── */
  var loadSeq = 0;
  window.load = async function () {
    var my = ++loadSeq;
    var q = new URLSearchParams({ action: 'list' });
    if (!inTrash) q.set('sheet', curSheet);
    if (curQ) q.set('q', curQ);
    if (inTrash) q.set('trash', '1');
    bar(35);
    try {
      var d = await api('?' + q.toString());
      if (my !== loadSeq) return;
      items = d.items || [];
      if (curSheet === VFR && !inTrash) await ensureVfrData(); else render();
      el('cnt').textContent = items.length;
    } catch (e) {
      if (my !== loadSeq) return;
      toast('Khong tai duoc du lieu: ' + e.message, 'err');
      el('content').innerHTML = '';
      el('empty').style.display = 'block';
    }
    bar(100);
    if (!inTrash) loadCounts();
  };

  /* ── VFR: gia chi co VAT, khong co phi quan ly MA ─────────── */
  var T = { form: null, formTxt: '', pill: null, pillHtml: '' };
  function initTerms() {
    if (T.form) return true;
    T.form = document.getElementById('formulaText');
    if (!T.form) return false;
    T.formTxt = T.form.textContent;
    var par = T.form.parentNode, kids = par ? par.children : [];
    for (var i = 0; i < kids.length; i++) {
      if (kids[i] !== T.form && /qu\u1EA3n l\u00FD/i.test(kids[i].textContent || '')) {
        T.pill = kids[i]; T.pillHtml = kids[i].innerHTML; break;
      }
    }
    return true;
  }
  function applyTerms() {
    if (!initTerms()) return;
    var v = (curSheet === VFR && !inTrash);
    T.form.textContent = v
      ? 'TOTAL = NET \u00D7 1.08  (ch\u1EC9 VAT 8%, kh\u00F4ng c\u00F3 ph\u00ED qu\u1EA3n l\u00FD MA)'
      : T.formTxt;
    if (T.pill) {
      T.pill.innerHTML = v
        ? T.pillHtml.replace(/ph\u00ED qu\u1EA3n l\u00FD \(MA\)\s*(?:&amp;|&|v\u00E0)\s*VAT/i, 'VAT')
        : T.pillHtml;
    }
    var ma = document.getElementById('calcMa');
    if (ma) {
      var row = ma.closest ? ma.closest('.calc-field') : ma.parentNode;
      if (v) {
        if (ma.value !== '0') { ma.setAttribute('data-sv', ma.value); ma.value = '0'; }
        if (row) row.style.display = 'none';
      } else {
        if (row) row.style.display = '';
        var sv = ma.getAttribute('data-sv');
        if (sv) { ma.value = sv; ma.removeAttribute('data-sv'); }
      }
      if (typeof runCalc === 'function') { try { runCalc(); } catch (e) {} }
    }
  }

  /* Bo dong "+MA" trong ket qua tinh tong khi dang o sheet VFR */
  var origCalc = window.runCalc;
  window.runCalc = function () {
    var r = origCalc ? origCalc.apply(this, arguments) : undefined;
    if (curSheet === VFR && !inTrash) {
      var box = document.getElementById('calcResult');
      if (box && box.innerHTML.indexOf('+MA') >= 0) {
        box.innerHTML = box.innerHTML.replace(/→\s*\+MA:.*?(?=→)/, '');
      }
    }
    return r;
  };

  var origRender = window.render;
  window.render = function () {
    if (!inTrash && items.length && items[0] && items[0].sheet_key && items[0].sheet_key !== curSheet) return;
    var r = origRender.apply(this, arguments);
    applyTerms();
    return r;
  };

  function guardTick(n) {
    if (n <= 0) return;
    setTimeout(function () {
      applyTerms();
      if (!inTrash && items.length && items[0] && items[0].sheet_key !== curSheet) { load(); return; }
      guardTick(n - 1);
    }, 400);
  }

  injectCss();
  buildModal();
  applyTerms();
  ensureMeta().then(function () {
    if (curSheet === VFR && !inTrash) ensureVfrData();
    applyTerms();
    guardTick(6);
  });
})();
