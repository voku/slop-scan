<?php

declare(strict_types=1);

namespace SlopScan;

interface LanguagePlugin
{
    public function id(): string;
    public function supports(string $filePath): bool;
}

interface FactProvider
{
    public function id(): string;
    public function scope(): string;
    /** @return list<string> */
    public function requires(): array;
    /** @return list<string> */
    public function provides(): array;
    public function supports(ProviderContext $context): bool;
    /** @return array<string,mixed> */
    public function run(ProviderContext $context): array;
}

interface RulePlugin
{
    public function id(): string;
    public function family(): string;
    public function severity(): string;
    public function scope(): string;
    /** @return list<string> */
    public function requires(): array;
    public function supports(ProviderContext $context): bool;
    /** @return list<Finding> */
    public function evaluate(ProviderContext $context): array;
}

interface ReporterPlugin
{
    public function id(): string;
    public function render(AnalysisResult $result): string;
}

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

final class AnalysisResult
{
    /**
     * @param list<FileRecord> $files
     * @param list<DirectoryRecord> $directories
     * @param list<Finding> $findings
     * @param array<string,mixed> $config
     * @param array<string,mixed> $summary
     * @param list<array{path:string,score:float,findingCount:int}> $fileScores
     * @param list<array{path:string,score:float,findingCount:int}> $directoryScores
     */
    public function __construct(
        public string $rootDir,
        public array $config,
        public array $summary,
        public array $files,
        public array $directories,
        public array $findings,
        public array $fileScores,
        public array $directoryScores,
        public float $repoScore,
    ) {
    }

    /** @return array<string,mixed> */
    public function toReport(): array
    {
        return [
            'metadata' => [
                'schemaVersion' => 1,
                'tool' => ['name' => 'slop-scan-php', 'version' => '0.1.0'],
                'configHash' => hash('sha256', Json::encode($this->config)),
                'findingFingerprintVersion' => 1,
                'plugins' => [],
            ],
            'rootDir' => $this->rootDir,
            'config' => $this->config,
            'summary' => $this->summary,
            'files' => array_map(static fn(FileRecord $file): array => $file->toReport(), $this->files),
            'directories' => array_map(static fn(DirectoryRecord $directory): array => $directory->toReport(), $this->directories),
            'findings' => array_map(static fn(Finding $finding): array => $finding->toReport(), $this->findings),
            'fileScores' => $this->fileScores,
            'directoryScores' => $this->directoryScores,
        ];
    }
}

final class ProviderContext
{
    public function __construct(
        public string $scope,
        public AnalyzerRuntime $runtime,
        public ?FileRecord $file = null,
        public ?DirectoryRecord $directory = null,
        public array $ruleConfig = ['enabled' => true, 'weight' => 1.0],
    ) {
    }
}

final class AnalyzerRuntime
{
    /** @param list<FileRecord> $files @param list<DirectoryRecord> $directories */
    public function __construct(
        public string $rootDir,
        public array $config,
        public array $files,
        public array $directories,
        public FactStore $store,
    ) {
    }
}

final class FactStore
{
    /** @var array<string,mixed> */
    private array $repoFacts = [];
    /** @var array<string,array<string,mixed>> */
    private array $directoryFacts = [];
    /** @var array<string,array<string,mixed>> */
    private array $fileFacts = [];

    public function getRepoFact(string $factId): mixed
    {
        return $this->repoFacts[$factId] ?? null;
    }

    public function setRepoFact(string $factId, mixed $value): void
    {
        $this->repoFacts[$factId] = $value;
    }

    public function getDirectoryFact(string $directoryPath, string $factId): mixed
    {
        return $this->directoryFacts[$directoryPath][$factId] ?? null;
    }

    public function setDirectoryFact(string $directoryPath, string $factId, mixed $value): void
    {
        $this->directoryFacts[$directoryPath][$factId] = $value;
    }

    public function getFileFact(string $filePath, string $factId): mixed
    {
        return $this->fileFacts[$filePath][$factId] ?? null;
    }

    public function setFileFact(string $filePath, string $factId, mixed $value): void
    {
        $this->fileFacts[$filePath][$factId] = $value;
    }

    /** @param array<string,mixed> $facts */
    public function setFileFacts(string $filePath, array $facts): void
    {
        $this->fileFacts[$filePath] = array_replace($this->fileFacts[$filePath] ?? [], $facts);
    }

    /** @return list<string> */
    public function listFilePathsWithFact(string $factId): array
    {
        $paths = [];
        foreach ($this->fileFacts as $path => $facts) {
            if (array_key_exists($factId, $facts)) {
                $paths[] = $path;
            }
        }
        sort($paths, SORT_STRING);
        return $paths;
    }
}

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

