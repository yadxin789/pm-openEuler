# PM 项目管理系统 —— openEuler 社区版部署详细步骤

> 适用对象：PM 项目管理系统（Laravel 12 + PHP 8.3 + MySQL 8 + Nginx / Apache）
> 本步骤已在项目内完成 PHP 8.4 → PHP 8.3 依赖降级、SQLite → MySQL 切换，并针对 MySQL 修复了迁移兼容性问题（详见文末附录）。
> 部署前请先确认服务器已安装 openEuler 24.03 LTS 及以上版本社区版系统（其官方软件仓库自带 PHP 8.3.x）。
> openEuler 自带 Apache（httpd），可直接选用 Apache 部署，无需安装 Nginx（两种方案见第七节）。

---

## 一、环境要求总览

| 组件 | 版本要求 | 说明 |
|------|---------|------|
| 操作系统 | openEuler 24.03 LTS（SP1 及以上推荐） | 社区版仓库自带 PHP 8.3.x |
| PHP | **8.3.x（最低 8.3.0）** | 项目 composer.json 已声明 `php: ^8.3`，低于 8.3 无法安装依赖 |
| Web 服务器 | Nginx 1.20+（推荐）或 Apache httpd 2.4+（openEuler 自带） | 两者均可配合 PHP-FPM 运行 Laravel |
| 数据库 | MySQL 8.0+ / 兼容（MariaDB 10.6+） | 数据库名 `pm_db`，账号 root |
| Composer | 2.x | 依赖管理 |
| Node.js | 18+（推荐 20 LTS） | 前端 Vite 6 构建 |
| Redis（可选） | 任意 | 项目默认不使用 Redis（缓存/队列/会话均走数据库），未配置可忽略 |

---

## 二、openEuler 系统准备

### 1. 确认系统版本

```bash
cat /etc/openEuler-release
# 示例输出：openEuler release 24.03 (LTS)
```

> 说明：openEuler 24.03 LTS 官方仓库的 PHP 版本为 8.3.x（如 8.3.17，SP3/SP4 已更新至 8.3.33）。
> 若使用 22.03 及更早版本，仓库内 PHP 版本低于 8.3，请升级系统或改用源码编译 PHP 8.3（本步骤不展开）。

### 2. 更新系统与软件源

```bash
dnf update -y
dnf install -y epel-release          # 如需要补充源；若报不存在可跳过
```

---

## 三、安装 PHP 8.3 及扩展

```bash
dnf install -y php php-cli php-fpm php-common \
    php-mysqlnd php-mbstring php-xml php-gd php-zip \
    php-intl php-bcmath php-curl php-openssl php-process \
    php-json php-simplexml php-dom
```

> `php-json`、`php-dom`、`php-simplexml` 在较新版本中可能已合并进 `php-common`，安装时若提示“未找到软件包”可忽略对应项（说明已内置）。

### 验证版本与扩展

```bash
php -v        # 必须显示 PHP 8.3.x
php -m | grep -iE "pdo_mysql|mbstring|gd|zip|fileinfo|openssl|curl|intl"
```

关键扩展核对表（项目 composer 平台要求）：

| 扩展 | 用途 |
|------|------|
| pdo_mysql / mysqlnd | MySQL 数据库连接（**必需**） |
| mbstring | 字符串处理（polyfill 可兜底，建议安装） |
| gd | 图片处理（intervention/image） |
| fileinfo | 文件类型识别 |
| zip / zlib | Excel 导出（maatwebsite/excel）与文件上传 |
| openssl / curl | 加密、HTTP 客户端 |
| intl | 国际化（可选） |
| bcmath | 高精度计算（可选） |

---

## 四、安装 MySQL 8 并初始化

### 1. 安装

```bash
dnf install -y mysql-server mysql
systemctl enable --now mysqld
systemctl status mysqld        # 确认 active (running)
```

> `mysql` 包提供 `mysqldump` 命令行客户端，后台"数据库备份"功能依赖它。

### 2. 设置 root 密码并创建数据库

```bash
# 首次安装 root 默认无密码，直接登录
mysql -uroot

# 在 mysql> 中执行：
ALTER USER 'root'@'localhost' IDENTIFIED BY 'root';
CREATE DATABASE IF NOT EXISTS pm_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
FLUSH PRIVILEGES;
EXIT;
```

> ⚠️ 本部署文档按项目要求使用 root 密码 `root`、数据库名 `pm_db`。
> **生产环境强烈建议**：创建专用低权限账号（如 `pm_user`）并只授权 `pm_db`，不要使用 root 连接业务库。

### 3. 验证

```bash
mysql -uroot -proot -e "SHOW DATABASES;"   # 应包含 pm_db
```

---

## 五、安装 Composer、Node.js（Web 服务器见第七节，二选一）

### 1. Nginx（可选：若选用 Apache 可跳过本节）

```bash
dnf install -y nginx
systemctl enable --now nginx
```

> openEuler 自带 Apache（httpd），如无特殊要求可直接使用 httpd 部署（配置见第七节 7.3），本节的 Nginx 安装可跳过。

### 2. Composer

```bash
php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
php composer-setup.php
php -r "unlink('composer-setup.php');"
mv composer.phar /usr/local/bin/composer
composer --version
```

### 3. Node.js（用于前端资源构建）

```bash
node -v   # 要求 ≥ 18，推荐 20+
```

若仓库自带版本过低，使用 nvm 安装 Node 20 LTS：

```bash
curl -o- https://raw.githubusercontent.com/nvm-sh/nvm/v0.39.7/install.sh | bash
source ~/.bashrc
nvm install 20
nvm use 20
```

---

## 六、部署项目代码

### 1. 上传代码

将项目代码（可排除 `node_modules`、`vendor`，部署机上重新安装）上传到服务器。以下两种方式任选其一：

#### 方式一：U 盘直传（U 盘插服务器本地 USB 口）

> ⚠️ U 盘必须插在 **openEuler 服务器本机的 USB 口**上。远程终端只能操作服务器自己的文件系统，看不到插在你远程电脑上的 U 盘。

```bash
mkdir -p /var/www/pm

lsblk                        # 查看 U 盘设备名，通常是 /dev/sdb1 或 /dev/sda1
mkdir -p /mnt/usb
mount /dev/sdb1 /mnt/usb     # 把设备名换成上面 lsblk 查到的实际值
ls /mnt/usb                  # 确认能看到 pm.zip

unzip /mnt/usb/pm.zip -d /var/www/pm

umount /mnt/usb              # 卸载后即可拔出 U 盘
```

