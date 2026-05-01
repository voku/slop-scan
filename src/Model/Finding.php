<?php

declare(strict_types=1);

namespace SlopScan\Model;

use SlopScan\Delta;

final class Finding
{
    /**
     * @param list<string> $evidence
     * @param list<array{path:string,line:int,column?:int}> $locations
     */
    public function __construct(
        public string $ruleId,
        public string $family,
        public string $severity,
        public string $scope,
        public string $message,
        public array $evidence,
        public float $score,
        public array $locations,
        public ?string $path = null,
        public ?array $deltaIdentity = null,
    ) {
        $this->deltaIdentity ??= Delta::identityFor($this);
    }

    /** @return array<string,mixed> */
    public function toReport(): array
    {
        return [
            'ruleId' => $this->ruleId,
            'family' => $this->family,
            'severity' => $this->severity,
            'scope' => $this->scope,
            'message' => $this->message,
            'evidence' => $this->evidence,
            'score' => $this->score,
            'locations' => $this->locations,
            'path' => $this->path,
            'deltaIdentity' => $this->deltaIdentity,
        ];
    }
}