final class DefaultRegistry
{
    public static function create(): Registry
    {
        $registry = new Registry();
        $registry->registerLanguage(new PhpLanguage());
        $registry->registerFactProvider(new PhpStructureFactProvider());
        $registry->registerFactProvider(new DirectoryMetricsFactProvider());
        $registry->registerFactProvider(new FunctionDuplicationFactProvider());
        foreach ([
            new EmptyCatchRule(),
            new ErrorSwallowingRule(),
            new PlaceholderCommentsRule(),
            new PassThroughWrappersRule(),
            new DirectoryFanoutHotspotRule(),
            new OverFragmentationRule(),
            new DuplicateFunctionSignaturesRule(),
        ] as $rule) {
            $registry->registerRule($rule);
        }
        $registry->registerReporter(new TextReporter());
        $registry->registerReporter(new JsonReporter());
        $registry->registerReporter(new LintReporter());
        return $registry;
    }
}

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

final class Config
{
    /** @return array<string,mixed> */
    public static function defaults(): array
    {
        return [
            'ignores' => ['**/vendor/**', '**/.git/**', '**/node_modules/**', '**/dist/**', '**/coverage/**', '**/*.generated.*'],
            'rules' => [],
            'thresholds' => [],
            'overrides' => [],
        ];
    }

    /** @return array<string,mixed> */
    public static function load(string $rootDir): array
    {
        $config = self::defaults();
        foreach (['slop-scan.config.json', 'repo-slop.config.json'] as $filename) {
            $path = $rootDir . DIRECTORY_SEPARATOR . $filename;
            if (!is_file($path)) {
                continue;
            }
            $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            if (is_array($decoded)) {
                $config = self::merge($config, $decoded);
            }
            break;
        }
        return $config;
    }

    /** @param array<string,mixed> $base @param array<string,mixed> $next @return array<string,mixed> */
    private static function merge(array $base, array $next): array
    {
        return [
            'ignores' => array_values(array_map('strval', $next['ignores'] ?? $base['ignores'])),
            'rules' => array_replace_recursive($base['rules'] ?? [], is_array($next['rules'] ?? null) ? $next['rules'] : []),
            'thresholds' => array_replace($base['thresholds'] ?? [], is_array($next['thresholds'] ?? null) ? $next['thresholds'] : []),
            'overrides' => is_array($next['overrides'] ?? null) ? $next['overrides'] : ($base['overrides'] ?? []),
        ];
    }
}

final class PatternMatcher
{
    /** @param list<string> $patterns */
    public static function ignored(string $path, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (self::matches($path, $pattern)) {
                return true;
            }
        }
        return false;
    }

    public static function matches(string $path, string $pattern): bool
    {
        $path = str_replace('\\', '/', $path);
        $pattern = str_replace('\\', '/', $pattern);
        $regex = preg_quote($pattern, '~');
        $regex = str_replace(['\*\*/', '\*\*', '\*'], ['(?:.*/)?', '.*', '[^/]*'], $regex);
        return (bool) preg_match('~^' . $regex . '$~', $path);
    }
}

final class Discoverer
{
    /** @return array{files:list<FileRecord>,directories:list<DirectoryRecord>} */
    public static function discover(string $rootDir, array $config, Registry $registry): array
    {
        $files = [];
        $root = realpath($rootDir) ?: $rootDir;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                static function (\SplFileInfo $item) use ($root, $config): bool {
                    $relative = self::relativePath($root, $item->getPathname());
                    return !PatternMatcher::ignored($relative, $config['ignores'] ?? []);
                },
            ),
        );

        foreach ($iterator as $item) {
            if (!$item instanceof \SplFileInfo || !$item->isFile()) {
                continue;
            }
            $relative = self::relativePath($root, $item->getPathname());
            $language = $registry->detectLanguage($relative);
            if ($language === null) {
                continue;
            }
            $files[] = new FileRecord($relative, $item->getPathname(), '.' . strtolower($item->getExtension()), 0, 0, $language->id());
        }
        usort($files, static fn(FileRecord $left, FileRecord $right): int => strcmp($left->path, $right->path));

        $byDirectory = [];
        foreach ($files as $file) {
            $directory = str_replace('\\', '/', dirname($file->path));
            $byDirectory[$directory][] = $file->path;
        }
        ksort($byDirectory, SORT_STRING);
        $directories = [];
        foreach ($byDirectory as $directory => $paths) {
            sort($paths, SORT_STRING);
            $directories[] = new DirectoryRecord($directory, $paths);
        }

        return ['files' => $files, 'directories' => $directories];
    }

    private static function relativePath(string $root, string $path): string
    {
        $relative = ltrim(substr($path, strlen($root)), DIRECTORY_SEPARATOR);
        return str_replace(DIRECTORY_SEPARATOR, '/', $relative);
    }
}

final class Scheduler
{
    /** @param list<FactProvider> $providers @param list<string> $initialFacts @return list<FactProvider> */
    public static function orderFactProviders(array $providers, array $initialFacts = []): array
    {
        $ordered = [];
        $available = array_fill_keys($initialFacts, true);
        while ($providers !== []) {
            $readyIndex = null;
            foreach ($providers as $index => $provider) {
                if (array_reduce($provider->requires(), static fn(bool $carry, string $fact): bool => $carry && isset($available[$fact]), true)) {
                    $readyIndex = $index;
                    break;
                }
            }
            if ($readyIndex === null) {
                throw new \RuntimeException('Unresolved fact provider dependencies.');
            }
            $provider = array_splice($providers, $readyIndex, 1)[0];
            $ordered[] = $provider;
            foreach ($provider->provides() as $fact) {
                $available[$fact] = true;
            }
        }
        return $ordered;
    }
}