> U 盘格式说明：FAT32、exFAT（openEuler 24.03 内核原生支持）直接可挂载；若为 NTFS 格式，需先安装 `dnf install -y ntfs-3g`（若官方仓库没有该包，请改用方式二）。

#### 方式二：scp / SFTP 网络传输（推荐）

U 盘插在你的电脑（远程机）上，通过 SSH 把压缩包传到服务器，然后解压：

```bash
# 在你自己的电脑终端执行（Windows 可用 CMD/PowerShell/WinSCP/FileZilla）
scp E:\路径\pm.zip root@服务器IP:/tmp/
```

然后在服务器远程终端执行：

```bash
mkdir -p /var/www/pm
unzip /tmp/pm.zip -d /var/www/pm
```

> 方式二优点：不依赖 U 盘格式与挂载权限，跨网络直传且全程 SSH 加密；生产环境推荐使用。git 部署时则直接 `git clone` 到 `/var/www/pm` 即可。

#### 解压后检查目录结构

```bash
ls /var/www/pm        # 应直接看到 composer.json、app/、config/、public/ 等项目根文件
```

> ⚠️ 若看到的是外层多套了一层文件夹（如 `/var/www/pm/pm-server-openEuler/...`），说明压缩包根目录多包了一层，把内容移到正确位置：

```bash
mv /var/www/pm/pm-server-openEuler/* /var/www/pm/
rm -rf /var/www/pm/pm-server-openEuler
```

### 2. 配置 .env

```bash
cd /var/www/pm
cp .env.example .env
mcedit .env
```

关键配置项（数据库部分项目已默认写好，按实际环境核对）：

```ini
APP_NAME=Laravel
APP_ENV=production
APP_KEY=                # 下一步执行 php artisan key:generate 自动生成
APP_DEBUG=false
APP_URL=http://你的域名或IP

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=pm_db
DB_USERNAME=root
DB_PASSWORD=root

SESSION_DRIVER=database
QUEUE_CONNECTION=database
CACHE_STORE=database

# 企业微信配置（可选，部署时填写）
WECOM_CORP_ID=
WECOM_AGENT_ID=
WECOM_SECRET=
WECOM_TOKEN=
WECOM_AES_KEY=

# OnlyOffice 协同编辑（可选；部署 OnlyOffice 后按第九节填写，未部署可保持默认）
ONLYOFFICE_SERVER_URL=http://localhost:8080
ONLYOFFICE_JWT_SECRET=
ONLYOFFICE_PM_INTERNAL_URL=http://host.docker.internal
```

> 未配置企业微信不影响系统核心功能；OnlyOffice 未部署时文档协作功能不可用，部署方法见第九节。

### 3. 生成应用密钥

```bash
php artisan key:generate
```

### 4. 安装 PHP 依赖

```bash
composer install --no-dev --optimize-autoloader
```

> 项目 composer.json 已配置 `platform.php = 8.3.0`，`composer.lock` 已锁定全部 PHP 8.3 兼容版本（Symfony 7.4.x、spatie/laravel-model-states 2.12.1 等）。
> 安装时若提示平台版本不符，请先确认 `php -v` 为 8.3.x。

### 5. 设置目录权限

```bash
chown -R nginx:nginx /var/www/pm
chmod -R 775 /var/www/pm/storage /var/www/pm/bootstrap/cache
```

> PHP-FPM 以 `nginx` 用户运行（两种 Web 方案通用，见第七节），确保 `storage/`、`bootstrap/cache/` 对该用户可写。

### 6. 执行数据库迁移与初始化数据

```bash
php artisan migrate --seed --force
```

- 将创建全部数据表（含 `sessions`、`jobs`、`cache` 等系统表）并写入默认角色、部门、任务类型和管理员账号（UserSeeder）。
- 如再次执行，使用 `php artisan migrate:fresh --seed`（**会清空数据，慎用**）。

### 7. 建立公共存储软链接

```bash
php artisan storage:link
```

### 8. 前端资源构建

```bash
npm install
npm run build
```

> 构建产物输出到 `public/build`，生产环境无需再运行 `npm run dev`。

### 9. 清理并生成缓存

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

### 10. 验证

```bash
php artisan about        # 应显示 PHP Version 8.3.x、Database: mysql
php artisan migrate:status   # 全部显示 Ran（含 2026_09_18_000000_add_foreign_keys_for_mysql）
```

---

## 七、Web 服务器配置（Nginx / Apache 二选一）

> 两种方案都依赖 PHP-FPM（见 7.1），差异只在 Web 服务器本身。openEuler 自带 Apache（httpd），可直接使用方案 B；如需 Nginx 则用方案 A。

### 1. PHP-FPM（两种方案通用）

openEuler 安装 `php-fpm` 后默认池为 `www`，确认配置：

```bash
mcedit /etc/php-fpm.d/www.conf
# 关键项：
#   user = nginx
#   group = nginx
#   listen = /run/php-fpm/www.sock
systemctl enable --now php-fpm
```

按需调整 `php.ini`（上传大小需 ≥ 项目 `PM_MAX_UPLOAD_SIZE=20480`KB=20MB）：

```ini
; /etc/php.ini
upload_max_filesize = 25M
post_max_size = 26M
memory_limit = 256M
max_execution_time = 120
```

### 2. 方案 A：Nginx 站点配置

创建 `/etc/nginx/conf.d/pm.conf`：

