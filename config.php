<?php
declare(strict_types=1);

/**
 * 固定配置：部署时改一次即可。
 * 可变配置（域名、格式、质量、白名单等）不在这里，放在 后台设置页 → data/settings.json
 */
return [
    // 图片实体目录（必须在站点根之外，nginx 用 alias 映射 /i/ 到这里）
    'storage_dir' => __DIR__ . '/storage',

    // 元数据、限流计数、临时文件
    'data_dir'    => __DIR__ . '/data',

    // 回收站：软删除的图片挪到这里，30 天内可恢复
    'trash_dir'   => __DIR__ . '/trash',

    // 管理员密码哈希。首次启动时留空，浏览器访问首页会进入初始化页面。
    // 如希望完全用命令行配置，也可生成哈希后填在这里：
    //   php -r "echo password_hash('你的密码', PASSWORD_DEFAULT), PHP_EOL;"
    // 把输出的整串（含 $2y$ 前缀）粘贴到下面引号里，不要换行。
    'admin_hash'  => '',

    // 会话 cookie 名
    'session_name' => 'imgxin_sid',

    // 是否信任 X-Forwarded-Proto。只有源站不可被直连、且反代/CDN 会覆盖该头时才开启。
    // 默认关闭，避免有人直接伪造请求头影响协议判断和 Secure Cookie。
    'trusted_proxy' => false,
];
