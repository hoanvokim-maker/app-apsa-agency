/* APSA — Nội dung chuyển khoản cho từng dòng chi phí (v1)
 * Thêm icon ghi chú cạnh ô "Ngày TT"; nội dung này được nhét vào mã QR.
 * Không sửa file nào khác — chỉ bọc thêm expDayCell / expQrOpen.
 */
(function () {
  'use strict';

  var MAXLEN = 190;

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }
  function noDia(s) {
    s = String(s == null ? '' : s);
    try { s = s.normalize('NFD').replace(/[̀-ͯ]/g, ''); } catch (e) {}
    return s.replace(/đ/g, 'd').replace(/Đ/g, 'D')
            .replace(/[^0-9A-Za-z ]/g, ' ').replace(/\s+/g, ' ').trim();
  }
  function rowOf(i) {
    try { if (typeof EXP !== 'undefined' && EXP && EXP[i]) return EXP[i]; } catch (e) {}
    return null;
  }

  /* ---------- icon trong bảng ---------- */
  window.expMemoCell = function (r, i) {
    var m = (r && r.pay_memo) ? String(r.pay_memo) : '';
    return '<button type="button" class="expmemo' + (m ? ' on' : '') + '" data-i="' + i + '"'
      + ' title="' + (m ? esc(m) : 'Nội dung chuyển khoản') + '"'
      + ' onclick="expMemoOpen(' + i + ')">'
      + '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
      + ' stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
      + '<path d="M20 14.5V5a1 1 0 0 0-1-1H5a1 1 0 0 0-1 1v14l3.5-3.5H13"/>'
      + '<path d="M7.5 8h9M7.5 11.5h5"/></svg></button>';
  };

  function refresh(i) {
    var b = document.querySelector('.expmemo[data-i="' + i + '"]');
    if (!b) return;
    var r = rowOf(i);
    var m = (r && r.pay_memo) ? String(r.pay_memo) : '';
    b.className = 'expmemo' + (m ? ' on' : '');
    b.title = m || 'Nội dung chuyển khoản';
  }

  /* ---------- hộp nhập ---------- */
  window.expMemoOpen = function (i) {
    var r = rowOf(i);
    if (!r) return;
    var cur = r.pay_memo ? String(r.pay_memo) : '';
    var goi = noDia((typeof expQuoCode === 'function' ? expQuoCode() : '') + ' ' + (r.name || '')).trim();

    var ov = document.createElement('div');
    ov.className = 'expmemo-ov';
    ov.innerHTML =
        '<div class="expmemo-bx"><h3>Nội dung chuyển khoản</h3>'
      + '<p class="expmemo-sub">Nội dung này sẽ hiện trong mã QR và khi bấm Yêu cầu thanh toán.</p>'
      + '<textarea class="expmemo-ta" maxlength="' + MAXLEN + '" rows="3"'
      + ' placeholder="VD: ' + esc(goi || 'TT chi phi thang 8') + '">' + esc(cur) + '</textarea>'
      + '<div class="expmemo-hint"><span class="expmemo-qr"></span>'
      + '<button type="button" class="expmemo-sg">Dùng gợi ý</button></div>'
      + '<div class="expmemo-a"><button type="button" class="btn" data-v="x">Huỷ</button>'
      + '<button type="button" class="btn ok" data-v="s">Lưu</button></div></div>';
    document.body.appendChild(ov);

    var ta  = ov.querySelector('.expmemo-ta');
    var pv  = ov.querySelector('.expmemo-qr');
    var msg = null;

    function paint() {
      var v = noDia(ta.value);
      pv.textContent = v ? ('Trong QR: ' + v) : 'Chưa nhập — QR sẽ dùng nội dung mặc định';
    }
    ta.addEventListener('input', paint);
    paint();
    setTimeout(function () { ta.focus(); }, 50);

    ov.querySelector('.expmemo-sg').addEventListener('click', function () {
      ta.value = goi; paint(); ta.focus();
    });

    function close() { if (ov.parentNode) document.body.removeChild(ov); }

    ov.addEventListener('click', async function (e) {
      if (e.target === ov) { close(); return; }
      var b = e.target.closest('button[data-v]');
      if (!b) return;
      if (b.getAttribute('data-v') === 'x') { close(); return; }

      var v = ta.value.replace(/\s+/g, ' ').trim().slice(0, MAXLEN);
      r.pay_memo = v;
      var id = Number(r.id) || 0;
      if (id > 0) {
        b.disabled = true; b.textContent = 'Đang lưu…';
        try {
          await api(API, 'exp-row-save', { body: { id: id, pay_memo: v } });
        } catch (err) {
          b.disabled = false; b.textContent = 'Lưu';
          if (!msg) { msg = document.createElement('div'); msg.className = 'expmemo-err';
                      ov.querySelector('.expmemo-bx').appendChild(msg); }
          msg.textContent = 'Không lưu được: ' + (err && err.message ? err.message : 'lỗi không rõ');
          return;
        }
      }
      refresh(i);
      close();
    });
  };

  /* ---------- gắn icon cạnh ô Ngày TT ---------- */
  var _day = window.expDayCell;
  window.expDayCell = function (r, i) {
    var out = _day ? _day.apply(this, arguments) : '';
    return '<span class="expday-wrap">' + out + window.expMemoCell(r, i) + '</span>';
  };

  /* ---------- nhét nội dung vào mã QR ---------- */
  var _qr = window.expQrOpen;
  window.expQrOpen = function (i) {
    var out = _qr ? _qr.apply(this, arguments) : undefined;
    try {
      var r = rowOf(i);
      var inf = document.getElementById('expQrInfo');
      if (inf && r && r.pay_memo) {
        inf.value = noDia(String(r.pay_memo));
        if (typeof window.expQrDraw === 'function') window.expQrDraw(i);
      }
    } catch (e) {}
    return out;
  };

  /* ---------- CSS ---------- */
  var st = document.createElement('style');
  st.textContent =
      '.exptbl col:nth-child(9){width:164px}'
    + '.exp-dayc{overflow:hidden}'
    + '.expday-wrap{display:flex;align-items:center;gap:5px;width:100%;min-width:0}'
    + '.expday-wrap>input.expday{flex:1 1 auto;min-width:0;max-width:none}'
    + '.expmemo{flex:0 0 auto;display:inline-flex;align-items:center;justify-content:center;'
    + 'width:24px;height:24px;padding:0;border:1px solid rgba(255,255,255,.14);border-radius:7px;'
    + 'background:transparent;color:var(--text3,#8b8f98);cursor:pointer}'
    + '.expmemo:hover{color:var(--text,#e8e8e8);border-color:rgba(255,255,255,.32)}'
    + '.expmemo.on{color:var(--green,#dff20d);border-color:rgba(223,242,13,.45);'
    + 'background:rgba(223,242,13,.10)}'
    + '.expmemo-ov{position:fixed;inset:0;z-index:10060;background:rgba(0,0,0,.6);'
    + 'display:flex;align-items:center;justify-content:center;padding:20px}'
    + '.expmemo-bx{width:100%;max-width:480px;background:#14161a;border:1px solid #333;'
    + 'border-radius:14px;padding:20px 22px;box-shadow:0 20px 60px rgba(0,0,0,.55)}'
    + '.expmemo-bx h3{margin:0 0 4px;font-size:16px}'
    + '.expmemo-sub{margin:0 0 14px;font-size:12px;opacity:.7;line-height:1.5}'
    + '.expmemo-ta{width:100%;box-sizing:border-box;background:#0f1114;color:inherit;'
    + 'border:1px solid #333;border-radius:9px;padding:10px 12px;font:inherit;font-size:13px;'
    + 'resize:vertical}'
    + '.expmemo-ta:focus{outline:none;border-color:var(--green,#dff20d)}'
    + '.expmemo-hint{display:flex;align-items:center;gap:10px;margin:8px 0 16px;font-size:11.5px}'
    + '.expmemo-qr{flex:1;opacity:.65;word-break:break-all}'
    + '.expmemo-sg{flex:none;background:transparent;border:1px solid rgba(255,255,255,.18);'
    + 'color:inherit;border-radius:7px;font-size:11px;padding:3px 8px;cursor:pointer;opacity:.8}'
    + '.expmemo-sg:hover{opacity:1}'
    + '.expmemo-err{margin-top:10px;font-size:12px;color:#ff8b8b}'
    + '.expmemo-a{display:flex;gap:10px;justify-content:flex-end}';
  (document.head || document.documentElement).appendChild(st);
})();
