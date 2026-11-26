<?php
declare(strict_types=1);

/**
 * 文件落盘：storage/{site}/{YYYYMM}/{id}.{ext}
 * 元数据：data/meta/{site}/{YYYYMM}/{id}.json
 * 回收站：trash/{site}/{YYYYMM}/ + data/trash/{site}/{YYYYMM}/{id}.json
 *
 * id = 内容哈希前 16 位，同一张图同档位重复上传直接复用。
 */
final class Store
{
    public static function root(): string
    {
        $dir = (string)Config::base('storage_dir');
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return rtrim($dir, '/\\');
    }

    public static function siteDir(string $site): string
    {
        return self::root() . '/' . $site;
    }

    public static function monthDir(string $site, string $month): string
    {
        return self::siteDir($site) . '/' . $month;
    }

    public static function month(): string
    {
        return date('Ym');
    }

    private static function validId(string $id): bool
    {
        return (bool)preg_match('/^[a-f0-9]{8,64}$/', $id);
    }

    private static function validMonth(string $month): bool
    {
        return (bool)preg_match('/^\d{6}$/', $month);
    }

    private static function validSite(string $site): bool
    {
        return Sites::slugOk($site);
    }

    // ---------- 写入 / 查询 ----------

    /** 已经存在则直接返回，用于秒传（同一站点内） */
    public static function find(string $site, string $id): ?array
    {
        if (!self::validSite($site) || !self::validId($id)) {
            return null;
        }

        foreach (glob(self::siteDir($site) . '/*/' . $id . '.*') ?: [] as $file) {
            if (!is_file($file)) {
                continue;
            }
            $month = basename(dirname($file));
            $name  = basename($file);
            return [
                'site'  => $site,
                'month' => $month,
                'name'  => $name,
                'path'  => $file,
                'meta'  => self::meta($site, $month, $id),
                'url'   => self::url($site, $month, $name),
            ];
        }
        return null;
    }

    public static function put(string $site, string $tmpFile, string $id, string $ext, array $meta): array
    {
        if (!self::validSite($site) || !self::validId($id)) {
            throw new RuntimeException('非法站点或文件名');
        }

        $ext   = strtolower((string)preg_replace('/[^a-z0-9]/i', '', $ext));
        $month = self::month();
        $dir   = self::monthDir($site, $month);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $name   = $id . '.' . $ext;
        $target = $dir . '/' . $name;

        if (is_file($target)) {
            @unlink($tmpFile);
        } else {
            $moved = @rename($tmpFile, $target);

            // 并发上传同一张图时，另一个请求可能已经先写好了。
            // 遇到这种情况按秒传处理，不要误报“写入失败”。
            if (!$moved && !is_file($target)) {
                $moved = @copy($tmpFile, $target);
                if ($moved) {
                    @unlink($tmpFile);
                }
            }

            if ($moved && is_file($target)) {
                @chmod($target, 0644);
            } elseif (is_file($target)) {
                @unlink($tmpFile);
            } else {
                throw new RuntimeException('图片写入失败，请检查 storage 目录权限');
            }
        }

        self::saveMeta($site, $month, $id, $meta);

        return [
            'site'  => $site,
            'month' => $month,
            'name'  => $name,
            'path'  => $target,
            'url'   => self::url($site, $month, $name),
        ];
    }

    private static function metaFile(string $site, string $month, string $id): string
    {
        return (string)Config::base('data_dir') . '/meta/' . $site . '/' . $month . '/' . $id . '.json';
    }

    /** 仅在目录为空时删除，避免留下大量月份/站点空目录。 */
    private static function removeEmptyDir(string $dir): void
    {
        if ($dir !== '' && is_dir($dir)) {
            @rmdir($dir);
        }
    }

    public static function saveMeta(string $site, string $month, string $id, array $meta): void
    {
        if (!self::validSite($site) || !self::validMonth($month) || !self::validId($id)) {
            return;
        }
        $dir = dirname(self::metaFile($site, $month, $id));
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents(
            self::metaFile($site, $month, $id),
            json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
        );
    }

