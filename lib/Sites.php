<?php
declare(strict_types=1);

/**
 * 站点（多个博客共用一个图床时用来分组存储和统计）。
 * 数据落在 data/sites.json，无数据库。
 */
final class Sites
{
    private static ?array $cache = null;

    public static function file(): string
    {
        return (string)Config::base('data_dir') . '/sites.json';
    }

    public static function slugOk(string $slug): bool
    {
        return (bool)preg_match('/^[a-z0-9][a-z0-9\-_]{0,31}$/', $slug) && $slug !== 'all';
    }

    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $file = self::file();
        $list = [];

        if (is_file($file)) {
            $json = json_decode((string)@file_get_contents($file), true);
            if (is_array($json)) {
                foreach ($json as $row) {
                    if (is_array($row) && isset($row['slug'], $row['name']) && self::slugOk((string)$row['slug'])) {
                        $list[] = [
                            'slug'       => (string)$row['slug'],
                            'name'       => (string)$row['name'],
                            'created_at' => (string)($row['created_at'] ?? ''),
                        ];
                    }
                }
            }
        }

        if ($list === []) {
            $list[] = ['slug' => 'default', 'name' => '默认站点', 'created_at' => date('Y-m-d H:i:s')];
            self::save($list);
        }

        return self::$cache = $list;
    }

    public static function save(array $list): void
    {
        $file = self::file();
        $tmp  = $file . '.tmp';
        $json = json_encode(array_values($list), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false || @file_put_contents($tmp, $json, LOCK_EX) === false) {
            throw new RuntimeException('站点配置写入失败，请检查 data 目录权限');
        }
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            throw new RuntimeException('站点配置写入失败');
        }
        @chmod($file, 0640);
        self::$cache = null;
    }

    public static function find(string $slug): ?array
    {
        foreach (self::all() as $site) {
            if ($site['slug'] === $slug) {
                return $site;
            }
        }
        return null;
    }

    public static function exists(string $slug): bool
    {
        return self::find($slug) !== null;
    }

    /** 默认站点：列表里的第一个，用于没传 site 参数的接口 */
    public static function defaultSlug(): string
    {
        $all = self::all();
        return $all[0]['slug'];
    }

    public static function add(string $name, string $slug = ''): array
    {
        $name = trim($name);
        if ($name === '') {
            throw new RuntimeException('站点名称不能为空');
        }
        if (mb_strlen($name) > 30) {
            throw new RuntimeException('站点名称最多 30 个字');
        }

        $slug = strtolower(trim($slug));
        if ($slug === '') {
            $slug = preg_match('/^[a-z0-9\-]+$/', strtolower($name))
                ? strtolower(preg_replace('/[^a-z0-9\-]/', '-', strtolower($name)))
                : 'site-' . bin2hex(random_bytes(2));
            $slug = trim(preg_replace('/-+/', '-', $slug), '-');
            if ($slug === '') {
                $slug = 'site-' . bin2hex(random_bytes(2));
            }
        }

        if (!self::slugOk($slug)) {
            throw new RuntimeException('站点标识只能用字母、数字、连字符、下划线，1-32 位，且不能叫 all');
        }
        if (self::exists($slug)) {
            throw new RuntimeException('站点标识「' . $slug . '」已被占用');
        }

        $list = self::all();
        $list[] = ['slug' => $slug, 'name' => $name, 'created_at' => date('Y-m-d H:i:s')];
        self::save($list);

        return ['slug' => $slug, 'name' => $name];
    }

    /**
     * 删除站点。有图片时拒绝，避免把文件变成无主数据。
     * 返回 ok / notfound / last / not_empty
     */
    public static function remove(string $slug): string
    {
        if (!self::exists($slug)) {
            return 'notfound';
        }

        $list = self::all();
        if (count($list) <= 1) {
            return 'last';
        }

        $dir = (string)Config::base('storage_dir') . '/' . $slug;
        $files = is_dir($dir) ? (glob($dir . '/*/*') ?: []) : [];
        $count = count(array_filter($files, static fn($f) => is_file($f)));

        // 回收站里也属于该站点的数据，不能因为 storage 已空就把站点删掉，
        // 否则 trash/ 和 data/trash/ 下的文件会变成永远列不出来的孤儿。
        $trashDir = (string)Config::base('trash_dir') . '/' . $slug;
        $trashFiles = is_dir($trashDir) ? (glob($trashDir . '/*/*') ?: []) : [];
        $trashCount = count(array_filter($trashFiles, static fn($f) => is_file($f)));

        $trashMetaDir = (string)Config::base('data_dir') . '/trash/' . $slug;
        $trashMetaFiles = is_dir($trashMetaDir) ? (glob($trashMetaDir . '/*/*') ?: []) : [];
        $trashMetaCount = count(array_filter($trashMetaFiles, static fn($f) => is_file($f)));

        if ($count + $trashCount + $trashMetaCount > 0) {
            return 'not_empty';
        }

        $list = array_values(array_filter($list, static fn(array $s): bool => $s['slug'] !== $slug));
        self::save($list);

        if (is_dir($dir)) {
            @rmdir($dir);
        }
        if (is_dir($trashDir)) {
            @rmdir($trashDir);
        }
        if (is_dir($trashMetaDir)) {
            @rmdir($trashMetaDir);
        }

        return 'ok';
    }

    /**
     * 扫描图片库与回收站里存在、但站点列表中没有登记的目录。
     * 覆盖更新或误删 data/sites.json 后，图片仍躺在磁盘上，用这里找回。
     *
     * @return array<int, array{slug:string, files:int, trash:int, months:int}>
     */
    public static function orphans(): array
    {
        $known = [];
        foreach (self::all() as $site) {
            $known[$site['slug']] = true;
        }

        $roots = [
            'storage' => (string)Config::base('storage_dir'),
            'trash'   => (string)Config::base('trash_dir'),
        ];

        $found = [];
        foreach ($roots as $kind => $root) {
            if (!is_dir($root)) {
                continue;
            }
            foreach (glob($root . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
                $slug = basename($dir);
                if (!self::slugOk($slug) || isset($known[$slug])) {
                    continue;
                }
                if (!isset($found[$slug])) {
                    $found[$slug] = ['slug' => $slug, 'files' => 0, 'trash' => 0, 'months' => 0];
                }

                $months = glob($dir . '/*', GLOB_ONLYDIR) ?: [];
                if (count($months) > $found[$slug]['months']) {
                    $found[$slug]['months'] = count($months);
                }

                $files = 0;
                foreach ($months as $monthDir) {
                    foreach (glob($monthDir . '/*') ?: [] as $f) {
                        if (is_file($f)) {
                            $files++;
                        }
                    }
                }
                $found[$slug][$kind === 'storage' ? 'files' : 'trash'] = $files;
            }
        }

        ksort($found);

        return array_values(array_filter($found, static fn(array $o): bool => $o['files'] + $o['trash'] > 0));
    }

    /** 把磁盘上已有的站点目录登记回 sites.json，不移动、不改名任何文件 */
    public static function adopt(string $slug, string $name = ''): array
    {
        $slug = strtolower(trim($slug));
        if (!self::slugOk($slug)) {
            throw new RuntimeException('站点标识「' . $slug . '」不合法');
        }
        if (self::exists($slug)) {
            throw new RuntimeException('站点标识「' . $slug . '」已经登记过了');
        }

        $name = trim($name);
        if ($name === '') {
            $name = $slug . '（已恢复）';
        }
        if (mb_strlen($name) > 30) {
            $name = mb_substr($name, 0, 30);
        }

        $list   = self::all();
        $list[] = ['slug' => $slug, 'name' => $name, 'created_at' => date('Y-m-d H:i:s')];
        self::save($list);

        return ['slug' => $slug, 'name' => $name];
    }
}
