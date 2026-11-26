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
| 权限 | PHP 可写 `storage/`、`data/`、`trash/`（`install.sh` 自动处理） |

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
│     └─ sites.php      # 新增 / 删除 / 登记站点
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
├─ install.sh         # 一键安装脚本（创建目录、权限、nginx 配置）
└─ README.md
```

---

## 快速安装

三步搞定，脚本自动创建目录、设置权限、检查 PHP 扩展、生成 nginx 配置。

### 1. 上传程序

把 `imgxin` 里的内容上传到网站根目录（比如 `/www/wwwroot/img.example.com/`），结构是：

```text
img.example.com/
├─ public/          # 站点入口（nginx root 指向这里）
├─ lib/             # 核心类
├─ storage/         # 图片实体（脚本自动创建）
├─ trash/           # 回收站（脚本自动创建）
├─ data/            # 元数据、设置、限流（脚本自动创建）
├─ config.php       # 固定配置
├─ install.sh       # 一键安装脚本
└─ README.md
```

### 2. 运行安装脚本

```bash
cd /www/wwwroot/img.example.com
sudo bash install.sh
```

脚本会：
- 检查 PHP 版本和扩展（缺什么会告诉你装什么）
- 创建 `storage/`、`data/{meta,trash,rate,tmp}`、`trash/` 目录
- 设置目录权限（自动检测宝塔 `www` 或 Debian `www-data`）
- 提示输入域名，自动生成 nginx 配置文件
- 输出 nginx 重载命令

按提示执行 `nginx -t && systemctl reload nginx` 即可。

### 3. 浏览器初始化

打开 `https://img.example.com/`，首次访问进入密码初始化页面：

1. 输入管理员密码（至少 8 位）
2. 确认密码
3. 点击「设置密码并进入」

自动登录后进设置页填直链域名、防盗链白名单、磁盘配额等。

### 升级

```bash
git pull          # 或重新上传覆盖代码
bash install.sh    # 脚本幂等，重复跑无副作用
```

覆盖更新时**只替换代码**：`public/`、`lib/`，以及根目录的 `README.md` / `install.sh` / `nginx-img.conf`。下面这些千万不要删、不要用压缩包里的空目录覆盖：

| 路径 | 里面是什么 | 覆盖后果 |
| --- | --- | --- |
| `data/sites.json` | 站点列表 | 新增的站点全部消失（可用后台「一键登记」找回） |
| `data/settings.json` | 密码哈希、直链域名、白名单、API Token | 设置全丢；密码哈希丢失后初始化入口会被锁定，只能改 `config.php` |
| `data/meta/` | 每张图的元数据 | 图片列表里原文件名、尺寸、时间全丢 |
| `storage/` | 图片实体 | 直链全部 404，只能靠备份或 CDN 缓存抢救 |
| `trash/` | 回收站 | 待恢复的图彻底没了 |

宝塔上传压缩包后，注意压缩包顶层带 `imgxin/` 目录，解压会得到 `站点目录/imgxin/...`；应把其中的 `public/` 和 `lib/` 移到站点根再删除多余的 `imgxin/`，不要整包解压后直接删原目录。

代码版本和数据版本在后台「设置 → 版本与升级」里查看。有数据迁移时会出现「一键升级」按钮，**迁移不会自动执行**，必须手动点；纯代码更新没有迁移步骤，按钮不出现属正常。

---

## 手动配置（可选）

`install.sh` 没覆盖到的场景（宝塔自定义路径、特殊 PHP 版本）参考以下内容。

### PHP 扩展

必需：`fileinfo`、`mbstring`、`gd`、`json`、`session`、`hash`。推荐 Imagick（支持 AVIF 输出）。

```bash
# Debian / Ubuntu
apt install -y php8.5-cli php8.5-fpm php8.5-mbstring php8.5-gd php8.5-imagick

# 验证
php -m | grep -Ei 'fileinfo|mbstring|gd|imagick'
```

