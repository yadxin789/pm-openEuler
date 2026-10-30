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
- **AI 智能助手**：智能问答、PM 数据洞察、自然语言生成文档。
- **AI 文件解析（新增）**：AI 聊天支持上传 PDF / DOCX / XLSX / PPTX / ODT / TXT / MD / CSV 等格式，自动提取文本内容辅助设计。
- **AI 会话工作区（新增）**：上传 ZIP 文件自动解压，AI 可列出目录、读取文件内容，辅助设计资料库查询；会话删除时自动清理临时文件。
- **OpenVPN 远程办公（新增）**：自托管 VPN，员工在家通过 OpenVPN 客户端访问 PM 系统，无需第三方 SaaS 依赖。

---

## 三、AI 智能助手

### 3.1 能力总览

| 能力 | 说明 |
|---|---|
| 智能问答 | 对项目、任务、用户、部门、知识库等真实数据进行问答，可调用工具查询，不编造数据 |
| PM 数据洞察 | 项目进度、逾期、任务完成情况、经营概览 |
| 自然语言生成文档 | 用户用自然语言提供数据/参数，AI 依据**模板文件、范例文件、知识库、支援文件**自动生成可交付文档 |
| AI 文件解析 | 上传 PDF/DOCX/XLSX/PPTX/ODT 等文件，AI 自动提取文本内容 |
| AI 会话工作区 | 上传 ZIP 自动解压，AI 可浏览文件列表、读取文件内容 |
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

供 AI / 外部 Agent 读取与变更 PM 系统数据，返回干净 JSON。

### 4.1 授权模型

> **核心原则：谁对这份文件拥有读 / 写 / 下载的权限，谁就可以授权 AI 读 / 写 / 下载。**
> **编辑保护：AI 编辑文件前需用户同意，编辑结果需用户确认。**

| 凭据 | 请求头 | 作用 |
|---|---|---|
| 系统密钥 | `X-AI-Key` / `?api_key=` | 证明调用方是已登记的 AI 客户端（`.env` 的 `AI_API_KEY`） |
| 委托令牌 | `X-AI-Token` / `?ai_token=` | 证明「某个用户授权了 AI」，并以其身份执行 |

委托令牌由用户在 **「AI 授权中心」（`/ai-delegation`）** 签发，或管理员用命令行签发：

```bash
php artisan ai:issue-token {user_id|username} --name="文档生成 Agent" --scopes=read,write,download --days=90
```

令牌**不产生任何额外权限**：AI 拿到令牌后能做的事，完全等同于签发者本人。

> **默认策略：所有 `/api/ai/*` 调用（含只读）都必须携带委托令牌，否则 401。**
> 这样每一条 AI 取数都能追溯到具体用户，且可见范围自动收敛为他本人的可见范围。
> 由 `AI_API_ALLOW_ANONYMOUS_READ` 控制，默认 `false`。
> 仅在本地调试、且能接受「拿到 `AI_API_KEY` 的人即可读取全部项目/任务/知识库数据」时才设为 `true`。

可选作用域：`read`（读取）、`write`（编辑，仍需逐条确认）、`download`（下载文件正文）。

### 4.2 两阶段写入（AI 永不直接改数据）

```
AI 调用写接口
   └─> 校验授权人权限 → 生成待确认提案（status=pending）→ 返回 202 + proposal_id + diff
           ✗ 权限不足：直接 403，提案都不会生成
           ✗ 业务数据此时完全未被触碰

授权人在「AI 授权中心」审阅 diff
   ├─> 确认 → 真正执行写入（以审批人身份记录操作日志）
   └─> 驳回 → 丢弃，暂存文件一并清理
```

提案 30 分钟未处理自动作废（定时任务 `ai:expire-proposals`）。
AI 可用 `GET /api/ai/write/proposals/{id}` 跟踪状态。

### 4.3 只读端点

