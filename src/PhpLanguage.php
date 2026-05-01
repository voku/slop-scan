<?php

declare(strict_types=1);

namespace SlopScan;

use SlopScan\Contract\LanguagePlugin;

final class PhpLanguage implements LanguagePlugin
{
    public function id(): string
    {
        return 'php';
    }

    public function supports(string $filePath): bool
    {
        return in_array(strtolower(pathinfo($filePath, PATHINFO_EXTENSION)), ['php', 'phtml', 'inc'], true);
    }
}