宝塔面板：软件商店 → PHP 8.5 → 设置 → 安装扩展。

### PHP 上传限制

`php.ini` 或宝塔「性能调整」：

```ini
upload_max_filesize = 25M
post_max_size = 30M
memory_limit = 256M
max_execution_time = 60
```

### Nginx 配置

站点 `server {}` 内添加：

```nginx
client_max_body_size 25m;

# 图片直链：nginx 直接吐静态文件，不经 PHP
location ^~ /i/ {
    alias /www/wwwroot/img.example.com/storage/;
    autoindex off;
    add_header Cache-Control "public, max-age=31536000, immutable";
    add_header X-Content-Type-Options nosniff;

    # 防盗链：none 放行空 Referer（微信/QQ 直接打开不发 Referer）
    valid_referers none blocked server_names *.example.com;
    if ($invalid_referer) { return 403; }
}

# PHP 入口
location ~ \.php$ {
    fastcgi_pass unix:/run/php/php8.5-fpm.sock;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    include fastcgi_params;
}
```

关键点：
- `alias` 指向 `storage/` 根目录，站点分目录由 URL 里的 `/{站点}/` 带出
- 新增站点不需要改 Nginx
- 后台「设置 → Nginx 防盗链片段」会根据白名单生成同款配置
- 这段防盗链只在**没有 CDN** 时有效。接了 EdgeOne 之类的 CDN 后请求命中缓存就不回源，必须把防盗链放到 CDN 侧（见上文「接入 EdgeOne」）

保存后 `nginx -t && systemctl reload nginx`。

### 目录权限

```bash
chown -R www:www storage trash data
find storage -type d -exec chmod 755 {} \;
find storage -type f -exec chmod 644 {} \;
chmod -R 750 data trash
chmod 640 config.php
```

### 验证

上传一张图，确认直链可打开：

```bash
curl -I "https://img.example.com/i/default/202611/xxxxxxxxxxxxxxxx.webp"
```

正常应返回 `HTTP/2 200` + `content-type: image/webp`。设置页底部的「直链自检」也会真实请求一张现有图片。

---

## 接入 EdgeOne（主站与直链分离）

推荐拓扑：**主站只做管理，直链域名只做分发**。

| 角色 | 域名 | 走不走 CDN | 用途 |
| --- | --- | --- | --- |
| 主站 | `img.example.com` | 否 | 登录、上传、后台 |
| 直链 | `cdn.example.com` | 是（EdgeOne） | 只承接 `/i/` 图片 |

设置页把 `base_url` 填成 `https://cdn.example.com`。这一步必须最先做：留空时程序会用"当前访问域名"拼直链，从主站上传就会生成主站链接，CDN 完全没被用上。

### 1. 主站 `/i/` 改为 301

老链接仍然指向主站，用 301 平滑迁移，不要直接 403（否则历史文章里的图会全部裂）：

```nginx
location ^~ /i/ {
    return 301 https://cdn.example.com$request_uri;
}
```

301 只是让浏览器换地址，图片字节不会经过主站。浏览器缓存的 301 之后，后续请求直接打到 CDN，不再经过主站。

### 2. 源站加一个只服务回源的站点

EdgeOne 回源配置：**源站地址填服务器 IP、回源 HOST 填 `cdn.example.com`、回源协议选 HTTP**。

回源 HOST 只是请求头，EO 直连 IP、不解析这个域名，所以不会循环。只有把"源站地址"填成 `cdn.example.com` 才会真正循环。

源站需要单独的 server 块，只放 `/i/`：

```nginx
server {
    listen 80;
    server_name cdn.example.com;

    location ^~ /i/ {
        alias /www/wwwroot/img.example.com/storage/;
        add_header Cache-Control "public, max-age=2592000, immutable" always;
        add_header X-Content-Type-Options nosniff always;
        access_log off;
    }

    location / { return 404; }
}
```

三个要点：

