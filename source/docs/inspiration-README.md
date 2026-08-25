# Inspiration Board — Hướng dẫn sử dụng

Kho ý tưởng dùng chung cho team APSA khi làm sự kiện: kéo thả ảnh/video, dán link
Facebook · Instagram · YouTube · TikTok · Pinterest — tất cả lưu trên server APSA, cả team đều xem được.

**🔗 Truy cập: https://app.apsa.agency/inspiration.html**
(hoặc vào https://app.apsa.agency → card 💡 Inspiration)

---

## Đã cài đặt xong ✅

Toàn bộ đã được deploy lên Plesk (`app.apsa.agency`) và kiểm tra chạy thật ngày 02/08/2026:

```
/inspiration.html                  ← trang giao diện
/api/inspiration-api.php           ← API backend
/uploads/inspiration/              ← nơi lưu file (đã tạo, ghi được)
/uploads/inspiration/.htaccess     ← chặn thực thi mã trong thư mục upload
/index.html                        ← đã thêm card "Inspiration"
/docs/inspiration-README.md        ← file này
```

Đã xác nhận hoạt động:

- Bảng `inspiration_items` tự tạo trong database `mee53661_apps`
- Upload ảnh + tự sinh thumbnail WebP (GD có sẵn)
- Lấy tiêu đề & ảnh đại diện YouTube qua oEmbed, nhúng iframe xem trực tiếp
- Lọc theo tag, thùng rác, khôi phục

**Giới hạn upload của server:** `upload_max_filesize` và `post_max_size` đều là **512 MB**,
`max_execution_time` 300s — không cần chỉnh gì thêm.
Giới hạn phía API đặt thấp hơn cho an toàn: ảnh **25 MB**, video **200 MB**, tối đa **30 file** mỗi lần.
Muốn đổi thì sửa `MAX_IMAGE` / `MAX_VIDEO` ở đầu file `api/inspiration-api.php`.

---

## Cách dùng

| Thao tác | Cách làm |
|---|---|
| Thêm ảnh/video từ máy | Kéo thả vào **bất kỳ đâu** trên trang, hoặc `＋ Thêm` → chọn file |
| Thêm link FB/IG/YouTube | Copy link → bấm `Ctrl+V` ngay trên trang, hoặc `＋ Thêm` → dán vào ô Link |
| Chụp màn hình rồi dán | `Ctrl+V` — ảnh trong clipboard được upload thẳng |
| Lọc theo tag | Bấm vào các chip `#tag` dưới thanh công cụ (chọn nhiều tag = lọc AND) |
| Tìm kiếm | Gõ vào ô tìm kiếm, hoặc bấm phím `/` |
| Ghim ý tưởng hay | Mở item → `✎ Sửa` → `📌 Ghim lên đầu` |
| Xoá | Icon 🗑 trên thẻ → vào **Thùng rác**, khôi phục được bất cứ lúc nào |

**Phím tắt:** `n` = thêm mới · `/` = tìm kiếm · `Esc` = đóng · `Ctrl+V` = dán link/ảnh

### Tag — gợi ý cách đặt

Không có "board" riêng, tất cả nằm trên một tường chung và lọc bằng tag.
Nên đặt tag theo 2 nhóm để lọc chéo được:

- **Theo sự kiện:** `hội nghị az 2026`, `launch tezspire`, `gala novartis`
- **Theo hạng mục:** `backdrop`, `sân khấu`, `booth`, `led`, `quà tặng`, `photobooth`, `standee`

Chọn cả 2 tag cùng lúc để xem "backdrop của riêng hội nghị AZ 2026".
Tag tự động viết thường và bỏ trùng.

---

## Ghi chú kỹ thuật

- **Ảnh lớn** (> 720px) được tự tạo thumbnail WebP để lưới tải nhanh; ảnh gốc vẫn giữ nguyên khi mở lightbox.
  Cần extension GD của PHP — nếu hosting không có, trang vẫn chạy, chỉ là dùng ảnh gốc.
- **Link social** được lấy tiêu đề + ảnh đại diện qua oEmbed / thẻ Open Graph, và nhúng bằng iframe chính chủ
  (YouTube nocookie, FB Plugin, IG embed, TikTok embed) — không cần API key.
  Một số post Facebook/Instagram để chế độ riêng tư sẽ không nhúng được; khi đó bấm `↗ Mở gốc`.
- **Xoá là xoá mềm** — item vào thùng rác, file vẫn còn trên server.
  Muốn xoá hẳn cả file phải gọi `?action=purge` kèm header `X-API-Key` (chỉ admin).
- Ai cũng xem và thêm được, không cần đăng nhập. Tên hiển thị lưu trong trình duyệt
  (`localStorage`), bấm vào avatar góc phải để đổi.
