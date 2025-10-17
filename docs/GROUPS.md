# Groups - 基于 Drubase One 的智能团队运动平台

---

## 🌐 项目故事

### 🏁 起点：一个简单的想法

最初，**Groups** 只是一个基于 Supabase 的团队运动小应用。
我们希望帮助朋友们更轻松地组织一场足球或篮球比赛——
不用微信群吵闹、不用手动分队、不再为场地和时间冲突而头疼。

然而，当功能逐步扩展，我们发现——
社区级应用需要**更强的控制权**、**更灵活的后端**、
以及一个能让开发者"自托管"的可扩展 BaaS 平台。

**于是，我们启动了迁移计划：**
> 从 **Supabase** → 自研 **Drubase One BaaS Platform**。

---

## 🚀 新架构：Drubase One 驱动的多租户 BaaS 后端

**Drubase One** 是一个基于 **Drupal 11 + Node 服务层**的现代化 BaaS 平台。
它不仅仅是一个后端，更是一个**可自托管、可扩展、可商业化**的"后端即服务"解决方案。

在 **Groups** 项目中，Drubase One 提供了完整的后端支撑：

| 模块 | 功能 | 技术实现 |
|------|------|----------|
| 🔐 **认证系统** | 用户注册、登录、JWT Token、资料更新 | `user/login`, `user/register` API |
| 📦 **数据管理** | 多租户结构（tenant + project_id） | PostgreSQL schema 隔离 |
| 🌍 **文件服务** | 图片上传、头像管理、活动海报生成 | Node FileUpload Service |
| 🔄 **实时同步** | WebSocket 实时推送活动、队伍、位置变化 | Node + PostgreSQL LISTEN/NOTIFY |
| 🧠 **智能算法** | 分组、队伍占用率计算 | Node 服务层中的 AlgorithmService |
| 🧰 **API 一致性** | 所有实体接口自动生成 RESTful API | Drupal Entity + JSON:API 扩展 |
| 🧩 **插件式扩展** | 任意功能模块可热插拔 | Drupal Service Container 架构 |

**一句话总结：**
> Drubase One 就像是 Supabase 的「开源兄弟」，
> 但更开放、更自由，允许开发者自定义业务逻辑与多租户架构。

---

## 📱 Groups 前端：用 React Native 构建跨端体验

在前端层面，**Groups** 使用 **React Native Web + Expo** 架构，
实现了一个能同时运行于 **Web / iOS / Android** 的现代化应用：

- 🧍 **用户登录注册** → 通过 Drubase One JWT API 认证
- 🏟️ **活动创建** → 调用 `baas/activities` 接口
- 🧩 **智能分组** → 调用 Node 端算法服务，实时返回队伍分配结果
- 🔄 **实时数据同步** → 通过 WebSocket 与 BaaS 平台保持数据一致
- 🖼️ **活动海报生成** → Node 服务动态生成可分享图片

**核心设计理念是：**

> **"所有状态都来自后端，所有逻辑都在服务层实现。"**

这让 Groups 具备了天然的**可维护性**和**一致性**。

---

## 🧠 技术亮点（Tech Highlights）

### 1️⃣ 自托管 BaaS：Supabase 的开源替代方案

Groups 不再依赖云端 Supabase，而是部署在 **Drubase One 自研 BaaS 平台**上。
用户可在本地部署、亦可云端托管，**完全掌控数据与逻辑**。

### 2️⃣ 智能分组算法

内置自研**概率分配算法**：

```
概率 = (1 - 占用率^occupancyFactor) × (可用座位数 / 总可用座位数)^(1/occupancyFactor)
```

确保每次分组既**公平**又**动态平衡**，支持上百人实时匹配。

**算法特性：**
- **标准模式**（占用率 < 70%）：均衡分配，确保各队伍人数接近
- **高占用模式**（占用率 ≥ 70%）：避免碎片化，优先填充占用率低的队伍

### 3️⃣ 实时同步

通过 **PostgreSQL 的 LISTEN/NOTIFY** 与 **Node WebSocket 层**结合，
实现**毫秒级**的活动与队伍变更推送。

### 4️⃣ 多租户架构

每个用户或团队可在 Drubase One 中以独立租户（`tenant_project_id`）存在，
形成一个天然的"**微 SaaS**"，可自托管或商业化运营。

### 5️⃣ 无缝文件存储

头像、海报、活动图片统一存储在 **Drubase 文件服务**中，
支持 **CDN 分发**与**权限控制**。

---

## 💼 对开发者的意义

**Drubase One + Groups** 的组合，为独立开发者提供了一个完整的参考架构：

| 层级 | 实现 | 技术 |
|------|------|------|
| 🧩 **数据层** | PostgreSQL + Entity Model | Drupal 11 Entity API |
| ⚙️ **服务层** | Node.js + TypeScript | ServiceFactory 模式 |
| 🌐 **通信层** | RESTful + WebSocket | JWT + 实时通道 |
| 📱 **前端层** | React Native Web | Expo + Hooks + Service Layer |
| 🔁 **部署层** | Docker Compose / K3s | 可自托管多租户 BaaS |

**它证明了一件事：**

> 一个前端开发者，也能在 Drubase One 上构建自己的 SaaS 平台。

---

## 🎯 核心功能

### ✅ 已实现功能