```nginx
server {
    listen 80;
    server_name pm.example.com;          # 改成你的域名或服务器 IP
    root /var/www/pm/public;
    index index.php;

    client_max_body_size 25m;            # 与上传大小匹配

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php-fpm/www.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

```bash
nginx -t
systemctl reload nginx
```

> 如启用 HTTPS，请为 443 端口增加 `ssl_certificate`/`ssl_certificate_key` 并配置跳转。

### 3. 方案 B：Apache（httpd）站点配置（openEuler 自带）

openEuler 自带 Apache（httpd），无需额外安装；如未安装：

```bash
dnf install -y httpd
systemctl enable --now httpd
```

#### 3.1 确认所需模块

```bash
httpd -M | grep -E "rewrite|proxy_fcgi|setenvif"
# 期望输出包含：rewrite_module、proxy_fcgi_module、setenvif_module
# 缺少时在 /etc/httpd/conf.modules.d/ 下启用（默认发行版已带这些模块）
```

#### 3.2 虚拟主机配置

创建 `/etc/httpd/conf.d/pm.conf`：

```apache
<VirtualHost *:80>
    ServerName pm.example.com          # 改成你的域名或服务器 IP
    DocumentRoot /var/www/pm/public

    <Directory /var/www/pm/public>
        Options -Indexes +FollowSymLinks
        AllowOverride All              # 允许 public/.htaccess 生效（Laravel 路由重写依赖）
        Require all granted
    </Directory>

    # 将 .php 请求转发给 PHP-FPM（unix socket，与 www.conf 的 listen 一致）
    <FilesMatch \.php$>
        SetHandler "proxy:unix:/run/php-fpm/www.sock|fcgi://localhost"
    </FilesMatch>

    # 限制上传大小（与 php.ini 一致）
    LimitRequestBody 26214400

    ErrorLog logs/pm-error.log
    CustomLog logs/pm-access.log combined
</VirtualHost>
```

```bash
apachectl configtest
systemctl reload httpd
```

> 说明：
> - Laravel 的路由重写依赖 `public/.htaccess`（项目已自带），所以必须设置 `AllowOverride All` 且启用 `mod_rewrite`。
> - Apache（httpd）进程只需对 `/var/www/pm/public` 有**读**权限即可；写入 `storage/`、`bootstrap/cache/` 由 PHP-FPM（`nginx` 用户）完成，无需改动 httpd 用户。
> - 若想走 TCP 方式转发 PHP，可将 `SetHandler` 改为 `"proxy:fcgi://127.0.0.1:9000"`，并把 `www.conf` 的 `listen` 改为 `127.0.0.1:9000`（二选一，不要同时）。

#### 3.3 启用 HTTPS（可选）

```bash
dnf install -y mod_ssl
# 在 pm.conf 增加 443 VirtualHost，配置 SSLCertificateFile / SSLCertificateKeyFile
```

### 4. SELinux 放行（openEuler 默认 enforcing，两种方案通用）

openEuler 默认开启 SELinux（Enforcing），需为项目目录与 PHP-FPM 通信放行，否则会出现 403/500：

```bash
# 安装管理工具（如已装可跳过）
dnf install -y policycoreutils-python-utils

# 项目目录：httpd/nginx 可读（public），storage/、bootstrap/cache/ 可读写（php-fpm 写入）
semanage fcontext -a -t httpd_sys_content_t "/var/www/pm(/.*)?"
semanage fcontext -a -t httpd_sys_rw_content_t "/var/www/pm/storage(/.*)?"
semanage fcontext -a -t httpd_sys_rw_content_t "/var/www/pm/bootstrap/cache(/.*)?"
restorecon -Rv /var/www/pm

# 允许 Web 服务器通过 fcgi 连接 PHP-FPM（socket 或 127.0.0.1:9000）
setsebool -P httpd_can_network_connect 1

# 若数据库为远程/本机 TCP 连接，需放行（本地 unix socket 通常不需要）
# setsebool -P httpd_can_network_connect_db 1

# 验证
getenforce    # Enforcing
```

> 若项目目录不在 `/var/www`（如 `/opt/pm`），将上述 `semanage fcontext` 路径替换为实际路径。

---

## 八、后台任务配置（队列与定时调度）

项目依赖队列（消息/通知）与定时任务（如逾期检查），必须常驻运行：

### 生产环境运行完整步骤（systemd + cron + npm run build）

> 生产环境**不运行** start.sh（它含 Vite 开发服务器、进程依附终端，仅开发用，见 8.3）。
> 生产推荐组合：**`npm run build` 构建前端 + systemd 常驻队列 Worker + crontab 触发定时调度**（即 6.8 + 8.1 + 8.2 的完整串联）。以下为完整操作步骤，按第六节部署完成后顺序执行。

**第 1 步：构建前端生产资源**

```bash
cd /var/www/pm
npm install            # 首次部署或 package.json 变更后执行
npm run build          # 生成 public/build 下的静态资源与 manifest.json
ls -l public/build/manifest.json   # 确认构建产物已生成
```

> 构建后生产环境由 Web 服务器直接提供 `public/build` 静态资源（第七节 root 指向 public，无需额外配置）。
> **禁止在生产环境运行 `npm run dev`**（Vite 开发服务器不适用于生产）。

**第 2 步：创建 systemd 队列服务（常驻消费 jobs 表）**

创建 `/etc/systemd/system/pm-queue.service`：

```ini
[Unit]
Description=PM Queue Worker
After=network.target mysqld.service php-fpm.service

[Service]
User=nginx
Group=nginx
WorkingDirectory=/var/www/pm
ExecStart=/usr/bin/php artisan queue:work --tries=3 --sleep=3
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

```bash
systemctl daemon-reload
systemctl enable --now pm-queue
systemctl status pm-queue          # 应显示 active (running)
journalctl -u pm-queue -n 50       # 查看队列运行日志
```

> 关键项说明：
> - `User=nginx` 必须与 7.1 中 PHP-FPM 运行用户一致（写入 storage/ 的权限依赖它）；
> - `ExecStart` 使用 `/usr/bin/php`（openEuler 上 dnf 安装的 PHP 路径）；
> - `Restart=always` 保证异常退出自动拉起；
> - `After` 中写 `mysqld.service`（与第四节安装的服务名一致；若实际数据库服务名不同，如 MariaDB 的 `mariadb.service`，请改为对应服务名）。

**第 3 步：配置 crontab 定时调度（每分钟触发 schedule:run）**

> ⚠️ 调度条目必须写入应用运行用户 **pmadmin**（PHP-FPM 用户）的 crontab，PM 系统的"后台服务状态"页面才会显示定时任务已启动。
> 直接使用 `crontab -e` 编辑的是 **root** 的 crontab，条目虽能执行，但页面自检（pmadmin 身份执行 `crontab -l`）看不到，会误报"已停止"。