final class Analyzer
{
    public function analyze(string $rootDir, array $config, Registry $registry): AnalysisResult
    {
        $root = realpath($rootDir) ?: $rootDir;
        $discovery = Discoverer::discover($root, $config, $registry);
        $store = new FactStore();
        $runtime = new AnalyzerRuntime($root, $config, $discovery['files'], $discovery['directories'], $store);
        $store->setRepoFact('repo.files', $discovery['files']);
        $store->setRepoFact('repo.directories', $discovery['directories']);
        foreach ($discovery['directories'] as $directory) {
            $store->setDirectoryFact($directory->path, 'directory.record', $directory);
        }

        $providers = Scheduler::orderFactProviders($registry->factProviders(), ['file.record', 'file.text', 'directory.record', 'repo.files', 'repo.directories']);
        $findings = [];
        foreach ($discovery['files'] as $file) {
            $text = (string) file_get_contents($file->absolutePath);
            $file->lineCount = Lines::physical($text);
            $file->logicalLineCount = Lines::logical($text);
            $context = new ProviderContext('file', $runtime, $file);
            $store->setFileFacts($file->path, ['file.record' => $file, 'file.text' => $text, 'file.lineCount' => $file->lineCount, 'file.logicalLineCount' => $file->logicalLineCount]);
            $this->runProviders($providers, $context, $store);
            $findings = array_merge($findings, $this->runRules($registry->rules(), $context, $config));
        }
        foreach ($discovery['directories'] as $directory) {
            $context = new ProviderContext('directory', $runtime, null, $directory);
            $this->runProviders($providers, $context, $store);
            $findings = array_merge($findings, $this->runRules($registry->rules(), $context, $config));
        }
        $repoContext = new ProviderContext('repo', $runtime);
        $this->runProviders($providers, $repoContext, $store);
        $findings = array_merge($findings, $this->runRules($registry->rules(), $repoContext, $config));
        usort($findings, static fn(Finding $left, Finding $right): int => strcmp($left->ruleId . ($left->path ?? ''), $right->ruleId . ($right->path ?? '')));

        return $this->result($root, $config, $discovery['files'], $discovery['directories'], $findings, $store);
    }

    /** @param list<FactProvider> $providers */
    private function runProviders(array $providers, ProviderContext $context, FactStore $store): void
    {
        foreach ($providers as $provider) {
            if ($provider->scope() !== $context->scope || !$provider->supports($context)) {
                continue;
            }
            foreach ($provider->run($context) as $fact => $value) {
                if ($context->scope === 'file' && $context->file !== null) {
                    $store->setFileFact($context->file->path, $fact, $value);
                } elseif ($context->scope === 'directory' && $context->directory !== null) {
                    $store->setDirectoryFact($context->directory->path, $fact, $value);
                } else {
                    $store->setRepoFact($fact, $value);
                }
            }
        }
    }

    /** @param list<RulePlugin> $rules @param array<string,mixed> $config @return list<Finding> */
    private function runRules(array $rules, ProviderContext $context, array $config): array
    {
        $findings = [];
        foreach ($rules as $rule) {
            if ($rule->scope() !== $context->scope) {
                continue;
            }
            $ruleConfig = $this->ruleConfig($config, $rule->id());
            $context->ruleConfig = $ruleConfig;
            if (!$ruleConfig['enabled'] || !$rule->supports($context)) {
                continue;
            }
            foreach ($rule->evaluate($context) as $finding) {
                $finding->score *= (float) $ruleConfig['weight'];
                $findings[] = $finding;
            }
        }
        return $findings;
    }

    /** @param array<string,mixed> $config @return array{enabled:bool,weight:float,options:mixed} */
    private function ruleConfig(array $config, string $ruleId): array
    {
        $rule = is_array($config['rules'][$ruleId] ?? null) ? $config['rules'][$ruleId] : [];
        return ['enabled' => (bool) ($rule['enabled'] ?? true), 'weight' => (float) ($rule['weight'] ?? 1.0), 'options' => $rule['options'] ?? null];
    }

    /** @param list<FileRecord> $files @param list<DirectoryRecord> $directories @param list<Finding> $findings */
    private function result(string $root, array $config, array $files, array $directories, array $findings, FactStore $store): AnalysisResult
    {
        $repoScore = array_reduce($findings, static fn(float $sum, Finding $finding): float => $sum + $finding->score, 0.0);
        $physical = array_reduce($files, static fn(int $sum, FileRecord $file): int => $sum + $file->lineCount, 0);
        $logical = array_reduce($files, static fn(int $sum, FileRecord $file): int => $sum + $file->logicalLineCount, 0);
        $functionCount = 0;
        foreach ($files as $file) {
            $functionCount += count($store->getFileFact($file->path, 'file.functionSummaries') ?? []);
        }
        $kloc = $logical / 1000;
        $summary = [
            'fileCount' => count($files),
            'directoryCount' => count($directories),
            'findingCount' => count($findings),
            'repoScore' => $repoScore,
            'physicalLineCount' => $physical,
            'logicalLineCount' => $logical,
            'functionCount' => $functionCount,
            'normalized' => [
                'scorePerFile' => self::divide($repoScore, count($files)),
                'scorePerKloc' => self::divide($repoScore, $kloc),
                'scorePerFunction' => self::divide($repoScore, $functionCount),
                'findingsPerFile' => self::divide(count($findings), count($files)),
                'findingsPerKloc' => self::divide(count($findings), $kloc),
                'findingsPerFunction' => self::divide(count($findings), $functionCount),
            ],
        ];
        return new AnalysisResult($root, $config, $summary, $files, $directories, $findings, $this->fileScores($files, $findings), $this->directoryScores($directories, $findings), $repoScore);
    }

