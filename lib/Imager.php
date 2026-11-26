<?php
declare(strict_types=1);

/**
 * 图片转码。统一走 Imagick：能自动纠正 EXIF 方向、能转动态 webp、能出 AVIF。
 */
final class Imager
{
    public const MAX_FRAMES = 300;

    public static function available(): bool
    {
        return self::backend() !== '';
    }

    /** imagick 优先；没有就退回 GD（功能少一些，但至少能转 webp/avif） */
    public static function backend(): string
    {
        if (extension_loaded('imagick') && class_exists('Imagick')) {
            return 'imagick';
        }
        if (extension_loaded('gd') && function_exists('imagewebp')) {
            return 'gd';
        }
        return '';
    }

    public static function supports(string $format): bool
    {
        $format = strtoupper($format);
        $backend = self::backend();

        if ($backend === 'imagick') {
            try {
                return in_array($format, array_map('strtoupper', Imagick::queryFormats($format)), true);
            } catch (Throwable) {
                return false;
            }
        }

        if ($backend === 'gd') {
            return match ($format) {
                'WEBP' => function_exists('imagewebp'),
                'AVIF' => function_exists('imageavif'),
                'PNG'  => true,
                default => false,
            };
        }

        return false;
    }

    /**
     * @param string $src     源文件
     * @param string $dst     输出文件
     * @param string $target  目标格式大写：WEBP / AVIF / PNG
     * @param int    $quality 有损质量
     * @param bool   $losslessAlpha 带透明通道时用无损 webp
     * @param int    $maxEdge 最长边上限，0 表示不缩放
     * @return array{format:string,width:int,height:int,frames:int,bytes:int}
     */
    public static function convert(string $src, string $dst, string $target, int $quality, bool $losslessAlpha, int $maxEdge = 0): array
    {
        if (!self::available()) {
            throw new RuntimeException('服务器既没有 imagick 也没有 GD，无法转码');
        }

        $maxEdge = max(0, min(20000, $maxEdge));

        if (self::backend() === 'gd') {
            return self::convertGd($src, $dst, $target, $quality, $losslessAlpha, $maxEdge);
        }

        $target  = strtoupper($target);
        $quality = max(60, min(95, $quality));

        $im = new Imagick();
        $im->setResourceLimit(Imagick::RESOURCETYPE_MEMORY, 256 * 1024 * 1024);
        $im->setResourceLimit(Imagick::RESOURCETYPE_MAP, 512 * 1024 * 1024);
        $im->setResourceLimit(Imagick::RESOURCETYPE_DISK, 1024 * 1024 * 1024);

        try {
            $im->readImage($src);
        } catch (Throwable $e) {
            $im->clear();
            $im->destroy();
            throw new RuntimeException('图片无法解码：' . $e->getMessage());
        }

        $frames = $im->getNumberImages();
        if ($frames > self::MAX_FRAMES) {
            $im->clear();
            throw new RuntimeException('动图帧数过多（' . $frames . ' 帧），请压缩后再传');
        }

        // 透明通道检测
        $hasAlpha = false;
        $im->setFirstIterator();
        foreach ($im as $frame) {
            try {
                if ($frame->getImageAlphaChannel()) {
                    $hasAlpha = true;
                    break;
                }
            } catch (Throwable) {
                // 个别格式查询透明通道会抛异常，按无透明处理
            }
        }
        $im->setFirstIterator();

        // 动图先把每帧展开成完整画布，避免转换后残影
        if ($frames > 1) {
            $coalesced = $im->coalesceImages();
            $im->clear();
            $im            = $coalesced;
            $frames        = $im->getNumberImages();
            $im->setFirstIterator();
        }

        // 单帧图：纠正手机拍照的 EXIF 方向，再转 sRGB 统一颜色
        if ($frames === 1) {
            if ($im->getImageOrientation() !== Imagick::ORIENTATION_UNDEFINED) {
                $im->autoOrient();
            }
            try {
                $im->transformImageColorspace(Imagick::COLORSPACE_SRGB);
            } catch (Throwable) {
                // 少数颜色空间转换失败时保持原样
            }
        }

        // 按最长边等比缩小（博客正文用不到手机原图的 4000px）。逐帧处理以兼容动图。
        if ($maxEdge > 0) {
            $im->setFirstIterator();
            do {
                $w = $im->getImageWidth();
                $h = $im->getImageHeight();
                if ($w > 0 && $h > 0 && max($w, $h) > $maxEdge) {
                    $ratio = $maxEdge / max($w, $h);
                    $im->scaleImage(max(1, (int)round($w * $ratio)), max(1, (int)round($h * $ratio)));
                }
            } while ($im->nextImage());
            $im->setFirstIterator();
        }

        // 剥掉 EXIF/GPS/缩略图等全部元数据（隐私 + 体积）
        $im->stripImage();

        if ($frames > 1 && $target === 'AVIF') {
            // AVIF 多帧支持不稳，动图统一回落到 webp
            $target = 'WEBP';
        }

        $im->setImageFormat(strtolower($target));

        // 动图必须逐帧设置格式，否则 writeImages 会沿用首帧之外的原格式
        if ($frames > 1) {
            $im->setFirstIterator();
            do {
                $im->setImageFormat(strtolower($target));
            } while ($im->nextImage());
            $im->setFirstIterator();
        }

        if ($target === 'WEBP') {
            $im->setOption('webp:method', '6');
            // 压缩质量是逐帧属性；动图必须每一帧都设置，否则后续帧会沿用
            // coalesce 后遗留的质量值，导致同一张 GIF 各帧画质不一致。
            $im->setFirstIterator();
            do {
                // 无损只给单帧透明图用；动图走有损，否则体积可能比原图还大
                if ($hasAlpha && $losslessAlpha && $frames === 1) {
                    $im->setOption('webp:lossless', 'true');
                    $im->setImageCompressionQuality(100);
                } else {
                    $im->setOption('webp:lossless', 'false');
                    $im->setImageCompressionQuality($quality);
                }
            } while ($im->nextImage());
            $im->setFirstIterator();
        } elseif ($target === 'AVIF') {
            $im->setFirstIterator();
            do {
                $im->setImageCompressionQuality($quality);
            } while ($im->nextImage());
            $im->setFirstIterator();
        } elseif ($target === 'PNG') {
            $im->setFirstIterator();
            do {
                $im->setImageCompression(Imagick::COMPRESSION_ZIP);
                $im->setImageCompressionQuality(9);
            } while ($im->nextImage());
            $im->setFirstIterator();
        }

        try {
            if ($frames > 1) {
                $im->writeImages($dst, true);
            } else {
                $im->writeImage($dst);
            }
        } catch (Throwable $e) {
            $im->clear();
            @unlink($dst);
            throw new RuntimeException('转码失败：' . $e->getMessage());
        }

        $im->setFirstIterator();
        $width  = $im->getImageWidth();
        $height = $im->getImageHeight();
        $im->clear();
        $im->destroy();

        if (!is_file($dst)) {
            throw new RuntimeException('转码失败：输出文件未生成');
        }
        @chmod($dst, 0644);

        return [
            'format' => strtolower($target),
            'width'  => $width,
            'height' => $height,
            'frames' => $frames,
            'bytes'  => (int)filesize($dst),
        ];
    }

