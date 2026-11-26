# imgxin · 自用图床

无数据库的 PHP 图床：上传即转码、内容哈希命名、**按站点 + 按月归档**、生成直链。

面向一个人维护多个博客的场景（cipba.cn 等多个站共用一个图床，图片分开存放、分开统计）。

架构上，PHP 只负责「收图 + 转码 + 写元数据 + 返回链接」；图片最终由 Nginx 通过 `/i/` 静态下发，EdgeOne 只做加速、缓存和防盗链。**正常读取图片时不会经过 PHP。**

---

## 运行要求

### 服务器

| 项目 | 要求 |
| --- | --- |
| 操作系统 | Linux，推荐 Debian / Ubuntu |
| Web 服务 | Nginx + PHP-FPM |
| PHP | **8.1 及以上**，推荐 8.4 / 8.5 |
| 数据库 | 不需要 |
| 磁盘 | 至少数百 MB，图片占用取决于上传量 |
| 权限 | PHP 可写 `storage/`、`data/`、`trash/` |

### PHP 扩展

| 扩展 | 是否必需 | 说明 |
| --- | --- | --- |
| `fileinfo` | **必需** | 检测上传文件的真实 MIME，防止伪造扩展名 |
| `mbstring` | **必需** | 处理中文站点名、原文件名和搜索 |
| `json` | **必需** | 读写设置、站点和图片元数据 |
| `session` | **必需** | 登录态与 CSRF |
| `hash` | **必需** | 内容哈希、Token 校验等 |
| `imagick` | **二选一，推荐** | 转码质量更好，支持 EXIF 方向纠正、动态 WebP、AVIF |
| `gd` | **二选一** | Imagick 不可用时的兜底；必须支持 WebP，AVIF 取决于编译情况 |
| `opcache` | 可选 | 提升 PHP 页面性能，不影响图片直链 |

> `imagick` 和 `gd` 至少安装并启用一个。推荐使用 `imagick`。
>
> Debian / Ubuntu 中，`fileinfo`、`json`、`session`、`hash` 通常由 `phpX.Y-common` 提供。安装 PHP 后用 `php -m` 确认；如果缺少 `fileinfo`，安装对应的 `phpX.Y-common` 或在 PHP 配置中启用 `fileinfo.so`。
>
> GD 模式下：动图只保留首帧、不自动纠正手机照片 EXIF 方向、单张图片限制为 2000 万像素。
>
> 不需要 `exif`、`curl`、`zip` 扩展；`curl` 只用于文档中的 API 示例。

### ImageMagick 格式支持

使用 Imagick 时，ImageMagick 本体需要支持：

- `WEBP`：**必需**，默认输出格式。
- `AVIF`：可选，设置页选择 AVIF 时需要。
- JPEG / PNG / GIF / BMP：常用输入格式，默认发行版均支持。

### 推荐 PHP 配置

```ini
file_uploads = On
upload_max_filesize = 25M
post_max_size = 30M
memory_limit = 256M
max_execution_time = 60
```

说明：

- 图床后台默认单张上限是 20 MB，因此 `upload_max_filesize` 建议至少 25M。
- `post_max_size` 必须大于 `upload_max_filesize`，给表单和其他字段留余量。
- 如果后台把单张上限调到 25 MB 以上，PHP 和 Nginx 的上传限制也要同步调大。
- 转码 4000px 以上大图时，`memory_limit` 建议不低于 256M。
- 使用宝塔的 `open_basedir` 防跨站时，确保站点根目录在允许范围内。

---

## 目录结构

```text
imgxin/
├─ public/            # 站点根目录（宝塔里“运行目录”设成 /public）
│  ├─ index.php       # 上传页 / 登录 / 首次安装初始化
│  ├─ admin.php       # 图片管理 + 容量面板 + 站点管理
│  ├─ trash.php       # 回收站（软删除，默认 30 天内可恢复）
│  ├─ settings.php    # 后台设置 + 改密码 + API Token
│  ├─ partials/       # 公共页面组件
│  ├─ assets/         # 公共 CSS 等静态资源
│  └─ api/
│     ├─ upload.php     # 上传接口（site / quality / lossless，支持 Token 免登录）
│     ├─ delete.php     # 删除单张（进回收站）
│     ├─ bulk_delete.php # 批量删除（进回收站）
│     ├─ trash.php      # 恢复 / 彻底删除 / 清空
│     ├─ thumb.php      # 回收站缩略图（带登录校验）
│     ├─ links.php      # 导出链接（按站点 / 月份 / 搜索词）
│     └─ sites.php      # 新增 / 删除站点
├─ lib/               # Config / Sites / Auth / Http / Rate / Imager / Store
├─ config.php         # 固定配置（路径、密码哈希），不在站点根下
├─ storage/           # 图片实体：storage/{站点}/{YYYYMM}/{id}.{ext}
├─ trash/             # 回收站实体：trash/{站点}/{YYYYMM}/
├─ data/
│  ├─ settings.json   # 后台设置（域名/质量/白名单/Token），页面改的就是它
│  ├─ sites.json      # 站点列表
│  ├─ meta/{站点}/{YYYYMM}/   # 每张图的元数据 json
│  ├─ trash/{站点}/{YYYYMM}/  # 回收站元数据（含 deleted_at）
│  ├─ rate/           # 限流计数
│  └─ tmp/            # 转码临时文件
├─ nginx-img.conf     # 需要粘贴到宝塔的片段
├─ dev-server.php     # 本地预览用，线上不需要
└─ README.md
```