    private static function divide(float|int $numerator, float|int $denominator): ?float
    {
        return $denominator > 0 ? $numerator / $denominator : null;
    }

    /** @param list<FileRecord> $files @param list<Finding> $findings @return list<array{path:string,score:float,findingCount:int}> */
    private function fileScores(array $files, array $findings): array
    {
        $scores = [];
        foreach ($findings as $finding) {
            if ($finding->path === null) {
                continue;
            }
            $scores[$finding->path] ??= ['path' => $finding->path, 'score' => 0.0, 'findingCount' => 0];
            $scores[$finding->path]['score'] += $finding->score;
            $scores[$finding->path]['findingCount']++;
        }
        usort($scores, static fn(array $left, array $right): int => $right['score'] <=> $left['score'] ?: strcmp($left['path'], $right['path']));
        return array_values($scores);
    }

    /** @param list<DirectoryRecord> $directories @param list<Finding> $findings @return list<array{path:string,score:float,findingCount:int}> */
    private function directoryScores(array $directories, array $findings): array
    {
        $scores = [];
        foreach ($findings as $finding) {
            if ($finding->scope !== 'directory' || $finding->path === null) {
                continue;
            }
            $scores[$finding->path] ??= ['path' => $finding->path, 'score' => 0.0, 'findingCount' => 0];
            $scores[$finding->path]['score'] += $finding->score;
            $scores[$finding->path]['findingCount']++;
        }
        usort($scores, static fn(array $left, array $right): int => $right['score'] <=> $left['score'] ?: strcmp($left['path'], $right['path']));
        return array_values($scores);
    }
}

final class Lines
{
    public static function physical(string $text): int
    {
        if ($text === '') {
            return 0;
        }
        return substr_count($text, "\n") + (str_ends_with($text, "\n") ? 0 : 1);
    }

    public static function logical(string $text): int
    {
        $count = 0;
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $trimmed = trim($line);
            if ($trimmed !== '' && !str_starts_with($trimmed, '//') && !str_starts_with($trimmed, '*')) {
                $count++;
            }
        }
        return $count;
    }
}

final class PhpStructureFactProvider implements FactProvider
{
    public function id(): string { return 'php.structure'; }
    public function scope(): string { return 'file'; }
    public function requires(): array { return ['file.text']; }
    public function provides(): array { return ['file.comments', 'file.functionSummaries', 'file.tryCatches', 'file.parserSummary']; }
    public function supports(ProviderContext $context): bool { return $context->file?->languageId === 'php'; }

    public function run(ProviderContext $context): array
    {
        $text = (string) $context->runtime->store->getFileFact($context->file->path, 'file.text');
        return [
            'file.comments' => PhpFacts::comments($text),
            'file.functionSummaries' => PhpFacts::functions($text),
            'file.tryCatches' => PhpFacts::tryCatches($text),
            'file.parserSummary' => PhpFacts::parserSummary($context->file->absolutePath),
        ];
    }
}

final class DirectoryMetricsFactProvider implements FactProvider
{
    public function id(): string { return 'directory.metrics'; }
    public function scope(): string { return 'directory'; }
    public function requires(): array { return ['directory.record']; }
    public function provides(): array { return ['directory.metrics']; }
    public function supports(ProviderContext $context): bool { return $context->directory !== null; }

    public function run(ProviderContext $context): array
    {
        return ['directory.metrics' => ['fileCount' => count($context->directory->filePaths)]];
    }
}

final class FunctionDuplicationFactProvider implements FactProvider
{
    public function id(): string { return 'repo.functionDuplication'; }
    public function scope(): string { return 'repo'; }
    public function requires(): array { return ['repo.files', 'file.functionSummaries']; }
    public function provides(): array { return ['repo.duplicateFunctionSignatures']; }
    public function supports(ProviderContext $context): bool { return true; }

    public function run(ProviderContext $context): array
    {
        $groups = [];
        foreach ($context->runtime->files as $file) {
            foreach ($context->runtime->store->getFileFact($file->path, 'file.functionSummaries') ?? [] as $function) {
                $groups[$function['signature']][] = ['path' => $file->path, 'line' => $function['line'], 'name' => $function['name']];
            }
        }
        $duplicates = array_filter($groups, static fn(array $group): bool => count($group) > 1);
        ksort($duplicates, SORT_STRING);
        return ['repo.duplicateFunctionSignatures' => $duplicates];
    }
}