```bash
crontab -u pmadmin -e
# 加入一行：
* * * * * cd /var/www/pm && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

```bash
crontab -u pmadmin -l          # 确认已写入
```

**第 4 步：确认调度任务已注册**

```bash
cd /var/www/pm
php artisan schedule:list
# 应看到：projects:refresh-status   自动刷新项目状态、任务状态、进度和统计数据（每天 00:00）
```

> 本项目在 `routes/console.php` 注册了 `projects:refresh-status`（每天 00:00 自动刷新项目/任务状态与统计），依赖 cron 每分钟触发才会执行。

**第 5 步：确认数据库驱动配套表存在**

项目队列/会话/缓存均使用 database 驱动（见 6.2 的 .env），迁移已自动创建对应表，确认即可：

```bash
mysql -uroot -proot -e "USE pm_db; SHOW TABLES LIKE 'jobs'; SHOW TABLES LIKE 'sessions'; SHOW TABLES LIKE 'cache'; SHOW TABLES LIKE 'failed_jobs';"
```

**第 6 步：设置开机自启（一次）**

```bash
systemctl enable --now mysqld php-fpm pm-queue
systemctl enable --now httpd        # 或 nginx，按第七节所选方案
```

**第 7 步：整体验证**

```bash
# ① 页面访问：浏览器打开 http://服务器IP，应正常显示登录页
# ② 队列消费验证：在系统内发一条通知/消息后，jobs 表应很快被消费清空
mysql -uroot -proot -e "USE pm_db; SELECT COUNT(*) AS pending_jobs FROM jobs;"
# ③ 定时调度验证：手动触发一次，无报错即可
php artisan schedule:run
```

> **项目匹配性说明（已核对，无需额外改造）**：本项目队列驱动=database、会话/缓存=database（无需 Redis）；`jobs`/`failed_jobs`/`sessions`/`cache` 表已由迁移创建；`routes/console.php` 已注册定时任务 `projects:refresh-status`；`package.json` 提供 `build` 脚本（vite build），`public/build` 构建产物已生成。**完全匹配 systemd + cron + npm run build 的生产运行方式**。

### 1. 队列 Worker（systemd 服务）

创建 `/etc/systemd/system/pm-queue.service`：

```ini
[Unit]
Description=PM Queue Worker
After=network.target mysqld.service php-fpm.service

[Service]
User=nginx
Group=nginx
WorkingDirectory=/var/www/pm
ExecStart=/usr/bin/php artisan queue:work --tries=3 --sleep=3
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

```bash
systemctl enable --now pm-queue
systemctl status pm-queue
```

### 2. 定时调度（cron）

> ⚠️ 必须用 `-u pmadmin` 指定应用运行用户；不要用 `crontab -e`（那是编辑 root 的 crontab，会导致 PM 系统服务状态页面检测不到调度配置而误报"已停止"）。

```bash
crontab -u pmadmin -e
# 加入一行（每分钟触发 Laravel 调度器）：
* * * * * cd /var/www/pm && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

### 3. 开发环境一键启动脚本（可选，start.sh）

> 项目根目录自带的 `start.bat` 是 **Windows 批处理脚本**，无法在 openEuler 上运行。
> 项目已同时提供 Linux 版 **`start.sh`**（位于项目根目录），功能与 start.bat 完全一致：
> 同时启动队列 Worker（`queue:work`）、定时调度器（`schedule:work`）、Vite 开发服务器（`npm run dev`），按 Ctrl+C 停止所有服务。

**使用方法**：

```bash
cd /var/www/pm
chmod +x start.sh
./start.sh
```

**脚本内容**（start.sh，与项目根目录文件一致）：

```bash
#!/usr/bin/env bash
# ============================================================================
#  PM 项目管理系统 - 开发环境启动脚本（openEuler / Linux）
#
#  与 Windows 版 start.bat 功能一致，同时启动以下服务：
#    1. Queue Worker    (php artisan queue:work --tries=3 --sleep=3)
#    2. Task Scheduler  (php artisan schedule:work)
#    3. Vite Dev Server (npm run dev)
#  按 Ctrl+C 可停止所有服务；任一服务异常退出时其余服务也会自动停止。
#
#  使用方法（服务器上执行）：
#    chmod +x start.sh
#    ./start.sh
#
#  注意：
#    - 若从 Windows 复制本文件到服务器后执行报错（换行符问题），先执行：
#        sed -i 's/\r$//' start.sh
#    - 本脚本仅用于【开发环境】。生产环境部署请按本部署文档
#      使用 systemd 托管队列（第八节）、crontab 执行定时调度、
#      npm run build 构建前端静态资源。
# ============================================================================

set -u

# 切换到脚本所在目录（兼容从任意目录执行）
cd "$(dirname "$0")" || { echo "[错误] 无法进入项目目录"; exit 1; }

echo "============================================"
echo " PM 项目管理系统 - 启动中..."
echo "============================================"
echo ""
echo "正在启动以下服务:"
echo "  1. Queue Worker (php artisan queue:work)"
echo "  2. Task Scheduler (php artisan schedule:work)"
echo "  3. Vite Dev Server (npm run dev)"
echo ""
echo "按 Ctrl+C 停止所有服务"
echo "============================================"
echo ""

# ---------- 前置检查 ----------

# 检查 php
if ! command -v php >/dev/null 2>&1; then
    echo "[错误] 未找到 php 命令，请先安装 PHP 8.3（见部署文档第三节）"
    exit 1
fi

# 检查 npm
if ! command -v npm >/dev/null 2>&1; then
    echo "[错误] 未找到 npm 命令，请先安装 Node.js（见部署文档第五节）"
    exit 1
fi

# 检查 vendor（php artisan 依赖）
if [ ! -f "vendor/autoload.php" ]; then
    echo "[错误] vendor 目录不存在，请先执行: composer install"
    exit 1
fi

# 检查 npm 依赖
if [ ! -d "node_modules" ]; then
    echo "[警告] node_modules 不存在，正在运行 npm install..."
    if ! npm install; then
        echo "[错误] npm install 执行失败"
        exit 1
    fi
fi

# ---------- 启动三个服务（后台运行） ----------

php artisan queue:work --tries=3 --sleep=3 &
QUEUE_PID=$!

php artisan schedule:work &
SCHEDULER_PID=$!

npm run dev &
VITE_PID=$!

echo "服务已启动: queue=$QUEUE_PID  scheduler=$SCHEDULER_PID  vite=$VITE_PID"
echo ""

# ---------- 停止与守护逻辑 ----------

