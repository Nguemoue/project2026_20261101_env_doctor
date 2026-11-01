<?php

declare(strict_types=1);

namespace EnvDoctor;

final class Cli
{
    public function __construct(
        private readonly Parser $parser = new Parser(),
        private readonly Checker $checker = new Checker(),
    ) {
    }

    /** @param list<string> $argv */
    public function run(array $argv): int
    {
        $command = $argv[1] ?? 'check';
        $rest = array_slice($argv, 2);

        if ($command === 'help' || $command === '--help' || $command === '-h') {
            fwrite(STDOUT, $this->help());
            return 0;
        }

        if (str_starts_with($command, '-')) {
            $rest = array_slice($argv, 1);
            $command = 'check';
        }

        if (!in_array($command, ['check', 'lint', 'secrets'], true)) {
            fwrite(STDERR, 'Unknown command: ' . $command . "\n\n" . $this->help());
            return 2;
        }

        try {
            $opts = $this->parseArgs($rest);
        } catch (\InvalidArgumentException $exception) {
            fwrite(STDERR, $exception->getMessage() . "\n\n" . $this->help());
            return 2;
        }

        if (!empty($opts['help'])) {
            fwrite(STDOUT, $this->help());
            return 0;
        }

        $color = $opts['color'];
        if ($color === null) {
            $color = $opts['format'] === 'text' && defined('STDOUT') && stream_isatty(STDOUT) && getenv('NO_COLOR') === false;
        }

        if ($command === 'lint' || $command === 'secrets') {
            if (count($opts['positionals']) !== 1) {
                fwrite(STDERR, $command . " expects exactly one file.\n\n" . $this->help());
                return 2;
            }

            $path = $opts['positionals'][0];
            $loaded = $this->load($path);
            if ($loaded['usage']) {
                return $this->finish($loaded['issues'], $opts['format'], false, $loaded['summary'], $color, true);
            }

            $issues = $command === 'secrets'
                ? $this->checker->secrets($loaded['document'])
                : $this->checker->sort($loaded['document']->issues);

            return $this->finish($issues, $opts['format'], $opts['strict'], $loaded['summary'], $color, false);
        }

        if ($opts['positionals'] !== []) {
            fwrite(STDERR, "check does not take positional arguments.\n\n" . $this->help());
            return 2;
        }

        $example = $this->load($opts['example']);
        $env = $this->load($opts['env']);
        if ($example['usage'] || $env['usage']) {
            $issues = array_merge($example['issues'], $env['issues']);
            $summary = trim($example['summary'] . ' ' . $env['summary']);

            return $this->finish($issues, $opts['format'], false, $summary, $color, true);
        }

        $issues = $this->checker->diff($example['document'], $env['document'], $opts['strict_extra']);
        $summary = sprintf(
            'Checked %s (%d keys) against %s (%d keys).',
            $example['document']->path,
            count($example['document']->variables),
            $env['document']->path,
            count($env['document']->variables),
        );

        return $this->finish($issues, $opts['format'], $opts['strict'], $summary, $color, false);
    }

    /**
     * @param list<string> $args
     * @return array{
     *   example: string,
     *   env: string,
     *   format: string,
     *   strict: bool,
     *   strict_extra: bool,
     *   color: ?bool,
     *   help: bool,
     *   positionals: list<string>
     * }
     */
    private function parseArgs(array $args): array
    {
        $opts = [
            'example' => '.env.example',
            'env' => '.env',
            'format' => 'text',
            'strict' => false,
            'strict_extra' => false,
            'color' => null,
            'help' => false,
            'positionals' => [],
        ];

        $count = count($args);
        for ($i = 0; $i < $count; $i++) {
            $arg = $args[$i];
            if ($arg === '--strict') {
                $opts['strict'] = true;
                continue;
            }
            if ($arg === '--strict-extra') {
                $opts['strict_extra'] = true;
                continue;
            }
            if ($arg === '--no-color') {
                $opts['color'] = false;
                continue;
            }
            if ($arg === '--color') {
                $opts['color'] = true;
                continue;
            }
            if ($arg === '--help' || $arg === '-h') {
                $opts['help'] = true;
                continue;
            }

            foreach (['example', 'env', 'format'] as $name) {
                $flag = '--' . $name;
                if ($arg === $flag || str_starts_with($arg, $flag . '=')) {
                    $value = $arg === $flag ? ($args[++$i] ?? null) : substr($arg, strlen($flag) + 1);
                    if ($value === null || $value === '' || str_starts_with($value, '-')) {
                        throw new \InvalidArgumentException('Missing value for --' . $name . '.');
                    }
                    $opts[$name] = $value;
                    continue 2;
                }
            }

            if (str_starts_with($arg, '-')) {
                throw new \InvalidArgumentException('Unknown option ' . $arg . '.');
            }

            $opts['positionals'][] = $arg;
        }

        if (!in_array($opts['format'], ['text', 'json'], true)) {
            throw new \InvalidArgumentException('Format must be text or json.');
        }

        return $opts;
    }