    public static function meta(string $site, string $month, string $id): ?array
    {
        if (!self::validSite($site) || !self::validMonth($month) || !self::validId($id)) {
            return null;
        }
        $file = self::metaFile($site, $month, $id);
        if (!is_file($file)) {
            return null;
        }
        $json = json_decode((string)@file_get_contents($file), true);
        return is_array($json) ? $json : null;
    }

    /** 直链：{base_url}/i/{site}/{YYYYMM}/{name} */
    public static function url(string $site, string $month, string $name): string
    {
        $base = trim((string)Config::get('base_url', ''));
        if ($base === '') {
            $base = Http::baseUrl();
        }
        return rtrim($base, '/') . '/i/' . $site . '/' . $month . '/' . $name;
    }

    // ---------- 回收站 ----------

    private static function trashMetaFile(string $site, string $month, string $id): string
    {
        return (string)Config::base('data_dir') . '/trash/' . $site . '/' . $month . '/' . $id . '.json';
    }

    public static function trash(string $site, string $month, string $id): bool
    {
        if (!self::validSite($site) || !self::validMonth($month) || !self::validId($id)) {
            return false;
        }

        $dir = (string)Config::base('trash_dir') . '/' . $site . '/' . $month;
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $moved = false;
        foreach (glob(self::monthDir($site, $month) . '/' . $id . '.*') ?: [] as $file) {
            if (!is_file($file)) {
                continue;
            }
            $dest = $dir . '/' . basename($file);
            if (@rename($file, $dest)) {
                $moved = true;
            } elseif (@copy($file, $dest)) {
                @unlink($file);
                $moved = true;
            }
        }

        if (!$moved) {
            return false;
        }

        $meta = self::meta($site, $month, $id) ?? [];
        $meta['id']         = $id;
        $meta['site']       = $site;
        $meta['deleted_at'] = date('Y-m-d H:i:s');

        $metaDir = dirname(self::trashMetaFile($site, $month, $id));
        if (!is_dir($metaDir)) {
            @mkdir($metaDir, 0755, true);
        }
        @file_put_contents(
            self::trashMetaFile($site, $month, $id),
            json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
        );

        @unlink(self::metaFile($site, $month, $id));

        self::removeEmptyDir(self::monthDir($site, $month));
        self::removeEmptyDir(self::siteDir($site));
        self::removeEmptyDir(dirname(self::metaFile($site, $month, $id)));
        self::removeEmptyDir(dirname(dirname(self::metaFile($site, $month, $id))));

        return true;
    }