# 捕获 Ctrl+C / 终止信号，停止所有子进程
cleanup() {
    echo ""
    echo "正在停止所有服务..."
    kill "$QUEUE_PID" "$SCHEDULER_PID" "$VITE_PID" 2>/dev/null
    wait 2>/dev/null
    echo "所有服务已停止"
    exit 0
}
trap cleanup INT TERM

# 前台循环守护：任一服务退出则整体停止
while true; do
    for pid in "$QUEUE_PID" "$SCHEDULER_PID" "$VITE_PID"; do
        if ! kill -0 "$pid" 2>/dev/null; then
            echo ""
            echo "[提示] 检测到服务进程已退出 (pid=$pid)，正在停止其余服务..."
            kill "$QUEUE_PID" "$SCHEDULER_PID" "$VITE_PID" 2>/dev/null
            wait 2>/dev/null
            exit 1
        fi
    done
    sleep 2
done
```

> 说明：
> - 相比 start.bat，start.sh 增加了前置检查（php/npm/vendor 是否存在）与"任一服务退出即停止其余服务"的守护逻辑，避免开发时某个进程挂掉后留下孤儿进程。
> - **生产环境不要用 start.sh**：它包含 Vite 开发服务器（`npm run dev`），且进程依附于当前终端；生产请用 8.1 的 systemd 服务 + 8.2 的 crontab，前端资源用 `npm run build` 构建（见第六节 6.8）。
> - 从 Windows 复制到服务器后如执行报错，先执行 `sed -i 's/\r$//' start.sh` 清除 Windows 换行符。

---

## 九、协同办公 OnlyOffice Document Server 安装部署

> PM 系统的"项目协同办公"功能基于开源软件 **ONLYOFFICE Document Server（社区版）**：
> 用户在浏览器内通过 iframe 嵌入的 DocsAPI 在线协同编辑 docx / xlsx / pptx 文档，保存时由 Document Server 回调 PM 系统落盘。
> 本项目的集成代码（`config/collab.php`、`app/Services/CollabOfficeService.php`、`CollabDocumentController`）已就绪，只需部署 Document Server 并配置 3 个环境变量即可启用。

### 9.1 架构与三条连通性（务必先理解）

```
┌──────────────┐  ①浏览器加载编辑器UI    ┌─────────────────────┐
│  用户浏览器   │ ──────────────────────→ │  OnlyOffice DS      │
│              │ ←────────────────────── │  容器/服务器 (8080)  │
└──────────────┘    ②DS 服务器下载文档    └──────────┬──────────┘
        │                                            │ ③DS 服务器保存回调
        │  PM 应用 (Apache/Nginx :80)  ←─────────────┘
        └──────────────────────────────────────────────────┘
```

| 连通方向 | 地址 | 由哪个配置决定 | 说明 |
|---------|------|---------------|------|
| ① 浏览器 → Document Server | `http://服务器IP:8080` | `ONLYOFFICE_SERVER_URL` | 浏览器直接访问 DS 的界面与 api.js，**必须在浏览器可达** |
| ②③ Document Server → PM 系统 | `http://host.docker.internal`（容器内访问宿主机）或 `http://宿主机IP` | `ONLYOFFICE_PM_INTERNAL_URL` | DS 服务器（服务器到服务器）下载文档与保存回调，**必须从 DS 侧可达 PM**，无需登录态（路由已用 `doc_key` 校验并绕过 CSRF） |

> ⚠️ 两个地址角色不同：`server_url` 是给**浏览器**用的；`pm_internal_url` 是给 **Document Server 服务器**用的，不要填反。
> 若浏览器通过 HTTPS 访问 PM，Document Server 也必须是 HTTPS（否则 iframe 内 http 资源被浏览器拦截），见 9.4。

### 9.2 安装 Docker（如未安装）

```bash
dnf install -y docker
systemctl enable --now docker
docker --version
```

> openEuler 社区版软件源提供 Docker。Document Server 官方推荐以容器方式部署，也最省事（自带 nginx、redis、rabbitmq、postgresql 等内部依赖）。

### 9.3 部署 Document Server 容器

**硬件要求**：最低 2 vCPU / 2GB 内存 / 10GB 磁盘；建议 4 vCPU / 4GB 内存。

```bash
# 生成一个固定 JWT 密钥（也可自己写一串足够长的随机字符串，记下来备用）
JWT_SECRET=$(openssl rand -hex 32)
echo $JWT_SECRET

docker run -i -t -d --name onlyoffice \
    --restart=always \
    -p 8080:80 \
    --add-host host.docker.internal:host-gateway \
    -e JWT_ENABLED=true \
    -e JWT_SECRET="$JWT_SECRET" \
    onlyoffice/documentserver:latest
```

参数说明：

| 参数 | 作用 |
|------|------|
| `-p 8080:80` | 宿主机 8080 端口映射容器内 80 |
| `--add-host host.docker.internal:host-gateway` | 让容器内 `host.docker.internal` 指向宿主机（PM 系统所在），供保存回调使用 |
| `-e JWT_ENABLED=true -e JWT_SECRET=...` | 开启 Document Server 的 JWT 签名，**该密钥必须与 PM 的 `.env` 中 `ONLYOFFICE_JWT_SECRET` 完全一致** |
| `--restart=always` | 开机自启、异常自动拉起（相当于 systemd 托管） |

> ⚠️ **`host.docker.internal` 必须指向宿主机（PM 所在机器），而不是默认网关**：
> - 用 `host-gateway`（Docker ≥20.10）最稳妥，或显式写 `172.17.0.1`（docker0 网桥宿主侧 IP）；
> - **禁止**用 `--add-host host.docker.internal:<默认网关IP>`（`ip route show default` 取到的通常是路由器，如 `192.168.2.1`）。若容器内 `cat /etc/hosts` 看到 `host.docker.internal` 解析为路由器 IP，DS 下载/保存回调会打到路由器，浏览器端表现为**打开文档弹"下载失败"**（容器内 `curl http://host.docker.internal/` 返回的是路由器页面而非 PM 页面）；
> - 验证：`docker exec onlyoffice cat /etc/hosts | grep host.docker`。

**安装中文字体**（否则中文显示为方框乱码）：

```bash
docker exec -it onlyoffice bash -c "apt-get update && apt-get install -y fonts-wqy-zenhei fonts-wqy-microhei && rm -rf /var/lib/apt/lists/*"
docker restart onlyoffice
```

