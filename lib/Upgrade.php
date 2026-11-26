<?php
declare(strict_types=1);

/**
 * 轻量版本迁移。升级流程：覆盖代码文件 → 后台点「一键升级」→ 执行未完成的迁移。
 *
 * 迁移只做数据目录内的必要调整，绝不触碰 storage/ 和 trash/。
 * 每个迁移函数必须可重复读取、尽量幂等；失败时不会推进版本号。
 */
final class Upgrade
{
    /** @return array<int, array{version:string,id:string,description:string,run:callable}> */
    private static function steps(): array
    {
        return [
            [
                'version' => '1.0.0',
                'id' => 'baseline-1.0.0',
                'description' => '初始化版本记录，确认设置文件与目录结构',
                'run' => static function (): string {
                    // 旧安装没有 version.json；这里只记录基线，不改图片和设置。
                    Config::all();
                    Sites::all();
                    return '已记录初始版本 1.0.0';
                },
            ],
        ];
    }

    public static function file(): string
    {
        return (string)Config::base('data_dir') . '/version.json';
    }

    /** 代码版本 */
    public static function codeVersion(): string
    {
        return (string)Version::CODE_VERSION;
    }

    /** data/version.json 中的版本；缺失或损坏时按 0.0.0 处理 */
    public static function storedVersion(): string
    {
        $raw = @file_get_contents(self::file());
        $data = is_string($raw) ? json_decode($raw, true) : null;
        $version = is_array($data) ? (string)($data['version'] ?? '') : '';
        return self::normalize($version);
    }

    /** 尚未执行的迁移，按版本升序 */
    public static function pending(): array
    {
        $stored = self::storedVersion();
        if (version_compare(self::codeVersion(), $stored, '<')) {
            return []; // 降级不自动迁移，避免来回切换造成数据漂移
        }

        $applied = self::appliedIds();
        return array_values(array_filter(self::steps(), static function (array $step) use ($applied): bool {
            return version_compare($step['version'], self::storedVersion(), '>')
                && !in_array($step['id'], $applied, true);
        }));
    }

    public static function pendingCount(): int
    {
        return count(self::pending());
    }

    /** data/version.json 中已执行的迁移 id */
    private static function appliedIds(): array
    {
        $raw = @file_get_contents(self::file());
        $data = is_string($raw) ? json_decode($raw, true) : null;
        $ids = is_array($data) && is_array($data['applied'] ?? null) ? $data['applied'] : [];
        return array_values(array_filter(array_map('strval', $ids), static fn($v) => $v !== ''));
    }

    public static function isDowngrade(): bool
    {
        return version_compare(self::storedVersion(), self::codeVersion(), '>');
    }

    /** 执行全部待处理迁移。成功返回摘要，失败抛出异常。 */
    public static function run(): array
    {
        $pending = self::pending();
        if (!$pending) {
            return ['applied' => [], 'message' => '当前已是最新版本'];
        }

        self::backupSettings();

        $lock = @fopen((string)Config::base('data_dir') . '/.upgrade.lock', 'w+');
        if ($lock !== false) {
            flock($lock, LOCK_EX);
        }

        try {
            // 和历史已执行记录合并，不能只写本次的，否则以后回滚再升级会丢掉旧记录
            $applied = self::appliedIds();
            foreach ($pending as $step) {
                ($step['run'])();
                $applied[] = $step['id'];
                self::saveVersion($step['version'], $applied);
            }
        } finally {
            if ($lock !== false) {
                flock($lock, LOCK_UN);
                fclose($lock);
                @unlink((string)Config::base('data_dir') . '/.upgrade.lock');
            }
        }

        $messages = [];
        foreach ($pending as $step) {
            $messages[] = $step['description'];
        }

        return [
            'applied' => $applied,
            'message' => '已执行 ' . count($applied) . ' 项迁移：' . implode('；', $messages),
        ];
    }

    private static function saveVersion(string $version, array $applied): void
    {
        $file = self::file();
        $tmp = $file . '.tmp';
        $json = json_encode([
            'version' => self::normalize($version),
            'applied' => array_values(array_unique($applied)),
            'updated_at' => date('c'),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        if ($json === false || @file_put_contents($tmp, $json, LOCK_EX) === false || !@rename($tmp, $file)) {
            @unlink($tmp);
            throw new RuntimeException('写入版本文件失败，请检查 data 目录权限');
        }
        @chmod($file, 0640);
    }

    /** 迁移前备份 settings.json，便于回滚配置 */
    private static function backupSettings(): void
    {
        $src = Config::settingsFile();
        if (!is_file($src)) {
            return;
        }

        $dir = (string)Config::base('data_dir') . '/backups';
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }

        $dst = $dir . '/settings-before-upgrade-' . date('Ymd-His') . '.json';
        if (!@copy($src, $dst)) {
            throw new RuntimeException('升级前备份 settings.json 失败，已停止升级');
        }
        @chmod($dst, 0640);
    }

    private static function normalize(string $version): string
    {
        $version = trim($version);
        return preg_match('/^\d+(?:\.\d+){0,2}$/', $version) === 1 ? $version : '0.0.0';
    }
}
