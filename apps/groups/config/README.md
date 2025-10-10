# Groups 应用运行时配置

## 文件说明

- **runtime-config.js** - 运行时配置文件，可随时修改

## 修改配置

编辑 `runtime-config.js` 文件，修改以下配置项：

```javascript
window.__RUNTIME_CONFIG__ = {
  // 修改为您的BaaS后端地址
  BAAS_BASE_URL: 'http://your-server.com',

  // 修改为您的API Key
  BAAS_API_KEY: 'your-actual-api-key',

  // 其他配置...
};
```

## 使配置生效

1. 修改 `runtime-config.js` 文件
2. 保存文件
3. 刷新浏览器（无需重启容器！）

## 技术说明

- 此文件通过Docker volume映射到nginx容器
- nginx将其作为静态文件提供
- 前端应用在启动时加载此配置
- 配置优先级：运行时配置 > 环境变量 > 默认值

## 目录结构

```
apps/groups/
├── config/
│   ├── runtime-config.js    # 可修改的配置文件
│   └── README.md            # 本说明文档
└── dist/                    # 编译后的应用（不要修改）
    ├── index.html
    ├── _expo/
    └── ...
```
