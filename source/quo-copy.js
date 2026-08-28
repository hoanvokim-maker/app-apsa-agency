/* APSA - nut copy "<ma bao gia>-<tieu de>" canh o Ma bao gia */
(function () {
  'use strict';
  function txt() {
    var c = document.getElementById('fCode'), t = document.getElementById('fTitle');
    var code = c ? String(c.value || '').trim() : '';
    var title = t ? String(t.value || '').trim() : '';
    if (!code) return '';
    return title ? code + '-' + title : code;
  }
  function flash(b, ok) {
    b.textContent = ok ? '✓ Đã copy' : '✕ Lỗi';
    b.classList.add('on');
    clearTimeout(b._h);
    b._h = setTimeout(function () { b.textContent = '⧉ Copy'; b.classList.remove('on'); }, 1800);
  }
  function fallback(s, b) {
    var ta = document.createElement('textarea');
    ta.value = s; ta.style.position = 'fixed'; ta.style.opacity = '0';
    document.body.appendChild(ta); ta.select();
    var ok = false; try { ok = document.execCommand('copy'); } catch (e) {}
    document.body.removeChild(ta); flash(b, ok);
  }
  function copy(b) {
    var s = txt();
    if (!s) { flash(b, false); return; }
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(s).then(function () { flash(b, true); }, function () { fallback(s, b); });
    } else { fallback(s, b); }
  }
  function mount() {
    var c = document.getElementById('fCode');
    if (!c || document.getElementById('btnCopyCode')) return;
    var wrap = document.createElement('div');
    wrap.className = 'qcopy-wrap';
    c.parentNode.insertBefore(wrap, c);
    wrap.appendChild(c);
    var b = document.createElement('button');
    b.type = 'button'; b.id = 'btnCopyCode'; b.className = 'qcopy';
    b.title = 'Copy: mã báo giá - tiêu đề';
    b.textContent = '⧉ Copy';
    b.onclick = function () { copy(b); };
    wrap.appendChild(b);
    c.style.paddingRight = '92px';
  }
  var css = '.qcopy-wrap{position:relative;display:block}'
    + '.qcopy{position:absolute;right:6px;top:50%;transform:translateY(-50%);z-index:3;'
    + 'font-family:inherit;font-size:11px;font-weight:700;cursor:pointer;padding:4px 9px;border-radius:7px;'
    + 'border:1px solid rgba(255,255,255,.18);background:rgba(255,255,255,.06);color:#9a9a9a;white-space:nowrap}'
    + '.qcopy:hover{color:#fff;background:rgba(255,255,255,.12)}'
    + '.qcopy.on{color:#dff20d;border-color:rgba(223,242,13,.55);background:rgba(223,242,13,.12)}';
  var st = document.createElement('style'); st.textContent = css; document.head.appendChild(st);
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { setTimeout(mount, 400); });
  else setTimeout(mount, 400);
  setTimeout(mount, 1600);
})();
