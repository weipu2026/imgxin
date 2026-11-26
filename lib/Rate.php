<?php
declare(strict_types=1);

/**
 * 极简滑动窗口限流：按 key（通常是 IP）统计最近一段时间内的命中次数。
 * 无数据库，落在 data/rate/*.json，单文件加锁写入。
 *
 * hit()   —— 计数 +1，超过上限返回 false（用于上传等"每次都要算"的场景）
 * blocked() —— 只读判断，不消耗名额（用于登录锁定）
 * fail()  —— 记一次失败，返回窗口内累计失败次数
 * reset() —— 清空（登录成功后调用）
 */
final class Rate
{
    public static function hit(string $key, int $limit, int $window = 3600): bool
    {
        if ($limit <= 0) {
            return true;
        }

        $now = time();
        $ok  = true;
        self::mutate($key, static function (array $hits) use ($limit, $now, $window, &$ok): array {
            $hits = self::prune($hits, $now - $window);
            if (count($hits) >= $limit) {
                $ok = false;
                return $hits;
            }
            $hits[] = $now;
            return $hits;
        });

        return $ok;
    }

    public static function blocked(string $key, int $limit, int $window): bool
    {
        if ($limit <= 0) {
            return false;
        }
        $file = self::file($key);
        if (!is_file($file)) {
            return false;
        }
        $hits = self::prune(json_decode((string)@file_get_contents($file), true) ?: [], time() - $window);
        return count($hits) >= $limit;
    }

    /** 记一次失败，返回窗口内的累计次数 */
    public static function fail(string $key, int $window): int
    {
        $now = time();
        $count = 0;
        self::mutate($key, static function (array $hits) use ($now, $window, &$count): array {
            $hits = self::prune($hits, $now - $window);
            $hits[] = $now;
            $count = count($hits);
            return $hits;
        });
        return $count;
    }

    public static function reset(string $key): void
    {
        $file = self::file($key);
        if (is_file($file)) {
            @unlink($file);
        }
    }

    private static function file(string $key): string
    {
        $dir = (string)Config::base('data_dir') . '/rate';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir . '/' . md5($key) . '.json';
    }

    private static function prune(array $hits, int $since): array
    {
        return array_values(array_filter($hits, static fn($t) => is_int($t) && $t > $since));
    }

    /** 读-改-写，带文件锁；文件不可写时按"放行"处理，不把正常业务卡死 */
    private static function mutate(string $key, callable $fn): array
    {
        $file = self::file($key);
        $fh = @fopen($file, 'c+');
        if ($fh === false) {
            return [];
        }

        $hits = [];
        try {
            if (!flock($fh, LOCK_EX)) {
                // 拿不到锁就跳过本次限流，不把正常业务卡死
                return [];
            }
            $raw = stream_get_contents($fh);
            $hits = is_string($raw) && $raw !== '' ? (json_decode($raw, true) ?: []) : [];
            if (!is_array($hits)) {
                $hits = [];
            }
            $hits = $fn($hits);

            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, json_encode($hits));
            fflush($fh);
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }

        return $hits;
    }
}
