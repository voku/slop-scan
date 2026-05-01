<?php

declare(strict_types=1);

namespace SlopScan;

interface LanguagePlugin
{
    public function id(): string;
    public function supports(string $filePath): bool;
}
