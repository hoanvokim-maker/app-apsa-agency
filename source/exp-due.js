/* exp-due.js — to mau dong chi phi theo ngay den han thanh toan (chi-phi.html)
 * Do  = den han hoac tre han (pay_date <= hom nay)
 * Cam = con toi da 7 ngay
 * Nap SAU chi-phi-group.js va exp-pay.js.  v1
 */
(function () {
  'use strict';

  function ymd(v) {
    var s = String(v == null ? '' : v).trim().slice(0, 10);
    return /^\d{4}-\d{2}-\d{2}$/.test(s) ? s : '';
  }
  function dmy(s) {
    return s ? s.slice(8, 10) + '/' + s.slice(5, 7) + '/' + s.slice(0, 4) : '';
  }
  function daysTo(s) {
    var n = new Date();
    var today = new Date(n.getFullYear(), n.getMonth(), n.getDate());
    var p = s.split('-');
    var d = new Date(Number(p[0]), Number(p[1]) - 1, Number(p[2]));
    return Math.round((d - today) / 86400000);
  }
  function stateOf(r) {
    if (Number(r && r.paid) === 1) return '';
    var d = ymd(r && r.pay_date);
    if (!d) return '';
    var n = daysTo(d);
    if (n <= 0) return 'due-red';
    if (n <= 7) return 'due-orange';
    return '';
  }
  function labelOf(r, st, d) {
    var n = daysTo(d);
    if (Number(r.paid) === 1) return 'Hạn ' + dmy(d);
    if (n < 0) return '🔴 Trễ ' + Math.abs(n) + ' ngày · ' + dmy(d);
    if (n === 0) return '🔴 Đến hạn hôm nay · ' + dmy(d);
    if (st === 'due-orange') return '🟠 Còn ' + n + ' ngày · ' + dmy(d);
    return 'Hạn ' + dmy(d);
  }

  /* --- badge trong o trang thai --- */
  var _pay = window.payCell;
  window.payCell = function (r) {
    var h = _pay ? _pay(r) : '';
    var d = ymd(r && r.pay_date);
    if (!d) return h;
    var st = stateOf(r);
    var badge = '<span class="pydue' + (st ? ' ' + st : '') + '" data-st="' + st + '">'
      + labelOf(r, st, d) + '</span>';
    return /<\/div>\s*$/.test(h) ? h.replace(/<\/div>\s*$/, badge + '</div>') : h + badge;
  };

  /* --- to mau ca dong --- */
  function paint() {
    var old = document.querySelectorAll('tr.due-red, tr.due-orange');
    for (var i = 0; i < old.length; i++) old[i].classList.remove('due-red', 'due-orange');
    var m = document.querySelectorAll('span.pydue[data-st]');
    for (var j = 0; j < m.length; j++) {
      var s = m[j].getAttribute('data-st');
      if (!s) continue;
      var tr = m[j].closest('tr');
      if (tr) tr.classList.add(s);
    }
  }
  var _render = window.render;
  window.render = function () {
    var out = _render ? _render.apply(this, arguments) : undefined;
    paint();
    return out;
  };
  if (document.readyState !== 'loading') setTimeout(paint, 300);
  else document.addEventListener('DOMContentLoaded', function () { setTimeout(paint, 300); });

  /* --- CSS --- */
  var st = document.createElement('style');
  st.textContent =
    'tr.due-red > td{background:rgba(239,68,68,.24)!important}' +
    'tr.due-red > td:first-child{box-shadow:inset 3px 0 0 #ef4444}' +
    'tr.due-orange > td{background:rgba(249,115,22,.18)!important}' +
    'tr.due-orange > td:first-child{box-shadow:inset 3px 0 0 #f97316}' +
    'tr.due-red:hover > td{background:rgba(239,68,68,.32)!important}' +
    'tr.due-orange:hover > td{background:rgba(249,115,22,.26)!important}' +
    '.pydue{display:block;margin-top:4px;font-size:10.5px;line-height:1.3;' +
    'color:var(--text3,#777);white-space:nowrap}' +
    '.pydue.due-red{color:#fca5a5;font-weight:600}' +
    '.pydue.due-orange{color:#fdba74;font-weight:600}';
  (document.head || document.documentElement).appendChild(st);
})();
