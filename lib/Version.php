<?php
declare(strict_types=1);

/**
 * 当前代码版本。升级时只需要：
 * 1. 修改这里的 CODE_VERSION；
 * 2. 在 lib/Upgrade.php 的 steps() 里增加对应迁移函数。
 *
 * data/version.json 记录数据实际升级到的版本，两者不一致时后台会提示。
 */
final class Version
{
    public const CODE_VERSION = '1.0.0';
}
