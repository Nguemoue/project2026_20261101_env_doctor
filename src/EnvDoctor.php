<?php

declare(strict_types=1);

namespace EnvDoctor;

final class Issue
{
    public function __construct(
        public readonly string $severity,
        public readonly string $code,
        public readonly string $message,
        public readonly ?string $file = null,
        public readonly ?int $line = null,
        public readonly ?string $key = null,
    ) {
    }

    /** @return array{severity: string, code: string, message: string, file: ?string, line: ?int, key: ?string} */
    public function toArray(): array
    {
        return [
            'severity' => $this->severity,
            'code' => $this->code,
            'message' => $this->message,
            'file' => $this->file,
            'line' => $this->line,
            'key' => $this->key,
        ];
    }
}

final class Variable
{
    public function __construct(
        public readonly string $key,
        public readonly string $value,
        public readonly int $line,
        public readonly bool $quoted,
    ) {
    }
}

final class EnvDocument
{
    /**
     * @param array<string, Variable> $variables
     * @param list<Issue> $issues
     */
    public function __construct(
        public readonly array $variables,
        public readonly array $issues,
        public readonly string $path,
    ) {
    }
}

final class Parser
{
    public function parse(string $contents, string $path): EnvDocument
    {
        if (str_starts_with($contents, "\xEF\xBB\xBF")) {
            $contents = substr($contents, 3);
        }

        $contents = str_replace(["\r\n", "\r"], ["\n", "\n"], $contents);
        $lines = explode("\n", $contents);

        /** @var array<string, Variable> $variables */
        $variables = [];
        /** @var list<Issue> $issues */
        $issues = [];
        $count = count($lines);

        for ($i = 0; $i < $count; $i++) {
            $lineNo = $i + 1;
            $trim = ltrim($lines[$i]);
            if ($trim === '' || str_starts_with($trim, '#')) {
                continue;
            }
            if (str_starts_with($trim, 'export ')) {
                $trim = ltrim(substr($trim, 7));
            }
            if (!preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\s*=(.*)$/', $trim, $matches)) {
                $issues[] = new Issue(
                    'error',
                    'malformed_line',
                    'Unparseable line. Expected KEY=VALUE.',
                    $path,
                    $lineNo,
                );
                continue;
            }

            $key = $matches[1];
            $rawWithSpace = $matches[2];
            $raw = ltrim($rawWithSpace, " \t");
            if ($raw !== '' && ($raw[0] === '"' || $raw[0] === "'")) {
                $quote = $raw[0];
                while ($this->closingQuote($raw, $quote) === null && ($i + 1) < $count) {
                    $i++;
                    $raw .= "\n" . $lines[$i];
                }
            }

            [$value, $quoted, $valueIssues] = $this->parseValue($raw, $lineNo, $path, $key);
            foreach ($valueIssues as $issue) {
                $issues[] = $issue;
            }
            if (
                !$quoted
                && $raw !== ''
                && $raw[0] === '#'
                && $rawWithSpace !== ''
                && $rawWithSpace[0] !== ' '
                && $rawWithSpace[0] !== "\t"
            ) {
                $issues[] = new Issue(
                    'warning',
                    'hash_comment',
                    $key . ' is empty: an unquoted # starts a comment. Quote the value if you meant to keep it, for example ' . $key . '="#...".',
                    $path,
                    $lineNo,
                    $key,
                );
            }

            if (isset($variables[$key])) {
                $issues[] = new Issue(
                    'warning',
                    'duplicate_key',
                    $key . ' is defined more than once. The last value wins, which surprises most people.',
                    $path,
                    $lineNo,
                    $key,
                );
            }

            $variables[$key] = new Variable($key, $value, $lineNo, $quoted);
        }

        return new EnvDocument($variables, $issues, $path);
    }

