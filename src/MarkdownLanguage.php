<?php

declare(strict_types=1);

namespace SlopScan;

use SlopScan\Contract\LanguagePlugin;

final class MarkdownLanguage implements LanguagePlugin
{
    public function id(): string
    {
        return 'markdown';
    }

    public function supports(string $filePath): bool
    {
        return in_array(strtolower(pathinfo($filePath, PATHINFO_EXTENSION)), ['md', 'markdown'], true);
    }
}
