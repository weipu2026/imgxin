#!/usr/bin/env bash
#
# imgxin 一键安装脚本
#
# 用法：
#   1. 把 imgxin 目录上传到网站根目录（比如 /www/wwwroot/img.example.com/）
#   2. cd 到该目录
#   3. bash install.sh
#   4. 按提示完成 nginx 配置
#   5. 浏览器访问域名完成密码初始化
#
# 需要 root 或 sudo 权限来修改 nginx 配置和目录权限。
set -euo pipefail

# ---------- 颜色输出 ----------
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; CYAN='\033[0;36m'; NC='\033[0m'
info()  { echo -e "${GREEN}[INFO]${NC} $*"; }
warn()  { echo -e "${YELLOW}[WARN]${NC} $*"; }
error() { echo -e "${RED}[ERROR]${NC} $*"; }
prompt(){ echo -e "${CYAN}[INPUT]${NC} $*"; }

# ---------- 前置检查 ----------
if [ ! -f config.php ]; then
    error "请在 imgxin 目录内运行此脚本（当前目录：$(pwd)）"
    exit 1
fi

if [ "$EUID" -ne 0 ]; then
    warn "建议用 root 运行，以便自动配置 nginx 和目录权限。"
    warn "继续运行可能需要你手动 sudo 或自行完成 nginx 配置。"
fi

APP_DIR="$(pwd)"
info "安装目录：$APP_DIR"

# ---------- 检测 PHP ----------
PHP_BIN="$(command -v php || true)"
if [ -z "$PHP_BIN" ]; then
    error "找不到 php 命令，请先安装 PHP 8.1+"
    exit 1
fi
PHP_VER="$($PHP_BIN -r 'echo PHP_VERSION;')"
info "PHP 版本：$PHP_VER"

# ---------- 检查 PHP 扩展 ----------
MISSING=()
for ext in fileinfo mbstring gd json session hash; do
    if ! $PHP_BIN -m 2>/dev/null | grep -q "^${ext}$"; then
        MISSING+=("$ext")
    fi
done

if [ ${#MISSING[@]} -gt 0 ]; then
    error "缺少 PHP 扩展：${MISSING[*]}"
    echo
    echo "  Debian/Ubuntu 安装命令："
    echo "    apt install -y php${PHP_VER%%.*}-cli php${PHP_VER%%.*}-fpm php${PHP_VER%%.*}-mbstring php${PHP_VER%%.*}-gd"
    echo
    echo "  宝塔面板：软件商店 → PHP → 设置 → 安装扩展"
    exit 1
fi
info "PHP 扩展齐全（fileinfo / mbstring / gd / json / session / hash）"

# Imagick 可选但推荐
if $PHP_BIN -m 2>/dev/null | grep -q "^imagick$"; then
    info "Imagick 已安装（支持 AVIF 输出）"
else
    warn "Imagick 未安装（可选）。GD 已够用，想支持 AVIF 可装："
    echo "    apt install -y php${PHP_VER%%.*}-imagick"
fi

# ---------- 创建目录 ----------
info "创建目录..."
mkdir -p storage trash data/meta data/trash data/rate data/tmp data/backups
info "目录结构：storage/ trash/ data/{meta,trash,rate,tmp,backups}"

# ---------- 设置权限 ----------
# 宝塔用 www，Debian 原生用 www-data，自动检测
if [ -d /www/server ]; then
    WEB_USER="www"; WEB_GROUP="www"
elif id -u www-data >/dev/null 2>&1; then
    WEB_USER="www-data"; WEB_GROUP="www-data"
else
    WEB_USER="$(whoami)"; WEB_GROUP="$WEB_USER"
fi
info "Web 用户：$WEB_USER:$WEB_GROUP"

chown -R "$WEB_USER:$WEB_GROUP" storage trash data 2>/dev/null || \
    warn "chown 失败，请手动设置 storage/ trash/ data/ 为 $WEB_USER:$WEB_GROUP 可写"

find storage -type d -exec chmod 755 {} \; 2>/dev/null || true
find storage -type f -exec chmod 644 {} \; 2>/dev/null || true
chmod -R 750 data trash 2>/dev/null || true
info "权限设置完成"

# ---------- 生成 nginx 配置 ----------
DOMAIN=""
if [ -f /etc/nginx/sites-available/ ] || [ -d /etc/nginx/conf.d ]; then
    prompt "请输入你的域名（如 img.example.com，回车跳过）："
    read -r DOMAIN
    if [ -n "$DOMAIN" ]; then
        NGINX_CONF="/etc/nginx/conf.d/imgxin.conf"
        if [ -d /etc/nginx/sites-available/ ]; then
            NGINX_CONF="/etc/nginx/sites-available/imginx"
        fi
        if [ -w "$(dirname "$NGINX_CONF")" ] || [ "$EUID" -eq 0 ]; then
            cat > "$NGINX_CONF" <<NGINX
server {
    listen 80;
    server_name ${DOMAIN};
    root ${APP_DIR}/public;
    index index.php;
    client_max_body_size 25m;

    location /i/ {
        alias ${APP_DIR}/storage/;
        expires 30d;
        add_header Cache-Control "public, max-age=2592000, immutable";
        access_log off;
    }

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php${PHP_VER%%.*}-fpm.sock;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 60;
    }
}
NGINX
            info "nginx 配置已写入：$NGINX_CONF"
            info "请执行：nginx -t && systemctl reload nginx"
        else
            warn "没有权限写 nginx 配置，请手动添加（见下方模板）"
        fi
    fi
fi

# ---------- 输出 nginx 模板（权限不足或跳过时） ----------
if [ -z "${NGINX_CONF:-}" ]; then
    echo
    warn "请手动把以下内容添加到 nginx 配置（宝塔：网站 → 设置 → 配置文件）："
    echo
    cat <<NGINX
server {
    listen 80;
    server_name ${DOMAIN:-your-domain.com};
    root ${APP_DIR}/public;
    index index.php;
    client_max_body_size 25m;

    # 图片直链：nginx 直接吐静态文件，不经 PHP
    location /i/ {
        alias ${APP_DIR}/storage/;
        expires 30d;
        add_header Cache-Control "public, max-age=2592000, immutable";
        access_log off;
    }

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php${PHP_VER%%.*}-fpm.sock;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 60;
    }
}
NGINX
    echo
fi

# ---------- 完成 ----------
echo
info "========================================="
info "  安装完成！"
info "========================================="
echo
echo "  1. 确认 nginx 配置已生效"
echo "     nginx -t && systemctl reload nginx"
echo
echo "  2. 浏览器访问你的域名"
echo "     http://${DOMAIN:-your-domain.com}"
echo "     → 首次访问会进入密码初始化页面"
echo
echo "  3. 初始化完成后进设置页"
echo "     填直链域名、防盗链白名单、磁盘配额等"
echo
echo "  升级方式："
echo "     git pull && bash install.sh"
echo "     （或重新上传覆盖代码，访问后台会自动检测版本并提示升级）"
echo
