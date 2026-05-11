<?php

declare(strict_types=1);

namespace SlopScan\Rule;

use SlopScan\Model\Finding;
use SlopScan\Runtime\ProviderContext;

final class LowSignalMarkdownRule extends BaseRule
{
    private const DEFAULT_MIN_CONTENT_LINES = 6;
    private const DEFAULT_MIN_BOILERPLATE_LINES = 4;
    private const DEFAULT_MAX_REPO_ANCHORS = 2;
    private const DEFAULT_MIN_GENERIC_HEADINGS = 2;
    private const DEFAULT_MIN_CHECKLIST_LINES = 2;
    private const FILENAME_ARTIFACT_BONUS = 1;
    private const REPO_ANCHOR_WEIGHT = 2;
    private const REPO_ANCHOR_OFFSET = 1;
    private const FINDING_SCORE = 0.75;
    private const GENERIC_ARTIFACT_FILENAME_PATTERN = '/(?:^|[-_.])(agent|analysis|checklist|implementation|notes|plan|progress|prompt|report|status|summary|task|todo)(?:[-_.]|$)/i';
    private const GENERIC_HEADING_PATTERN = '/^(?:#+\s*)?(?:summary|overview|changes?|implementation|testing|validation|next steps|follow-up|notes?|status|checklist|plan|prompt|completed work|remaining work)\b/i';
    private const GENERIC_BULLET_PATTERN = '/^(?:[-*+]|\d+\.)\s+(?:\[[ xX]\]\s*)?(?:summary|overview|changes?|implementation|testing|validation|next steps|follow-up|notes?|status|checklist|plan|prompt|completed work|remaining work)\b/i';
    private const GENERIC_PROCESS_PATTERN = '/\b(?:implemented|updated|added|removed|validated|completed|remaining work|follow-up|next steps|this document|the following changes|successfully)\b/i';
    private const REPO_ANCHOR_PATTERN = '/(?:`[^`]+`|\[[^\]]+\]\([^)]+\)|(?:^|[\s(])(?:\.{0,2}\/)?(?:[A-Za-z0-9_.-]+\/)+[A-Za-z0-9_.-]+\.[A-Za-z0-9]+(?:[:#]\d+)?|(?:^|\s)(?:composer|php|vendor\/bin\/[A-Za-z0-9_.-]+|bin\/[A-Za-z0-9_.-]+)\b)/i';

    public function id(): string
    {
        return 'markdown.low-signal';
    }

    public function family(): string
    {
        return 'docs';
    }

    public function severity(): string
    {
        return 'weak';
    }

    public function scope(): string
    {
        return 'file';
    }

    public function requires(): array
    {
        return ['file.text'];
    }

    public function supports(ProviderContext $context): bool
    {
        return $context->file?->languageId === 'markdown';
    }

    public function evaluate(ProviderContext $context): array
    {
        $text = (string) ($context->runtime->store->getFileFact($context->file->path, 'file.text') ?? '');
        $fileName = basename($context->file->path);
        $signals = $this->signals($fileName, $text);
        $minContentLines = (int) ($context->ruleConfig['options']['minContentLines'] ?? self::DEFAULT_MIN_CONTENT_LINES);
        $minBoilerplateLines = (int) ($context->ruleConfig['options']['minBoilerplateLines'] ?? self::DEFAULT_MIN_BOILERPLATE_LINES);
        $maxRepoAnchors = (int) ($context->ruleConfig['options']['maxRepoAnchors'] ?? self::DEFAULT_MAX_REPO_ANCHORS);
        $boilerplateScore = $signals['boilerplateLines'] + ($signals['suspiciousFilename'] ? self::FILENAME_ARTIFACT_BONUS : 0);

        if (
            $signals['contentLines'] < $minContentLines
            || $boilerplateScore < $minBoilerplateLines
            || $signals['repoAnchors'] > $maxRepoAnchors
            || (
                !$signals['suspiciousFilename']
                && $signals['genericHeadings'] < self::DEFAULT_MIN_GENERIC_HEADINGS
                && $signals['checklistLines'] < self::DEFAULT_MIN_CHECKLIST_LINES
            )
            || ($signals['repoAnchors'] * self::REPO_ANCHOR_WEIGHT) >= ($boilerplateScore + self::REPO_ANCHOR_OFFSET)
        ) {
            return [];
        }

        $evidence = [
            'filename=' . $fileName,
            'boilerplateLines=' . $signals['boilerplateLines'],
            'genericHeadings=' . $signals['genericHeadings'],
            'checklistLines=' . $signals['checklistLines'],
            'repoAnchors=' . $signals['repoAnchors'],
        ];
        if ($signals['suspiciousFilename']) {
            $evidence[] = 'filenamePattern=generic-artifact';
        }

        return [new Finding(
            $this->id(),
            $this->family(),
            $this->severity(),
            'file',
            'Found Markdown document dominated by generic process scaffolding with few repo-specific anchors',
            $evidence,
            self::FINDING_SCORE,
            [[
                'path' => $context->file->path,
                'line' => $signals['firstBoilerplateLine'] ?? 1,
                'column' => 1,
            ]],
            $context->file->path
        )];
    }

    /**
     * @return array{boilerplateLines:int,checklistLines:int,contentLines:int,firstBoilerplateLine:?int,genericHeadings:int,repoAnchors:int,suspiciousFilename:bool}
     */
    private function signals(string $fileName, string $text): array
    {
        $boilerplateLines = 0;
        $checklistLines = 0;
        $contentLines = 0;
        $firstBoilerplateLine = null;
        $genericHeadings = 0;
        $repoAnchors = 0;
        $inFence = false;

        foreach (preg_split("/\r\n|\n|\r/", $text) ?: [] as $index => $line) {
            $trimmed = trim($line);
            if (preg_match('/^(?:```|~~~)/', $trimmed) === 1) {
                $inFence = !$inFence;
                $repoAnchors++;
                continue;
            }
            if ($inFence || $trimmed === '') {
                continue;
            }

            $contentLines++;
            $isChecklist = preg_match('/^(?:[-*+]|\d+\.)\s+\[[ xX]\]/', $trimmed) === 1;
            $isGenericHeading = preg_match(self::GENERIC_HEADING_PATTERN, $trimmed) === 1;
            $isGenericBullet = preg_match(self::GENERIC_BULLET_PATTERN, $trimmed) === 1;
            $isGenericProcess = preg_match(self::GENERIC_PROCESS_PATTERN, $trimmed) === 1;
            $hasRepoAnchor = preg_match(self::REPO_ANCHOR_PATTERN, $trimmed) === 1;

            if ($isChecklist) {
                $checklistLines++;
            }
            if ($isGenericHeading) {
                $genericHeadings++;
            }
            if ($hasRepoAnchor) {
                $repoAnchors++;
            }
            if (!$isGenericHeading && !$isGenericBullet && !$isGenericProcess && !$isChecklist) {
                continue;
            }

            $boilerplateLines++;
            $firstBoilerplateLine ??= $index + 1;
        }

        return [
            'boilerplateLines' => $boilerplateLines,
            'checklistLines' => $checklistLines,
            'contentLines' => $contentLines,
            'firstBoilerplateLine' => $firstBoilerplateLine,
            'genericHeadings' => $genericHeadings,
            'repoAnchors' => $repoAnchors,
            'suspiciousFilename' => preg_match(self::GENERIC_ARTIFACT_FILENAME_PATTERN, $fileName) === 1,
        ];
    }
}