---

## 安装步骤（宝塔 / Debian）

下面以域名 `img.40404.cn`、站点目录 `/www/wwwroot/img.40404.cn` 为例。实际部署时替换成自己的域名和路径。

### 1. 上传程序

把 `imgxin` 里的内容上传到 `/www/wwwroot/img.40404.cn/`，最终结构是：

```text
/www/wwwroot/img.40404.cn/
├─ public/
├─ lib/
├─ storage/
├─ trash/
├─ data/
├─ config.php
├─ dev-server.php
└─ README.md
```

注意：`public`、`lib`、`storage`、`data` 与 `config.php` 必须平级，不要在站点根里再套一层 `imgxin`。

### 2. 新建站点

在宝塔中：

1. 添加站点，域名填写 `img.40404.cn`。
2. 如需，把 `www.img.40404.cn` 也绑定并做 301 跳转。
3. PHP 版本选择 8.4 或 8.5。
4. 站点根目录保持 `/www/wwwroot/img.40404.cn`。
5. 在「网站目录」中把**运行目录**设为 `/public`。
6. 申请 Let's Encrypt 证书并开启强制 HTTPS。

### 3. 安装 PHP 扩展

#### 方式 A：Debian / Ubuntu 的 apt 版本 PHP

如果 PHP 8.5 是通过 apt 或 sury 源安装的：

```bash
apt update
apt install -y \
  php8.5-cli \
  php8.5-fpm \
  php8.5-common \
  php8.5-mbstring \
  php8.5-gd \
  imagemagick \
  libmagickwand-dev

# 推荐再装 Imagick
apt install -y php8.5-imagick

systemctl restart php8.5-fpm
```

如果 `php8.5-imagick` 找不到，先确认已经添加 sury 源，或者使用 `pecl install imagick`。

#### 方式 B：宝塔自己编译的 PHP

如果 PHP 是宝塔面板编译安装的：

1. 到「软件商店 → PHP 8.5 → 设置 → 安装扩展」。
2. 安装 `fileinfo`、`mbstring`、`gd`。
3. Imagick 如果面板里没有，可使用宝塔终端执行 `pecl install imagick`，然后在 PHP 配置中启用。
4. 修改完成后重启 PHP-FPM。

#### 验证扩展

在宝塔终端或 SSH 执行：

```bash
php -m | grep -Ei 'fileinfo|mbstring|gd|imagick|json|session|hash'

# Imagick 应输出 true
php -r 'var_dump(in_array("WEBP", Imagick::queryFormats("WEBP")));'

# 如果准备使用 AVIF，也应输出 true
php -r 'var_dump(in_array("AVIF", Imagick::queryFormats("AVIF")));'
```

如果使用 GD：

```bash
php -r 'var_dump(function_exists("imagewebp"));'   # 应为 true
php -r 'var_dump(function_exists("imageavif"));'   # true 才支持 GD 输出 AVIF
```

### 4. 创建目录并设置权限

```bash
cd /www/wwwroot/img.40404.cn

mkdir -p storage trash data/meta data/trash data/rate data/tmp

# 宝塔默认 PHP 和 Nginx 用户都是 www
chown -R www:www storage trash data

# 图片目录需要被 Nginx 读取
find storage -type d -exec chmod 755 {} \;
find storage -type f -exec chmod 644 {} \;

# 数据目录只允许所有者写入
chmod -R 750 data trash
find data trash -type f -exec chmod 640 {} \;

# 固定配置不要被 Web 直接访问
chmod 640 config.php
```