- **只监听 80，不要开强制 HTTPS**。回源走 HTTP 时，如果这个站点把 80 跳 443，EO 会拿到 301 跳回自己的加速域名，回源直接失效。
- **只放 `/i/`，其余 404**。否则 `cdn.example.com/api/upload.php` 通过 CDN 也能摸到后台接口。
- `root` 可以不写，`alias` 不依赖 root。

### 3. 缓存

- 规则：`/i/` 前缀，TTL 30 天，**忽略查询参数**（否则带 `?v=` 的请求全部 MISS）。
- 源站只发一条 `Cache-Control`。`expires 30d` 和 `add_header Cache-Control` 同时写会输出两条头，边缘和浏览器取值不确定。
- 不要开启「图片压缩 / 格式转换」，避免边缘二次转码影响画质。

### 4. 防盗链

- 用 EdgeOne「访问控制 → Referer 防盗链」，白名单按**域名**填（`*.example.com`），并勾选**允许空 Referer**。
- 不要用"Referer 不等于 [...]"这类整串精确比较的自定义规则：真实浏览器发的是 `https://blog.com/post/1`，永远"不等于"列表里的 `blog.com`，结果把自己的图也拦了。
- 防盗链必须在 EO 侧生效。只靠源站 nginx 的 `valid_referers` 时，命中缓存的请求不回源，等于没设。
- **301 不影响防盗链**：跳转后浏览器对 CDN 发起的是新请求，Referer 仍是原博客页面，EO 照常判断。空 Referer 会走放行分支，所以直接打开、微信预览都正常。
- **设置页「直链自检」为什么用服务器探测**：后台在 `img.example.com`、直链在 `cdn.example.com` 时属于跨域，浏览器 `fetch` 读响应需要 CORS 头，缺了就是 `Failed to fetch`，而 `<img>` 加载图片本来就不受这个限制。现在自检由 PHP 在服务器上发 HEAD，返回真实状态码和原因；本地 `php -S` 预览会自己请求自己超时，线上 nginx 不存在这个问题。

### 5. 边缘 HTTPS 证书

- EO 免费证书（TrustAsia DV）自动签发、90 天自动续期，不需要手动部署；回源走 HTTP 时，源站也不必为 `cdn.example.com` 配证书。
- 浏览器提示"连接不是私密连接"时，先查 **DNS** 而不是证书：如果解析到一个只装了主站证书的旧节点，SNI 对不上就会握手失败。DNS 里只保留一条 `CNAME → 你的加速域名.eo.dnse0.com`，删掉以前接其他 CDN 时留下的按线路解析。
- 该站点如有「HTTP → HTTPS 跳转」开关，建议打开；找不到开关时可用规则引擎：`scheme = http` → `301 https://${host}${uri}`。

### 6. 删图与边缘缓存

删除或替换图片后，边缘缓存仍会按 TTL 继续命中，最长 30 天——后台删了、外面还能打开。需要立即生效就去 EO 刷新对应 URL；个人站更省事的做法是把 TTL 调到 7 天。

### 7. 备案与其他

- 加速区域包含中国大陆时，域名必须完成 ICP 备案；源站是腾讯云机器时还要完成腾讯云接入备案。
- 换 `base_url` 后，新生成的链接使用新域名；已经发出去的老直链不会自动改，靠主站 301 兜底。
- 源站前面如果有可信反代/CDN 且源站不能被公网直连，可在 `config.php` 把 `trusted_proxy` 设为 `true`；否则保持 `false`，避免伪造 `X-Forwarded-Proto`。

---

## 四个页面

| 地址 | 作用 |
| --- | --- |
| `/` | 上传页。拖拽 / 多选 / Ctrl+V 粘贴截图，3 路并发；可选站点和压缩档位；单张传完自动复制直链；批量传完显示总压缩率 |
| `/admin.php` | 图片管理。容量面板（含磁盘剩余，低于 10% 红色告警）、站点分布、按站点/月份筛选、跨月搜索、批量删除、一键复制或导出当前筛选的全部直链 / Markdown、存储一致性检查（比对文件与元数据，只报告不自动删）、未登记站点目录自检与一键登记 |
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

