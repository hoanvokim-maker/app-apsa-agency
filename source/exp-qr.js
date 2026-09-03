/* ============================================================
   APSA — Chi phí thực tế: người nhận · Đã trả/Chưa trả · QR VietQR
   Nạp sau script chính của quotation.html.
   Dùng các biến/hàm sẵn có: EXP, renderExp, markDirty, esc, fmt, api, API, toast
   ============================================================ */
(function () {
  'use strict';

  /* ── Danh sách ngân hàng (BIN theo chuẩn NAPAS/VietQR) ───── */
  var BANKS = [
    { bin: '970416', name: 'ACB',                 full: 'NH TMCP Á Châu',                 al: ['acb', 'achau'] },
    { bin: '970436', name: 'Vietcombank',         full: 'NH TMCP Ngoại thương VN',        al: ['vietcombank', 'vcb', 'ngoaithuong'] },
    { bin: '970415', name: 'VietinBank',          full: 'NH TMCP Công thương VN',         al: ['vietinbank', 'vietin', 'congthuong'] },
    { bin: '970418', name: 'BIDV',                full: 'NH Đầu tư & Phát triển VN',      al: ['bidv', 'dautuvaphattrien'] },
    { bin: '970405', name: 'Agribank',            full: 'NH Nông nghiệp & PTNT',          al: ['agribank', 'nongnghiep'] },
    { bin: '970407', name: 'Techcombank',         full: 'NH TMCP Kỹ thương',              al: ['techcombank', 'techcom'] },
    { bin: '970432', name: 'VPBank',              full: 'NH TMCP Việt Nam Thịnh Vượng',   al: ['vpbank', 'thinhvuong'] },
    { bin: '970423', name: 'TPBank',              full: 'NH TMCP Tiên Phong',             al: ['tpbank', 'tienphong'] },
    { bin: '970403', name: 'Sacombank',           full: 'NH TMCP Sài Gòn Thương Tín',     al: ['sacombank'] },
    { bin: '970437', name: 'HDBank',              full: 'NH TMCP Phát triển TP.HCM',      al: ['hdbank'] },
    { bin: '970422', name: 'MB Bank',             full: 'NH TMCP Quân đội',               al: ['mbbank', 'quandoi', 'militarybank'] },
    { bin: '970441', name: 'VIB',                 full: 'NH TMCP Quốc tế',                al: ['vib', 'quocte'] },
    { bin: '970443', name: 'SHB',                 full: 'NH TMCP Sài Gòn – Hà Nội',       al: ['shb', 'saigonhanoi'] },
    { bin: '970431', name: 'Eximbank',            full: 'NH TMCP Xuất Nhập Khẩu',         al: ['eximbank', 'xuatnhapkhau'] },
    { bin: '970426', name: 'MSB',                 full: 'NH TMCP Hàng Hải',               al: ['msb', 'maritime', 'hanghai'] },
    { bin: '970448', name: 'OCB',                 full: 'NH TMCP Phương Đông',            al: ['ocb', 'phuongdong'] },
    { bin: '970429', name: 'SCB',                 full: 'NH TMCP Sài Gòn',                al: ['scb'] },
    { bin: '970440', name: 'SeABank',             full: 'NH TMCP Đông Nam Á',             al: ['seabank', 'dongnama'] },
    { bin: '970425', name: 'ABBANK',              full: 'NH TMCP An Bình',                al: ['abbank', 'anbinh'] },
    { bin: '970409', name: 'BacABank',            full: 'NH TMCP Bắc Á',                  al: ['bacabank', 'baca'] },
    { bin: '970419', name: 'NCB',                 full: 'NH TMCP Quốc Dân',               al: ['ncb', 'quocdan'] },
    { bin: '970427', name: 'VietABank',           full: 'NH TMCP Việt Á',                 al: ['vietabank', 'vieta'] },
    { bin: '970433', name: 'VietBank',            full: 'NH TMCP Việt Nam Thương Tín',    al: ['vietbank'] },
    { bin: '970454', name: 'BVBank',              full: 'NH TMCP Bản Việt',               al: ['bvbank', 'banviet', 'vietcapital'] },
    { bin: '970449', name: 'LPBank',              full: 'NH TMCP Lộc Phát VN',            al: ['lpbank', 'lienvietpostbank', 'lienviet'] },
    { bin: '970452', name: 'KienLongBank',        full: 'NH TMCP Kiên Long',              al: ['kienlongbank', 'kienlong'] },
    { bin: '970438', name: 'BaoVietBank',         full: 'NH TMCP Bảo Việt',               al: ['baovietbank'] },
    { bin: '970430', name: 'PGBank',              full: 'NH TMCP Thịnh vượng & PT',       al: ['pgbank'] },
    { bin: '970412', name: 'PVcomBank',           full: 'NH TMCP Đại Chúng VN',           al: ['pvcombank', 'pvcom'] },
    { bin: '970400', name: 'SaigonBank',          full: 'NH TMCP Sài Gòn Công Thương',    al: ['saigonbank', 'saigoncongthuong'] },
    { bin: '970428', name: 'NamABank',            full: 'NH TMCP Nam Á',                  al: ['namabank', 'nama'] },
    { bin: '970446', name: 'COOPBANK',            full: 'NH Hợp tác xã VN',               al: ['coopbank', 'hoptacxa'] },
    { bin: '970424', name: 'ShinhanBank',         full: 'NH Shinhan VN',                  al: ['shinhan'] },
    { bin: '970457', name: 'Woori',               full: 'NH Woori VN',                    al: ['woori'] },
    { bin: '970458', name: 'UOB',                 full: 'NH United Overseas',             al: ['uob'] },
    { bin: '970442', name: 'HongLeong',           full: 'NH Hong Leong VN',               al: ['hongleong'] },
    { bin: '970434', name: 'IVB',                 full: 'NH Indovina',                    al: ['indovina', 'ivb'] },
    { bin: '970421', name: 'VRB',                 full: 'NH Liên doanh Việt – Nga',       al: ['vrb'] },
    { bin: '970444', name: 'CBBank',              full: 'NH Xây dựng VN',                 al: ['cbbank', 'xaydung'] },
    { bin: '970414', name: 'Oceanbank',           full: 'NH Đại Dương',                   al: ['oceanbank', 'daiduong'] },
    { bin: '546034', name: 'CAKE by VPBank',      full: 'CAKE',                           al: ['cake'] },
    { bin: '546035', name: 'Ubank by VPBank',     full: 'Ubank',                          al: ['ubank'] },
    { bin: '963388', name: 'Timo',                full: 'Timo by BVBank',                 al: ['timo'] },
    { bin: '971005', name: 'ViettelMoney',        full: 'Viettel Money',                  al: ['viettelmoney', 'viettelpay'] },
    { bin: '971011', name: 'VNPTMoney',           full: 'VNPT Money',                     al: ['vnptmoney'] }
  ];

  function nrm(s) {
    return String(s || '')
      .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
      .replace(/đ/gi, 'd')
      .toLowerCase().replace(/[^a-z0-9]/g, '');
  }
  function noDia(s) {
    return String(s || '')
      .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
      .replace(/đ/g, 'd').replace(/Đ/g, 'D');
  }

  /* Dò tên ngân hàng đã lưu -> BIN. Trả '' nếu không chắc. */
  function findBin(bankName) {
    var q = nrm(bankName);
    if (!q) return '';
    var i, j, b;
    for (i = 0; i < BANKS.length; i++) {          // 1. khớp tuyệt đối
      b = BANKS[i];
      if (nrm(b.name) === q) return b.bin;
      for (j = 0; j < b.al.length; j++) if (b.al[j] === q) return b.bin;
    }
    for (i = 0; i < BANKS.length; i++) {          // 2. chứa alias đủ dài
      b = BANKS[i];
      for (j = 0; j < b.al.length; j++) {
        if (b.al[j].length >= 5 && q.indexOf(b.al[j]) >= 0) return b.bin;
      }
    }
    return '';
  }
  window.expFindBin = findBin;
  window.EXP_BANKS  = BANKS;

  /* ── Danh sách người nhận ─────────────────────────────────── */
  window.EXP_PAYEES = { sup: [], user: [], loaded: false, seeBank: 1 };

  window.expLoadPayees = async function () {
    try {
      var d = await api(API, 'payees');
      EXP_PAYEES.sup     = d.sup  || [];
      EXP_PAYEES.user    = d.user || [];
      EXP_PAYEES.seeBank = Number(d.see_personal_bank) === 1 ? 1 : 0;
      EXP_PAYEES.loaded  = true;
      if (typeof renderExp === 'function' && Array.isArray(EXP) && EXP.length) renderExp();
    } catch (e) { /* im lặng — vẫn dùng được phần còn lại */ }
  };

  function payeeKey(r) {
    if (!r || !r.payee_type || !r.payee_id) return '';
    return r.payee_type + ':' + r.payee_id;
  }
  function findPayee(t, id) {
    var arr = t === 'sup' ? EXP_PAYEES.sup : EXP_PAYEES.user, i;
    for (i = 0; i < arr.length; i++) if (String(arr[i].id) === String(id)) return arr[i];
    return null;
  }

  /* ── Ô chọn người nhận ────────────────────────────────────── */
  window.expPayeeCell = function (r, i) {
    var cur = payeeKey(r), o = '', k;
    o += '<option value=""' + (cur === '' ? ' selected' : '') + '>— chưa chọn —</option>';
    if (EXP_PAYEES.sup.length) {
      o += '<optgroup label="Nhà cung cấp (công ty)">';
      EXP_PAYEES.sup.forEach(function (p) {
        k = 'sup:' + p.id;
        o += '<option value="' + k + '"' + (k === cur ? ' selected' : '') + '>' + esc(p.name) + '</option>';
      });
      o += '</optgroup>';
    }
    if (EXP_PAYEES.user.length) {
      o += '<optgroup label="Nhân sự / Freelancer (cá nhân)">';
      EXP_PAYEES.user.forEach(function (p) {
        k = 'user:' + p.id;
        o += '<option value="' + k + '"' + (k === cur ? ' selected' : '') + '>' + esc(p.name) + '</option>';
      });
      o += '</optgroup>';
    }
    if (cur && !findPayee(r.payee_type, r.payee_id) && r.payee_name) {
      o += '<option value="' + esc(cur) + '" selected>' + esc(r.payee_name) + '</option>';
    }
    return '<select class="exp-payee-sel" onchange="expPayeeSet(' + i + ',this.value)">' + o + '</select>';
  };

  window.expPayeeSet = function (i, v) {
    var r = EXP[i]; if (!r) return;
    if (!v) {
      r.payee_type = ''; r.payee_id = 0; r.payee_name = '';
      r.bank_name = ''; r.bank_account = ''; r.bank_holder = '';
    } else {
      var p = v.split(':'), o = findPayee(p[0], p[1]);
      r.payee_type = p[0];
      r.payee_id   = Number(p[1]) || 0;
      r.payee_name = o ? o.name : '';
      r.bank_name    = o ? (o.bank_name    || '') : '';
      r.bank_account = o ? (o.bank_account || '') : '';
      r.bank_holder  = o ? (o.bank_holder  || '') : '';
      r.vat_percent  = (p[0] === 'sup' && (!o || o.kind !== 'person')) ? 8 : 0;   // công ty 8% VAT · cá nhân không VAT
      r.bank_masked  = (p[0] === 'user' && !EXP_PAYEES.seeBank) ? 1 : 0;
    }
    markDirty();
    renderExp();
  };

  /* ── Toggle Chưa trả / Đã trả ─────────────────────────────── */
  window.expPaidCell = function (r, i) {
    var on = Number(r.paid) === 1;
    return '<button type="button" class="exp-paid' + (on ? ' on' : '') + '" onclick="expPaidToggle(' + i + ')">' +
           (on ? '✓ Đã trả' : 'Chưa trả') + '</button>';
  };
  window.expPaidToggle = function (i) {
    var r = EXP[i]; if (!r) return;
    r.paid = Number(r.paid) === 1 ? 0 : 1;
    markDirty();
    renderExp();
  };

  /* ── Nút QR + nút xoá dòng ────────────────────────────────── */
  window.expQrCell = function (r, i) {
    var masked = Number(r.bank_masked) === 1;
    var ok = !masked && r.bank_account && String(r.bank_account).trim() !== '';
    var tip = masked ? 'Chỉ Admin xem được số tài khoản cá nhân'
                     : (ok ? 'Mã QR chuyển khoản' : 'Chưa có số tài khoản');
    return '<button type="button" class="exp-qrb' + (ok ? '' : ' off') + '" title="' + tip + '"' +
             ' onclick="expQrOpen(' + i + ')">▦</button>' +
           '<button type="button" class="exp-x" onclick="expDel(' + i + ')" title="Xoá dòng">×</button>';
  };

  /* ── Màu nền dòng theo loại chi ───────────────────────────── */
  window.expRowCls = function (r) {
    if (!r || !r.payee_type) return '';
    return r.payee_type === 'user' ? 'exp-pers' : 'exp-corp';
  };

  /* ── Popup QR ─────────────────────────────────────────────── */
  function expQuoCode() {
    var e = document.getElementById('fCode');
    if (e && e.value) return e.value;
    var m = String(document.title || '').match(/[0-9]{6,8}-[0-9]+/);
    return m ? m[0] : '';
  }

  function amtOf(r) {
    var amt = (Number(r.qty) || 0) * (Number(r.price) || 0);
    amt = amt + amt * (Number(r.vat_percent) || 0) / 100;
    return Math.round(amt);
  }

  window.expQrOpen = function (i) {
    var r = EXP[i]; if (!r) return;
    if (Number(r.bank_masked) === 1) {
      if (typeof toast === 'function') toast('Số tài khoản cá nhân chỉ Admin xem được.', 'err');
      return;
    }
    if (!r.bank_account) {
      if (typeof toast === 'function') toast('Dòng này chưa có số tài khoản — chọn người nhận trước.', 'err');
      return;
    }
    var ov = document.getElementById('expQrOv');
    if (!ov) {
      ov = document.createElement('div');
      ov.id = 'expQrOv';
      ov.className = 'expqr-ov';
      ov.onclick = function (e) { if (e.target === ov) expQrClose(); };
      document.body.appendChild(ov);
    }
    var bin  = findBin(r.bank_name);
    var opts = BANKS.map(function (b) {
      return '<option value="' + b.bin + '"' + (b.bin === bin ? ' selected' : '') + '>' +
             esc(b.name) + ' — ' + esc(b.full) + '</option>';
    }).join('');

    ov.innerHTML =
      '<div class="expqr-box">' +
        '<div class="expqr-hd">' +
          '<b>Chuyển khoản</b>' +
          '<button type="button" class="expqr-x" onclick="expQrClose()">✕</button>' +
        '</div>' +
        '<div class="expqr-bd">' +
          '<div class="expqr-l ro" id="expQrL">' +
            '<label>Ngân hàng</label>' +
            '<select id="expQrBank" onchange="expQrDraw(' + i + ')">' +
              '<option value=""' + (bin ? '' : ' selected') + '>— chọn ngân hàng —</option>' + opts +
            '</select>' +
            '<label>Số tài khoản</label>' +
            '<input id="expQrAcc" readonly value="' + esc(r.bank_account) + '" oninput="expQrDraw(' + i + ')" />' +
            '<label>Chủ tài khoản</label>' +
            '<input id="expQrName" readonly value="' + esc(r.bank_holder || r.payee_name || '') + '" oninput="expQrDraw(' + i + ')" />' +
            '<label>Số tiền (VND)</label>' +
            '<input id="expQrAmt" readonly value="' + String(Math.round(amtOf(r))).replace(/\B(?=(\d{3})+(?!\d))/g, '.') + '" oninput="expQrDraw(' + i + ')" />' +
            '<label>Nội dung chuyển khoản</label>' +
            '<input id="expQrInfo" readonly value="' + esc(noDia(expQuoCode() + ' ' + (r.name || '')).trim()) + '" oninput="expQrDraw(' + i + ')" />' +
            '<div class="expqr-note">Quét bằng app ngân hàng. Bấm “Sửa” nếu cần đổi ngân hàng hoặc thông tin.</div>' +
            '<button type="button" class="expqr-edit" onclick="expQrEdit()">✎ Sửa thông tin</button>' +
          '</div>' +
          '<div class="expqr-r"><div id="expQrImgWrap"></div></div>' +
        '</div>' +
      '</div>';
    ov.classList.add('open');
    window.expQrDraw(i);
  };

  window.expQrEdit = function () {
    var l = document.getElementById('expQrL');
    if (l) l.classList.remove('ro');
    var ids = ['expQrAcc', 'expQrName', 'expQrInfo'];
    for (var i = 0; i < ids.length; i++) {
      var e = document.getElementById(ids[i]);
      if (e) e.readOnly = false;
    }
    var b = document.querySelector('.expqr-edit');
    if (b) b.style.display = 'none';
    if (typeof window.expQrDraw === 'function') window.expQrDraw();
  };

  window.expQrClose = function () {
    var ov = document.getElementById('expQrOv');
    if (ov) { ov.classList.remove('open'); ov.innerHTML = ''; }
  };

  window.expQrDraw = function () {
    var wrap = document.getElementById('expQrImgWrap'); if (!wrap) return;
    var bin  = (document.getElementById('expQrBank')  || {}).value || '';
    var acc  = ((document.getElementById('expQrAcc')  || {}).value || '').replace(/[^0-9A-Za-z]/g, '');
    var nm   = noDia((document.getElementById('expQrName') || {}).value || '');
    var amt  = String((document.getElementById('expQrAmt')  || {}).value || '').replace(/[^0-9]/g, '');
    var info = noDia((document.getElementById('expQrInfo') || {}).value || '');
    if (!bin || !acc) {
      wrap.innerHTML = '<div class="expqr-empty">Chọn ngân hàng và nhập số tài khoản để tạo mã QR.</div>';
      return;
    }
    var url = 'https://img.vietqr.io/image/' + bin + '-' + encodeURIComponent(acc) + '-compact2.png' +
              '?amount=' + encodeURIComponent(amt) +
              '&addInfo=' + encodeURIComponent(info) +
              '&accountName=' + encodeURIComponent(nm);
    wrap.innerHTML = '<img src="' + url + '" alt="QR chuyển khoản" class="expqr-img" ' +
                     'onerror="this.parentNode.innerHTML=\'<div class=&quot;expqr-empty&quot;>Không tạo được mã QR — kiểm tra lại ngân hàng / số tài khoản.</div>\'" />' +
                     '<a class="expqr-open" href="' + url + '" target="_blank" rel="noopener">Mở ảnh QR</a>' +
                     '<a class="expqr-open" id="expQrDl" download="QR-' + acc + '.png" ' +
                     'href="api/quotation-api.php' + String.fromCharCode(63) + 'action=qr-png' +
                     '&bin=' + bin + '&acc=' + acc + '&amount=' + encodeURIComponent(amt) +
                     '&info=' + encodeURIComponent(info) + '&name=' + encodeURIComponent(nm) +
                     '">⬇ Tải ảnh PNG</a>';
  };

  /* ── CSS ──────────────────────────────────────────────────── */
  var css = ''
    + '.exptbl tr.exp-pers > td{background-image:linear-gradient(rgba(250,204,21,.16),rgba(250,204,21,.16))!important}'
    + '.exptbl tr.exp-corp > td{background-image:linear-gradient(rgba(56,148,255,.18),rgba(56,148,255,.18))!important}'
    + '.exptbl tr.exp-pers > td:first-child{box-shadow:inset 3px 0 0 rgba(250,204,21,.75)}'
    + '.exptbl tr.exp-corp > td:first-child{box-shadow:inset 3px 0 0 rgba(56,148,255,.75)}'
    + '.exp-payee-sel{width:100%;max-width:100%;background:transparent;border:1px solid var(--line,#333);'
    +   'border-radius:6px;color:inherit;font:inherit;font-size:12px;padding:3px 4px}'
    + '.exp-payee-sel option,.exp-payee-sel optgroup{background:#14161a;color:#e8e8e8}'
    + '.exp-paid{border:1px solid var(--line,#333);background:transparent;color:var(--text2,#999);'
    +   'border-radius:999px;font:inherit;font-size:11px;padding:2px 9px;cursor:pointer;white-space:nowrap}'
    + '.exp-paid.on{border-color:rgba(34,197,94,.6);color:#22c55e;background:rgba(34,197,94,.12);font-weight:700}'
    + '.exp-qrb{border:1px solid var(--line,#333);background:transparent;color:inherit;border-radius:6px;'
    +   'font-size:13px;line-height:1;padding:3px 6px;cursor:pointer;margin-right:4px}'
    + '.exp-qrb.off{opacity:.35;cursor:not-allowed}'
    + '.expqr-ov{position:fixed;inset:0;background:rgba(0,0,0,.72);z-index:9999;display:none;'
    +   'align-items:center;justify-content:center;padding:20px}'
    + '.expqr-ov.open{display:flex}'
    + '.expqr-box{background:var(--bg2,#16181d);border:1px solid var(--line,#333);border-radius:14px;'
    +   'width:min(720px,100%);max-height:92vh;overflow:auto}'
    + '.expqr-hd{display:flex;align-items:center;justify-content:space-between;padding:14px 18px;'
    +   'border-bottom:1px solid var(--line,#333);font-size:14px;letter-spacing:.04em}'
    + '.expqr-x{background:transparent;border:0;color:inherit;font-size:16px;cursor:pointer}'
    + '.expqr-bd{display:grid;grid-template-columns:1fr 260px;gap:18px;padding:18px}'
    + '@media(max-width:640px){.expqr-bd{grid-template-columns:1fr}}'
    + '.expqr-l label{display:block;font-size:10px;letter-spacing:.08em;text-transform:uppercase;'
    +   'color:var(--text3,#888);margin:10px 0 4px}'
    + '.expqr-l input,.expqr-l select{width:100%;background:transparent;border:1px solid var(--line,#333);'
    +   'border-radius:8px;color:inherit;font:inherit;font-size:13px;padding:7px 9px}'
    + '.expqr-l select option{background:#14161a;color:#e8e8e8}'
    + '.expqr-note{margin-top:12px;font-size:11px;color:var(--text3,#888);line-height:1.5}'
    + '.expqr-r{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:8px}'
    + '.expqr-img{width:240px;height:auto;background:#fff;border-radius:10px;padding:6px}'
    + '.expqr-open{font-size:11px;color:var(--text3,#888)}'
    + '.expqr-empty{font-size:12px;color:var(--text3,#888);text-align:center;padding:24px 8px;line-height:1.6}' +
      '.expqr-l.ro #expQrBank,.expqr-l.ro #expQrAcc,.expqr-l.ro #expQrName,'
    + '.expqr-l.ro #expQrAmt,.expqr-l.ro #expQrInfo{border-color:transparent;'
    + 'background:transparent;padding:3px 0;text-align:left;font-size:14px;'
    + 'color:inherit;-webkit-appearance:none;appearance:none}'
    + '.expqr-l.ro #expQrBank{pointer-events:none;cursor:default;border-color:transparent!important;background:transparent!important;background-image:none!important;padding-left:0!important}'
    + '.expqr-l.ro label{margin:12px 0 2px}#expQrImgWrap{text-align:center}.expqr-open{display:inline-block;margin:6px 7px 0}'
    + '.expqr-edit{margin-top:14px;font-size:11px;color:var(--text3,#888);cursor:pointer;'
    + 'border:1px solid var(--line,#333);border-radius:8px;padding:5px 11px;'
    + 'background:transparent;font-family:inherit}'
    + '.expqr-edit:hover{color:inherit;border-color:var(--text3,#888)}';
  var st = document.createElement('style');
  st.textContent = css;
  document.head.appendChild(st);

  /* nạp danh sách người nhận khi trang sẵn sàng */
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { setTimeout(window.expLoadPayees, 300); });
  } else {
    setTimeout(window.expLoadPayees, 300);
  }
})();