如果 `data/settings.json` 已存在，也确保它属于 `www:www` 且权限为 `640`：

```bash
chown www:www data/settings.json 2>/dev/null || true
chmod 640 data/settings.json 2>/dev/null || true
```

`storage` 必须可写，但里面只会保存重新编码后的图片，文件名由哈希生成，不会出现可执行的 PHP 文件。

### 5. 调整 PHP 上传限制

宝塔路径：

「软件商店 → PHP 8.5 → 设置 → 性能调整」

建议值：

```ini
upload_max_filesize = 25M
post_max_size = 30M
memory_limit = 256M
max_execution_time = 60
```

保存后重启 PHP-FPM。也可以在「设置 → 运行环境」里确认实际生效值。

### 6. 配置 Nginx 图片直链

打开 `nginx-img.conf`，把 `/www/wwwroot/YOUR_SITE` 替换成实际站点目录，然后粘贴到：

「网站 → img.40404.cn → 设置 → 配置文件」的 `server {}` 内。

核心配置如下：

```nginx
# 如果后台单张上限超过 25MB，同步调大
client_max_body_size 30m;

location ^~ /i/ {
    alias /www/wwwroot/img.40404.cn/storage/;
    autoindex off;
    add_header Cache-Control "public, max-age=31536000, immutable";
    add_header X-Content-Type-Options nosniff;
    add_header Access-Control-Allow-Origin "*";

    # none 表示允许空 Referer；不需要放行空 Referer 时删掉 none
    valid_referers none blocked server_names *.40404.cn;
    if ($invalid_referer) { return 403; }
}
```

关键点：

- `alias` 指向 `storage/` 的**根**即可，站点这一层目录由 URL 里的 `/{站点}/` 自然带出来。
- 以后新增站点不需要修改 Nginx。
- 防盗链白名单以你实际博客域名为准，例如 `*.40404.cn`。
- 后台「设置 → Nginx 防盗链片段」会根据当前白名单生成同款配置，改完白名单后可直接覆盖这一段。

保存后重载 Nginx：

```bash
nginx -t && systemctl reload nginx
```

### 7. 初始化管理员密码

浏览器访问：

```text
https://img.40404.cn/
```

首次运行时，`config.php` 的 `admin_hash` 默认为空，因此会直接进入初始化页面：

1. 输入管理员密码，至少 8 位。
2. 再次输入确认。
3. 点击「设置密码并进入」。

设置成功后：

- 自动登录并进入上传页。
- 密码哈希写入 `data/settings.json`。
- 初始化入口自动关闭，以后正常登录。

如果希望完全用命令行配置，也可以手动生成哈希：

```bash
php -r "echo password_hash('换成你的密码', PASSWORD_DEFAULT), PHP_EOL;"
```

把输出整串（形如 `$2y$12$...`）填入 `config.php` 的 `admin_hash`，注意用单引号包裹，避免 shell 展开 `$`。启动后也可以在设置页改密码，后台保存的哈希优先于 `config.php`。

### 8. 配置后台参数

登录后进入：

```text
https://img.40404.cn/settings.php
```

建议至少确认：

- **直链域名**：填写 `https://img.40404.cn`；留空则自动使用当前访问域名。
- **输出格式**：默认 `webp`。
- **质量**：默认 84。
- **最长边上限**：默认 2560px。
- **单张大小上限**：默认 20 MB，不要超过 PHP 和 Nginx 的限制。
- **防盗链白名单**：填写实际博客域名，例如 `*.40404.cn`。
- **回收站保留天数**：默认 30 天。
- **API Token**：只有需要脚本或编辑器免登录上传时才生成。

### 9. 验证安装

1. 上传一张 JPG 或 PNG。
2. 确认返回的直链可以打开，格式为 WebP。
3. 复制直链并执行：

```bash
curl -I "https://img.40404.cn/i/default/202611/xxxxxxxxxxxxxxxx.webp"
```

正常应返回：

```text
HTTP/2 200
content-type: image/webp
cache-control: public, max-age=31536000, immutable
x-content-type-options: nosniff
```

如果返回 404，优先检查：

- Nginx 的 `alias` 路径和结尾斜杠。
- `storage/{站点}/{月份}/` 中是否真的有文件。
- Nginx 是否已经 reload。

设置页底部的「直链自检」也会真实请求一张现有图片，用来验证 `/i/` 链路。

---

## 接入 EdgeOne

