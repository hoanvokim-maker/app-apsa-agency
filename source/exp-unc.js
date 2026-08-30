/* exp-unc.js v1 - Chung tu thanh toan (UNC) cho bang chi phi trong bao gia
   Dung chung API voi module Chi phi thuc te: /api/pay-api.php
   Duoc goi tu quotation.html: window.expUncCell(r, i) */
(function () {
  var API = './api/pay-api.php';

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }
  function isAdmin() {
    try { if (window.APSA_PERM && window.APSA_PERM.admin) return true; } catch (e) {}
    try { if (window.DSC && window.DSC.is_admin) return true; } catch (e) {}
    return false;
  }
  function say(m, k) { if (typeof toast === 'function') toast(m, k); else alert(m); }
  function rowOf(i) { try { return EXP[i]; } catch (e) { return null; } }
  function redraw() { if (typeof renderExp === 'function') renderExp(); }

  async function post(action, body) {
    var r = await fetch(API + '?action=' + action, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify(body)
    });
    var d = await r.json().catch(function () { return { ok: false, error: 'Lỗi mạng' }; });
    if (!d || !d.ok) throw new Error((d && d.error) || 'Lỗi không rõ');
    return d.data || {};
  }

  /* ---- O nut trong cot Trang thai ---- */
  window.expUncCell = function (r, i) {
    if (!r || r.kind === 'group') return '';
    var id = Number(r.id) || 0;
    if (!id) {
      return ' <button type="button" class="exp-unc off" title="Lưu báo giá trước rồi mới tải được chứng từ"'
        + ' onclick="expUncNoId()">&#128206;</button>';
    }
    if (Number(r.has_proof) === 1) {
      var t = r.proof_name ? ('Chứng từ: ' + r.proof_name) : 'Xem chứng từ đã tải';
      return ' <button type="button" class="exp-unc on" title="' + esc(t)
        + '" onclick="expUncView(' + i + ')">&#128206;</button>';
    }
    return ' <button type="button" class="exp-unc" title="Tải chứng từ thanh toán (ảnh hoặc PDF)"'
      + ' onclick="expUncPick(' + i + ')">&#128206;</button>';
  };

  window.expUncNoId = function () {
    say('Bấm Lưu báo giá trước, rồi mới tải chứng từ lên được.', 'warn');
  };

  /* ---- Chon file va tai len ---- */
  window.expUncPick = function (i) {
    var r = rowOf(i); if (!r) return;
    var id = Number(r.id) || 0; if (!id) return window.expUncNoId();
    var inp = document.createElement('input');
    inp.type = 'file';
    inp.accept = 'image/png,image/jpeg,image/webp,application/pdf';
    inp.style.display = 'none';
    document.body.appendChild(inp);
    inp.onchange = function () {
      var f = inp.files && inp.files[0];
      document.body.removeChild(inp);
      if (!f) return;
      if (f.size > 12 * 1024 * 1024) return say('File quá lớn (tối đa 12MB).', 'err');
      var fr = new FileReader();
      fr.onload = async function () {
        try {
          say('Đang tải chứng từ...', '');
          var d = await post('paid', { ids: [id], file: { name: f.name, data: String(fr.result) } });
          r.has_proof = 1;
          r.proof_name = d.proof_name || f.name;
          r.paid = 1;
          r.paid_at = d.paid_at || '';
          expUncClose();
          redraw();
          say('Đã lưu chứng từ cho: ' + (r.name || 'dòng chi phí'), 'ok');
        } catch (e) { say('Không tải được: ' + e.message, 'err'); }
      };
      fr.onerror = function () { say('Không đọc được file.', 'err'); };
      fr.readAsDataURL(f);
    };
    inp.click();
  };

  /* ---- Xem chung tu ---- */
  window.expUncView = function (i) {
    var r = rowOf(i); if (!r) return;
    var id = Number(r.id) || 0; if (!id) return;
    injectCss();
    var src = API + '?action=proof&id=' + id;
    var nm = r.proof_name || ('chung-tu-' + id);
    var pdf = /\.pdf$/i.test(nm);
    var ov = document.createElement('div');
    ov.className = 'unc-ov';
    ov.id = 'uncOv';
    ov.innerHTML =
      '<div class="unc-box">'
      + '<div class="unc-hd"><b>Chứng từ thanh toán</b>'
      + '<button type="button" class="unc-x" onclick="expUncClose()">&times;</button></div>'
      + '<div class="unc-nm">' + esc(nm) + '</div>'
      + '<div class="unc-bd">'
      + (pdf ? '<iframe src="' + esc(src) + '"></iframe>'
             : '<img src="' + esc(src) + '" alt="' + esc(nm) + '">')
      + '</div>'
      + '<div class="unc-ft">'
      + '<a class="unc-b" href="' + esc(src) + '" target="_blank" rel="noopener">Mở tab mới</a>'
      + '<button type="button" class="unc-b" onclick="expUncClose();expUncPick(' + i + ')">Đổi file khác</button>'
      + (isAdmin() ? '<button type="button" class="unc-b danger" onclick="expUncDel(' + i + ')">Xoá chứng từ</button>' : '')
      + '<button type="button" class="unc-b" onclick="expUncClose()">Đóng</button>'
      + '</div></div>';
    ov.onclick = function (e) { if (e.target === ov) expUncClose(); };
    document.body.appendChild(ov);
  };

  window.expUncClose = function () {
    var o = document.getElementById('uncOv');
    if (o && o.parentNode) o.parentNode.removeChild(o);
  };

  /* ---- Xoa chung tu (chi Admin) ---- */
  window.expUncDel = async function (i) {
    var r = rowOf(i); if (!r) return;
    var id = Number(r.id) || 0; if (!id) return;
    if (!confirm('Xoá chứng từ của dòng "' + (r.name || '') + '"?')) return;
    try {
      await post('proof-del', { id: id });
      r.has_proof = 0; r.proof_name = '';
      expUncClose();
      redraw();
      say('Đã xoá chứng từ.', 'ok');
    } catch (e) { say('Không xoá được: ' + e.message, 'err'); }
  };

  /* ---- CSS ---- */
  var cssDone = false;
  function injectCss() {
    if (cssDone) return; cssDone = true;
    var s = document.createElement('style');
    s.textContent =
      '.unc-ov{position:fixed;inset:0;background:rgba(0,0,0,.72);z-index:9999;display:flex;'
      + 'align-items:center;justify-content:center;padding:20px}'
      + '.unc-box{background:#141414;border:1px solid #333;border-radius:12px;max-width:900px;'
      + 'width:100%;max-height:92vh;display:flex;flex-direction:column;color:#e8e8e8}'
      + '.unc-hd{display:flex;align-items:center;justify-content:space-between;padding:12px 16px;'
      + 'border-bottom:1px solid #2a2a2a}'
      + '.unc-x{background:none;border:0;color:#999;font-size:24px;line-height:1;cursor:pointer}'
      + '.unc-nm{padding:8px 16px;font-size:12px;color:#9a9a9a;word-break:break-all}'
      + '.unc-bd{flex:1;overflow:auto;padding:0 16px 12px;text-align:center;min-height:200px}'
      + '.unc-bd img{max-width:100%;border-radius:8px}'
      + '.unc-bd iframe{width:100%;height:70vh;border:0;background:#fff;border-radius:8px}'
      + '.unc-ft{display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end;padding:12px 16px;'
      + 'border-top:1px solid #2a2a2a}'
      + '.unc-b{background:#242424;border:1px solid #3a3a3a;color:#e8e8e8;border-radius:8px;'
      + 'padding:7px 14px;font-size:13px;cursor:pointer;text-decoration:none;display:inline-block}'
      + '.unc-b:hover{background:#2e2e2e}'
      + '.unc-b.danger{border-color:#7a2b2b;color:#ff9a9a}'
      + '.exp-paidc{white-space:nowrap}'
      + '.exp-unc{background:none;border:0;cursor:pointer;font-size:13px;opacity:.35;padding:0 2px;'
      + 'line-height:1;vertical-align:middle;filter:grayscale(1)}'
      + '.exp-unc:hover{opacity:.9;filter:none}'
      + '.exp-unc.on{opacity:1;filter:none;background:#12301c;border:1px solid #2f7a45;border-radius:6px;padding:1px 3px}'
      + '.exp-unc.off{opacity:.18;cursor:help}';
    document.head.appendChild(s);
  }
  document.addEventListener('DOMContentLoaded', injectCss);
  injectCss();
})();