**验证安装**：

```bash
curl -s http://127.0.0.1:8080/healthcheck      # 输出 true 即正常
# 浏览器访问 http://服务器IP:8080/welcome 能看到欢迎页
```

### 9.4 防火墙与 HTTPS

```bash
firewall-cmd --permanent --add-port=8080/tcp
firewall-cmd --reload
```

**HTTPS 建议**：Document Server 官方要求生产环境使用 HTTPS。两种做法：

- 方式一（简单）：内网部署且 PM 本身也是 HTTP，则两者都保持 HTTP 即可；
- 方式二（推荐，公网）：给 Document Server 加一层 TLS 反向代理（Nginx 443 → 127.0.0.1:8080），并把 PM 也切换到 HTTPS，保证浏览器与 PM、与 DS 全部 HTTPS。

```nginx
# /etc/nginx/conf.d/onlyoffice-ssl.conf（若使用方案 A 的 Nginx）
server {
    listen 443 ssl;
    server_name office.example.com;
    ssl_certificate     /etc/nginx/ssl/office.crt;
    ssl_certificate_key /etc/nginx/ssl/office.key;
    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_redirect off;
        client_max_body_size 25m;
    }
}
```

> 使用 Apache 时同理：`dnf install -y mod_ssl`，在 443 VirtualHost 中用 `ProxyPass / http://127.0.0.1:8080/`（需启用 `proxy_http` 模块）。

### 9.5 配置 PM 系统的 .env

编辑 `/var/www/pm/.env`（对应 `config/collab.php` 的三个配置项）：

```ini
# 协同办公 OnlyOffice 配置
ONLYOFFICE_SERVER_URL=http://服务器IP或域名:8080     # 浏览器访问 DS 的地址（若配了 HTTPS 反代则填 https 地址）
ONLYOFFICE_JWT_SECRET=<与 9.3 容器启动时 JWT_SECRET 一致>
ONLYOFFICE_PM_INTERNAL_URL=http://192.168.1.10   # DS 服务器访问 PM 的地址；PM 与 DS 同机时直接用宿主机 IP 最稳（PM 在 80 端口时无需带端口）
```

配置要点：

- `ONLYOFFICE_JWT_SECRET` **必须与 Document Server 的 JWT_SECRET 完全一致**，否则打开编辑器会报 JWT 校验失败（错误码 401）；
- `ONLYOFFICE_PM_INTERNAL_URL` 是 Document Server 侧访问 PM 的**基础地址**（不带路径），系统会自动拼接 `/collab/download/{id}?k=...` 与 `/collab/callback/{id}?k=...`：
  - **PM 与 DS 在同一台机器（最常见）→ 直接用宿主机 IP，如 `http://192.168.1.10`（最稳，不依赖容器 DNS 映射）**；也可用 `http://host.docker.internal`（需 9.3 的 `--add-host host-gateway` 正确指向宿主机，**不能解析到路由器**）；
  - Document Server 原生安装（非容器）→ 用 `http://127.0.0.1`；
  - PM 与 DS 不在同一台机器 → 填 PM 所在机器的内网 IP，如 `http://192.168.1.10`；
- ⚠️ **常见坑（打开文档弹"下载失败"）**：
  1. `ONLYOFFICE_PM_INTERNAL_URL` 误填为带端口（如 `http://host.docker.internal:8000`）而 PM 实际由 Apache 监听 80 端口 → 该端口无服务，下载/回调必然失败。PM 走 80 端口部署时该值**不要带端口**，或端口必须与实际监听一致；
  2. `host.docker.internal` 被解析到**默认网关（路由器）**而非宿主机 → 容器内 `curl http://host.docker.internal/` 返回路由器页面而非 PM 页面。排查：`docker exec onlyoffice cat /etc/hosts | grep host.docker`；修复见 9.3 警告；
- `deploy.sh` 第 11.6 步已做自动修正：检测到 `.env` 中该值带端口时自动去掉端口；无既有值则默认写入宿主机 IP（`http://<服务器IP>`）；
- 修改后若已执行过 `config:cache`，需重新生成缓存：`php artisan config:cache`。

### 9.6 使用验证

1. 登录 PM 系统，进入某项目 → 协同办公 → 新建文档（模板新建 docx/xlsx/pptx 或上传）→ 自动进入协同编辑页；
2. 编辑器正常加载并可多人同时编辑；
3. 修改内容后保存（自动保存/强制保存），返回文档列表确认版本号 +1；
4. 查看 `storage/app/file-repository/collab/<项目ID>/` 下生成最新文件，`history/` 下保留历史版本。

> 协同文档本体与历史版本保存在 `storage/app/file-repository/collab/`，**数据库备份之外需同步备份该目录**（或纳入第十一节的全量备份范围）。

### 9.7 常见问题

| 现象 | 可能原因 | 处理 |
|------|---------|------|
| 编辑器加载失败（api.js 加载被拦） | 浏览器 PM 用 HTTPS、DS 用 HTTP，混合内容 | 给 DS 加 HTTPS 反代（9.4），或两者统一 HTTP |
| 打开编辑器报 JWT/401 错误 | JWT 密钥不一致 | 核对 `ONLYOFFICE_JWT_SECRET` 与容器 `JWT_SECRET` 完全一致 |
| 打开文档弹"下载失败" | ① `ONLYOFFICE_PM_INTERNAL_URL` 带端口而 PM 实际监听 80（端口无服务） | ① 改为不带端口（如 `http://192.168.1.10`）后 `php artisan config:clear`；容器内验证 `docker exec onlyoffice curl -sf http://宿主机IP/` |
| 打开文档弹"下载失败" | ② `host.docker.internal` 被解析到默认网关（路由器）而非宿主机 | ② `docker exec onlyoffice cat /etc/hosts \| grep host.docker` 检查；重建容器时用 `host-gateway` 或 `172.17.0.1`（见 9.3 警告）；或 PM_INTERNAL_URL 直接改用宿主机 IP |
| 能打开但保存后版本不变 | 回调失败：DS 容器访问不到 PM | 检查 `pm_internal_url` 是否从容器内可达（`docker exec onlyoffice curl http://host.docker.internal/collab/callback/1`）；确认 `--add-host` 已加 |
| 中文显示为方块 | 容器缺中文字体 | 执行 9.3 的中文字体安装并 `docker restart onlyoffice` |
| 多人同时编辑被拒 | 社区版同时连接数上限（20） | 超出请升级商业版/企业版，或错峰使用 |
| `healthcheck` 返回 false | 容器未就绪或内存不足 | `docker logs onlyoffice` 查看日志；确保内存 ≥2GB |
| 编辑器 UI（菜单/工具栏）正常但文档内容区空白，无错误弹窗 | `open.blade.php` 嵌套在 PM 布局里，占位容器高度 `calc(100vh - 140px)` 在初始化时计算异常，导致 iframe 高度不足、文档画布未渲染 | 协同编辑页已改为**独立全屏页**：`html/body` 100% + flex 布局（顶部 48px 工具条，`#placeholder` 占满剩余空间），`window load` 后再初始化 `DocsAPI.DocEditor`，窗口缩放时调用 `editor.resize()`；该写法已在源码 `resources/views/collab/open.blade.php` 内置，重新部署自动生效 |
| 浏览器控制台出现 `[Violation] unload is not allowed` 警告 | Chrome/Edge 权限策略默认禁止 **iframe 内**的 unload 事件（ONLYOFFICE 内部脚本注册所致，DS 官方示例页直接访问不触发） | **无害，可忽略**：不影响编辑与保存（保存走 HTTP 回调 `callbackUrl`，与 unload 无关）；如需验证功能，实际编辑保存后重新打开确认内容保留 |