- 加速域名使用 `img.40404.cn`，或另开 `cdn.40404.cn` 并把 `base_url` 改成它。
- 缓存规则：`/i/` 前缀，缓存 TTL 30 天以上，**忽略查询参数**。
- 回源协议 HTTPS，回源 Host 填写 `img.40404.cn`。
- 防盗链：在 EdgeOne 的访问管控里配置 Referer 白名单 `*.40404.cn`，并放行空 Referer。
- 不要开启「图片压缩 / 格式转换」类功能，避免边缘二次转码影响画质。
- 加速区域包含中国大陆时，域名必须完成 ICP 备案；源站是腾讯云机器时还要完成腾讯云接入备案。
- 换 `base_url` 后，新生成的链接会使用新域名；旧直链本身不会自动改变。
- 如果源站前面还有可信反代/CDN，并且源站不能被公网直连，可在 `config.php` 把 `trusted_proxy` 设为 `true`；否则保持 `false`，避免伪造 `X-Forwarded-Proto`。

---

## 四个页面

| 地址 | 作用 |
| --- | --- |
| `/` | 上传页。拖拽 / 多选 / Ctrl+V 粘贴截图，3 路并发；可选站点和压缩档位；单张传完自动复制直链；批量传完显示总压缩率 |
| `/admin.php` | 图片管理。容量面板、站点分布、按站点/月份筛选、跨月搜索、批量删除、一键复制或导出当前筛选的全部直链 / Markdown |
| `/trash.php` | 回收站。删除只是挪走，直链立刻失效；保留期内可恢复，也可彻底删除或清空 |
| `/settings.php` | 设置。直链域名、输出格式、质量、最长边、大小与像素上限、限流、防盗链、回收站、API Token、改密码、环境自检 |

四个页面都需要登录，未登录访问会跳回登录页。

---

## 站点（多博客分组）

- 首次运行时自动创建一个 `default` / 默认站点，老图都在它下面。
- 后台「站点分布」卡片里可以新增站点：填名称和标识。
- 上传时选择站点，图片落到 `storage/{标识}/{YYYYMM}/`，直链是 `/i/{标识}/{YYYYMM}/{id}.webp`。
- 站点下还有图时拒绝删除站点，避免产生无主文件；至少保留一个站点。
- 站点标识只能使用小写字母、数字、`-`、`_`，1–32 位，不能叫 `all`。

标识一旦确定就不要改：它已经写进了图片直链，改标识等于把旧链接全部打断。

---

## 上传后的行为

1. 校验：`finfo` 真实 MIME + `getimagesize` 双重校验，两者不一致直接拒绝；SVG 一律不收。
2. 限制：单文件大小、总像素数、每 IP 每小时张数。
3. 命名：`sha256(原文件内容 + 压缩档位 + 站点)` 前 16 位。
4. 转码：纠正 EXIF 方向 → 转 sRGB → 按最长边等比缩小 → 剥离 EXIF/GPS → 输出目标格式。
5. 落盘：`storage/{站点}/{YYYYMM}/{id}.{ext}`，元数据写入 `data/meta/{站点}/{YYYYMM}/{id}.json`。
6. 返回：`{base_url}/i/{站点}/{YYYYMM}/{id}.{ext}`，同时返回 Markdown / HTML。

同一站点内、同图同档位重复上传会复用旧链接；不同档位和不同站点互相独立。

### 最长边上限

默认会把超过 2560px 的图片等比缩到最长边 2560 再转码。设置页可以改成 1280 / 1920 / 3840，或选择「不缩放」。

缩放值参与图片指纹，因此修改上限后，同一张原图重新上传会生成新链接，旧图仍可访问。

---

## 环境自检

设置页底部提供只读环境信息：

- PHP 版本 / SAPI、转码后端（Imagick 或 GD）。
- `upload_max_filesize`、`post_max_size`、`memory_limit`、`max_execution_time` 实际值。
- `storage`、`data`、`trash` 是否存在、是否可写。
- 磁盘剩余 / 总量。
- 直链自检：真实请求一张现有图片的 `/i/` 地址。

常见问题会直接标红：PHP 上传上限小于后台单张上限、`memory_limit` 低于 256M。

---

## API Token

设置页生成 Token 后，可以不带登录态调用上传接口：

```bash
curl -X POST "https://img.40404.cn/api/upload.php" \
  -H "X-API-Token: 你的Token" \
  -F "file=@photo.jpg" \
  -F "site=cipba" \
  -F "quality=84"
```

- 也支持 `Authorization: Bearer 你的Token`。
- 不要把 Token 放在表单或 URL 中，避免被日志记录。
- `site` 不填落到默认站点；`quality` 可选 60–95。
- 返回 JSON，包含 `url`、`markdown`、`html`。
- Token 只给上传权限；删除、回收站、设置仍然需要登录态和 CSRF。
- Token 泄露后，在设置页修改或清空即可立即失效。

