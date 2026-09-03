/* APSA — Yêu cầu thanh toán qua Zalo + chứng từ Ủy nhiệm chi (chi-phi.html)
   Nạp SAU chi-phi-group.js. Ghi đè window.payCell / togglePaid / bulkPaid. */
(function () {
  'use strict';

  var PAPI  = './api/pay-api.php';
  var PINFO = {};          // id -> { pay_req_at, pay_req_by, paid_at, paid_by, has_proof, proof_name, proof_mime }
  var PADMIN = 0;
  var pendIds = [], pendFile = null;

  function el(i) { return document.getElementById(i); }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function toast(m) { if (window.toast) window.toast(m); else alert(m); }
  function money(n) { return (Math.round(Number(n) || 0)).toLocaleString('vi-VN') + ' đ'; }
  function dt(s) { s = String(s || ''); return s.length >= 16 ? s.slice(8, 10) + '/' + s.slice(5, 7) + ' ' + s.slice(11, 16) : ''; }

  function api(action, body) {
    var o = { credentials: 'same-origin' };
    if (body) { o.method = 'POST'; o.headers = { 'Content-Type': 'application/json' }; o.body = JSON.stringify(body); }
    return fetch(PAPI + '?action=' + action, o).then(function (r) { return r.json(); })
      .then(function (j) { if (!j.ok) throw new Error(j.error || 'Lỗi'); return j.data; });
  }

  /* ── mã QR VietQR ─────────────────────────────────────── */
  function nrm(s) {
    return String(s || '').toLowerCase()
      .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
      .replace(/đ/g, 'd').replace(/[^a-z0-9]/g, '');
  }
  function pyBin(name) {
    var B = window.EXP_BANKS || [], n = nrm(name);
    if (!n) return '';
    var i, j;
    for (i = 0; i < B.length; i++) {
      if (nrm(B[i].name) === n) return B[i].bin;
      for (j = 0; j < (B[i].al || []).length; j++) if (B[i].al[j] === n) return B[i].bin;
    }
    for (i = 0; i < B.length; i++)
      for (j = 0; j < (B[i].al || []).length; j++)
        if (B[i].al[j].length >= 5 && n.indexOf(B[i].al[j]) >= 0) return B[i].bin;
    return '';
  }
  function noDia(s) {
    return String(s || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '')
      .replace(/đ/g, 'd').replace(/Đ/g, 'D').replace(/[^0-9A-Za-z ]/g, ' ').replace(/\s+/g, ' ').trim();
  }
  function amountOf(r) {
    var a = (Number(r.qty) || 0) * (Number(r.price) || 0);
    return Math.round(a * (1 + (Number(r.vat_percent) || 0) / 100));
  }
  function qrUrl(r) {
    var bin = pyBin(r.bank_name), acc = String(r.bank_account || '').replace(/[^0-9A-Za-z]/g, '');
    if (!bin || !acc) return '';
    var info = noDia(r.pay_memo
      ? r.pay_memo
      : ((r.q_code || r.code || '') + ' ' + (r.name || ''))).slice(0, 50);
    return 'https://img.vietqr.io/image/' + bin + '-' + acc + '-compact2.png'
      + '?amount=' + amountOf(r)
      + '&addInfo=' + encodeURIComponent(info)
      + '&accountName=' + encodeURIComponent(noDia(r.bank_holder || r.payee_name).slice(0, 50));
  }

  function rowById(id) {
    var R = window.ROWS || [];
    for (var i = 0; i < R.length; i++) if (String(R[i].id) === String(id)) return R[i];
    return null;
  }
  function redraw() { if (window.render) window.render(); }

  /* ── ô trạng thái ─────────────────────────────────────── */
  window.payCell = function (r) {
    var p = PINFO[String(r.id)] || {};
    var paid = Number(r.paid) === 1;
    var h = '<div class="pywrap">';

    if (paid) {
      h += '<button class="pbtn on" title="' + esc('Đã trả · ' + (p.paid_by || '') + (p.paid_at ? ' · ' + dt(p.paid_at) : '') + ' — bấm để bỏ đánh dấu') +
           '" onclick="payUnmark(' + r.id + ')">✓ Đã trả</button>';
      h += Number(p.has_proof)
        ? '<button class="pysm ok" title="' + esc('Xem Ủy nhiệm chi: ' + (p.proof_name || '')) + '" onclick="payProof(' + r.id + ')">📎 Xem UNC</button>'
        : '<button class="pysm up" title="Đính kèm Ủy nhiệm chi" onclick="payMark(' + r.id + ')">📎 Thêm UNC</button>';
      return h + '</div>';
    }

    if (p.pay_req_at) {
      h += '<span class="pbtn req" title="' + esc('Đã yêu cầu bởi ' + (p.pay_req_by || '') + ' · ' + dt(p.pay_req_at)) + '">⏳ Đã yêu cầu</span>';
      h += '<button class="pysm" title="Gửi lại yêu cầu qua Zalo" onclick="payReq(' + r.id + ',1)">↻ Gửi lại</button>';
    } else {
      h += '<span class="pbtn">Chưa trả</span>';
      h += '<button class="pysm go" title="Gửi yêu cầu thanh toán qua Zalo" onclick="payReq(' + r.id + ',0)">⚡ Yêu cầu TT</button>';
    }
    h += '<button class="pysm up" title="Đã chuyển khoản rồi — đính kèm Ủy nhiệm chi" onclick="payMark(' + r.id + ')">📎 Đã CK</button>';
    return h + '</div>';
  };

  /* ── gửi yêu cầu thanh toán ───────────────────────────── */
  window.payReq = function (id, again) {
    var r = rowById(id);
    if (!r) return;
    if (!r.payee_name) { toast('Dòng này chưa gán người nhận — chọn người nhận trước.'); return; }
    var msg = (again ? 'Gửi LẠI yêu cầu thanh toán qua Zalo?' : 'Gửi yêu cầu thanh toán qua Zalo?') +
      '\n\n' + (r.payee_name || '') + '\n' + money(amountOf(r)) +
      '\n' + (r.bank_name || '') + ' ' + (r.bank_account || '');
    if (!confirm(msg)) return;
    api('req', { id: id, qr_url: qrUrl(r) }).then(function (d) {
      PINFO[String(id)] = PINFO[String(id)] || {};
      PINFO[String(id)].pay_req_at = d.pay_req_at;
      PINFO[String(id)].pay_req_by = d.pay_req_by;
      toast('Đã gửi Zalo cho ' + (d.sent || []).join(', '));
      redraw();
    }).catch(function (e) { toast(e.message); });
  };

  /* ── bỏ đánh dấu đã trả ───────────────────────────────── */
  window.payUnmark = function (id) {
    if (!confirm('Bỏ đánh dấu "Đã trả" cho dòng này?\n(Chứng từ đã đính kèm vẫn được giữ lại)')) return;
    api('unpaid', { id: id }).then(function () {
      var r = rowById(id); if (r) r.paid = 0;
      var p = PINFO[String(id)]; if (p) { p.paid_at = null; p.paid_by = null; }
      redraw();
    }).catch(function (e) { toast(e.message); });
  };

  window.payProof = function (id) { window.open(PAPI + '?action=proof&id=' + id, '_blank', 'noopener'); };

  /* ── hộp thoại đính kèm Ủy nhiệm chi ──────────────────── */
  window.payMark = function (id) {
    var r = rowById(id); if (!r) return;
    openDlg([id], (r.name || '') + ' · ' + (r.payee_name || 'chưa gán người nhận') + ' · ' + money(amountOf(r)));
  };

  window.bulkPaid = function () {
    var S = window.SEL, ids = [];
    if (S && S.forEach) S.forEach(function (v) { ids.push(v); });
    else if (S) ids = Object.keys(S).filter(function (k) { return S[k]; });
    ids = ids.filter(function (id) { var r = rowById(id); return r && Number(r.paid) !== 1; });
    if (!ids.length) { toast('Chưa chọn dòng "Chưa trả" nào.'); return; }
    var tot = 0;
    ids.forEach(function (id) { var r = rowById(id); if (r) tot += amountOf(r); });
    openDlg(ids, ids.length + ' khoản · tổng ' + money(tot) + ' — dùng chung 1 file chứng từ');
  };

  function openDlg(ids, sub) {
    pendIds = ids.map(Number); pendFile = null;
    el('pySub').textContent = sub;
    el('pyPrev').innerHTML = '';
    el('pyGo').disabled = true;
    el('pyOv').classList.add('open');
  }
  window.payClose = function () { el('pyOv').classList.remove('open'); pendIds = []; pendFile = null; };

  function takeFile(f) {
    if (!f) return;
    var okType = /^image\/(png|jpe?g|webp)$/i.test(f.type) || f.type === 'application/pdf';
    if (!okType) { toast('Chỉ nhận ảnh PNG/JPG/WEBP hoặc PDF.'); return; }
    if (f.size > 12 * 1024 * 1024) { toast('File quá lớn (tối đa 12MB).'); return; }
    var rd = new FileReader();
    rd.onload = function () {
      pendFile = { name: f.name, mime: f.type, data: rd.result };
      el('pyPrev').innerHTML = f.type === 'application/pdf'
        ? '<div class="pyfile">📄 ' + esc(f.name) + ' · ' + Math.round(f.size / 1024) + ' KB</div>'
        : '<img src="' + rd.result + '" alt="" /><div class="pyfile">' + esc(f.name) + '</div>';
      el('pyGo').disabled = false;
    };
    rd.readAsDataURL(f);
  }

  window.paySend = function () {
    if (!pendFile) { toast('Chọn file Ủy nhiệm chi trước.'); return; }
    var b = el('pyGo'); b.disabled = true; b.textContent = 'Đang lưu…';
    api('paid', { ids: pendIds, file: pendFile }).then(function (d) {
      d.ids.forEach(function (id) {
        var r = rowById(id); if (r) r.paid = 1;
        PINFO[String(id)] = PINFO[String(id)] || {};
        PINFO[String(id)].paid_at = d.paid_at; PINFO[String(id)].paid_by = d.paid_by;
        PINFO[String(id)].has_proof = 1; PINFO[String(id)].proof_name = d.proof_name;
      });
      b.disabled = false; b.textContent = '✓ Xác nhận đã trả';
      window.payClose();
      if (window.clearSel) window.clearSel();
      toast('Đã ghi nhận thanh toán ' + d.ids.length + ' khoản');
      redraw();
    }).catch(function (e) {
      b.disabled = false; b.textContent = '✓ Xác nhận đã trả'; toast(e.message);
    });
  };

  /* ── dựng giao diện ───────────────────────────────────── */
  function mount() {
    var css = document.createElement('style');
    css.textContent =
      '.pywrap{display:flex;flex-direction:column;gap:3px;align-items:stretch}' +
      'span.pbtn{display:block;text-align:center;font-size:11.5px;font-weight:700;padding:4px 8px;border-radius:8px;' +
      'border:1px solid rgba(255,255,255,.14);color:#9aa0a6;background:rgba(255,255,255,.03)}' +
      '.pysm{font:inherit;font-size:11px;font-weight:700;padding:3px 7px;border-radius:7px;cursor:pointer;' +
      'border:1px solid rgba(255,255,255,.18);background:rgba(255,255,255,.05);color:#9aa0a6;white-space:nowrap}' +
      '.pysm:hover{color:#fff;border-color:rgba(255,255,255,.4)}' +
      '.pysm.go{color:#dff20d;border-color:rgba(223,242,13,.45)}' +
      '.pysm.go:hover{background:rgba(223,242,13,.14)}' +
      '.pysm.up,.pysm.ok{color:#4ade80;border-color:rgba(74,222,128,.42)}' +
      '.pysm.up:hover,.pysm.ok:hover{background:rgba(74,222,128,.14);color:#4ade80}' +
      '.pbtn.req{color:#facc15;border-color:rgba(250,204,21,.45)}' +
      '.pyov{position:fixed;inset:0;background:rgba(0,0,0,.75);display:none;align-items:center;justify-content:center;z-index:120;padding:22px}' +
      '.pyov.open{display:flex}' +
      '.pydlg{width:100%;max-width:520px;max-height:88vh;overflow:auto;background:#131313;border:1px solid rgba(255,255,255,.13);border-radius:16px;padding:18px}' +
      '.pydlg h2{font-size:17px;margin-bottom:4px}' +
      '.pydlg .s{font-size:12.5px;color:#9aa0a6;margin-bottom:14px;line-height:1.5}' +
      '.pydrop{border:1.5px dashed rgba(255,255,255,.22);border-radius:12px;padding:26px 14px;text-align:center;' +
      'font-size:13px;color:#9aa0a6;cursor:pointer;transition:.15s}' +
      '.pydrop:hover,.pydrop.hot{border-color:#dff20d;color:#dff20d;background:rgba(223,242,13,.06)}' +
      '#pyPrev img{max-width:100%;margin-top:12px;border-radius:10px;display:block}' +
      '.pyfile{margin-top:8px;font-size:12.5px;color:#dff20d;word-break:break-all}' +
      '.pyrow{display:flex;gap:8px;justify-content:flex-end;margin-top:16px}' +
      '.pyb{font:inherit;font-size:13px;font-weight:600;padding:9px 15px;border-radius:9px;cursor:pointer;' +
      'border:1px solid rgba(255,255,255,.16);background:#141414;color:#fff}' +
      '.pyb.p{background:#dff20d;color:#000;border-color:#dff20d}' +
      '.pyb:disabled{opacity:.45;cursor:default}';
    document.head.appendChild(css);

    var d = document.createElement('div');
    d.className = 'pyov'; d.id = 'pyOv';
    d.innerHTML =
      '<div class="pydlg">' +
        '<h2>Xác nhận đã thanh toán</h2>' +
        '<div class="s" id="pySub"></div>' +
        '<div class="pydrop" id="pyDrop">📎 Bấm để chọn — hoặc kéo thả / dán ảnh<br />' +
          '<span style="font-size:11.5px">Ủy nhiệm chi: ảnh PNG · JPG · WEBP hoặc file PDF (tối đa 12MB)</span></div>' +
        '<div id="pyPrev"></div>' +
        '<div class="pyrow">' +
          '<button class="pyb" onclick="payClose()">Hủy</button>' +
          '<button class="pyb p" id="pyGo" onclick="paySend()" disabled>✓ Xác nhận đã trả</button>' +
        '</div>' +
      '</div>';
    document.body.appendChild(d);

    var inp = document.createElement('input');
    inp.type = 'file'; inp.accept = 'image/png,image/jpeg,image/webp,application/pdf'; inp.style.display = 'none';
    document.body.appendChild(inp);
    inp.addEventListener('change', function () { takeFile(inp.files[0]); inp.value = ''; });

    var dp = el('pyDrop');
    dp.addEventListener('click', function () { inp.click(); });
    ['dragenter', 'dragover'].forEach(function (e) {
      dp.addEventListener(e, function (ev) { ev.preventDefault(); dp.classList.add('hot'); });
    });
    ['dragleave', 'drop'].forEach(function (e) {
      dp.addEventListener(e, function (ev) { ev.preventDefault(); dp.classList.remove('hot');
        if (e === 'drop' && ev.dataTransfer) takeFile(ev.dataTransfer.files[0]); });
    });
    document.addEventListener('paste', function (ev) {
      if (!el('pyOv').classList.contains('open')) return;
      var it = ev.clipboardData && ev.clipboardData.items; if (!it) return;
      for (var i = 0; i < it.length; i++) if (it[i].type.indexOf('image/') === 0) { takeFile(it[i].getAsFile()); ev.preventDefault(); }
    });
    d.addEventListener('click', function (ev) { if (ev.target === d) window.payClose(); });

    api('info').then(function (x) { PINFO = x.map || {}; PADMIN = x.admin ? 1 : 0; redraw(); })
      .catch(function (e) { console.warn('pay info:', e.message); });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mount);
  else mount();
})();