    /** ok / conflict（原位置已有同名文件）/ missing */
    public static function restore(string $site, string $month, string $id): string
    {
        if (!self::validSite($site) || !self::validMonth($month) || !self::validId($id)) {
            return 'missing';
        }

        $files = glob((string)Config::base('trash_dir') . '/' . $site . '/' . $month . '/' . $id . '.*') ?: [];
        if ($files === []) {
            return 'missing';
        }

        if ((glob(self::monthDir($site, $month) . '/' . $id . '.*') ?: []) !== []) {
            return 'conflict';
        }

        $dir = self::monthDir($site, $month);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $ok = false;
        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }
            $dest = $dir . '/' . basename($file);
            if (@rename($file, $dest)) {
                $ok = true;
            } elseif (@copy($file, $dest)) {
                @unlink($file);
                $ok = true;
            }
            if ($ok) {
                @chmod($dest, 0644);
            }
        }

        if (!$ok) {
            return 'missing';
        }

        $metaFile = self::trashMetaFile($site, $month, $id);
        $meta = is_file($metaFile) ? (json_decode((string)@file_get_contents($metaFile), true) ?: []) : [];
        unset($meta['deleted_at']);
        self::saveMeta($site, $month, $id, $meta);
        @unlink($metaFile);

        self::removeEmptyDir(dirname($metaFile));
        self::removeEmptyDir(dirname(dirname($metaFile)));
        self::removeEmptyDir((string)Config::base('trash_dir') . '/' . $site . '/' . $month);
        self::removeEmptyDir((string)Config::base('trash_dir') . '/' . $site);

        return 'ok';
    }

    public static function purge(string $site, string $month, string $id): bool
    {
        if (!self::validSite($site) || !self::validMonth($month) || !self::validId($id)) {
            return false;
        }

        $ok = false;
        foreach (glob((string)Config::base('trash_dir') . '/' . $site . '/' . $month . '/' . $id . '.*') ?: [] as $file) {
            if (@unlink($file)) {
                $ok = true;
            }
        }
        $metaFile = self::trashMetaFile($site, $month, $id);
        @unlink($metaFile);

        self::removeEmptyDir(dirname($metaFile));
        self::removeEmptyDir(dirname(dirname($metaFile)));
        self::removeEmptyDir((string)Config::base('trash_dir') . '/' . $site . '/' . $month);
        self::removeEmptyDir((string)Config::base('trash_dir') . '/' . $site);

        return $ok;
    }

    // ---------- 列表 / 统计 ----------

    public static function months(?string $site = null): array
    {
        $found = [];
        $sites = $site !== null ? [$site] : array_column(Sites::all(), 'slug');

        foreach ($sites as $s) {
            if (!self::validSite((string)$s)) {
                continue;
            }
            foreach (glob(self::siteDir((string)$s) . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
                $m = basename($dir);
                if (!self::validMonth($m)) {
                    continue;
                }
                foreach (glob($dir . '/*') ?: [] as $file) {
                    if (is_file($file) && !str_starts_with(basename($file), '.')) {
                        $found[$m] = true;
                        break;
                    }
                }
            }
        }

        $list = array_keys($found);
        rsort($list);
        return array_map('strval', $list);
    }

    public static function countMonth(string $site, string $month): int
    {
        if (!self::validSite($site) || !self::validMonth($month)) {
            return 0;
        }
        $files = glob(self::monthDir($site, $month) . '/*') ?: [];
        return count(array_filter($files, static fn($f) => is_file($f) && !str_starts_with(basename($f), '.')));
    }

    /**
     * 某个（或全部站点的）某月图片列表；$month 传 'all' 时跨月收集（配合搜索）
     * @return array{items:array,total:int,page:int,pages:int}
     */
    public static function listMonth(?string $site, string $month, int $page = 1, int $perPage = 40, string $keyword = ''): array
    {
        if ($month !== 'all' && !self::validMonth($month)) {
            return ['items' => [], 'total' => 0, 'page' => 1, 'pages' => 1];
        }

        $sites = $site !== null ? [$site] : array_column(Sites::all(), 'slug');
        $items = [];

        foreach ($sites as $s) {
            if (!self::validSite((string)$s)) {
                continue;
            }

            $months = $month === 'all' ? self::months((string)$s) : [$month];

            foreach ($months as $m) {
                foreach (glob(self::monthDir((string)$s, (string)$m) . '/*') ?: [] as $file) {
                    if (!is_file($file)) {
                        continue;
                    }
                    $name = basename($file);
                    $id   = pathinfo($name, PATHINFO_FILENAME);
                    if (!self::validId($id)) {
                        continue;
                    }

                    $meta = self::meta((string)$s, (string)$m, $id) ?? [];
                    $stat = @stat($file);

                    $items[] = [
                        'site'          => (string)$s,
                        'id'            => $id,
                        'month'         => (string)$m,
                        'name'          => $name,
                        'url'           => self::url((string)$s, (string)$m, $name),
                        'bytes'         => (int)($stat['size'] ?? 0),
                        'origin_bytes'  => (int)($meta['origin_bytes'] ?? 0),
                        'width'         => (int)($meta['width'] ?? 0),
                        'height'        => (int)($meta['height'] ?? 0),
                        'format'        => pathinfo($name, PATHINFO_EXTENSION),
                        'original_name' => (string)($meta['original_name'] ?? $name),
                        'uploaded_at'   => (string)($meta['uploaded_at'] ?? date('Y-m-d H:i:s', (int)($stat['mtime'] ?? time()))),
                    ];
                }
            }
        }

        $keyword = trim($keyword);
        if ($keyword !== '') {
            $needle = mb_strtolower($keyword);
            $items = array_values(array_filter($items, static function (array $it) use ($needle): bool {
                return str_contains(mb_strtolower($it['original_name']), $needle)
                    || str_contains($it['id'], $needle);
            }));
        }

        usort($items, static fn(array $a, array $b): int => strcmp($b['uploaded_at'], $a['uploaded_at']));

        $total = count($items);
        $pages = max(1, (int)ceil($total / max(1, $perPage)));
        $page  = max(1, min($page, $pages));

        return [
            'items' => array_slice($items, ($page - 1) * $perPage, $perPage),
            'total' => $total,
            'page'  => $page,
            'pages' => $pages,
        ];
    }

    /** 回收站列表，按删除时间倒序 */
    public static function listTrash(?string $site, int $page = 1, int $perPage = 40): array
    {
        $sites = $site !== null ? [$site] : array_column(Sites::all(), 'slug');
        $items = [];
        $keepDays = max(1, (int)Config::get('trash_keep_days', 30));

        foreach ($sites as $s) {
            if (!self::validSite((string)$s)) {
                continue;
            }
            foreach (glob((string)Config::base('data_dir') . '/trash/' . $s . '/*', GLOB_ONLYDIR) ?: [] as $monthDir) {
                $month = basename($monthDir);
                if (!self::validMonth($month)) {
                    continue;
                }
                foreach (glob($monthDir . '/*.json') ?: [] as $metaFile) {
                    $id = basename($metaFile, '.json');
                    if (!self::validId($id)) {
                        continue;
                    }
                    $files = glob((string)Config::base('trash_dir') . '/' . $s . '/' . $month . '/' . $id . '.*') ?: [];
                    if ($files === []) {
                        continue;
                    }
                    $file = $files[0];
                    $meta = json_decode((string)@file_get_contents($metaFile), true) ?: [];
                    $deletedAt = (string)($meta['deleted_at'] ?? '');
                    $ts = $deletedAt !== '' ? (int)strtotime($deletedAt) : (int)@filemtime($file);

                    $items[] = [
                        'site'          => (string)$s,
                        'id'            => $id,
                        'month'         => $month,
                        'name'          => basename($file),
                        'bytes'         => (int)@filesize($file),
                        'width'         => (int)($meta['width'] ?? 0),
                        'height'        => (int)($meta['height'] ?? 0),
                        'original_name' => (string)($meta['original_name'] ?? basename($file)),
                        'deleted_at'    => $deletedAt,
                        'ts'            => $ts,
                        'days_left'     => max(0, $keepDays - (int)floor((time() - $ts) / 86400)),
                    ];
                }
            }
        }

        usort($items, static fn(array $a, array $b): int => $b['ts'] <=> $a['ts']);

        $total = count($items);
        $pages = max(1, (int)ceil($total / max(1, $perPage)));
        $page  = max(1, min($page, $pages));

        return [
            'items' => array_slice($items, ($page - 1) * $perPage, $perPage),
            'total' => $total,
            'page'  => $page,
            'pages' => $pages,
        ];
    }

    public static function purgeExpired(int $days = 30): int
    {
        $count = 0;
        $deadline = time() - $days * 86400;

        foreach (glob((string)Config::base('data_dir') . '/trash/*', GLOB_ONLYDIR) ?: [] as $siteDir) {
            $site = basename($siteDir);
            if (!self::validSite($site)) {
                continue;
            }
            foreach (glob($siteDir . '/*', GLOB_ONLYDIR) ?: [] as $monthDir) {
                $month = basename($monthDir);
                if (!self::validMonth($month)) {
                    continue;
                }
                foreach (glob($monthDir . '/*.json') ?: [] as $metaFile) {
                    $id = basename($metaFile, '.json');
                    if (!self::validId($id)) {
                        continue;
                    }
                    $meta = json_decode((string)@file_get_contents($metaFile), true) ?: [];
                    $ts = !empty($meta['deleted_at']) ? (int)strtotime((string)$meta['deleted_at']) : (int)@filemtime($metaFile);
                    if ($ts > 0 && $ts < $deadline && self::purge($site, $month, $id)) {
                        $count++;
                    }
                }
            }
        }

        return $count;
    }

    /**
     * 导出用：一次取该筛选下的全部链接（不分页）
     * @return array<int,array{url:string,markdown:string,html:string,name:string,month:string,site:string}>
     */
    public static function links(?string $site, string $month, string $keyword = ''): array
    {
        $list = self::listMonth($site, $month, 1, 1000000, $keyword);
        $out = [];
        foreach ($list['items'] as $it) {
            $out[] = [
                'url'      => $it['url'],
                'markdown' => '![' . self::altText($it['original_name']) . '](' . $it['url'] . ')',
                'html'     => '<img src="' . $it['url'] . '" alt="' . htmlspecialchars(self::altText($it['original_name']), ENT_QUOTES) . '">',
                'name'     => $it['original_name'],
                'month'    => $it['month'],
                'site'     => $it['site'],
            ];
        }
        return $out;
    }

    /** 文件名去掉扩展名当 alt，太长的截断 */
    private static function altText(string $originalName): string
    {
        $base = (string)pathinfo($originalName, PATHINFO_FILENAME);
        $base = trim((string)preg_replace('/[\r\n\[\]\(\)]/u', ' ', $base));
        return $base === '' ? '图片' : mb_substr($base, 0, 40);
    }

    /** 回收站占用（张数 / 字节），用于首页与后台提醒 */
    public static function trashUsage(): array
    {
        $bytes = 0;
        $count = 0;
        foreach (glob((string)Config::base('trash_dir') . '/*/*/*') ?: [] as $file) {
            if (is_file($file) && !str_starts_with(basename($file), '.')) {
                $bytes += (int)@filesize($file);
                $count++;
            }
        }
        return ['bytes' => $bytes, 'count' => $count];
    }

    /**
     * 容量面板数据：总占用、各站点占用、本月新增、磁盘余量
     */
    public static function capacity(): array
    {
        $perSite = [];
        $total = 0;
        $count = 0;
        $thisMonth = 0;

        foreach (Sites::all() as $site) {
            $slug = $site['slug'];
            $bytes = 0;
            $n = 0;

            foreach (glob(self::siteDir($slug) . '/*', GLOB_ONLYDIR) ?: [] as $monthDir) {
                if (!self::validMonth(basename($monthDir))) {
                    continue;
                }
                foreach (glob($monthDir . '/*') ?: [] as $file) {
                    if (!is_file($file) || str_starts_with(basename($file), '.')) {
                        continue;
                    }
                    $bytes += (int)@filesize($file);
                    $n++;
                    if (basename($monthDir) === self::month()) {
                        $thisMonth++;
                    }
                }
            }

            $perSite[$slug] = [
                'slug'  => $slug,
                'name'  => $site['name'],
                'bytes' => $bytes,
                'count' => $n,
            ];
            $total += $bytes;
            $count += $n;
        }

        $diskFree = is_dir(self::root()) ? @disk_free_space(self::root()) : false;
        $trash = self::trashUsage();

        return [
            'total_bytes'  => $total,
            'total_count'  => $count,
            'this_month'   => $thisMonth,
            'sites'        => $perSite,
            'disk_free'    => $diskFree === false ? null : (float)$diskFree,
            'trash_bytes'  => $trash['bytes'],
            'trash_count'  => $trash['count'],
        ];
    }
}