final class PhpFacts
{
    /** @return list<array{text:string,line:int}> */
    public static function comments(string $text): array
    {
        $comments = [];
        foreach (token_get_all($text) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $comments[] = ['text' => $token[1], 'line' => $token[2]];
            }
        }
        return $comments;
    }

    /** @return list<array{name:string,signature:string,line:int,body:string,params:list<string>}> */
    public static function functions(string $text): array
    {
        $functions = [];
        if (!preg_match_all('/function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(([^)]*)\)\s*\{/m', $text, $matches, PREG_OFFSET_CAPTURE)) {
            return [];
        }
        foreach ($matches[0] as $index => $match) {
            $offset = $match[1];
            $line = substr_count(substr($text, 0, $offset), "\n") + 1;
            $name = $matches[1][$index][0];
            $params = array_values(array_filter(array_map(static fn(string $param): string => trim(preg_replace('/=.*$/', '', $param) ?? ''), explode(',', $matches[2][$index][0]))));
            $className = self::enclosingClassName($text, $offset);
            // Qualify methods so common signatures such as constructors do not look duplicated across unrelated classes.
            $signature = ($className !== null ? strtolower($className) . '::' : '') . strtolower($name) . '(' . count($params) . ')';
            $bodyStart = $offset + strlen($match[0]);
            $body = self::balancedBody($text, $bodyStart);
            $functions[] = ['name' => $name, 'signature' => $signature, 'line' => $line, 'body' => $body, 'params' => $params];
        }
        return $functions;
    }

    /** @return list<array{line:int,body:string}> */
    public static function tryCatches(string $text): array
    {
        $catches = [];
        if (!preg_match_all('/catch\s*\([^)]*\)\s*\{/m', $text, $matches, PREG_OFFSET_CAPTURE)) {
            return [];
        }
        foreach ($matches[0] as $match) {
            $offset = $match[1];
            $line = substr_count(substr($text, 0, $offset), "\n") + 1;
            $body = self::balancedBody($text, $offset + strlen($match[0]));
            $catches[] = ['line' => $line, 'body' => $body];
        }
        return $catches;
    }

    /** @return array{available:bool,classCount:int,functionCount:int,error?:string} */
    public static function parserSummary(string $absolutePath): array
    {
        $class = '\\voku\\SimplePhpParser\\Parsers\\SimplePhpParser';
        if (!class_exists($class)) {
            return ['available' => false, 'classCount' => 0, 'functionCount' => 0];
        }
        try {
            $parser = new $class();
            $parser->parse($absolutePath);
            return [
                'available' => true,
                'classCount' => count($parser->getClasses()),
                'functionCount' => count($parser->getFunctions()),
            ];
        } catch (\Throwable $exception) {
            return ['available' => true, 'classCount' => 0, 'functionCount' => 0, 'error' => $exception->getMessage()];
        }
    }

    private static function balancedBody(string $text, int $start): string
    {
        $depth = 1;
        $length = strlen($text);
        for ($index = $start; $index < $length; $index++) {
            if ($text[$index] === '{') {
                $depth++;
            } elseif ($text[$index] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($text, $start, $index - $start);
                }
            }
        }
        return substr($text, $start);
    }

    /**
     * Returns the class-like scope containing the function at the given byte offset, if any.
     */
    private static function enclosingClassName(string $text, int $offset): ?string
    {
        $position = 0;
        $depth = 0;
        $pendingClass = false;
        $pendingClassName = null;
        $classScopes = [];
        foreach (token_get_all($text) as $token) {
            $content = is_array($token) ? $token[1] : $token;
            if ($position >= $offset) {
                break;
            }
            if (is_array($token)) {
                if ($token[0] === T_CLASS || $token[0] === T_INTERFACE || $token[0] === T_TRAIT) {
                    $pendingClass = true;
                    $pendingClassName = null;
                } elseif ($pendingClass && $token[0] === T_STRING) {
                    $pendingClassName = $content;
                }
            } elseif ($content === '{') {
                $depth++;
                if ($pendingClassName !== null) {
                    $classScopes[] = ['name' => $pendingClassName, 'depth' => $depth];
                    $pendingClass = false;
                    $pendingClassName = null;
                }
            } elseif ($content === '}') {
                if ($classScopes !== [] && $classScopes[array_key_last($classScopes)]['depth'] === $depth) {
                    array_pop($classScopes);
                }
                $depth--;
            }
            $position += strlen($content);
        }
        if ($classScopes === []) {
            return null;
        }
        return $classScopes[array_key_last($classScopes)]['name'];
    }
}

abstract class BaseRule implements RulePlugin
{
    public function supports(ProviderContext $context): bool { return true; }
    public function severity(): string { return 'medium'; }
}

final class EmptyCatchRule extends BaseRule
{
    public function id(): string { return 'php.empty-catch'; }
    public function family(): string { return 'error-handling'; }
    public function scope(): string { return 'file'; }
    public function requires(): array { return ['file.tryCatches']; }