---

## 容量面板

`admin.php` 顶部显示：

- 图床总占用。
- 图片总数。
- 本月新增张数。
- 服务器磁盘剩余。

下面按站点列出张数、占用和占比。回收站占用不计入可用图片容量，可在回收站页面单独查看。

---

## 搜索与导出

- 搜索原文件名片段或哈希前缀时，会自动跨全部月份查询。
- 月份下拉支持「全部月份」。
- 筛选条右侧可一次复制当前筛选条件下的全部直链或 Markdown。
- 导出口径与列表一致：选择「全部站点」会混合所有站点。

---

## 安全说明

- 登录锁定：同一 IP 连续输错 5 次密码，锁定 15 分钟。
- 上传限流：默认每 IP 每小时 60 张，可配置。
- Token 只给上传权限。
- 所有写操作校验 CSRF。
- 后台页面带 CSP、`X-Frame-Options: DENY` 和 Referrer-Policy。
- 默认不信任 `X-Forwarded-Proto`，只有确认反代可信时才开启 `trusted_proxy`。
- 不存用户表、不存访客数据；`data/` 中只有设置、站点和元数据。

---

## 回收站提醒

后台首页在回收站非空时会显示黄色提示，包括张数、占用和自动清理时间。

清理时机是打开回收站页面或后台首页时自动删除过期文件，不需要额外配置定时任务。

---

## 本地预览

不上服务器也可以本地预览：

```bash
cd imgxin

# Windows PowerShell
$env:PHP_CLI_SERVER_WORKERS='4'
php -S 127.0.0.1:8899 dev-server.php
```

浏览器打开 `http://127.0.0.1:8899`。

`dev-server.php` 会把 `/i/{站点}/{月}/xx.webp` 映射到 `storage/`，模拟线上 Nginx 的 alias，线上部署不需要这个文件。

本机没装 Imagick 时会自动退回 GD，功能限制见「运行要求」。

---

## 常见问题

### 上传报「文件超过服务器限制」

检查 PHP 的 `upload_max_filesize`、`post_max_size`，以及 Nginx 的 `client_max_body_size`。修改后重启 PHP-FPM 和 Nginx。

### 宝塔里找不到 Imagick

面板通常只列出它自己编译的 PHP 扩展。apt / sury 安装的 PHP 8.5 需要命令行安装：

```bash
apt install php8.5-imagick
systemctl restart php8.5-fpm
```

### 提示 `open_basedir` 限制

确保 `storage`、`data`、`trash` 都在站点根目录内。如果被 `.user.ini` 限制，把实际路径加入允许列表。

### 图片打开 404

检查 Nginx `alias` 路径结尾斜杠、`storage/{站点}/{月份}/` 中是否存在文件，以及 Nginx 是否已 reload。

### 提示站点不存在

检查上传时使用的 `site` 是否已在后台创建。站点标识区分大小写，程序内部只保存小写标识。

### 删图后还能访问

EdgeOne 边缘缓存可能仍然存在。提交 URL 或目录刷新，或等待 TTL 过期。

### 首次访问不是初始化页

说明程序已经读取到密码哈希，可能来自：

- `data/settings.json` 的 `admin_hash`。
- `config.php` 的 `admin_hash`。

如果想重新初始化，备份设置文件后，把这两处哈希都清空，再访问首页。已有图片和设置不会被删除。

---

## 备份

至少备份三个目录：

- `storage/`：图片实体。
- `trash/`：回收站，可选。
- `data/`：设置、站点列表、元数据。

增量备份示例：

```bash
rsync -av --delete /www/wwwroot/img.40404.cn/{storage,trash,data} /backup/imgxin/
```

`data/tmp` 和 `data/rate` 是临时数据，备不备都可以。`config.php` 如做过固定配置修改，也建议单独备份。

---

## 升级步骤

1. 备份 `storage/`、`trash/`、`data/` 和 `config.php`。
2. 上传新版程序文件，覆盖 `public/`、`lib/`、`dev-server.php`、`README.md` 等代码文件。
3. **不要覆盖**现有的 `config.php`、`storage/`、`trash/`、`data/`，除非明确需要重置。
4. 检查目录权限和 PHP 扩展。
5. 执行 `nginx -t && systemctl reload nginx`。
6. 登录后台，确认「运行环境」中的转码后端和上传限制正常。