| 端点 | 说明 |
|---|---|
| `GET /api/ai/meta` | 能力清单 / 端点列表 |
| `GET /api/ai/overview` | 项目 / 任务 / 用户 / 部门统计概览 |
| `GET /api/ai/projects` | 项目列表（`status`,`keyword`,`limit`） |
| `GET /api/ai/projects/{id}` | 项目详情（含任务） |
| `GET /api/ai/tasks` | 任务列表（`status`,`keyword`,`executor`,`limit`） |
| `GET /api/ai/tasks/{id}` | 任务详情（含文件与 AI 可执行动作） |
| `GET /api/ai/users` | 用户列表（`keyword`,`limit`） |
| `GET /api/ai/departments` | 部门列表 |
| `GET /api/ai/knowledge-categories` | 知识库分类树 |
| `GET /api/ai/knowledge` | 知识库列表（`keyword`,`category`,`limit`） |
| `GET /api/ai/knowledge/{id}` | 知识库详情（**完整文档树**：文档/章节/链接） |
| `GET /api/ai/files` | 任务文件列表（`task_id`,`project_id`,`status`,`keyword`,`limit`） |
| `GET /api/ai/files/{id}` | 文件详情 |
| `GET /api/ai/files/{id}/versions` | 文件历史版本 |
| `GET /api/ai/files/{id}/content` | 文件正文（纯文本类，需 `download` 权限） |
| `GET /api/ai/files/{id}/download` | 下载文件（需委托令牌 + `download`） |
| `GET /api/ai/department-files` | 支援文件（部门资料，按可见性收敛） |
| `GET /api/ai/department-files/{id}/download` | 下载支援文件 |
| `GET /api/ai/templates` | AI 文档模板列表 |
| `GET /api/ai/templates/{id}` | **模板全文 + 范例 + 支援文件内容 + 生成指引** |
| `GET /api/ai/todos` | 待办事项（`completed`,`limit`） |

### 4.4 写入端点（全部返回 202 提案）

| 端点 | 说明 |
|---|---|
| `POST /api/ai/write/tasks` | 新建任务 |
| `PUT /api/ai/write/tasks/{id}` | 修改任务 |
| `POST /api/ai/write/tasks/{id}/extension` | 申请延期 |
| `POST /api/ai/write/tasks/{id}/upload` | 上传任务文件（multipart） |
| `POST /api/ai/write/tasks/{id}/update-file` | 更新任务文件新版本（multipart） |
| `POST /api/ai/write/tasks/{id}/audit` | 审核（`result=approve\|reject`） |
| `POST /api/ai/write/department-files` | 新建支援文件（multipart） |
| `POST /api/ai/write/department-files/{id}` | 更新支援文件（multipart） |
| `DELETE /api/ai/write/department-files/{id}` | 删除支援文件 |
| `POST /api/ai/write/knowledge` | 新建知识库 |
| `PUT /api/ai/write/knowledge/{id}` | 修改知识库 |
| `DELETE /api/ai/write/knowledge/{id}` | 删除知识库 |
| `POST /api/ai/write/knowledge/{id}/docs` | 新建知识库文档 |
| `PUT /api/ai/write/knowledge-docs/{docId}` | 修改知识库文档 |
| `DELETE /api/ai/write/knowledge-docs/{docId}` | 删除知识库文档 |
| `GET /api/ai/write/proposals` | 提案列表 |
| `GET /api/ai/write/proposals/{id}` | 提案详情 + diff |

调用示例：

```bash
# 1) AI 提交编辑意图
curl -X POST http://host/api/ai/write/knowledge \
  -H "X-AI-Key: $AI_API_KEY" -H "X-AI-Token: $DELEGATION_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"title":"新产品立项说明","desc":"AI 起草","is_share":true,"reason":"按周报模板整理"}'
# => 202 {"proposal_id":12,"diff":{...},"confirm_url":"http://host/ai-delegation"}

# 2) 用户在「AI 授权中心」确认后，变更才生效
curl http://host/api/ai/write/proposals/12 \
  -H "X-AI-Key: $AI_API_KEY" -H "X-AI-Token: $DELEGATION_TOKEN"
```

### 内置 AI 助手的工具（Laravel AI SDK Tool）

`app/Ai/Tools/` 下共 17 个工具，供聊天 / 文档生成 Agent 调用。

**查询类（14）**：`QueryPmOverview`、`QueryProjects`、`QueryProjectDetail`、`QueryTasks`、`QueryUsers`、`QueryDepartments`、`QueryKnowledge`、`QueryFiles`、`QueryTodos`、`GetDocTemplate`、`SearchKnowledgeBase`、`QueryResourceFiles`、`ListWorkspaceFiles`、`ReadWorkspaceFile`。

**变更类（6，只生成提案，不直接写库）**：

| 工具 | 用途 |
|---|---|
| `ProposeTaskChange` | 新建 / 修改任务、申请延期 |
| `ProposeTaskAudit` | 任务文件审核通过 / 驳回 |
| `ProposeKnowledgeChange` | 知识库增 / 改 / 删 |
| `ProposeKnowledgeDocChange` | 知识库文档（含章节、链接）增 / 改 / 删，可提交 Markdown 正文 |
| `ProposeDepartmentFileChange` | 支援文件改分类 / 可见范围、删除 |
| `ListMyProposals` | 查询我名下的提案状态（只读） |

