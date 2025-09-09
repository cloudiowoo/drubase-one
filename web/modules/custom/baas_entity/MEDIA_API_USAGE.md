# BaaS实体媒体字段API使用指南

## 📋 概述

本文档介绍如何通过BaaS实体API创建和更新包含媒体字段（文件和图片）的实体数据。

## 🔗 API端点

### 创建实体（支持文件上传）
```http
POST /api/v1/{tenant_id}/projects/{project_id}/entities/{entity_name}
Content-Type: multipart/form-data
Authorization: Bearer {api_token}
```

### 更新实体（支持文件上传）
```http
PUT /api/v1/{tenant_id}/projects/{project_id}/entities/{entity_name}/{id}
Content-Type: multipart/form-data
Authorization: Bearer {api_token}
```

## 📝 请求格式

### 1. 纯JSON数据（无文件）
```http
POST /api/v1/tenant_7375b0cd/projects/project_123/entities/articles
Content-Type: application/json

{
  "title": "我的文章",
  "content": "文章内容...",
  "status": "published"
}
```

### 2. 包含文件的表单数据
```http
POST /api/v1/tenant_7375b0cd/projects/project_123/entities/articles
Content-Type: multipart/form-data

title=我的文章
content=文章内容...
status=published
featured_image=@/path/to/image.jpg
attachment=@/path/to/document.pdf
```

### 3. cURL示例

**创建包含图片的文章**：
```bash
curl -X POST \
  "http://localhost/api/v1/tenant_7375b0cd/projects/project_123/entities/articles" \
  -H "Authorization: Bearer YOUR_API_TOKEN" \
  -F "title=我的文章标题" \
  -F "content=这是文章的内容..." \
  -F "status=published" \
  -F "featured_image=@./images/hero.jpg" \
  -F "gallery[]=@./images/img1.jpg" \
  -F "gallery[]=@./images/img2.jpg"
```

**更新文章的附件**：
```bash
curl -X PUT \
  "http://localhost/api/v1/tenant_7375b0cd/projects/project_123/entities/articles/1" \
  -H "Authorization: Bearer YOUR_API_TOKEN" \
  -F "title=更新的文章标题" \
  -F "attachment=@./documents/new_document.pdf"
```

## 📄 响应格式

### 成功响应
```json
{
  "success": true,
  "message": "实体创建成功",
  "data": {
    "id": 1,
    "title": "我的文章",
    "content": "文章内容...",
    "status": "published",
    "featured_image": [
      {
        "id": 123,
        "filename": "hero.jpg",
        "url": "http://localhost/sites/default/files/tenant/project/hero.jpg",
        "filesize": 245760,
        "size_formatted": "240 KB",
        "mime_type": "image/jpeg",
        "is_image": true
      }
    ],
    "attachment": [
      {
        "id": 124,
        "filename": "document.pdf",
        "url": "http://localhost/sites/default/files/tenant/project/document.pdf",
        "filesize": 1048576,
        "size_formatted": "1 MB",
        "mime_type": "application/pdf",
        "is_image": false
      }
    ],
    "created": "2023-12-01T10:00:00Z",
    "updated": "2023-12-01T10:00:00Z"
  }
}
```

### 错误响应
```json
{
  "success": false,
  "message": "文件上传失败",
  "error_code": "FILE_UPLOAD_ERROR",
  "details": {
    "featured_image": "文件大小超过限制",
    "attachment": "不支持的文件类型"
  }
}
```

## 🎯 字段类型支持

### 文件字段 (file)
- **支持格式**: PDF, DOC, DOCX, XLS, XLSX, PPT, PPTX, ZIP, RAR, TXT
- **默认限制**: 10MB
- **存储路径**: `{tenant_id}/{project_id}/files/{YYYY-MM}/`

### 图片字段 (image)  
- **支持格式**: JPG, JPEG, PNG, GIF, WEBP
- **默认限制**: 5MB
- **分辨率限制**: 最大 3840x2160, 最小 100x100
- **存储路径**: `{tenant_id}/{project_id}/images/{YYYY-MM}/`

## 🔧 JavaScript SDK示例

```javascript
// 创建包含文件的实体
async function createArticleWithMedia() {
  const formData = new FormData();
  
  // 基础字段
  formData.append('title', '我的文章');
  formData.append('content', '文章内容...');
  formData.append('status', 'published');
  
  // 文件字段
  const imageFile = document.getElementById('imageInput').files[0];
  const pdfFile = document.getElementById('pdfInput').files[0];
  
  if (imageFile) {
    formData.append('featured_image', imageFile);
  }
  
  if (pdfFile) {
    formData.append('attachment', pdfFile);
  }
  
  try {
    const response = await fetch('/api/v1/tenant_7375b0cd/projects/project_123/entities/articles', {
      method: 'POST',
      headers: {
        'Authorization': 'Bearer ' + apiToken
      },
      body: formData
    });
    
    const result = await response.json();
    
    if (result.success) {
      console.log('文章创建成功:', result.data);
    } else {
      console.error('创建失败:', result.message);
    }
  } catch (error) {
    console.error('网络错误:', error);
  }
}

// 更新实体的图片
async function updateArticleImage(articleId) {
  const formData = new FormData();
  const newImage = document.getElementById('newImageInput').files[0];
  
  if (newImage) {
    formData.append('featured_image', newImage);
  }
  
  const response = await fetch(`/api/v1/tenant_7375b0cd/projects/project_123/entities/articles/${articleId}`, {
    method: 'PUT',
    headers: {
      'Authorization': 'Bearer ' + apiToken
    },
    body: formData
  });
  
  return await response.json();
}
```

## ⚠️ 注意事项

1. **权限要求**: 需要 `create tenant entity data` 或 `update tenant entity data` 权限
2. **文件大小**: 受字段配置和服务器限制影响
3. **文件类型**: 严格按照字段配置的扩展名限制
4. **存储位置**: 文件按租户和项目隔离存储
5. **API令牌**: 所有请求都需要有效的API认证令牌

## 🚀 高级功能

### 多文件上传
```bash
# 上传多个图片到画廊字段
curl -X POST \
  "http://localhost/api/v1/tenant_7375b0cd/projects/project_123/entities/galleries" \
  -H "Authorization: Bearer YOUR_API_TOKEN" \
  -F "name=我的画廊" \
  -F "images[]=@./img1.jpg" \
  -F "images[]=@./img2.jpg" \
  -F "images[]=@./img3.jpg"
```

### 条件更新
```bash
# 只更新特定字段，保留其他字段不变
curl -X PATCH \
  "http://localhost/api/v1/tenant_7375b0cd/projects/project_123/entities/articles/1" \
  -H "Authorization: Bearer YOUR_API_TOKEN" \
  -F "featured_image=@./new_hero.jpg"
```

## 📊 项目媒体管理

访问项目媒体管理界面查看和管理所有上传的文件：
```
http://localhost/tenant/{tenant_id}/project/{project_id}/media
```

这个界面提供：
- 📁 文件列表和预览
- 🔍 搜索和过滤功能  
- 📈 使用统计信息
- 🗑️ 文件删除功能
- 📤 批量操作支持