    public function parseFile(string $path): EnvDocument
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            return new EnvDocument([], [
                new Issue('error', 'unreadable_file', 'Could not read ' . $path . '.', $path),
            ], $path);
        }

        return $this->parse($contents, $path);
    }

    /** @return array{0: string, 1: bool, 2: list<Issue>} */
    private function parseValue(string $raw, int $line, string $path, string $key): array
    {
        if ($raw === '' || str_starts_with($raw, '#')) {
            return ['', false, []];
        }

        $quote = $raw[0];
        if ($quote === '"' || $quote === "'") {
            $end = $this->closingQuote($raw, $quote);
            if ($end === null) {
                return [$raw, false, [new Issue(
                    'error',
                    'unclosed_quote',
                    'Unclosed ' . $quote . ' quote for ' . $key . '.',
                    $path,
                    $line,
                    $key,
                )]];
            }

            $inner = substr($raw, 1, $end - 1);
            $rest = ltrim(substr($raw, $end + 1), " \t");
            $issues = [];
            if ($rest !== '' && !str_starts_with($rest, '#')) {
                $issues[] = new Issue(
                    'warning',
                    'trailing_junk',
                    'Unexpected text after the quoted value of ' . $key . '.',
                    $path,
                    $line,
                    $key,
                );
            }

            $value = $quote === '"' ? $this->unescapeDouble($inner) : $inner;

            return [$value, true, $issues];
        }

        if (preg_match('/^(.*?)[ \t]+#.*$/s', $raw, $matches)) {
            $value = rtrim($matches[1], " \t");
        } else {
            $value = rtrim($raw, " \t");
        }

        $issues = [];
        if (preg_match('/\s/', $value) === 1) {
            $issues[] = new Issue(
                'warning',
                'unquoted_whitespace',
                $key . ' contains whitespace but is not quoted. Quote it so every dotenv parser agrees.',
                $path,
                $line,
                $key,
            );
        }

        return [$value, false, $issues];
    }

    private function closingQuote(string $raw, string $quote): ?int
    {
        $length = strlen($raw);
        for ($i = 1; $i < $length; $i++) {
            if ($quote === '"' && $raw[$i] === '\\') {
                $i++;
                continue;
            }
            if ($raw[$i] === $quote) {
                return $i;
            }
        }

        return null;
    }

    private function unescapeDouble(string $inner): string
    {
        $unescaped = preg_replace_callback('/\\\\([nrt"\\\\])/', static function (array $match): string {
            return match ($match[1]) {
                'n' => "\n",
                'r' => "\r",
                't' => "\t",
                '"' => '"',
                '\\' => '\\',
                default => $match[0],
            };
        }, $inner);

        return $unescaped ?? $inner;
    }
}

final class Checker
{
    /** @var array<string, array{name: string, pattern: string, allow: list<string>}> */
    private const SECRET_RULES = [
        [
            'name' => 'AWS access key',
            'pattern' => '/(?:AKIA|ASIA)[0-9A-Z]{16}/',
            'allow' => ['AKIAIOSFODNN7EXAMPLE'],
        ],
        [
            'name' => 'GitHub token',
            'pattern' => '/gh[pousr]_[A-Za-z0-9]{36,}/',
            'allow' => [],
        ],
        [
            'name' => 'GitHub fine-grained token',
            'pattern' => '/github_pat_[A-Za-z0-9_]{22,}/',
            'allow' => [],
        ],
        [
            'name' => 'Stripe live secret',
            'pattern' => '/sk_live_[A-Za-z0-9]{16,}/',
            'allow' => [],
        ],
        [
            'name' => 'Slack token',
            'pattern' => '/xox[baprs]-[A-Za-z0-9-]{10,}/',
            'allow' => [],
        ],
        [
            'name' => 'Google API key',
            'pattern' => '/AIza[0-9A-Za-z\-_]{35}/',
            'allow' => [],
        ],
        [
            'name' => 'OpenAI-style key',
            'pattern' => '/sk-(?:proj-)?[A-Za-z0-9_\-]{32,}/',
            'allow' => [],
        ],
        [
            'name' => 'private key',
            'pattern' => '/-----BEGIN (?:RSA |OPENSSH |EC |DSA )?PRIVATE KEY-----/',
            'allow' => [],
        ],
        [
            'name' => 'JWT',
            'pattern' => '/eyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}/',
            'allow' => [],
        ],
    ];

    /** @var list<string> */
    private const DUMMY_SECRETS = [
        'AKIAIOSFODNN7EXAMPLE',
        'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
    ];

