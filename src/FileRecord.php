<?php

declare(strict_types=1);

namespace SlopScan;

final class FileRecord
{
    public function __construct(
        public string $path,
        public string $absolutePath,
        public string $extension,
        public int $lineCount = 0,
        public int $logicalLineCount = 0,
        public ?string $languageId = null,
    ) {
    }

    /** @return array<string,mixed> */
    public function toReport(): array
    {
        return [
            'path' => $this->path,
            'extension' => $this->extension,
            'lineCount' => $this->lineCount,
            'languageId' => $this->languageId,
        ];
    }
}
