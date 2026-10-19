# PM 产品研发管理系统

基于 **Laravel 12** 构建的企业级产品研发（Product Management）管理系统，覆盖项目管理、任务协同、文件交付、知识库、协同文档、企业微信对接、语音协作等场景，并内置 **AI 智能助手**（智能问答、数据洞察、自然语言生成文档）。

---

## 一、技术栈

| 类别 | 选型 |
|---|---|
| 后端框架 | Laravel 12（当前 `v12.69.2`） |
| 运行环境 | PHP ≥ 8.3（生产为 `8.3.33`） |
| 数据库 | MySQL 8.4（库名 `pm_db`） |
| 前端 | Bootstrap 5 + Font Awesome + Blade（服务端渲染） |
| AI 能力 | 官方 `laravel/ai` SDK（`v1.2.3`），支持 DeepSeek / 通义千问 / 豆包 / 腾讯混元 |
| 其他关键包 | `spatie/laravel-model-states`（项目/任务状态机）、`spatie/laravel-permission`（RBAC）、`spatie/laravel-medialibrary`、`maatwebsite/excel`、`intervention/image`、`guzzlehttp/guzzle` |

---

## 二、功能模块

- **项目管理**：项目创建/状态流转（未开始→进行中→已完成/逾期/暂停）、进度、起止周期、发起人。
- **任务协同**：任务分配、执行人/审核人、部门归属、状态机（待开始/进行中/待审核/已完成/已逾期/已驳回）。
- **文件交付**：任务文件上传、版本管理、审核（approved/pending/rejected）、软删除与回收站。
- **知识库**：知识条目 + 文档（`knowledges` / `knowledge_docs`），支持分类与全文检索。
- **协同文档 / 白板 / 语音**：在线协作文档、会议语音协作。
- **企业微信**：WeCom 登录、消息、文件互通。
- **组织与权限**：用户、部门、角色权限（RBAC）。
- **日常办公**：待办、通知、站内信、私人消息。
- **AI 智能助手（新增）**：智能问答、PM 数据洞察、自然语言生成文档。

---

## 三、AI 智能助手

### 3.1 能力总览

| 能力 | 说明 |
|---|---|
| 智能问答 | 对项目、任务、用户、部门、知识库等真实数据进行问答，可调用工具查询，不编造数据 |
| PM 数据洞察 | 项目进度、逾期、任务完成情况、经营概览 |
| 自然语言生成文档 | 用户用自然语言提供数据/参数，AI 依据**模板文件、范例文件、知识库、支援文件**自动生成可交付文档 |
| AI 数据 API | REST 接口，供 AI / 外部 Agent 全面调取 PM 数据 |

### 3.2 界面入口

侧边栏 **「AI 智能助手」**：

- **开通引导**（`/ai`）：3 步开通——选择提供商 → 获取 API Key → 粘贴并测试。
- **AI 聊天**（`/ai/chat`）：带工具调用的智能问答。
- **AI 设置**（`/ai/settings`，管理员）：配置提供商 / API Key / Base URL / 模型 / 温度 / 启用开关，含连接测试。
- **文档生成**（`/ai/docs`）：选文档类型模板 → 自然语言描述 → 生成文档。

### 3.3 支持的 AI 提供商

| 提供商 | 说明 | 默认模型 |
|---|---|---|
| DeepSeek（深度求索） | 性价比最高，推荐首选，新用户送 ¥10 | `deepseek-chat` |
| 通义千问（阿里云） | 新用户送 100 万 token | `qwen-plus` |
| 豆包（字节） | 视觉能力强 | `doubao-pro-32k` |
| 腾讯混元 | 有免费模型 `hunyuan-lite` | `hunyuan-lite` |

### 3.4 文档生成 Skill（模板目录）

已预置 5 个文档模板（`storage/app/ai_docs/templates/`）：

| 模板 | 分类 |
|---|---|
| 项目立项报告 | 项目管理 |
| 工作周报 | 汇报 |
| 会议纪要 | 会议 |
| 需求规格说明书 | 研发 |
| 项目验收报告 | 项目管理 |

> 模板记录存于 `ai_doc_templates` 表，含模板文件、范例文件、支援文件、生成指引；`AiDocTemplate` 模型通过 `ai_docs` 磁盘（`storage/app`）读取内容。新增模板：在表内登记 `template_path/example_path/support_files` 并放置对应文件即可。

---

## 四、AI 数据 API

供 AI / 外部 Agent 全面调取 PM 系统数据，返回干净 JSON。

```
认证：请求头 X-AI-Key  或  查询参数 api_key
密钥：.env 中的 AI_API_KEY
```