    /** @return list<Issue> */
    public function diff(EnvDocument $example, EnvDocument $env, bool $strictExtra): array
    {
        /** @var list<Issue> $issues */
        $issues = array_merge($example->issues, $env->issues);

        foreach ($example->variables as $key => $sample) {
            $issues = array_merge($issues, $this->secretIssues($sample->value, $example->path, $sample->line, $key));

            if (!isset($env->variables[$key])) {
                $hint = $key === 'APP_KEY' ? ' Generate one with `php artisan key:generate`.' : '';
                $issues[] = new Issue(
                    'error',
                    'missing_key',
                    'Missing ' . $key . ' in ' . $env->path . '. It is declared in ' . $example->path . '.' . $hint,
                    $env->path,
                    null,
                    $key,
                );
                continue;
            }

            $actual = $env->variables[$key];
            if ($key === 'APP_KEY') {
                $appKeyIssue = $this->appKeyIssue($actual, $env->path);
                if ($appKeyIssue !== null) {
                    $issues[] = $appKeyIssue;
                }
            }

            if ($actual->value === '' && $sample->value !== '') {
                $issues[] = new Issue(
                    'warning',
                    'empty_value',
                    $key . ' is empty in ' . $env->path . ' but the example has a sample value.',
                    $env->path,
                    $actual->line,
                    $key,
                );
            }

            if ($actual->value !== '' && $actual->value === $sample->value && $this->isPlaceholder($sample->value)) {
                $issues[] = new Issue(
                    'warning',
                    'stale_placeholder',
                    $key . ' still has the example placeholder. Replace it before this environment is used.',
                    $env->path,
                    $actual->line,
                    $key,
                );
            }
        }

        foreach ($env->variables as $key => $actual) {
            if (isset($example->variables[$key])) {
                continue;
            }
            $issues[] = new Issue(
                $strictExtra ? 'error' : 'info',
                'extra_key',
                $key . ' is set in ' . $env->path . ' but missing from ' . $example->path . '. Add it to the example so other machines stay in sync.',
                $env->path,
                $actual->line,
                $key,
            );
        }

        return $this->sort($issues);
    }

    /** @return list<Issue> */
    public function secrets(EnvDocument $document): array
    {
        /** @var list<Issue> $issues */
        $issues = $document->issues;
        foreach ($document->variables as $variable) {
            $issues = array_merge(
                $issues,
                $this->secretIssues($variable->value, $document->path, $variable->line, $variable->key),
            );
        }

        return $this->sort($issues);
    }

    /** @param list<Issue> $issues
     *  @return list<Issue>
     */
    public function sort(array $issues): array
    {
        $rank = ['error' => 0, 'warning' => 1, 'info' => 2];
        usort($issues, static function (Issue $a, Issue $b) use ($rank): int {
            return ($rank[$a->severity] <=> $rank[$b->severity])
                ?: (($a->line ?? PHP_INT_MAX) <=> ($b->line ?? PHP_INT_MAX))
                ?: strcmp((string) $a->key, (string) $b->key)
                ?: strcmp($a->code, $b->code);
        });

        return $issues;
    }

    private function appKeyIssue(Variable $variable, string $path): ?Issue
    {
        if ($variable->value === '' || preg_match('/^base64:?$/', $variable->value) === 1 || $this->isPlaceholder($variable->value)) {
            return new Issue(
                'error',
                'app_key_empty',
                'APP_KEY is empty or still a placeholder. Run `php artisan key:generate` before deploying.',
                $path,
                $variable->line,
                'APP_KEY',
            );
        }

        if (preg_match('/^base64:[A-Za-z0-9+\/=]{24,}$/', $variable->value) !== 1) {
            return new Issue(
                'warning',
                'app_key_format',
                'APP_KEY does not look like a Laravel key (expected base64: followed by the encoded secret).',
                $path,
                $variable->line,
                'APP_KEY',
            );
        }

        return null;
    }

    private function isPlaceholder(string $value): bool
    {
        if (in_array($value, self::DUMMY_SECRETS, true)) {
            return true;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return false;
        }

        if (preg_match('/^(?:null|none|changeme|change[_-]?me|secret|password|passwd|xxx+|todo|fixme|replace[_-]?me|example|placeholder|<[^>]+>|\{[^}]+\}|\$\{[^}]+\})\z/i', $trimmed) === 1) {
            return true;
        }

        return preg_match('/(your[-_ ]|example\.com|changeme|placeholder|xxxx)/i', $trimmed) === 1;
    }

    /** @return list<Issue> */
    private function secretIssues(string $value, string $path, int $line, string $key): array
    {
        if ($value === '' || $this->isPlaceholder($value)) {
            return [];
        }

        $issues = [];
        foreach (self::SECRET_RULES as $rule) {
            if (preg_match_all($rule['pattern'], $value, $matches) < 1) {
                continue;
            }
            foreach ($matches[0] as $token) {
                if (in_array($token, $rule['allow'], true) || in_array($token, self::DUMMY_SECRETS, true)) {
                    continue;
                }
                $issues[] = new Issue(
                    'error',
                    'committed_secret',
                    'Possible ' . $rule['name'] . ' in ' . $path . ' (' . $key . '). Files that get committed must not contain live credentials. Rotate the key if this value is real.',
                    $path,
                    $line,
                    $key,
                );
                break;
            }
        }

        return $issues;
    }
}
