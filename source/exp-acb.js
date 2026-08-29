/* ---- Xuat file thanh toan hang loat cho ACB (chi-phi.html) ---- */
(function () {
  'use strict';
  var Q = String.fromCharCode(63);
  var CDN = 'https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js';
  var NOTE = 'Lưu ý:  Quý khách vui lòng nhập thông tin không dùng dấu tiếng Việt, không thay đổi định dạng file.';

  function say(m, k) { if (typeof window.toast === 'function') window.toast(m, k); }
  function noDia(s) {
    return String(s == null ? '' : s).replace(/đ/g, 'd').replace(/Đ/g, 'D')
      .normalize('NFD').replace(/[^\x00-\x7F]/g, '').replace(/\s+/g, ' ').trim();
  }
  function digits(s) { return String(s == null ? '' : s).replace(/[^0-9]/g, ''); }
  function money(r) {
    if (typeof window.amt === 'function') return Math.round(Number(window.amt(r)) || 0);
    var b = (Number(r.qty) || 0) * (Number(r.price) || 0);
    return Math.round(b * (1 + (Number(r.vat_percent) || 0) / 100));
  }
  function loadXlsx() {
    if (window.XLSX) return Promise.resolve();
    return new Promise(function (ok, no) {
      var s = document.createElement('script');
      s.src = CDN;
      s.onload = ok;
      s.onerror = function () { no(new Error('Không tải được thư viện Excel')); };
      document.head.appendChild(s);
    });
  }

  function pickRows() {
    var all = (typeof window.view === 'function') ? window.view() : (window.ROWS || []);
    var sel = window.SEL || {};
    var nSel = 0;
    for (var k in sel) if (sel[k]) nSel++;
    var out = [];
    for (var i = 0; i < all.length; i++) {
      var r = all[i];
      if (nSel > 0) { if (!sel[r.id]) continue; }
      else if (Number(r.paid) === 1) continue;
      out.push(r);
    }
    return { rows: out, bySel: nSel > 0 };
  }

  window.expAcbFile = async function () {
    var pick = pickRows();
    if (!pick.rows.length) { say('Không có dòng nào để xuất.', 'err'); return; }

    var good = [], noPayee = [], noBank = [];
    for (var i = 0; i < pick.rows.length; i++) {
      var r = pick.rows[i];
      var acc = digits(r.bank_account);
      var nm = noDia(r.bank_holder || r.payee_name || '');
      if (!nm) { noPayee.push(r); continue; }
      if (!acc || !String(r.bank_name || '').trim()) { noBank.push(r); continue; }
      good.push(r);
    }
    if (!good.length) {
      say('Không dòng nào đủ thông tin: ' + noPayee.length + ' dòng chưa gán người nhận, ' +
        noBank.length + ' dòng thiếu ngân hàng / số tài khoản.', 'err');
      return;
    }

    var names = [], seen = {};
    for (var j = 0; j < good.length; j++) {
      var bn = String(good[j].bank_name).trim();
      if (bn && !seen[bn]) { seen[bn] = 1; names.push(bn); }
    }
    var map = {};
    try {
      var res = await fetch('api/payroll-api.php' + Q + 'action=acb-map', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ names: names })
      });
      var jj = await res.json();
      map = (jj && jj.map) ? jj.map : ((jj.data && jj.data.map) ? jj.data.map : {});
    } catch (e) { say('Không tra được mã ngân hàng: ' + e.message, 'err'); return; }

    var miss = [];
    for (var m = 0; m < names.length; m++) if (!map[names[m]] || !map[names[m]].code) miss.push(names[m]);

    try { await loadXlsx(); } catch (e) { say(e.message, 'err'); return; }

    var aoa = [['TIÊU ĐỀ'],
      ['STT', 'Tên đơn vị thụ hưởng', 'Mã ngân hàng', 'Số tài khoản nhận', 'Số thẻ (ACB)', 'Số tiền', 'Nội dung']];
    for (var g = 0; g < good.length; g++) {
      var x = good[g];
      var bk = map[String(x.bank_name).trim()];
      var ct = noDia((x.code || '') + ' ' + (x.name || x.payee_name || '')).toUpperCase().slice(0, 45);
      aoa.push([g + 1, noDia(x.bank_holder || x.payee_name), bk ? bk.code : '',
        digits(x.bank_account), '', money(x), ct]);
    }
    aoa.push([]);
    aoa.push([NOTE]);

    var ws = window.XLSX.utils.aoa_to_sheet(aoa);
    ws['!cols'] = [{ wch: 6 }, { wch: 32 }, { wch: 14 }, { wch: 28 }, { wch: 7 }, { wch: 17 }, { wch: 26 }];
    ws['!merges'] = [{ s: { r: 0, c: 0 }, e: { r: 0, c: 6 } }];
    var wb = window.XLSX.utils.book_new();
    window.XLSX.utils.book_append_sheet(wb, ws, 'Chi Lo');
    var d = new Date();
    var pad = function (n) { return (n < 10 ? '0' : '') + n; };
    window.XLSX.writeFile(wb, 'ACB_CHI_' + d.getFullYear() + pad(d.getMonth() + 1) + pad(d.getDate()) + '.xlsx');

    var msg = 'Đã xuất ' + good.length + ' dòng';
    if (noPayee.length) msg += ' · bỏ qua ' + noPayee.length + ' dòng chưa gán người nhận';
    if (noBank.length) msg += ' · bỏ qua ' + noBank.length + ' dòng thiếu số TK';
    say(msg, 'ok');
    if (miss.length) say('Chưa có mã ngân hàng cho: ' + miss.join(', ') + ' — vào Bảng lương bấm “Danh mục ngân hàng” để nhập 2 file mã ACB.', 'err');
  };

  function mount() {
    if (document.getElementById('btnAcb')) return;
    var bar = document.querySelector('.toolbar') || document.querySelector('.tools');
    var ref = null;
    var bs = document.querySelectorAll('button');
    for (var i = 0; i < bs.length; i++) {
      var t = (bs[i].textContent || '').trim();
      if (t.indexOf('Khoản chi') >= 0 || t.indexOf('Tải lại') >= 0) { ref = bs[i]; break; }
    }
    if (!ref) return;
    var b = document.createElement('button');
    b.id = 'btnAcb';
    b.type = 'button';
    b.className = ref.className;
    b.textContent = '⬇ File ACB';
    b.title = 'Xuất file Excel để import vào ACB, thanh toán hàng loạt';
    b.onclick = function () { window.expAcbFile(); };
    ref.parentNode.insertBefore(b, ref);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { setTimeout(mount, 600); });
  else setTimeout(mount, 600);
})();