| 端点 | 说明 |
|---|---|
| `GET /api/ai/meta` | 能力清单 / 端点列表 |
| `GET /api/ai/overview` | 项目 / 任务 / 用户 / 部门统计概览 |
| `GET /api/ai/projects` | 项目列表（`status`,`keyword`,`limit`） |
| `GET /api/ai/projects/{id}` | 项目详情（含任务） |
| `GET /api/ai/tasks` | 任务列表（`status`,`keyword`,`executor`,`limit`） |
| `GET /api/ai/users` | 用户列表（`keyword`,`limit`） |
| `GET /api/ai/departments` | 部门列表 |
| `GET /api/ai/knowledge` | 知识库（`keyword`,`limit`） |
| `GET /api/ai/files` | 文件列表（`status`,`keyword`,`limit`） |
| `GET /api/ai/todos` | 待办事项（`completed`,`limit`） |
| `GET /api/ai/templates` | AI 文档模板列表 |

### 内部工具（Laravel AI SDK Tool）

`app/Ai/Tools/` 下共 11 个工具，供聊天/文档生成 Agent 调用：
`QueryPmOverview`、`QueryProjects`、`QueryProjectDetail`、`QueryTasks`、`QueryUsers`、`QueryDepartments`、`QueryKnowledge`、`QueryFiles`、`QueryTodos`、`GetDocTemplate`、`SearchKnowledgeBase`。

---

## 五、AI 相关数据表

| 表 | 说明 |
|---|---|
| `ai_settings` | AI 全局配置（单行）：提供商 / Key / Base URL / 模型 / 温度 / 启用 |
| `ai_doc_templates` | 文档生成模板目录 |
| `ai_skills` | PM 专业服务 Skill 注册表 |
| `agent_conversations` / `agent_conversation_messages` | Laravel AI SDK 会话表 |

---

## 六、安装与部署

### 6.1 本地开发

```bash
composer install
cp .env.example .env        # 配置数据库等
php artisan key:generate
php artisan migrate
php artisan db:seed --class=AiSeeder   # 初始化 AI 配置 / 文档模板 / Skill
php artisan serve
```

### 6.2 服务器部署（openEuler）

代码仓库：`ssh://root@192.168.2.20/srv/git/pm.git`，应用目录：`/var/www/pm`。

**日常更新（仅代码/视图）**：

```bash
# 本地提交推送
git add -A && git commit -m "说明" && git push

# 服务器一键同步
cd /var/www/pm && ./deploy-views.sh
```

**涉及 composer 依赖 / 迁移时**（服务器需先装 composer）：

```bash
# 服务器
cd /var/www/pm && git pull origin master
/usr/local/bin/composer install --no-dev --no-interaction
php artisan migrate --force
php artisan db:seed --class=AiSeeder --force
chown -R pmadmin:pmadmin vendor storage bootstrap/cache
php artisan optimize:clear
```

> 服务器 Composer 安装在 `/usr/local/bin/composer`（不在 PATH）。`.env` 需追加 AI 相关配置（见下）。

### 6.3 .env 中的 AI 配置

```dotenv
AI_ENABLED=true
DEEPSEEK_API_KEY=             # 在后台「AI 智能助手 → AI 设置」填入，或在此配置
DEEPSEEK_URL=https://api.deepseek.com/v1
OPENAI_COMPATIBLE_API_KEY=
OPENAI_COMPATIBLE_URL=
OPENAI_COMPATIBLE_MODEL=qwen-plus
AI_API_KEY=                   # 外部调用 /api/ai/* 的访问密钥
```

> 推荐在系统后台「AI 设置」界面配置提供商与 Key（持久化到 `ai_settings` 表），`.env` 仅为兜底默认值。

---

## 七、项目结构（AI 相关）

```
app/
├─ Ai/
│  ├─ Concerns/PmFormats.php          # 状态 → 中文标签
│  └─ Tools/*.php                     # 11 个 AI 工具
├─ Http/
│  ├─ Controllers/AiController.php    # 开通引导/聊天/设置/文档生成
│  ├─ Controllers/Api/AiApiController.php  # AI 数据 API
│  └─ Middleware/AiApiAuth.php        # X-AI-Key 认证
├─ Models/AiSetting.php / AiDocTemplate.php / AiSkill.php
└─ Services/AiService.php             # 桥接 DB 配置 ↔ Laravel AI SDK
resources/views/ai/                   # welcome / chat / settings / docs
routes/api.php                         # /api/ai/*
config/ai.php                          # laravel/ai 提供商配置
storage/app/ai_docs/templates/         # 文档模板 / 范例文件
```

---

## 八、维护与更新记录

- 本次新增：集成 `laravel/ai`（v1.2.3），AI 智能助手 + AI 数据 API + 文档生成 Skill，详见提交 `4fe0fe7`。
- 部署注意事项：新增 composer 依赖后服务器必须执行 `composer install`，新增迁移后执行 `php artisan migrate --force`。