> 说明：Document Server 社区版为免费开源，**最多支持 20 个同时编辑连接**；如需更高并发可购买商业授权或改用其他协同组件。

---

## 十、生产环境安全与上线检查清单

- [ ] `APP_ENV=production`、`APP_DEBUG=false`
- [ ] 使用 HTTPS 访问，`APP_URL` 使用 https 地址
- [ ] 修改数据库 root 密码或改用专用低权限账号
- [ ] `.env` 文件权限 `600`，禁止提交到代码仓库
- [ ] `storage`、`bootstrap/cache` 属主为 `nginx`（php-fpm 运行用户）且不被 Web 直接访问
- [ ] SELinux 已按第七节 7.4 放行项目目录与 PHP-FPM 连接（`semanage fcontext` + `setsebool`）
- [ ] 防火墙放行 80/443：`firewall-cmd --permanent --add-service=http --add-service=https && firewall-cmd --reload`
- [ ] 关闭不必要的服务与调试端口
- [ ] 定期执行数据库备份（见第十一节）

---

## 十一、数据库备份与恢复

### 1. 后台在线备份

系统管理 → 服务状态 → “立即备份”，MySQL 环境下自动调用 `mysqldump` 生成 `storage/app/backups/backup_时间戳.sql`，并按 `PM_BACKUP_RETENTION_DAYS`（默认 30 天）自动清理过期备份。
（本项目已把该功能从 SQLite 文件复制适配为 MySQL 逻辑备份，需服务器安装 `mysql` 客户端。）

### 2. 命令行手动备份

```bash
mysqldump -uroot -proot --single-transaction --routines --triggers pm_db > /backup/pm_$(date +%Y%m%d_%H%M%S).sql
```

### 3. 恢复

```bash
mysql -uroot -proot pm_db < /backup/pm_xxx.sql
```

> 建议将备份文件同步到异地存储，并设置每日 crontab 定时备份。

---

## 十二、可选组件说明

| 组件 | 用途 | 未部署影响 |
|------|------|-----------|
| 企业微信（WECOM_*） | 企微消息推送、单点登录 | 无影响，后台不配置即可 |
| OnlyOffice | 在线协同编辑文档 | 不部署则文档协作功能不可用；部署方法见第九节 |
| Redis | 缓存/队列加速 | 无影响，项目默认使用数据库驱动 |

---

## 十三、常见问题排查

| 现象 | 可能原因 | 处理 |
|------|---------|------|
| 页面 502 Bad Gateway | PHP-FPM 未启动或 socket 路径不符 | `systemctl status php-fpm`；核对 `www.conf` 的 listen 与 nginx `fastcgi_pass` / apache `SetHandler` 一致 |
| Apache 页面 502/504 | SELinux 拦截 fcgi 连接或 socket 路径不符 | `setsebool -P httpd_can_network_connect 1`；核对 `SetHandler "proxy:unix:/run/php-fpm/www.sock\|fcgi://localhost"` |
| 页面 403 | `public` 目录权限/索引问题；Apache 下 .htaccess 未生效 | 检查 `DocumentRoot`/`root` 指向 `public`；Apache 需 `AllowOverride All` + `mod_rewrite`；`chown -R nginx:nginx`；核对第七节 7.4 SELinux |
| 上传大文件失败 | 上传大小限制 | 同步调大 `php.ini upload_max_filesize/post_max_size` 与 nginx `client_max_body_size`（Apache 为 `LimitRequestBody`） |
| 500 白屏 | 缓存未清/权限/APP_KEY | `storage/logs/laravel.log` 查日志；`php artisan config:clear`；确认 `.env` 与 `APP_KEY` |
| `composer install` 报平台版本不符 | PHP 版本低于 8.3 | `php -v` 确认；升级到 openEuler 24.03 LTS 或编译 PHP 8.3 |
| 迁移报外键/语法错误 | 数据库不是 MySQL 8 / 用了旧代码 | 本项目已修复；确认代码含 `2026_09_18_000000_add_foreign_keys_for_mysql.php` 迁移 |
| 后台备份失败 | 未安装 mysql 客户端 | `dnf install -y mysql` |
| composer audit 告警 | guzzlehttp/guzzle 7.10.x-dev 存在已知 CVE | 项目为兼容性固定了该版本；建议评估升级至 ≥7.15.2 后重新 `composer update guzzlehttp/guzzle` |

---

## 十四、一键自动部署脚本（deploy.sh）

> 项目根目录已提供 **`deploy.sh`**，可将第三~八节的手动步骤整合为一条命令自动完成（安装依赖 → 挂载 U 盘解压 → 建库 → 迁移/种子 → Apache 配置 → 启动 → 定时任务 → 队列服务 → **OnlyOffice Document Server**）。
> 适用前提：openEuler 24.03 LTS、以 **root** 执行、PM 项目 ZIP（如 `pm.zip`）位于**服务器本地 U 盘**（也可用参数直接指定 ZIP 路径）。
> **本脚本是手动步骤的可选替代**：它内部执行的命令与第三~八节完全对应，部署效果一致。

