/* exp-day.js — ngay chi tra cho chi phi thuc te + tinh chinh dialog chuyen khoan
 * Nap SAU exp-qr.js trong quotation.html. v1
 */
(function () {
  'use strict';

  function dot(v) {
    var s = String(v == null ? '' : v).replace(/[^0-9]/g, '');
    return s ? s.replace(/\B(?=(\d{3})+(?!\d))/g, '.') : '';
  }
  function esc(s) {
    return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }
  function ymd(v) {
    var s = String(v == null ? '' : v).trim().slice(0, 10);
    return /^\d{4}-\d{2}-\d{2}$/.test(s) ? s : '';
  }
  function rowOf(i) {
    try { if (typeof EXP !== 'undefined' && EXP && EXP[i]) return EXP[i]; } catch (e) {}
    return null;
  }
  function setDate(i, v) {
    v = ymd(v);
    var r = rowOf(i);
    if (r) r.pay_date = v;
    try { if (typeof expSet === 'function') expSet(i, 'pay_date', v); } catch (e) {}
    var cell = document.getElementById('expday-' + i);
    if (cell && cell.value !== v) cell.value = v;
    var dlg = document.getElementById('expQrDay');
    if (dlg && dlg.value !== v) dlg.value = v;
  }

  /* ---------- 1. o "Ngay TT" trong bang chi phi thuc te ---------- */
  window.expDayCell = function (r, i) {
    return '<input type="date" class="expday" id="expday-' + i + '" value="' +
      esc(ymd(r && r.pay_date)) + '" title="Ngày sẽ thanh toán" ' +
      'onchange="expDaySet(' + i + ', this.value)">';
  };
  window.expDaySet = function (i, v) { setDate(i, v); };

  /* ---------- 2. dialog chuyen khoan ---------- */
  var _open = window.expQrOpen;
  var _draw = window.expQrDraw;

  function fixAmount() {
    var a = document.getElementById('expQrAmt');
    if (!a || a.getAttribute('data-ro') === '1') return;
    a.setAttribute('data-ro', '1');
    a.readOnly = true;
    a.value = dot(a.value);
    a.style.textAlign = 'right';
    a.style.opacity = '.75';
    a.style.cursor = 'not-allowed';
    a.title = 'Số tiền lấy từ dòng chi phí';
    a.addEventListener('keydown', function (e) { if (e.key !== 'Tab') e.preventDefault(); });
  }

  function addDate(i) {
    if (document.getElementById('expQrDay')) return;
    var info = document.getElementById('expQrInfo');
    if (!info || !info.parentNode) return;
    var lb = document.createElement('label');
    lb.textContent = 'Ngày sẽ thanh toán';
    var inp = document.createElement('input');
    inp.type = 'date';
    inp.id = 'expQrDay';
    inp.value = ymd((rowOf(i) || {}).pay_date);
    inp.addEventListener('change', function () { setDate(i, this.value); });
    info.parentNode.insertBefore(lb, info.nextSibling);
    lb.parentNode.insertBefore(inp, lb.nextSibling);
  }

  window.expQrOpen = function (i) {
    var out = _open ? _open.apply(this, arguments) : undefined;
    fixAmount(); addDate(i);
    return out;
  };

  window.expQrDraw = function () {
    var out = _draw ? _draw.apply(this, arguments) : undefined;
    fixAmount();
    var img = document.querySelector('.expqr-img');
    var lnk = document.querySelector('.expqr-open');
    if (!img || !lnk || document.getElementById('expQrDl')) return out;
    var src = img.getAttribute('src') || '';
    var m = src.match(/\/image\/(\d{6})-([0-9A-Za-z]+)-compact2\.png/);
    if (!m) return out;
    var q = src.indexOf(String.fromCharCode(63));
    var pr = new URLSearchParams(q >= 0 ? src.slice(q + 1) : '');
    var a = document.createElement('a');
    a.id = 'expQrDl';
    a.className = lnk.className;
    a.href = 'api/quotation-api.php' + String.fromCharCode(63) + 'action=qr-png' +
      '&bin=' + encodeURIComponent(m[1]) +
      '&acc=' + encodeURIComponent(m[2]) +
      '&amount=' + encodeURIComponent(pr.get('amount') || '') +
      '&info=' + encodeURIComponent(pr.get('addInfo') || '') +
      '&name=' + encodeURIComponent(pr.get('accountName') || '');
    a.setAttribute('download', 'QR-' + m[2] + '.png');
    a.textContent = '⬇ Tải ảnh PNG';
    a.style.marginLeft = '12px';
    lnk.parentNode.insertBefore(a, lnk.nextSibling);
    return out;
  };

  /* ---------- 3. CSS ---------- */
  var st = document.createElement('style');
  st.textContent =
    '.exp-dayc{text-align:center;white-space:nowrap}' +
    'input.expday{width:100%;max-width:124px;padding:5px 6px;border-radius:6px;' +
    'border:1px solid var(--line,#333);background:transparent;color:inherit;' +
    'font-family:inherit;font-size:12px}' +
    'input.expday::-webkit-calendar-picker-indicator,' +
    '#expQrDay::-webkit-calendar-picker-indicator{filter:invert(.7);cursor:pointer}' +
    '#expQrDl{cursor:pointer}';
  (document.head || document.documentElement).appendChild(st);
})();