内置 AI 与外部 API 共用同一套两阶段引擎，区别只是授权人身份来源不同
（内置 AI 取 `auth()->user()`，外部 API 取委托令牌绑定的用户）。

> **刻意不提供「确认提案」工具**：审批权必须留在人手里，AI 无法自我确认，
> 物理上杜绝「AI 自己提交又自己批准」的绕过路径。

系统提示词已写入硬性规则：只有用户本轮明确提出变更意图时才可调用 `Propose*`，
信息不全时先询问，提案返回后必须告知用户「尚未生效，需本人到 AI 授权中心确认」。

另注册 Skill **「数据变更助理」（`data-change`）**，只挂载变更类工具，便于按需隔离权限。

---

## 五、AI 相关数据表

| 表 | 说明 |
|---|---|
| `ai_settings` | AI 全局配置（单行）：提供商 / Key / Base URL / 模型 / 温度 / 启用 |
| `ai_doc_templates` | 文档生成模板目录 |
| `ai_skills` | PM 专业服务 Skill 注册表 |
| `agent_conversations` / `agent_conversation_messages` | Laravel AI SDK 会话表 |
| `ai_delegation_tokens` | 用户签发给 AI 的委托令牌（权限边界 = 用户本人） |
| `ai_change_proposals` | AI 变更提案（pending → confirmed / rejected / expired / failed） |

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

### 6.4 远程办公（OpenVPN）

服务器已部署 OpenVPN 2.6.9，用于员工在家远程访问 PM 系统。

**服务端配置：**
- 配置目录：`/etc/openvpn/server/server.conf`
- 端口：UDP 1194
- VPN 网段：10.8.0.0/24
- 服务：`openvpn-server@server`（systemd）

**生成客户端配置：**
```bash
ssh root@192.168.2.20 /usr/local/bin/gen_client.sh 张三
# 生成 /etc/openvpn/张三.ovpn，发给员工
```

**员工使用：**
1. 下载安装 [OpenVPN 客户端](https://openvpn.net/community-downloads/)
2. 导入 `.ovpn` 文件
3. 连接后访问 `https://192.168.2.20`

**路由器端口转发：**
- 外部端口：1194（UDP）
- 内部 IP：192.168.2.20
- 内部端口：1194（UDP）

### 6.5 HTTPS 证书

服务器使用自签名证书（带 IP SAN），部署在 Apache `/etc/httpd/ssl/`。
Windows 用户首次访问时需导入证书信任：运行 `导入PM证书.bat`。

---

## 七、项目结构（AI 相关）

```
app/
├─ Ai/
│  ├─ Concerns/PmFormats.php          # 状态 → 中文标签
│  └─ Tools/*.php                     # 11 个 AI 工具
├─ Http/
│  ├─ Controllers/AiController.php    # 开通引导/聊天/设置/文档生成
│  ├─ Controllers/Api/AiApiController.php       # AI 只读数据 API
│  ├─ Controllers/Api/AiWriteApiController.php  # AI 写入 API（两阶段提案）
│  ├─ Controllers/AiDelegationController.php    # AI 授权中心（令牌 / 审批）
│  └─ Middleware/AiApiAuth.php        # X-AI-Key 认证 + X-AI-Token 委托身份解析
├─ Models/AiSetting.php / AiDocTemplate.php / AiSkill.php
├─ Models/AiDelegationToken.php / AiChangeProposal.php   # 委托授权与变更提案
├─ Services/AiDelegationService.php   # 委托鉴权（单一可信权限源）
├─ Services/AiChangeProposalService.php # 提案预览 / 确认执行 / 驳回
└─ Services/AiService.php             # 桥接 DB 配置 ↔ Laravel AI SDK
resources/views/ai/                   # welcome / chat / settings / docs
resources/views/ai-delegation/        # AI 授权中心
routes/api.php                         # /api/ai/*
config/ai.php                          # laravel/ai 提供商配置
storage/app/ai_docs/templates/         # 文档模板 / 范例文件
```

---

## 八、维护与更新记录

- 本次新增：集成 `laravel/ai`（v1.2.3），AI 智能助手 + AI 数据 API + 文档生成 Skill，详见提交 `4fe0fe7`。
- 部署注意事项：新增 composer 依赖后服务器必须执行 `composer install`，新增迁移后执行 `php artisan migrate --force`。
