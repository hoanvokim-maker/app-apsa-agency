/* ============================================================
   APSA1874 - Chan lai khi dong hop thoai con thay doi chua luu.
   Dung chung cho cac module sua bang dialog (.ov + class open).
   KHONG tu ghi DB - chi hoi: Luu luon / Bo thay doi / Quay lai.
   ============================================================ */
(function () {
  'use strict';
  if (window.__apsaDirty) return;
  window.__apsaDirty = 1;

  var SEP = String.fromCharCode(1);
  /* 3 kieu hop thoai dang dung trong app */
  var OV_OPEN = '.ov.open, .mask.on, .modal-overlay.open';
  var OV_ANY  = '.ov, .mask, .modal-overlay';
  window.__AD_SEL = { open: OV_OPEN, any: OV_ANY };
  var SEL = 'input, textarea, select';
  var snap = new WeakMap();
  var touched = new WeakMap();
  var asking = false;

  function fields(ov) {
    var out = [], els = ov.querySelectorAll(SEL), i, e;
    for (i = 0; i < els.length; i++) {
      e = els[i];
      if (e.type === 'file' || e.disabled) continue;
      out.push((e.id || e.name || i) + '=' +
        (e.type === 'checkbox' || e.type === 'radio'
          ? (e.checked ? 1 : 0)
          : String(e.value == null ? '' : e.value)));
    }
    return out.join(SEP);
  }
  function take(ov) { if (ov) snap.set(ov, fields(ov)); }
  function isDirty(ov) {
    if (!ov || !touched.get(ov)) return false;
    var s = snap.get(ov);
    return s !== undefined && s !== fields(ov);
  }
  function topOv() {
    var l = document.querySelectorAll(OV_OPEN);
    return l.length ? l[l.length - 1] : null;
  }
  function low(el) { return (el.textContent || '').trim().toLowerCase(); }
  function saveBtn(ov) {
    var b = ov.querySelectorAll('button, .btn'), i, t;
    for (i = 0; i < b.length; i++) {
      t = low(b[i]);
      if (t.indexOf('hu') === 0 || t.indexOf('dong') === 0 || t.indexOf('đóng') === 0) continue;
      if (t.indexOf('xo') === 0 || t.indexOf('xóa') >= 0 || t.indexOf('xoá') >= 0) continue;
      if (t.indexOf('lưu') >= 0 || t.indexOf('luu') >= 0 || t.indexOf('cập nhật') >= 0) return b[i];
    }
    return null;
  }
  function isCloser(el, ov) {
    if (el === ov) return true;
    var n = el, t;
    while (n && n !== ov) {
      if (n.tagName === 'BUTTON' || (n.classList && n.classList.contains('btn'))) {
        t = low(n);
        return (t.indexOf('hu') === 0 || t.indexOf('đóng') === 0 ||
                t.indexOf('dong') === 0 || t === 'x' || t.length <= 2);
      }
      n = n.parentNode;
    }
    return false;
  }
  window.__AD = { fields: fields, take: take, isDirty: isDirty, topOv: topOv,
                  saveBtn: saveBtn, isCloser: isCloser, snap: snap, touched: touched,
                  get asking() { return asking; }, set asking(v) { asking = v; } };
})();