### 默认站点

站点一多，真正天天用的往往只有一个。在「站点分布」里点某一行右侧的「设为默认」，该站点会带上「默认」标记：

- 上传页的「上传到」默认选它；
- 上传接口不传 `site` 时（含 `curl` + API Token 的脚本上传）落到它；
- 只是默认选择，上传页仍可临时切到别的站点，切换后会记住你上次手动选的那个；
- 后台改默认站点时会同步清掉旧的本地记忆，避免刷新后还停在旧站点；
- 删掉默认站点会自动清空标记，回落到站点列表第一个。

标记存在 `data/settings.json` 的 `default_site` 里，升级覆盖不会丢。

标识一旦确定就不要改：它已经写进了图片直链，改标识等于把旧链接全部打断。

### 站点目录自检（误删 sites.json 后的补救）

后台打开「图片管理」时会扫一遍 `storage/{标识}/` 和 `trash/{标识}/`：凡是磁盘上存在、但 `sites.json` 里没有登记的目录，会在「站点分布」卡片顶部报一条黄色提示，列出标识和图片数量，右侧给一个「一键登记」按钮。

- 覆盖更新、解压到错误目录、手滑删掉 `data/sites.json` 时，图片本身不受影响，用这个按钮把目录登记回去即可，**不移动、不改名任何文件**。
- 登记后站点名默认是 `标识（已恢复）`，可在站点列表里继续用；图片、元数据和旧直链全部保持原样。
- 只报告存在图片的目录，空目录不提示，避免历史残留目录一直挂着。
- 反过来，如果 `storage/` 整个丢了，这个功能救不了，只能走备份或 CDN 缓存。

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
curl -X POST "https://img.example.com/api/upload.php" \
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

程序已经把文件挪进回收站、直链立即失效，此时还能打开只可能是 **CDN 边缘缓存**没清。按 TTL 最长会残留 30 天。

处理：EO 控制台提交该 URL 刷新；或把缓存 TTL 调短（7 天对个人站足够）。

### 打开图片提示「连接不是私密连接」

先看 DNS，不要先怀疑证书：

- 该域名是否解析到了只装了主站证书的旧节点（SNI 对不上会直接握手失败）？
- DNS 记录里是否还留着以前接其他 CDN 时的按线路解析？

用公共 DNS 交叉验证：`nslookup 域名 223.5.5.5`。公共 DNS 正常、本机异常时，多半是本机/路由器的 DNS 缓存，`ipconfig /flushdns` 或临时换 DNS 即可。

### 主站 301 之后，博客里的图还能打开吗

能。301 会把老链接带到 CDN 同一路径；防盗链判断的是博客页面发来的 Referer，跟这次跳转无关，所以白名单里的站照常显示，盗链依旧 403。

### 主站 `/i/` 改 301 后，后台缩略图为什么多了一跳

管理页缩略图用的是 `base_url`。`base_url` 已经指向 CDN 时不会有这一跳；如果还是空的（用的是当前域名），缩略图会先 301 到 CDN。功能不受影响，但建议先把 `base_url` 填好再改主站 301。

### 首次访问不是初始化页

说明程序已经读取到密码哈希，可能来自：

- `data/settings.json` 的 `admin_hash`。
- `config.php` 的 `admin_hash`。

如果想重新初始化，备份设置文件后，把这两处哈希都清空，再访问首页。已有图片和设置不会被删除。

---

## 已知限制与可选增强

这些不是 bug，是为了保持"轻量、无数据库"而有意留白的部分，按需再补。

