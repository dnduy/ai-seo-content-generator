# 🚀 Hướng dẫn đưa lên GitHub

## Bước 1: Tạo Repository trên GitHub
1. Truy cập [GitHub.com](https://github.com)
2. Click nút "New repository" (dấu + ở góc trên bên phải)
3. Điền thông tin:
   - **Repository name**: `ai-seo-content-generator`
   - **Description**: `AI SEO Content Generator - WordPress plugin for generating SEO-optimized content using Google Gemini or DeepSeek API`
   - **Visibility**: Chọn Public hoặc Private
   - **Không** tick "Initialize this repository with README" (vì đã có sẵn)
4. Click "Create repository"

## Bước 2: Connect Local với GitHub
Sau khi tạo repository, chạy các lệnh sau:

```bash
# Thêm remote GitHub (thay YOUR_USERNAME bằng tên GitHub của bạn)
git remote add origin https://github.com/YOUR_USERNAME/ai-seo-content-generator.git

# Push code lên GitHub
git push -u origin main

# Push tags
git push origin --tags
```

## Bước 3: Verify Upload
1. Refresh trang GitHub repository
2. Kiểm tra tất cả files đã được upload
3. Kiểm tra README.md hiển thị đúng
4. Kiểm tra tag v3.0.0 trong phần Releases

## 📋 Files đã sẵn sàng:
- ✅ README.md (documentation chi tiết)
- ✅ CHANGELOG.md (lịch sử phiên bản)
- ✅ .gitignore (loại trừ files không cần)
- ✅ Plugin files (PHP, JS, CSS)
- ✅ Git repository đã init
- ✅ Initial commit với tag v3.0.0

## 🎯 Lệnh nhanh (sau khi tạo repo):
```bash
git remote add origin https://github.com/YOUR_USERNAME/ai-seo-content-generator.git
git push -u origin main --tags
```

Thay `YOUR_USERNAME` bằng tên GitHub thực của bạn.