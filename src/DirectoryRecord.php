<?php

declare(strict_types=1);

namespace SlopScan;

final class DirectoryRecord
{
    /** @param list<string> $filePaths */
    public function __construct(public string $path, public array $filePaths)
    {
    }

    /** @return array{path:string,filePaths:list<string>} */
    public function toReport(): array
    {
        return ['path' => $this->path, 'filePaths' => $this->filePaths];
    }
}