    public function evaluate(ProviderContext $context): array
    {
        $findings = [];
        foreach ($context->runtime->store->getFileFact($context->file->path, 'file.tryCatches') ?? [] as $catch) {
            if (trim($catch['body']) === '') {
                $findings[] = new Finding($this->id(), $this->family(), $this->severity(), 'file', 'Found empty PHP catch block', ['catch block has no statements'], 2.0, [['path' => $context->file->path, 'line' => $catch['line'], 'column' => 1]], $context->file->path);
            }
        }
        return $findings;
    }
}

final class ErrorSwallowingRule extends BaseRule
{
    public function id(): string { return 'php.error-swallowing'; }
    public function family(): string { return 'error-handling'; }
    public function scope(): string { return 'file'; }
    public function requires(): array { return ['file.tryCatches']; }

    public function evaluate(ProviderContext $context): array
    {
        $findings = [];
        foreach ($context->runtime->store->getFileFact($context->file->path, 'file.tryCatches') ?? [] as $catch) {
            $body = strtolower($catch['body']);
            if (preg_match('/\b(error_log|echo|print|var_dump|trigger_error)\b/', $body) && !preg_match('/\b(throw|return)\b/', $body)) {
                $findings[] = new Finding($this->id(), $this->family(), $this->severity(), 'file', 'Found PHP catch block that logs or prints and continues', ['catch body logs/prints without throw or return'], 2.0, [['path' => $context->file->path, 'line' => $catch['line'], 'column' => 1]], $context->file->path);
            }
        }
        return $findings;
    }
}

final class PlaceholderCommentsRule extends BaseRule
{
    public function id(): string { return 'php.placeholder-comments'; }
    public function family(): string { return 'comments'; }
    public function severity(): string { return 'weak'; }
    public function scope(): string { return 'file'; }
    public function requires(): array { return ['file.comments']; }

    public function evaluate(ProviderContext $context): array
    {
        $findings = [];
        foreach ($context->runtime->store->getFileFact($context->file->path, 'file.comments') ?? [] as $comment) {
            if (preg_match('/\b(todo|fixme|hack|placeholder|temporary|generated by ai)\b/i', $comment['text'])) {
                $findings[] = new Finding($this->id(), $this->family(), $this->severity(), 'file', 'Found placeholder-style PHP comment', [trim($comment['text'])], 0.5, [['path' => $context->file->path, 'line' => $comment['line'], 'column' => 1]], $context->file->path);
            }
        }
        return $findings;
    }
}

final class PassThroughWrappersRule extends BaseRule
{
    public function id(): string { return 'php.pass-through-wrappers'; }
    public function family(): string { return 'abstraction'; }
    public function severity(): string { return 'weak'; }
    public function scope(): string { return 'file'; }
    public function requires(): array { return ['file.functionSummaries']; }

    public function evaluate(ProviderContext $context): array
    {
        $findings = [];
        foreach ($context->runtime->store->getFileFact($context->file->path, 'file.functionSummaries') ?? [] as $function) {
            $body = trim(preg_replace('/\s+/', ' ', $function['body']) ?? '');
            if (preg_match('/^return\s+[A-Za-z_\\\\][A-Za-z0-9_\\\\]*\s*\([^;]*\);?$/', $body)) {
                $findings[] = new Finding($this->id(), $this->family(), $this->severity(), 'file', 'Found pass-through PHP wrapper function', [$function['name']], 1.0, [['path' => $context->file->path, 'line' => $function['line'], 'column' => 1]], $context->file->path);
            }
        }
        return $findings;
    }
}

final class DirectoryFanoutHotspotRule extends BaseRule
{
    public function id(): string { return 'php.directory-fanout-hotspot'; }
    public function family(): string { return 'structure'; }
    public function scope(): string { return 'directory'; }
    public function requires(): array { return ['directory.metrics']; }

    public function evaluate(ProviderContext $context): array
    {
        $metrics = $context->runtime->store->getDirectoryFact($context->directory->path, 'directory.metrics') ?? [];
        $threshold = (int) ($context->ruleConfig['options']['fileCount'] ?? 12);
        if (($metrics['fileCount'] ?? 0) <= $threshold) {
            return [];
        }
        return [new Finding($this->id(), $this->family(), $this->severity(), 'directory', 'Found high PHP file fanout in one directory', ['fileCount=' . $metrics['fileCount']], 1.5, [['path' => $context->directory->filePaths[0] ?? $context->directory->path, 'line' => 1, 'column' => 1]], $context->directory->path)];
    }
}

final class OverFragmentationRule extends BaseRule
{
    public function id(): string { return 'php.over-fragmentation'; }
    public function family(): string { return 'structure'; }
    public function severity(): string { return 'weak'; }
    public function scope(): string { return 'directory'; }
    public function requires(): array { return ['directory.record']; }

    public function evaluate(ProviderContext $context): array
    {
        $small = 0;
        foreach ($context->directory->filePaths as $path) {
            $lineCount = $context->runtime->store->getFileFact($path, 'file.logicalLineCount') ?? 0;
            $functionCount = count($context->runtime->store->getFileFact($path, 'file.functionSummaries') ?? []);
            if ($lineCount > 0 && $lineCount <= 12 && $functionCount <= 1) {
                $small++;
            }
        }
        if ($small < 6 || $small < count($context->directory->filePaths) / 2) {
            return [];
        }
        return [new Finding($this->id(), $this->family(), $this->severity(), 'directory', 'Found many tiny PHP files in one directory', ["tinyFiles={$small}"], 1.0, [['path' => $context->directory->filePaths[0], 'line' => 1, 'column' => 1]], $context->directory->path)];
    }
}

