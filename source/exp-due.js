/* exp-due.js — ngay den han thanh toan tren chi-phi.html
 *   • to mau dong:  do = den han / tre han,  cam = con <= 7 ngay
 *   • the "Can chuan bi" o thanh so lieu tren cung
 *   • bo loc "Den han" canh Tat ca / Chua tra / Da tra
 * Nap SAU chi-phi-group.js va exp-pay.js.  v3
 */
(function () {
  'use strict';

  var WINDOW_DAYS = 7;

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
    if (n <= WINDOW_DAYS) return 'due-orange';
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
  function money(n) {
    return (typeof window.fmt === 'function')
      ? window.fmt(n)
      : Math.round(Number(n) || 0).toLocaleString('vi-VN');
  }
  function amtOf(r) {
    if (typeof window.amt === 'function') return Number(window.amt(r)) || 0;
    var base = (Number(r.qty) || 0) * (Number(r.price) || 0);
    return base * (1 + (Number(r.vat_percent) || 0) / 100);
  }

  window.expDueSoon = function (r) { return stateOf(r) !== ''; };

  /* ---------- 1. badge trong o trang thai ---------- */
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

  /* ---------- 2. bo loc "Den han" ---------- */
  var _view = window.view;
  window.view = function () {
    var rows = _view ? _view.apply(this, arguments) : [];
    if (window.FST === 'soon') rows = rows.filter(window.expDueSoon);
    return rows;
  };

  /* ---------- 3. thong ke + to mau + chen nut loc ---------- */
  function summary() {
    var all = (window.ROWS || []);
    var s = { n: 0, sum: 0, late: 0, lateSum: 0, next: '' };
    for (var i = 0; i < all.length; i++) {
      var st = stateOf(all[i]);
      if (!st) continue;
      var a = amtOf(all[i]);
      s.n++; s.sum += a;
      if (st === 'due-red') { s.late++; s.lateSum += a; }
      var d = ymd(all[i].pay_date);
      if (!s.next || d < s.next) s.next = d;
    }
    return s;
  }

  function paintRows() {
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

  function paintStat(s) {
    var box = document.getElementById('stats');
    if (!box || document.getElementById('statDue')) return;
    var d = document.createElement('div');
    d.id = 'statDue';
    d.className = 'stat duesoon' + (s.late ? ' late' : '') + (s.n ? '' : ' empty');
    d.title = 'Bấm để lọc các khoản đến hạn';
    d.onclick = function () { if (typeof window.setSt === 'function') window.setSt('soon'); };
    d.innerHTML =
      '<div class="k">⏰ Cần chuẩn bị · ' + WINDOW_DAYS + ' ngày</div>' +
      '<div class="v">' + money(s.sum) + ' đ</div>' +
      '<div class="sub">' + (s.n
        ? s.n + ' khoản' + (s.late ? ' · <b>trễ hạn ' + s.late + ' (' + money(s.lateSum) + ' đ)</b>' : '')
          + (s.next ? ' · gần nhất ' + dmy(s.next) : '')
        : 'Không có khoản nào tới hạn') + '</div>';
    box.appendChild(d);
  }

  function paintPill(s) {
    if (document.getElementById('pillDue')) {
      document.getElementById('pillDue').className = 'pill soon' + (window.FST === 'soon' ? ' on' : '');
      return;
    }
    var pills = document.querySelectorAll('button.pill');
    var after = null;
    for (var i = 0; i < pills.length; i++) {
      var oc = pills[i].getAttribute('onclick') || '';
      if (oc.indexOf('paid') >= 0) after = pills[i];
    }
    if (!after) return;
    var b = document.createElement('button');
    b.id = 'pillDue';
    b.type = 'button';
    b.className = 'pill soon' + (window.FST === 'soon' ? ' on' : '');
    b.innerHTML = '⏰ Đến hạn' + (s.n ? ' <b>' + s.n + '</b>' : '');
    b.onclick = function () { if (typeof window.setSt === 'function') window.setSt('soon'); };
    after.parentNode.insertBefore(b, after.nextSibling);
  }

  function paint() {
    var s = summary();
    paintRows();
    paintStat(s);
    paintPill(s);
  }

  var _render = window.render;
  window.render = function () {
    var out = _render ? _render.apply(this, arguments) : undefined;
    paint();
    return out;
  };
  if (document.readyState !== 'loading') setTimeout(paint, 400);
  else document.addEventListener('DOMContentLoaded', function () { setTimeout(paint, 400); });

  /* ---------- 4. CSS ---------- */
  var css = document.createElement('style');
  css.textContent =
    'tr.due-red > td{background:rgba(239,68,68,.24)!important}' +
    'tr.due-red > td:first-child{box-shadow:inset 3px 0 0 #ef4444}' +
    'tr.due-orange > td{background:rgba(249,115,22,.18)!important}' +
    'tr.due-orange > td:first-child{box-shadow:inset 3px 0 0 #f97316}' +
    'tr.due-red:hover > td{background:rgba(239,68,68,.32)!important}' +
    'tr.due-orange:hover > td{background:rgba(249,115,22,.26)!important}' +
    '.pydue{display:block;margin-top:4px;font-size:10.5px;line-height:1.3;' +
    'color:var(--text3,#777);white-space:nowrap}' +
    '.pydue.due-red{color:#fca5a5;font-weight:600}' +
    '.pydue.due-orange{color:#fdba74;font-weight:600}' +
    '#statDue{cursor:pointer;border-color:rgba(249,115,22,.5)!important}' +
    '#statDue .v{color:#fdba74}' +
    '#statDue.late{border-color:rgba(239,68,68,.6)!important}' +
    '#statDue.late .v{color:#fca5a5}' +
    '#statDue.empty{opacity:.55}#statDue.empty .v{color:var(--text3,#777)}' +
    '#statDue .sub{margin-top:3px;font-size:10.5px;color:var(--text3,#888);line-height:1.35}' +
    '#statDue .sub b{color:#fca5a5}' +
    '#statDue:hover{background:rgba(249,115,22,.08)}' +
    '#pillDue b{font-weight:700;opacity:.9}' +
    '#pillDue.on{background:rgba(249,115,22,.9);color:#1a1a1a;border-color:transparent}';
  (document.head || document.documentElement).appendChild(css);
})();
