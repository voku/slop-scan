<?php

declare(strict_types=1);

namespace SlopScan;

use SlopScan\Contract\FactProvider;
use SlopScan\Contract\LanguagePlugin;
use SlopScan\Contract\ReporterPlugin;
use SlopScan\Contract\RulePlugin;

final class Registry
{
    /** @var list<LanguagePlugin> */
    private array $languages = [];
    /** @var list<FactProvider> */
    private array $factProviders = [];
    /** @var list<RulePlugin> */
    private array $rules = [];
    /** @var array<string,ReporterPlugin> */
    private array $reporters = [];

    public function registerLanguage(LanguagePlugin $plugin): void
    {
        $this->languages[] = $plugin;
    }

    public function registerFactProvider(FactProvider $plugin): void
    {
        $this->factProviders[] = $plugin;
    }

    public function registerRule(RulePlugin $plugin): void
    {
        $this->rules[] = $plugin;
    }

    public function registerReporter(ReporterPlugin $plugin): void
    {
        $this->reporters[$plugin->id()] = $plugin;
    }

    /** @return list<LanguagePlugin> */
    public function languages(): array
    {
        return $this->languages;
    }

    /** @return list<FactProvider> */
    public function factProviders(): array
    {
        return $this->factProviders;
    }

    /** @return list<RulePlugin> */
    public function rules(): array
    {
        return $this->rules;
    }

    public function reporter(string $id): ReporterPlugin
    {
        return $this->reporters[$id] ?? throw new \RuntimeException("Unknown reporter: {$id}");
    }

    public function detectLanguage(string $filePath): ?LanguagePlugin
    {
        foreach ($this->languages as $language) {
            if ($language->supports($filePath)) {
                return $language;
            }
        }
        return null;
    }
}
