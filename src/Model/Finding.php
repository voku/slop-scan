<?php

declare(strict_types=1);

namespace SlopScan\Model;

use SlopScan\Delta;
use SlopScan\Support\FindingMetadataCatalog;

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
        public ?string $why = null,
        public ?string $suggestedAction = null,
        public ?string $confidence = null,
    ) {
        $this->deltaIdentity ??= Delta::identityFor($this);
    }

    /** @return array{why:?string,suggestedAction:?string,confidence:?string} */
    public function metadata(): array
    {
        $metadata = FindingMetadataCatalog::forRule($this->ruleId);

        return [
            'why' => $this->why ?? $metadata['why'],
            'suggestedAction' => $this->suggestedAction ?? $metadata['suggestedAction'],
            'confidence' => $this->confidence ?? $metadata['confidence'],
        ];
    }

    /** @return array<string,mixed> */
    public function toReport(): array
    {
        $metadata = $this->metadata();

        return [
            'ruleId' => $this->ruleId,
            'family' => $this->family,
            'severity' => $this->severity,
            'scope' => $this->scope,
            'message' => $this->message,
            'why' => $metadata['why'],
            'suggestedAction' => $metadata['suggestedAction'],
            'confidence' => $metadata['confidence'],
            'evidence' => $this->evidence,
            'score' => $this->score,
            'locations' => $this->locations,
            'path' => $this->path,
            'deltaIdentity' => $this->deltaIdentity,
        ];
    }
}
