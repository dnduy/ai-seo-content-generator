# 🚀 Hướng dẫn cập nhật AI SEO Content Generator v3.2

## 📋 Vấn đề đã sửa trong v3.2

### 🐛 **Lỗi E_PARSE** (Critical - Yêu cầu cập nhật ngay)
- **Vấn đề**: Duplicate code trong `api-handler.php` gây ra lỗi syntax
- **Ảnh hưởng**: Plugin không hoạt động, WordPress báo lỗi nghiêm trọng
- **Sửa chữa**: Đã xóa code trùng lặp, file giờ hoàn toàn hợp lệ

### ✅ **Cải tiến API Fallback**
- Khi API chính bị quota exceeded, plugin sẽ tự động thử API khác
- Hỗ trợ 3 API: Gemini 3 Flash, Gemini 2.0, DeepSeek R1
- Tự động phát hiện HTTP 429 status codes

### 🔐 **Cải tiến Xác thực**
- Kiểm tra user login trước khi xử lý request
- Kiểm tra quyền `edit_posts` rõ ràng
- Xác thực nonce an toàn hơn

### 📊 **Cải tiến Error Handling**
- Thông báo lỗi chi tiết hơn
- Gợi ý cụ thể cho từng loại lỗi
- Logging tốt hơn để debug

---

## 🔧 Cách cập nhật plugin

### **Phương pháp 1: Cập nhật qua SFTP/FTP (Recommended)**

1. **Download phiên bản mới từ GitHub**:
   ```bash
   git clone https://github.com/dnduy/ai-seo-content-generator.git
   # hoặc download ZIP từ: https://github.com/dnduy/ai-seo-content-generator/releases/latest
   ```

2. **Kết nối SFTP tới hosting**:
   - Host: `miraquynhon.com`
   - Username: `u469314067`
   - Password: [Từ email xác nhận hosting]

3. **Cập nhật folder plugin**:
   ```
   /home/u469314067/domains/miraquynhon.com/public_html/wp-content/plugins/ai-seo-content-generator/
   ```
   
   - Backup folder cũ trước (rename thành `ai-seo-content-generator-old`)
   - Upload folder mới `ai-seo-content-generator`

4. **Xác minh bằng WordPress Admin**:
   - Đăng nhập vào `/wp-admin/plugins.php`
   - Kiểm tra AI SEO Content Generator v3.2 đã kích hoạt
   - Không có thông báo lỗi

### **Phương pháp 2: Cập nhật qua Lệnh Lệnh SSH**

Nếu nhà cung cấp hosting hỗ trợ SSH:

```bash
cd /home/u469314067/domains/miraquynhon.com/public_html/wp-content/plugins/

# Backup plugin cũ
mv ai-seo-content-generator ai-seo-content-generator-old

# Clone phiên bản mới
git clone https://github.com/dnduy/ai-seo-content-generator.git

# Xác minh
ls -la ai-seo-content-generator/
```

### **Phương pháp 3: Cập nhật một số file cụ thể**

Nếu bạn chỉ muốn cập nhật files đã thay đổi:

1. Download 2 files này từ GitHub:
   - `includes/api-handler.php`
   - `ai-seo-content-generator.php`

2. Upload lên server bằng SFTP thay thế các file cũ

3. Xóa cache WordPress (nếu có plugin cache)

---

## ⚠️ Nếu còn gặp lỗi

### **Lỗi vẫn xuất hiện sau cập nhật**

1. **Vô hiệu hóa plugin tạm thời**:
   - Truy cập `/wp-admin/plugins.php`
   - Tìm "AI SEO Content Generator"
   - Nhấp "Deactivate"

2. **Kích hoạt lại**:
   - Đợi 30 giây
   - Nhấp "Activate"
   - Kiểm tra trang `/wp-admin/post-new.php`

3. **Nếu vẫn bị lỗi**:
   - Liên hệ nhà cung cấp hosting
   - Cung cấp thông tin:
     - PHP Version: 8.2.28 ✅
     - WordPress: 6.8.3 ✅
     - Plugin Version: 3.2

### **Kiểm tra Debug Mode**

Thêm vào `wp-config.php`:

```php
// Sau dòng: define('WP_DEBUG', false);
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
```

Kiểm tra log tại `/wp-content/debug.log`

---

## 📦 Thay đổi File trong v3.2

| File | Thay đổi |
|------|----------|
| `includes/api-handler.php` | ✅ Sửa E_PARSE error, cải tiến quota handling |
| `ai-seo-content-generator.php` | ✅ Cập nhật version 3.0 → 3.2 |
| `assets/js/block-editor.js` | ✅ Cải tiến error messages |

---

## 📞 Hỗ trợ

- GitHub Issues: https://github.com/dnduy/ai-seo-content-generator/issues
- Email: duyduong@email.com
- Hosting Support: Liên hệ nhà cung cấp (WordPress cung cấp chế độ Recovery Mode)

---

## ✅ Checklist sau khi cập nhật

- [ ] Plugin version hiển thị 3.2
- [ ] Không có lỗi trong `/wp-admin/plugins.php`
- [ ] Có thể truy cập `/wp-admin/post-new.php` bình thường
- [ ] Button "Generate SEO Content" xuất hiện trong editor
- [ ] Có thể mở modal và điền form
- [ ] Thử tạo content test (không cần API keys)

---

**Cập nhật vào**: 19/12/2025
**Version hiện tại**: 3.2
**Status**: ✅ Production Ready