| 功能模块 | 说明 | 状态 |
|---------|------|------|
| 🔐 **用户认证** | 注册、登录、JWT Token、资料管理 | ✅ 完成 |
| 👤 **资料管理** | 用户名、头像上传、密码修改 | ✅ 完成 |
| 🏟️ **活动管理** | 创建、编辑、删除、列表展示 | ✅ 完成 |
| 🧩 **智能分组** | 随机分组算法、动态概率分配 | ✅ 完成 |
| 📍 **位置管理** | 位置创建、编辑、锁定功能 | ✅ 完成 |
| 👥 **队伍管理** | 队伍创建、编辑、成员管理 | ✅ 完成 |
| 🔄 **实时同步** | WebSocket 实时数据推送 | ✅ 完成 |
| 🖼️ **海报生成** | 活动海报自动生成和分享 | ✅ 完成 |
| 📁 **文件上传** | 头像、图片上传到 BaaS | ✅ 完成 |

### 📋 技术特点

- **TypeScript** - 完整的类型安全
- **Service Layer** - 清晰的服务层架构
- **BaaS 集成一致性规范** - 统一的开发规范
- **错误处理** - 统一的错误处理和用户反馈
- **状态管理** - React Hooks + Context API
- **响应式设计** - 适配移动端和 Web 端

---

## 🧭 未来规划

Groups 将成为 **Drubase One 官方示例项目**，展示如何：
- 用自研 BaaS 平台替代 Supabase；
- 构建跨端应用；
- 实现**多租户 + 实时 + 文件 + AI 算法**的现代应用架构。

### 接下来计划：

- 🧠 **增加 AI 队伍建议算法** - 基于用户历史和偏好推荐队伍
- 📣 **增强通知系统** - 活动提醒、队伍变化推送
- 📍 **集成地图和活动推荐** - 基于位置的活动发现
- 🧰 **提供开发者模板** - 自托管 BaaS + 前端 Starter Kit
- 📱 **微信小程序版本** - 【分则Slide】迁移到 Drubase One

---

## 🌟 技术架构图

```
┌─────────────────────────────────────────────────────────┐
│                     前端应用层                            │
│   React Native Web (Expo) + TypeScript                  │
│   ├── 用户界面组件                                        │
│   ├── 状态管理 (Hooks + Context)                         │
│   └── 服务层 (ServiceFactory)                            │
└─────────────────────────────────────────────────────────┘
                           ↓
┌─────────────────────────────────────────────────────────┐
│                   BaaS Client 层                         │
│   RESTful API + WebSocket                               │
│   ├── JWT 认证                                           │
│   ├── 实体 CRUD 操作                                     │
│   └── 文件上传服务                                        │
└─────────────────────────────────────────────────────────┘
                           ↓
┌─────────────────────────────────────────────────────────┐
│                Drubase One BaaS 平台                     │
│   ┌──────────────┐  ┌──────────────┐  ┌─────────────┐ │
│   │ Drupal 11    │  │ Node.js      │  │ PostgreSQL  │ │
│   │ (实体管理)    │  │ (函数服务)    │  │ (数据存储)   │ │
│   └──────────────┘  └──────────────┘  └─────────────┘ │
│   ┌──────────────┐  ┌──────────────┐  ┌─────────────┐ │
│   │ 认证系统      │  │ 文件服务      │  │ 实时推送     │ │
│   └──────────────┘  └──────────────┘  └─────────────┘ │
└─────────────────────────────────────────────────────────┘
```

---

## 📚 相关文档

- **[Drubase One 主文档](README.md)** - 了解 BaaS 平台
- **[安装指南](INSTALL.md)** - 部署 Drubase One
- **[API 文档](API.md)** - RESTful API 使用说明
- **[Groups 源代码](../apps/groups/)** - 前端项目源码

---

## 🎁 快速体验

### 🌐 在线演示（推荐）

**立即体验 Groups，无需安装：**

- **📱 Groups 演示应用**: http://groups.logisticservice.site:8000/
- **🎯 Drubase One 管理后台**: http://drubase-one.logisticservice.site:8000/

**测试账户**：
- 邮箱：test@test.com
- 密码：888888

**体验步骤**：
1. 访问 Groups 应用并登录
2. 完善用户资料（上传头像）
3. 创建一个新的运动活动
4. 添加队伍和位置
5. 体验智能分组算法

---

### 💻 本地安装

#### 1. 安装 Drubase One

```bash
# 下载并安装 Drubase One
wget https://github.com/cloudiowoo/drubase-one/releases/latest/download/drubase-one.tar.gz
tar -xzf drubase-one.tar.gz
cd drubase-one
./install.sh
```

#### 2. 访问 Groups 应用

安装完成后，访问：

- **Groups 应用**: http://localhost:3000
- **测试账户**:
  - 邮箱：test@test.com
  - 密码：888888

#### 3. 体验核心功能

1. 登录后完善用户资料
2. 创建一个新的运动活动
3. 添加队伍和位置
4. 邀请朋友加入并体验智能分组

---

## 🌟 一句话总结

> **Drubase One 让独立开发者拥有像 Supabase 一样的能力，**
> **但更自由、更强大、更可扩展。**

**【Groups】**则是这场理念的第一个成功实践。

**【分则Slide】**是微信小程序在线版本，也将计划迁移到 Drubase One。

---

## 🤝 参与贡献

Groups 是一个开源项目，欢迎：

- 🐛 **报告问题** - [提交 Issue](https://github.com/cloudiowoo/drubase-one/issues)
- 💡 **功能建议** - [讨论区](https://github.com/cloudiowoo/drubase-one/discussions)
- 🔧 **代码贡献** - Fork 项目并提交 PR

---

<div align="center">

**Groups + Drubase One = 自由的 BaaS 开发体验**

Made with ❤️ by Drubase Team

</div>