    /**
     * GD 兜底实现：动图只取首帧，不做 EXIF 方向纠正（exif 扩展在时可由调用方预处理）
     * 适合没有 imagick 的机器，画质略逊于 Imagick。
     */
    private static function convertGd(string $src, string $dst, string $target, int $quality, bool $losslessAlpha, int $maxEdge = 0): array
    {
        $target  = strtoupper($target);
        $quality = max(60, min(95, $quality));

        $info = @getimagesize($src);
        if ($info === false || empty($info[2])) {
            throw new RuntimeException('图片无法解码');
        }

        $width  = (int)$info[0];
        $height = (int)$info[1];
        if ($width * $height > 20_000_000) {
            throw new RuntimeException('GD 模式下图片不能超过 2000 万像素，装 imagick 可解除');
        }

        $im = match ((int)$info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($src),
            IMAGETYPE_PNG  => @imagecreatefrompng($src),
            IMAGETYPE_GIF  => @imagecreatefromgif($src),
            IMAGETYPE_WEBP => @imagecreatefromwebp($src),
            IMAGETYPE_BMP  => @imagecreatefrombmp($src),
            default        => false,
        };

        if (!$im) {
            throw new RuntimeException('图片无法解码（GD 不支持该格式）');
        }

        if (function_exists('imagepalettetotruecolor')) {
            @imagepalettetotruecolor($im);
        }
        imagealphablending($im, false);
        imagesavealpha($im, true);

        // 按最长边等比缩小（GD 只会缩不会放大）
        if ($maxEdge > 0 && max($width, $height) > $maxEdge) {
            $ratio = $maxEdge / max($width, $height);
            $nw = max(1, (int)round($width * $ratio));
            $nh = max(1, (int)round($height * $ratio));
            $scaled = imagescale($im, $nw, $nh, IMG_BILINEAR_FIXED);
            if ($scaled !== false) {
                unset($im);
                $im = $scaled;
                imagealphablending($im, false);
                imagesavealpha($im, true);
                $width  = $nw;
                $height = $nh;
            }
        }

        // GD 没有真正的无损 webp，透明图用质量 100 近似
        $hasAlpha = ((int)($info['channels'] ?? 3)) === 4;
        $q = ($hasAlpha && $losslessAlpha && $target === 'WEBP') ? 100 : $quality;

        $ok = match ($target) {
            'WEBP'  => imagewebp($im, $dst, $q),
            'AVIF'  => function_exists('imageavif') ? imageavif($im, $dst, $q) : false,
            'PNG'   => imagepng($im, $dst, 9),
            default => false,
        };
        unset($im); // PHP 8+ 由 GC 回收，不再需要 imagedestroy()

        if ($ok === false || !is_file($dst)) {
            @unlink($dst);
            throw new RuntimeException('GD 转码失败（' . $target . '）');
        }
        @chmod($dst, 0644);

        $out = @getimagesize($dst);
        $w = $out !== false ? (int)$out[0] : $width;
        $h = $out !== false ? (int)$out[1] : $height;

        return [
            'format' => strtolower($target),
            'width'  => $w,
            'height' => $h,
            'frames' => 1,
            'bytes'  => (int)filesize($dst),
        ];
    }
}