(function () {
  'use strict';
  var AD = window.__AD;
  if (!AD) return;

  var CSS =
    '#adAsk{position:fixed;inset:0;z-index:99999;display:none;align-items:center;justify-content:center;' +
    'background:rgba(0,0,0,.6);backdrop-filter:blur(2px)}' +
    '#adAsk.on{display:flex}' +
    '#adAsk .bx{background:var(--bg2,#17171a);border:1px solid rgba(255,255,255,.12);border-radius:14px;' +
    'padding:22px 24px;max-width:420px;width:calc(100% - 40px);box-shadow:0 18px 50px rgba(0,0,0,.55)}' +
    '#adAsk h4{margin:0 0 8px;font-size:15px;font-weight:800;color:var(--text,#f2f2f2)}' +
    '#adAsk p{margin:0 0 18px;font-size:13px;line-height:1.5;color:var(--text2,#b5b5b5)}' +
    '#adAsk .row{display:flex;gap:9px;flex-wrap:wrap;justify-content:flex-end}' +
    '#adAsk button{border-radius:9px;padding:9px 15px;font-size:13px;font-weight:700;cursor:pointer;' +
    'font-family:inherit;border:1px solid rgba(255,255,255,.16);background:transparent;color:var(--text,#eee)}' +
    '#adAsk button.pri{background:var(--neon);color:#111;border-color:var(--neon)}' +
    '#adAsk button:hover{filter:brightness(1.12)}';

  var box = null;
  function ui() {
    if (box) return box;
    var s = document.createElement('style'); s.textContent = CSS; document.head.appendChild(s);
    box = document.createElement('div');
    box.id = 'adAsk';
    box.innerHTML =
      '<div class="bx"><h4>Bạn có thay đổi chưa lưu</h4>' +
      '<p>Hộp thoại này đang có nội dung chưa được lưu. Bạn muốn làm gì?</p>' +
      '<div class="row">' +
      '<button type="button" data-a="back">Quay lại</button>' +
      '<button type="button" data-a="drop">Bỏ thay đổi</button>' +
      '<button type="button" class="pri" data-a="save">Lưu luôn</button>' +
      '</div></div>';
    document.body.appendChild(box);
    return box;
  }

  function ask(ov, doClose) {
    var b = ui(), sb = AD.saveBtn(ov);
    var pri = b.querySelector('[data-a="save"]');
    pri.style.display = sb ? '' : 'none';
    AD.asking = true;
    b.classList.add('on');
    function done(act) {
      b.classList.remove('on');
      AD.asking = false;
      b.onclick = null;
      if (act === 'drop') { AD.touched.set(ov, false); AD.take(ov); doClose(); }
      else if (act === 'save' && sb) { AD.touched.set(ov, false); sb.click(); }
    }
    b.onclick = function (e) {
      var t = e.target.closest ? e.target.closest('[data-a]') : null;
      if (!t) { if (e.target === b) done('back'); return; }
      e.preventDefault(); e.stopPropagation();
      done(t.getAttribute('data-a'));
    };
  }

  /* Dialog vua mo -> chup lai gia tri goc */
  try {
    new MutationObserver(function (ms) {
      for (var i = 0; i < ms.length; i++) {
        var el = ms[i].target;
        if (el.matches && el.matches(window.__AD_SEL.open)) {
          if (!AD.snap.has(el) || AD.touched.get(el) === false) { }
          AD.touched.set(el, false);
          AD.take(el);
          (function (e2) { setTimeout(function () { if (!AD.touched.get(e2)) AD.take(e2); }, 500); })(el);
        }
      }
    }).observe(document.documentElement, { attributes: true, subtree: true, attributeFilter: ['class'] });
  } catch (e) {}

  /* Nguoi dung thao tac that -> danh dau da cham vao */
  function onEdit(e) {
    if (!e.isTrusted) return;
    var ov = e.target.closest ? e.target.closest(window.__AD_SEL.any) : null;
    if (ov) AD.touched.set(ov, true);
  }
  document.addEventListener('input', onEdit, true);
  document.addEventListener('change', onEdit, true);

  /* Chan click dong dialog */
  document.addEventListener('click', function (e) {
    if (AD.asking) return;
    var ov = e.target.closest ? e.target.closest(window.__AD_SEL.open) : null;
    if (!ov) return;
    if (!AD.isCloser(e.target, ov)) return;
    if (!AD.isDirty(ov)) return;
    e.preventDefault(); e.stopPropagation(); e.stopImmediatePropagation();
    var el = e.target;
    ask(ov, function () { AD.touched.set(ov, false); el.click(); });
  }, true);

  /* Chan phim Esc */
  document.addEventListener('keydown', function (e) {
    if (AD.asking || e.key !== 'Escape') return;
    var ov = AD.topOv();
    if (!ov || !AD.isDirty(ov)) return;
    e.preventDefault(); e.stopPropagation(); e.stopImmediatePropagation();
    ask(ov, function () { ov.classList.remove('open'); ov.classList.remove('on'); });
  }, true);
})();