| 现状 | 影响 | 可选增强 |
| --- | --- | --- |
| 删除图片后不会自动清 CDN 缓存 | 边缘按 TTL 继续命中，最长 30 天 | 接 EO 刷新 API，或加一个删除后的 purge webhook；更简单的做法是把 TTL 调到 7 天 |
| 限流计数按 IP 存文件，只在命中时清理 | 长期无人访问的 IP 文件会残留，量大会上万个 inode | 在启动时按 1% 概率顺手清理超过窗口 6 倍的文件 |
| `data/tmp` 只在正常流程里删临时文件 | 进程被杀会残留转码临时文件 | 每次上传时清理 1 小时前的 `data/tmp` 文件 |
| 站点标识写进直链，不支持改名 | 改名等于旧链接全断 | 如确需改名，自己加一条旧路径 rewrite，或干脆新建站点重新上传 |
| 无内置备份、无容量与证书告警 | 磁盘写满或证书到期只能靠人发现 | 宝塔计划任务每日打包 `storage/`、`data/` 到对象存储；配一个磁盘水位提醒 |
| 上传限流只有"每 IP 每小时张数" | Token 泄漏后可被持续刷盘 | 增加每日总量或每 Token 配额 |
| 不记录操作日志 | 被盗用后无法回溯 | 只记登录 IP、删除动作即可，不必做统计报表 |
| 源站 `/i/` 只能靠 CDN 规则保护 | 拿到源站 IP + 伪造 Host 可直连取图 | EO 回源配置里加 `X-Origin-Token`，nginx 校验该头；或按 EO 回源 IP 段做 allow/deny |

---

## 备份

至少备份三个目录：

- `storage/`：图片实体。
- `trash/`：回收站，可选。
- `data/`：设置、站点列表、元数据。

增量备份示例：

```bash
rsync -av --delete /www/wwwroot/img.example.com/{storage,trash,data} /backup/imgxin/
```

`data/tmp` 和 `data/rate` 是临时数据，备不备都可以。`config.php` 如做过固定配置修改，也建议单独备份。

---

## 版本与一键升级

程序使用两个版本号：

- **代码版本**：写在 `lib/Version.php` 的 `CODE_VERSION`，随新代码发布。
- **数据版本**：写在 `data/version.json`，由升级迁移实际推进。

覆盖代码后，打开后台「设置 → 版本与升级」，如果有待执行迁移，会出现「一键升级」按钮。点击后：

1. 自动备份 `data/settings.json` 到 `data/backups/`。
2. 按顺序执行尚未完成的数据迁移。
3. 更新 `data/version.json`。
4. 返回设置页并显示升级结果。

### 推荐升级流程

1. 备份 `storage/`、`trash/`、`data/` 和 `config.php`。
2. 上传新版代码，覆盖：
   - `public/`
   - `lib/`
   - `dev-server.php`
   - `README.md`
   - `nginx-img.conf`（如无配置变化可不覆盖）
3. `config.php` 如果改过固定项，不要直接覆盖；先备份，再只合并新版新增的键。
4. **不要覆盖** `storage/`、`trash/`、`data/`，图片、设置和元数据都保存在这里。
5. 登录后台，在「版本与升级」点击「一键升级」。
6. 执行 `nginx -t && systemctl reload nginx`。
7. 在「运行环境」确认转码后端、上传限制和目录权限正常。

如果代码版本高于数据版本但按钮没有出现，通常说明当前代码没有新增迁移步骤，属于纯代码更新，直接使用即可。

### 降级

降级覆盖旧代码后，数据版本可能高于代码版本。程序不会自动执行反向迁移，避免来回切换损坏数据。请先备份 `data/`，再恢复对应版本的代码。

### 新增数据迁移

以后需要修改设置结构或清理元数据时：

1. 把 `lib/Version.php` 的 `CODE_VERSION` 改成新版本。
2. 在 `lib/Upgrade.php` 的 `steps()` 增加一个迁移：
   - `version`：新版本号。
   - `id`：稳定的迁移标识。
   - `description`：后台显示说明。
   - `run`：迁移函数，必须幂等。
3. 迁移只能调整 `data/` 内的数据，不要移动或删除 `storage/`、`trash/`。
4. 升级前为关键数据保留备份；迁移函数失败时版本号不会推进。
