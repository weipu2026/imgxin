FROM php:8.5-fpm-bookworm

# Nginx + Supervisor + PHP 扩展（gd 需要编译参数）
RUN apt-get update && apt-get install -y --no-install-recommends \
        nginx supervisor \
        libpng-dev libjpeg-dev libfreetype6-dev libwebp-dev libavif-dev libheif-dev \
    && docker-php-ext-configure gd --with-png --with-jpeg --with-webp --with-avif \
    && docker-php-ext-install -j"$(nproc)" gd mbstring \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# 应用代码
COPY --chown=www-data:www-data . /var/www/imgxin

# Nginx 站点配置（容器内路径）
COPY docker/nginx.conf /etc/nginx/sites-available/imginx
RUN ln -sf /etc/nginx/sites-available/imginx /etc/nginx/sites-enabled/imginx \
    && rm -f /etc/nginx/sites-enabled/default

# Supervisor：同时跑 nginx 和 php-fpm
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# 运行时目录（compose 会用 volume 覆盖，但镜像内先建好保证首启不报错）
RUN mkdir -p /var/www/imgxin/data /var/www/imgxin/storage /var/www/imgxin/trash \
    && chown -R www-data:www-data /var/www/imgxin

WORKDIR /var/www/imgxin
EXPOSE 80

CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
