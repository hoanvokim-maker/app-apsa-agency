/* APSA — chia sẻ báo giá / nghiệm thu cho khách (link chỉ-xem)
   Nạp sau script chính của quotation.html. */
(function () {
  var API = './api/quotation-api.php';
  var PAGE = location.origin + location.pathname.replace(/[^/]*$/, '') + 'bao-gia.html';
  var cur = { id: 0, code: '', hasLiq: 0, data: { quote: null, liq: null } };

  function el(id) { return document.getElementById(id); }
  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }
  function say(msg, kind) {
    if (typeof toast === 'function') { toast(msg, kind); return; }
    console.log(msg);
  }

  function call(action, opts) {
    opts = opts || {};
    var cfg = { credentials: 'same-origin', cache: 'no-store', method: opts.body ? 'POST' : 'GET' };
    if (opts.body) { cfg.headers = { 'Content-Type': 'application/json' }; cfg.body = JSON.stringify(opts.body); }
    return fetch(API + '?action=' + action + (opts.query || ''), cfg)
      .then(function (r) {
        if (r.status === 401) { location.replace('./login.html'); throw new Error('401'); }
        return r.json().catch(function () { return { ok: false, error: 'Máy chủ trả về dữ liệu không hợp lệ.' }; });
      })
      .then(function (j) {
        if (!j || !j.ok) throw new Error((j && j.error) || 'Có lỗi xảy ra.');
        return j.data;
      });
  }

  function css() {
    if (el('shCss')) return;
    var s = document.createElement('style');
    s.id = 'shCss';
    s.textContent = [
      '#shOv{position:fixed;inset:0;background:rgba(0,0,0,.72);backdrop-filter:blur(4px);display:none;align-items:center;justify-content:center;padding:20px;z-index:600}',
      '#shOv.open{display:flex}',
      '#shOv .box{background:var(--bg2,#0b0b0b);border:1px solid var(--border-h,rgba(255,255,255,.16));border-radius:16px;width:100%;max-width:620px;padding:24px;max-height:88vh;overflow:auto}',
      '#shOv h2{font-size:18px;font-weight:800;margin-bottom:5px}',
      '#shOv .sub{font-size:12.5px;color:var(--text2,#9a9a9a);margin-bottom:18px;line-height:1.55}',
      '.shrow{border:1px solid var(--border,rgba(255,255,255,.08));border-radius:12px;padding:14px 16px;margin-bottom:12px;background:var(--bg3,#141414)}',
      '.shrow .top{display:flex;align-items:center;gap:10px;margin-bottom:10px}',
      '.shrow .ttl{font-size:13px;font-weight:700}',
      '.shrow .st{margin-left:auto;font-size:11.5px;color:var(--text3,#5e5e5e)}',
      '.shlink{display:flex;gap:8px;align-items:stretch}',
      '.shlink input{flex:1 1 auto;min-width:0;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:11.5px;color:var(--text,#fff);background:var(--bg4,#1c1c1c);border:1px solid var(--border,rgba(255,255,255,.08));border-radius:8px;padding:9px 11px;outline:none}',
      '.shbtn{font-family:inherit;font-size:12px;font-weight:600;padding:8px 13px;border-radius:8px;border:1px solid var(--border-h,rgba(255,255,255,.16));background:var(--bg3,#141414);color:var(--text,#fff);cursor:pointer;white-space:nowrap;transition:all .16s}',
      '.shbtn:hover{background:var(--bg4,#1c1c1c)}',
      '.shbtn.on{background:var(--green,#dff20d);color:#000;border-color:var(--green,#dff20d)}',
      '.shbtn.dg{color:#ffb3b3;border-color:rgba(255,77,77,.35)}',
      '.shbtn[disabled]{opacity:.45;pointer-events:none}',
      '.shnote{font-size:11.5px;color:var(--text3,#5e5e5e);margin-top:9px;line-height:1.55}',
      '#shOv .acts{display:flex;justify-content:flex-end;gap:9px;margin-top:18px}'
    ].join('\n');
    document.head.appendChild(s);
  }

  function build() {
    if (el('shOv')) return;
    var d = document.createElement('div');
    d.id = 'shOv';
    d.setAttribute('data-apsa-free', '');
    d.innerHTML =
      '<div class="box" data-apsa-free>' +
        '<h2>Chia sẻ cho khách hàng</h2>' +
        '<div class="sub" id="shSub"></div>' +
        '<div id="shBody"></div>' +
        '<div class="acts"><button class="shbtn" onclick="shareClose()">Đóng</button></div>' +
      '</div>';
    document.body.appendChild(d);
    d.addEventListener('click', function (e) { if (e.target === d) shareClose(); });
  }

  function rowHtml(scope, label, sh, disabled, why) {
    var url = sh ? (PAGE + '?t=' + sh.token) : '';
    var h = '<div class="shrow"><div class="top"><span class="ttl">' + esc(label) + '</span>';
    if (sh) h += '<span class="st">' + (Number(sh.views) || 0) + ' lượt xem' +
                 (sh.last_view_at ? ' · gần nhất ' + esc(String(sh.last_view_at).replace('T', ' ').slice(0, 16)) : '') + '</span>';
    h += '</div>';

    if (disabled) {
      h += '<div class="shnote">' + esc(why) + '</div></div>';
      return h;
    }
    if (!sh) {
      h += '<button class="shbtn on" onclick="shareMake(\'' + scope + '\')">Tạo link chia sẻ</button>'
        +  '<div class="shnote">Ai có link đều xem được, không cần đăng nhập. Link không hết hạn — thu hồi bất cứ lúc nào.</div></div>';
      return h;
    }
    h += '<div class="shlink">'
      +   '<input type="text" readonly value="' + esc(url) + '" onclick="this.select()" id="shIn_' + scope + '" />'
      +   '<button class="shbtn on" onclick="shareCopy(\'' + scope + '\')">Sao chép</button>'
      +   '<button class="shbtn" onclick="window.open(\'' + esc(url) + '\',\'_blank\',\'noopener\')">Mở thử</button>'
      + '</div>'
      + '<div class="shnote">Khách chỉ xem được, không sửa được. Không hiện giá vốn, chi phí nội bộ hay người phụ trách.'
      + ' <button class="shbtn dg" style="margin-left:6px;padding:4px 9px;font-size:11px" onclick="shareRevoke(\'' + scope + '\')">Thu hồi link</button></div>'
      + '</div>';
    return h;
  }

  function paint() {
    var h = rowHtml('quote', 'Báo giá', cur.data.quote, false, '');
    h += rowHtml('liq', 'Biên bản nghiệm thu', cur.data.liq,
                 !cur.hasLiq, 'Báo giá này chưa bật phần nghiệm thu — bật trong màn hình soạn trước khi chia sẻ.');
    el('shBody').innerHTML = h;
  }

  window.shareQuo = function (id) {
    css(); build();
    var r = (typeof LIST !== 'undefined' && LIST) ? LIST.find(function (x) { return x.id === id; }) : null;
    cur = { id: id, code: r ? (r.code || '') : '', hasLiq: r ? Number(r.has_liquidation) : 0, data: { quote: null, liq: null } };
    el('shSub').textContent = 'Gửi link cho khách để họ xem bản ' + (cur.code ? cur.code + ' ' : '') + 'dưới dạng hoá đơn — chỉ đọc, không sửa được.';
    el('shBody').innerHTML = '<div class="shnote">Đang tải…</div>';
    el('shOv').classList.add('open');
    call('share-list', { query: '&id=' + id }).then(function (d) {
      cur.data = { quote: null, liq: null };
      (d.shares || []).forEach(function (s) { cur.data[s.scope] = s; });
      paint();
    }).catch(function (e) { el('shBody').innerHTML = '<div class="shnote">' + esc(e.message) + '</div>'; });
  };

  window.shareClose = function () { var o = el('shOv'); if (o) o.classList.remove('open'); };

  window.shareMake = function (scope) {
    call('share-create', { body: { id: cur.id, scope: scope } }).then(function (d) {
      cur.data[scope] = d.share;
      paint();
      say('Đã tạo link chia sẻ.');
    }).catch(function (e) { say(e.message, 'err'); });
  };

  window.shareCopy = function (scope) {
    var i = el('shIn_' + scope);
    if (!i) return;
    i.select();
    var ok = false;
    try { ok = document.execCommand('copy'); } catch (e) {}
    if (navigator.clipboard && !ok) { navigator.clipboard.writeText(i.value); ok = true; }
    say(ok ? 'Đã sao chép link.' : 'Hãy chọn và sao chép thủ công.', ok ? '' : 'err');
  };

  window.shareRevoke = function (scope) {
    var sh = cur.data[scope];
    if (!sh) return;
    if (!confirm('Thu hồi link này? Khách đang giữ link sẽ không mở được nữa.')) return;
    call('share-revoke', { body: { token: sh.token } }).then(function () {
      cur.data[scope] = null;
      paint();
      say('Đã thu hồi link.');
    }).catch(function (e) { say(e.message, 'err'); });
  };

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') shareClose();
  });
})();