final class DuplicateFunctionSignaturesRule extends BaseRule
{
    public function id(): string { return 'php.duplicate-function-signatures'; }
    public function family(): string { return 'duplication'; }
    public function scope(): string { return 'repo'; }
    public function requires(): array { return ['repo.duplicateFunctionSignatures']; }

    public function evaluate(ProviderContext $context): array
    {
        $findings = [];
        foreach ($context->runtime->store->getRepoFact('repo.duplicateFunctionSignatures') ?? [] as $signature => $locations) {
            $findings[] = new Finding($this->id(), $this->family(), $this->severity(), 'repo', 'Found duplicated PHP function signatures', [$signature], 2.0, array_map(static fn(array $location): array => ['path' => $location['path'], 'line' => $location['line'], 'column' => 1], $locations), null);
        }
        return $findings;
    }
}

final class TextReporter implements ReporterPlugin
{
    public function id(): string { return 'text'; }

    public function render(AnalysisResult $result): string
    {
        $summary = $result->summary;
        $lines = [
            'slop-scan report',
            'root: ' . $result->rootDir,
            'files scanned: ' . $summary['fileCount'],
            'directories scanned: ' . $summary['directoryCount'],
            'physical LOC: ' . $summary['physicalLineCount'],
            'logical LOC: ' . $summary['logicalLineCount'],
            'functions: ' . $summary['functionCount'],
            '',
            'Raw totals:',
            '- findings: ' . $summary['findingCount'],
            '- repo score: ' . number_format((float) $summary['repoScore'], 2, '.', ''),
        ];
        return implode("\n", $lines);
    }
}

final class JsonReporter implements ReporterPlugin
{
    public function id(): string { return 'json'; }
    public function render(AnalysisResult $result): string { return Json::encode($result->toReport(), true); }
}

final class LintReporter implements ReporterPlugin
{
    public function id(): string { return 'lint'; }

    public function render(AnalysisResult $result): string
    {
        if ($result->findings === []) {
            return '0 findings';
        }
        $lines = [];
        foreach ($result->findings as $finding) {
            $lines[] = "{$finding->severity}  {$finding->message}  {$finding->ruleId}";
            foreach (array_slice($finding->locations, 0, 3) as $location) {
                $lines[] = '  at ' . $location['path'] . ':' . $location['line'] . ':' . ($location['column'] ?? 1);
            }
            $lines[] = '';
        }
        $lines[] = count($result->findings) . ' finding' . (count($result->findings) === 1 ? '' : 's');
        return rtrim(implode("\n", $lines));
    }
}

final class Delta
{
    public static function identityFor(Finding $finding): array
    {
        $occurrences = [];
        foreach ($finding->locations ?: [['path' => $finding->path ?? '<repo>', 'line' => 1, 'column' => 1]] as $location) {
            $key = implode(':', [$finding->ruleId, $finding->message, $location['path'] ?? '<repo>', (string) ($location['line'] ?? 1)]);
            $occurrences[] = ['fingerprint' => hash('sha256', $key), 'path' => $location['path'] ?? null, 'line' => $location['line'] ?? null, 'column' => $location['column'] ?? 1];
        }
        return ['fingerprintVersion' => 1, 'occurrences' => $occurrences];
    }

    /** @param array<string,mixed> $base @param array<string,mixed> $head @return array<string,mixed> */
    public static function diff(array $base, array $head): array
    {
        $baseMap = self::occurrenceMap($base['findings'] ?? []);
        $headMap = self::occurrenceMap($head['findings'] ?? []);
        $changes = [];
        foreach ($headMap as $fingerprint => $finding) {
            if (!isset($baseMap[$fingerprint])) {
                $changes[] = ['status' => 'added', 'fingerprint' => $fingerprint, 'finding' => $finding];
            }
        }
        foreach ($baseMap as $fingerprint => $finding) {
            if (!isset($headMap[$fingerprint])) {
                $changes[] = ['status' => 'resolved', 'fingerprint' => $fingerprint, 'finding' => $finding];
            }
        }
        usort($changes, static fn(array $left, array $right): int => strcmp($left['fingerprint'], $right['fingerprint']));
        return ['summary' => ['added' => count(array_filter($changes, static fn(array $c): bool => $c['status'] === 'added')), 'resolved' => count(array_filter($changes, static fn(array $c): bool => $c['status'] === 'resolved'))], 'changes' => $changes];
    }

    /** @param list<array<string,mixed>> $findings @return array<string,array<string,mixed>> */
    private static function occurrenceMap(array $findings): array
    {
        $map = [];
        foreach ($findings as $finding) {
            foreach (($finding['deltaIdentity']['occurrences'] ?? []) as $occurrence) {
                $map[$occurrence['fingerprint']] = $finding;
            }
        }
        ksort($map, SORT_STRING);
        return $map;
    }
}

