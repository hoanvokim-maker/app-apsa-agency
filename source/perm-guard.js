/* perm-guard.js - ap dung phan quyen len giao dien
 *   - an tile o trang chu + muc menu khong co quyen
 *   - chan mo thang trang bang link
 *   - window.APSA_PERM / window.apsaCan(nhom, muc)
 * Nap SAU apsa-ui.js tren moi trang.  v1
 */
(function () {
  'use strict';
  var Q = String.fromCharCode(63);
  var P = null;               // { admin, perm, groups }
  var BLOCK = null;           // ten nhom dang chan trang nay

  function page() {
    var p = (location.pathname || '').split('/').pop();
    return (p || 'index.html').toLowerCase();
  }
  function fileOf(href) {
    if (!href) return '';
    var h = String(href).split(Q)[0].split('#')[0];
    if (/^https?:/i.test(h)) {
      try { h = new URL(h).pathname; } catch (e) { return ''; }
    }
    return (h.split('/').pop() || '').toLowerCase();
  }
  function groupOfPage(f) {
    if (!P || !P.groups) return null;
    for (var i = 0; i < P.groups.length; i++) {
      var g = P.groups[i];
      for (var j = 0; j < (g.pages || []).length; j++) {
        if (String(g.pages[j]).toLowerCase() === f) return g;
      }
    }
    return null;
  }
  function lvl(key) {
    if (!P || !P.perm) return 2;
    return (P.perm[key] === undefined) ? 2 : Number(P.perm[key]);
  }

  window.apsaCan = function (key, need) {
    return lvl(key) >= (need === undefined ? 1 : need);
  };

  /* ---- an cac link toi trang khong co quyen ---- */
  function sweep() {
    if (!P || P.admin) return;
    var a = document.querySelectorAll('a[href]');
    for (var i = 0; i < a.length; i++) {
      var el = a[i];
      if (el.getAttribute('data-perm-ok') === '1') continue;
      var g = groupOfPage(fileOf(el.getAttribute('href')));
      if (!g) { el.setAttribute('data-perm-ok', '1'); continue; }
      if (lvl(g.key) <= 0) {
        var box = el.closest('.tile, .card-tile, li, .nav-i, .mi') || el;
        box.style.display = 'none';
        box.setAttribute('data-perm-hidden', '1');
      } else {
        el.setAttribute('data-perm-ok', '1');
      }
    }
  }

  /* ---- man hinh chan ---- */
  function deny(name) {
    var css = document.createElement('style');
    css.textContent =
      '#pgDeny{position:fixed;inset:0;z-index:99999;display:flex;align-items:center;' +
      'justify-content:center;background:#0d0d0d;color:#e8e8e8;font-family:inherit;padding:24px}' +
      '#pgDeny .b{max-width:460px;text-align:center;line-height:1.6}' +
      '#pgDeny h2{font-size:19px;margin:0 0 10px}' +
      '#pgDeny p{font-size:13.5px;color:#999;margin:0 0 20px}' +
      '#pgDeny a{display:inline-block;font-size:13px;padding:9px 18px;border-radius:10px;' +
      'border:1px solid #333;color:#e8e8e8;text-decoration:none}';
    document.head.appendChild(css);
    var d = document.createElement('div');
    d.id = 'pgDeny';
    d.innerHTML = '<div class="b"><h2>Bạn không có quyền vào mục này</h2>' +
      '<p>Mục <b>' + (name || '') + '</b> chưa được cấp cho vị trí của bạn. ' +
      'Liên hệ Admin nếu bạn cần dùng.</p>' +
      '<a href="./index.html">← Về trang chủ</a></div>';
    document.body.appendChild(d);
  }

  async function boot() {
    try {
      var r = await fetch('api/settings-api.php' + Q + 'action=perm-me', { credentials: 'same-origin' });
      if (!r.ok) return;
      var j = await r.json();
      var d = j.data || j;
      if (!d || !d.groups) return;
      P = { admin: !!d.admin, perm: d.perm || {}, groups: d.groups || [] };
      window.APSA_PERM = P;
    } catch (e) { return; }

    if (P.admin) return;

    var g = groupOfPage(page());
    if (g && lvl(g.key) <= 0) {
      BLOCK = g.name;
      if (document.body) deny(g.name);
      else document.addEventListener('DOMContentLoaded', function () { deny(g.name); });
      return;
    }
    sweep();
    setTimeout(sweep, 500);
    setTimeout(sweep, 1500);
    if (window.MutationObserver) {
      var t = null;
      new MutationObserver(function () {
        clearTimeout(t);
        t = setTimeout(sweep, 60);
      }).observe(document.body || document.documentElement, { childList: true, subtree: true });
    }
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();

/* ---- don dep: an tieu de nhom rong sau khi da an cac muc ---- */
(function () {
  'use strict';
  function vis(el) { return el && el.offsetParent !== null && el.style.display !== 'none'; }
  function tidy() {
    var P = window.APSA_PERM;
    if (!P || P.admin) return;
    var sc = document.querySelector('nav.apsa-side .as-scroll');
    if (sc) {
      var kids = sc.children, grp = null, live = 0;
      function close() { if (grp) grp.style.display = live ? '' : 'none'; }
      for (var i = 0; i < kids.length; i++) {
        var k = kids[i];
        if (k.classList.contains('as-grp')) { close(); grp = k; live = 0; continue; }
        if (k.classList.contains('as-item') && k.style.display !== 'none') live++;
      }
      close();
    }
    var secs = document.querySelectorAll('section.gsec');
    for (var j = 0; j < secs.length; j++) {
      var cards = secs[j].querySelectorAll('a.card');
      if (!cards.length) continue;
      var on = 0;
      for (var m = 0; m < cards.length; m++) if (cards[m].style.display !== 'none') on++;
      secs[j].style.display = on ? '' : 'none';
    }
  }
  window.apsaPermTidy = tidy;
  function loop() { tidy(); }
  setTimeout(loop, 800);
  setTimeout(loop, 1800);
  setTimeout(loop, 3000);
})();
