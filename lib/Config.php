<?php
declare(strict_types=1);

final class Config
{
    private static array $base = [];
    private static ?array $settings = null;

    public static function boot(array $base): void
    {
        self::$base = $base;
    }

    public static function base(string $key): mixed
    {
        return self::$base[$key] ?? null;
    }

    /** 可变配置的默认值，后台设置页只允许改这些键 */
    public static function defaults(): array
    {
        return [
            // 图片直链前缀。留空则自动取当前访问域名，填了就以它为准（换域名/接 CDN 时只改这里）
            'base_url'            => '',
            // 输出格式：webp / avif / png / original（不转码，原样保存）
            'output'              => 'webp',
            // 有损质量 60-95，照片类 82-86 体积和画质比较平衡
            'quality'             => 84,
            // PNG/透明图转 webp 时用无损，避免文字糊
            'lossless_alpha'      => true,
            // 单文件大小上限（字节）
            'max_bytes'           => 20 * 1024 * 1024,
            // 单张像素上限，防解压炸弹
            'max_pixels'          => 50_000_000,
            // 最长边上限：超过就等比缩小（0 = 保持原尺寸）。博客正文 2560 足够，4K 原图没必要存
            'max_edge'            => 2560,
            // 每 IP 每小时上传张数
            'rate_per_hour'       => 60,
            // 磁盘配额保护线（GB）：上传前要求磁盘剩余不少于这个值，0 = 不限制
            'total_quota_gb'      => 0,
            // 防盗链白名单（用于生成 nginx 片段 + 参考配置 EdgeOne）
            'referer_whitelist'   => [],
            // 是否放行空 Referer：直接打开、微信/QQ、部分 App 内嵌都不发 Referer，建议开
            'allow_empty_referer' => true,
            // 管理员密码哈希：由后台「修改密码」写入；留空则回退用 config.php 里的 admin_hash
            'admin_hash'          => '',
            // API Token：填了就能免登录调上传接口（脚本/编辑器用），空则不启用
            'api_token'           => '',
            // 回收站保留天数，超过自动彻底删除
            'trash_keep_days'     => 30,
        ];
    }

    public static function settingsFile(): string
    {
        return (string)self::base('data_dir') . '/settings.json';
    }

    public static function all(): array
    {
        if (self::$settings !== null) {
            return self::$settings;
        }

        $data = self::defaults();
        $file = self::settingsFile();

        if (is_file($file)) {
            $raw = @file_get_contents($file);
            $json = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($json)) {
                // 只接受已知键，防止脏数据混进来
                $data = array_merge($data, array_intersect_key($json, $data));
            }
        }

        self::$settings = $data;
        return $data;
    }

    public static function get(string $key, mixed $fallback = null): mixed
    {
        $all = self::all();
        return $all[$key] ?? $fallback;
    }

    public static function save(array $patch): void
    {
        $current = self::all();
        $merged  = array_merge($current, array_intersect_key($patch, $current));
        $file    = self::settingsFile();
        $tmp     = tempnam(dirname($file), '.settings-');
        if ($tmp === false) {
            throw new RuntimeException('写入设置失败，无法创建临时文件');
        }

        $json = json_encode($merged, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false || @file_put_contents($tmp, $json, LOCK_EX) === false) {
            throw new RuntimeException('写入设置失败，请检查 data 目录权限');
        }

        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            throw new RuntimeException('写入设置失败（rename 被拒绝）');
        }

        @chmod($file, 0640);
        self::$settings = $merged;
    }
}