    /**
     * @return array{document: EnvDocument, issues: list<Issue>, summary: string, usage: bool}
     */
    private function load(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            return [
                'document' => new EnvDocument([], [], $path),
                'issues' => [new Issue('error', 'unreadable_file', 'Cannot read ' . $path . '.', $path)],
                'summary' => 'Cannot read ' . $path . '.',
                'usage' => true,
            ];
        }

        $document = $this->parser->parseFile($path);

        return [
            'document' => $document,
            'issues' => $document->issues,
            'summary' => 'Read ' . $path . ' (' . count($document->variables) . ' keys).',
            'usage' => false,
        ];
    }

    /**
     * @param list<Issue> $issues
     */
    private function finish(array $issues, string $format, bool $strict, string $summary, bool $color, bool $usageFailure): int
    {
        $errors = 0;
        $warnings = 0;
        $infos = 0;
        foreach ($issues as $issue) {
            if ($issue->severity === 'error') {
                $errors++;
            } elseif ($issue->severity === 'warning') {
                $warnings++;
            } else {
                $infos++;
            }
        }

        $ok = $errors === 0 && (!$strict || $warnings === 0);
        if ($format === 'json') {
            fwrite(STDOUT, json_encode([
                'ok' => $ok && !$usageFailure,
                'errors' => $errors,
                'warnings' => $warnings,
                'infos' => $infos,
                'summary' => $summary,
                'issues' => array_map(static fn (Issue $issue): array => $issue->toArray(), $issues),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
        } else {
            $this->renderText($issues, $summary, $ok && !$usageFailure, $color);
        }

        if ($usageFailure) {
            return 2;
        }

        return $ok ? 0 : 1;
    }

    /** @param list<Issue> $issues */
    private function renderText(array $issues, string $summary, bool $ok, bool $color): void
    {
        $reset = $color ? "\033[0m" : '';
        if ($issues === []) {
            $paint = $color ? "\033[32m" : '';
            fwrite(STDOUT, $paint . 'env-doctor: clean' . $reset . "\n" . $summary . "\n");
            return;
        }

        $errors = count(array_filter($issues, static fn (Issue $issue): bool => $issue->severity === 'error'));
        $warnings = count(array_filter($issues, static fn (Issue $issue): bool => $issue->severity === 'warning'));
        fwrite(STDOUT, 'env-doctor: ' . $errors . ' error(s), ' . $warnings . ' warning(s)' . "\n\n");

        foreach ($issues as $issue) {
            $paint = $color ? match ($issue->severity) {
                'error' => "\033[31m",
                'warning' => "\033[33m",
                default => "\033[36m",
            } : '';
            $where = $issue->file ?? '';
            if ($issue->line !== null) {
                $where .= ':' . $issue->line;
            }
            $key = $issue->key !== null ? '  ' . $issue->key : '';
            fwrite(STDOUT, $paint . str_pad($issue->severity, 7) . $reset . ' ' . $issue->code . $key . ($where !== '' ? '  ' . $where : '') . "\n");
            fwrite(STDOUT, '        ' . $issue->message . "\n\n");
        }

        $warnings = count(array_filter($issues, static fn (Issue $issue): bool => $issue->severity === 'warning'));
        $label = !$ok ? 'failed' : ($warnings > 0 ? 'warnings' : 'clean');
        $paint = $color ? ($ok ? ($warnings > 0 ? "\033[33m" : "\033[32m") : "\033[31m") : '';
        fwrite(STDOUT, $paint . $label . $reset . '  ' . $summary . "\n");
    }

    private function help(): string
    {
        return <<<TXT
env-doctor — catch a broken .env before it breaks the deploy.

Usage:
  php bin/env-doctor check [--example FILE] [--env FILE] [--format text|json] [--strict] [--strict-extra]
  php bin/env-doctor lint FILE [--format text|json]
  php bin/env-doctor secrets FILE [--format text|json]
  php bin/env-doctor help

check (default) compares .env.example with .env:
  missing keys are errors, leftover placeholders are warnings,
  live-looking secrets inside the example file are errors,
  keys that exist only in .env are infos (errors with --strict-extra).

lint reports duplicate keys, broken quotes and unquoted spaces.
secrets scans one file for known credential shapes. Use it on files
you are about to commit, not as a substitute for a secret manager.

--strict        fail (exit 1) when there are warnings
--format json   machine-readable report on stdout

Exit codes: 0 clean, 1 findings, 2 bad usage or unreadable file.

TXT;
    }
}