final class Json
{
    public static function encode(mixed $value, bool $pretty = false): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | ($pretty ? JSON_PRETTY_PRINT : 0));
    }
}

final class Cli
{
    /** @param list<string> $argv */
    public static function main(array $argv): int
    {
        try {
            $args = self::parse($argv);
            if ($args['help'] || $argv === []) {
                fwrite(STDOUT, self::help() . "\n");
                return 0;
            }
            if ($args['command'] === 'scan') {
                $config = Config::load($args['target']);
                $config['ignores'] = array_values(array_merge($config['ignores'], $args['ignore']));
                $result = (new Analyzer())->analyze($args['target'], $config, DefaultRegistry::create());
                $reporter = DefaultRegistry::create()->reporter($args['json'] ? 'json' : ($args['lint'] ? 'lint' : 'text'));
                fwrite(STDOUT, $reporter->render($result) . "\n");
                return 0;
            }
            if ($args['command'] === 'delta') {
                $base = self::reportInput($args['baseReport'], $args['base'], $args['ignore']);
                $head = self::reportInput($args['headReport'], $args['head'] ?: '.', $args['ignore']);
                $delta = Delta::diff($base, $head);
                fwrite(STDOUT, $args['json'] ? Json::encode($delta, true) . "\n" : self::formatDelta($delta) . "\n");
                return self::shouldFail($delta, $args['failOn']) ? 1 : 0;
            }
            fwrite(STDERR, "Unknown command: {$args['command']}\n");
            return 1;
        } catch (\Throwable $exception) {
            fwrite(STDERR, $exception->getMessage() . "\n");
            return 1;
        }
    }

    public static function help(): string
    {
        return implode("\n", ['slop-scan', '', 'Usage:', '  slop-scan scan [path] [options]', '  slop-scan delta [base-path] [head-path] [options]', '  slop-scan --help']);
    }

    /** @param list<string> $argv @return array<string,mixed> */
    private static function parse(array $argv): array
    {
        $args = ['help' => false, 'json' => false, 'lint' => false, 'ignore' => [], 'command' => null, 'target' => '.', 'base' => null, 'head' => null, 'baseReport' => null, 'headReport' => null, 'failOn' => null];
        $positionals = [];
        for ($i = 0; $i < count($argv); $i++) {
            $arg = $argv[$i];
            if ($arg === '--help' || $arg === '-h') {
                $args['help'] = true;
            } elseif ($arg === '--json') {
                $args['json'] = true;
            } elseif ($arg === '--lint') {
                $args['lint'] = true;
            } elseif (in_array($arg, ['--ignore', '--base', '--head', '--base-report', '--head-report', '--fail-on'], true)) {
                $value = $argv[++$i] ?? throw new \InvalidArgumentException("Missing value for {$arg}");
                $key = ['--ignore' => 'ignore', '--base' => 'base', '--head' => 'head', '--base-report' => 'baseReport', '--head-report' => 'headReport', '--fail-on' => 'failOn'][$arg];
                if ($key === 'ignore') {
                    $args['ignore'][] = $value;
                } else {
                    $args[$key] = $value;
                }
            } else {
                $positionals[] = $arg;
            }
        }
        $args['command'] = $positionals[0] ?? null;
        if ($args['command'] === 'scan') {
            $args['target'] = $positionals[1] ?? '.';
        } elseif ($args['command'] === 'delta') {
            $args['base'] ??= $positionals[1] ?? null;
            $args['head'] ??= $positionals[2] ?? '.';
        }
        return $args;
    }

    /** @param list<string> $ignore @return array<string,mixed> */
    private static function reportInput(?string $reportPath, ?string $targetPath, array $ignore): array
    {
        if ($reportPath !== null) {
            return json_decode((string) file_get_contents($reportPath), true, 512, JSON_THROW_ON_ERROR);
        }
        if ($targetPath === null) {
            throw new \InvalidArgumentException('Missing delta input.');
        }
        $config = Config::load($targetPath);
        $config['ignores'] = array_values(array_merge($config['ignores'], $ignore));
        return (new Analyzer())->analyze($targetPath, $config, DefaultRegistry::create())->toReport();
    }

    /** @param array<string,mixed> $delta */
    private static function formatDelta(array $delta): string
    {
        $lines = ['slop-scan delta', 'added: ' . $delta['summary']['added'], 'resolved: ' . $delta['summary']['resolved']];
        foreach ($delta['changes'] as $change) {
            $lines[] = $change['status'] . ' ' . ($change['finding']['ruleId'] ?? '<unknown>');
        }
        return implode("\n", $lines);
    }

    /** @param array<string,mixed> $delta */
    private static function shouldFail(array $delta, ?string $failOn): bool
    {
        if ($failOn === null || $failOn === '') {
            return false;
        }
        $statuses = array_map('trim', explode(',', $failOn));
        foreach ($delta['changes'] as $change) {
            if (in_array('any', $statuses, true) || in_array($change['status'], $statuses, true)) {
                return true;
            }
        }
        return false;
    }
}