### 1. 使用步骤

```bash
# ① 将 deploy.sh 与 U 盘（内含 pm.zip）就绪：U 盘插入服务器本地 USB 口
# ② 把脚本拷贝到服务器（可用 U 盘/SCP），root 登录终端后执行：
chmod +x /root/deploy.sh
/root/deploy.sh
# 指定 ZIP 路径的另一种用法：
/root/deploy.sh /tmp/pm.zip
```

脚本执行完成后，浏览器访问 `http://服务器IP` 即可看到 PM 登录页（预置管理员账号由种子数据创建）。

### 2. 脚本执行的 11 个步骤与文档章节对应关系

| 脚本步骤 | 内容 | 对应手动章节 |
|---------|------|-------------|
| [1] | 安装 PHP 8.3+扩展、httpd、php-fpm、MySQL/MariaDB、Composer、unzip | 第三、四、五节 |
| [2] | 自动查找/挂载 U 盘（或指定路径），解压并修正目录层级到 /var/www/pm | 第六节 6.1 |
| [3] | 启动数据库、创建 pm_db（root/root）、utf8mb4 | 第四节 |
| [4] | 生成 .env（DB 段强制为 mysql/pm_db/root/root）与 APP_KEY | 第六节 6.2~6.3 |
| [5] | composer install --no-dev --optimize-autoloader | 第六节 6.4 |
| [6] | migrate --force + 空库时 db:seed（避免重复种子） | 第六节 6.6 |
| [7] | storage:link + 前端 npm run build（public/build 已存在则跳过） | 第六节 6.7~6.8 |
| [8] | PHP-FPM(www.conf/php.ini) + Apache VirtualHost + SELinux + 防火墙 + 启动 httpd/php-fpm | 第七节 |
| [9] | 安装 cronie 并启动 crond，crontab 写入 pmadmin 的 schedule:run（已存在则跳过） | 第八节 8.2 |
| [10] | 创建并启动 pm-queue.service（已存在则跳过） | 第八节 8.1 |
| [11] | 部署 OnlyOffice Document Server（可选）：安装 Docker → 解析 JWT（复用 .env/容器或自动生成）→ 创建容器（8080、JWT 开启、host.docker.internal）→ 中文字体 → 防火墙放行 8080 → .env 三个 ONLYOFFICE 变量联动（带端口自动修正）→ healthcheck 验证；设 `INSTALL_ONLYOFFICE=0` 可跳过 | 第九节 |

### 3. 可重复执行（幂等性）说明

脚本可安全重复运行，重复执行不会破坏已有部署：

- `/var/www/pm/composer.json` 已存在 → 跳过解压（如需强制覆盖请先删除该目录）
- 数据库用 `CREATE DATABASE IF NOT EXISTS`；root 密码已为 root 则跳过设置
- `APP_KEY` 已生成 → 跳过；`.env` 已存在 → 保留并强制修正 DB 段为目标值
- `migrate` 已完成的迁移自动跳过；`users` 表已有数据 → 跳过种子（避免重复插入）
- `public/build/manifest.json` 存在 → 跳过 npm 构建
- crontab / pm-queue.service / SELinux / 防火墙 均已做"已存在则跳过或覆盖"处理

### 4. 注意事项

- **U 盘挂载**：脚本自动遍历未挂载的 `/dev/sd*` 分区并查找 `pm*.zip`；FAT32/exFAT 直接支持，NTFS 需 `ntfs-3g`（见 6.1）。挂载失败或找不到 ZIP 时，请用参数指定路径。
- **数据库服务名**：脚本自动识别 `mysqld.service` / `mariadb.service`，无需手动指定。
- **Node.js 缺失**：跳过前端构建并给出警告（页面可访问，样式资源可能缺失）；建议部署机预装 Node ≥18 或提前在项目内完成 `npm run build`（脚本检测到 `public/build` 会直接跳过）。
- **生产建议**：脚本按文档默认使用 root/root；上线前请按第十节清单修改数据库密码、配置 HTTPS 与专用低权限账号。
- **脚本来源**：项目根目录 `deploy.sh`（LF 换行）。从 Windows 拷贝后如报错，先执行 `sed -i 's/\r$//' deploy.sh`。
- 失败排查：脚本在关键步骤失败即中止并提示，可参照第十三节常见问题处理。

---

## 附录：本次 SQLite → MySQL / PHP 8.3 改造说明（项目内已完成的变更）

1. **PHP 版本**：`composer.json` 将 `php` 要求由 `^8.2`（实际解析到 8.4）调整为 `^8.3`，并增加 `config.platform.php = 8.3.0`，`composer.lock` 已重新锁定为 PHP 8.3 兼容版本（Symfony 7.4.x、spatie/laravel-model-states 2.12.1 等）。
2. **数据库配置**：`.env` / `.env.example` 由 `DB_CONNECTION=sqlite` 切换为 `DB_CONNECTION=mysql`，`DB_DATABASE=pm_db`、`DB_USERNAME=root`、`DB_PASSWORD=root`；`config/database.php` 默认连接同步改为 mysql。
3. **迁移兼容性修复（MySQL 要求外键引用的表必须已存在）**：
   - `users` 表不再在建表时声明 `departments`/`roles` 外键（保留索引）；
   - `tasks.task_type_id`、`default_members.task_template_id` 同理；
   - 新增迁移 `2026_09_18_000000_add_foreign_keys_for_mysql.php` 在全部表创建后统一补齐这 4 个外键。
4. **移除建表时的 `->after()` 列位置子句**（MySQL 的 `CREATE TABLE` 不支持 `AFTER`，仅 `ALTER TABLE` 支持）：涉及 `projects`、`tasks`、`department_files` 三个迁移。
5. **后台备份功能适配**：`AdminController::backupDatabase()` 在 MySQL 下改用 `mysqldump` 生成 `.sql` 备份（SQLite 分支保留）；服务状态页数据库大小改为读取 `information_schema` 计算，备份列表兼容 `.sql/.sqlite/.zip`。
6. **遗留 SQLite 文件**：项目根 `database/database.sqlite` 为迁移前的本地数据文件，已不再被 .env 使用，可保留或删除；如需将旧数据导入 MySQL，请使用工具（如 Navicat/`dbForge`）或重新录入。
