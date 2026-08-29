/* exp-day.js — cot "Ngay TT" cho bang chi phi thuc te trong quotation.html
 * va truong "Ngay se thanh toan" trong dialog Chuyen khoan.
 * Nap SAU exp-qr.js.  v3
 */
(function () {
  'use strict';

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

  /* ---------- o "Ngay TT" trong bang ---------- */
  window.expDayCell = function (r, i) {
    return '<input type="date" class="expday" id="expday-' + i + '" value="' +
      esc(ymd(r && r.pay_date)) + '" title="Ngày sẽ thanh toán" ' +
      'onchange="expDaySet(' + i + ', this.value)">';
  };
  window.expDaySet = function (i, v) { setDate(i, v); };

  /* ---------- truong ngay trong dialog ---------- */
  var _open = window.expQrOpen;

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
    addDate(i);
    return out;
  };

  /* ---------- CSS ---------- */
  var st = document.createElement('style');
  st.textContent =
    '.exp-dayc{text-align:center;white-space:nowrap}' +
    'input.expday{width:100%;max-width:124px;padding:5px 6px;border-radius:6px;' +
    'border:1px solid var(--line,#333);background:transparent;color:inherit;' +
    'font-family:inherit;font-size:12px}' +
    'input.expday::-webkit-calendar-picker-indicator,' +
    '#expQrDay::-webkit-calendar-picker-indicator{filter:invert(.7);cursor:pointer}';
  (document.head || document.documentElement).appendChild(st);
})();
