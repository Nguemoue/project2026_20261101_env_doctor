<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/EnvDoctor.php';
require_once dirname(__DIR__) . '/src/Cli.php';

use EnvDoctor\Checker;
use EnvDoctor\Cli;
use EnvDoctor\Parser;

$failures = 0;
$passed = 0;

function expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function test(string $name, callable $fn): void
{
    global $failures, $passed;
    try {
        $fn();
        $passed++;
        fwrite(STDOUT, "ok  {$name}\n");
    } catch (Throwable $exception) {
        $failures++;
        fwrite(STDOUT, "FAIL {$name}\n    {$exception->getMessage()}\n");
    }
}

function codes(array $issues): array
{
    return array_map(static fn ($issue) => $issue->code, $issues);
}

function runBin(array $args): array
{
    $bin = dirname(__DIR__) . '/bin/env-doctor';
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($bin);
    foreach ($args as $arg) {
        $command .= ' ' . escapeshellarg($arg);
    }
    $command .= ' 2>&1';
    $output = [];
    $code = 0;
    exec($command, $output, $code);

    return [$code, implode("\n", $output)];
}

$parser = new Parser();
$checker = new Checker();
$root = dirname(__DIR__);

test('parses comments, export, quotes and escapes', function () use ($parser): void {
    $doc = $parser->parse(<<<'ENV'
# comment
export APP_NAME="My App"
EMPTY=
HASH_IN_QUOTES="keep # this"
INLINE=value # comment
COLOR=#fff
QUOTED_HASH="#fff"
ESCAPED="line\nnext"
SINGLE='foo bar'
ENV, '.env');

    expect($doc->variables['APP_NAME']->value === 'My App', 'export + double quotes');
    expect($doc->variables['EMPTY']->value === '', 'empty value');
    expect($doc->variables['HASH_IN_QUOTES']->value === 'keep # this', 'hash inside quotes');
    expect($doc->variables['INLINE']->value === 'value', 'inline comment stripped');
    expect($doc->variables['COLOR']->value === '', 'unquoted hash starts a comment');
    expect($doc->variables['QUOTED_HASH']->value === '#fff', 'quoted hash is kept');
    expect($doc->variables['ESCAPED']->value === "line\nnext", 'newline escape');
    expect($doc->variables['SINGLE']->value === 'foo bar', 'single quotes');
    expect(codes($doc->issues) === ['hash_comment'], 'only the swallowed-hash warning, got ' . json_encode(codes($doc->issues)));
    expect($doc->issues[0]->key === 'COLOR', 'hash warning points at COLOR');
});

test('keeps a backslash that only escapes a backslash', function () use ($parser): void {
    $doc = $parser->parse("PATH=\"C:\\\\temp\"\n", 'x');
    expect($doc->variables['PATH']->value === 'C:\\temp', 'got ' . json_encode($doc->variables['PATH']->value));
});

test('joins multiline quoted values', function () use ($parser): void {
    $doc = $parser->parse("CERT=\"line1\nline2\"\nNEXT=1\n", 'x');
    expect($doc->variables['CERT']->value === "line1\nline2", 'multiline value');
    expect($doc->variables['NEXT']->value === '1', 'parser resumes after the quote');
});

test('strips a utf-8 bom and crlf', function () use ($parser): void {
    $doc = $parser->parse("\xEF\xBB\xBFAPP_ENV=local\r\nDB_HOST=127.0.0.1\r\n", 'x');
    expect(isset($doc->variables['APP_ENV']) && $doc->variables['APP_ENV']->value === 'local', 'bom key');
    expect($doc->variables['DB_HOST']->value === '127.0.0.1', 'crlf value');
});

test('reports duplicates, bad lines, unclosed quotes and bare spaces', function () use ($parser): void {
    $doc = $parser->parse(<<<'ENV'
A=1
A=2
not a pair
NAME=Ada Lovelace
TOKEN="abc
ENV, 'x');

    $codes = codes($doc->issues);
    expect(in_array('duplicate_key', $codes, true), 'duplicate');
    expect(in_array('malformed_line', $codes, true), 'malformed');
    expect(in_array('unquoted_whitespace', $codes, true), 'spaces');
    expect(in_array('unclosed_quote', $codes, true), 'unclosed');
    expect($doc->variables['A']->value === '2', 'last duplicate wins');
});

test('treats a space then hash as an empty value', function () use ($parser): void {
    $doc = $parser->parse("NOTE= # just a comment\n", 'x');
    expect($doc->variables['NOTE']->value === '', 'empty comment value, got ' . json_encode($doc->variables['NOTE']->value));
});

test('missing key, stale placeholder, empty app key and extra key', function () use ($parser, $checker): void {
    $example = $parser->parse(<<<'ENV'
APP_KEY=
DB_PASSWORD=secret
MAIL_HOST=smtp.example.com
MAIL_PASSWORD=changeme
ENV, '.env.example');
    $env = $parser->parse(<<<'ENV'
APP_KEY=
DB_HOST=127.0.0.1
MAIL_HOST=smtp.example.com
MAIL_PASSWORD=changeme
ENV, '.env');

    $issues = $checker->diff($example, $env, false);
    $codes = codes($issues);
    expect(in_array('missing_key', $codes, true), 'missing DB_PASSWORD');
    expect(in_array('app_key_empty', $codes, true), 'empty APP_KEY');
    expect(in_array('stale_placeholder', $codes, true), 'placeholder copied');
    expect(in_array('extra_key', $codes, true), 'extra DB_HOST');
    $extra = array_values(array_filter($issues, static fn ($issue) => $issue->code === 'extra_key'))[0];
    expect($extra->severity === 'info', 'extra is info by default');

    $strict = $checker->diff($example, $env, true);
    $extraStrict = array_values(array_filter($strict, static fn ($issue) => $issue->code === 'extra_key'))[0];
    expect($extraStrict->severity === 'error', 'strict-extra promotes extras');
});

test('accepts a real laravel key and ignores the public aws example', function () use ($parser, $checker): void {
    $example = $parser->parse(<<<'ENV'
APP_KEY=
APP_ENV=local
AWS_ACCESS_KEY_ID=AKIAIOSFODNN7EXAMPLE
DB_PASSWORD=
ENV, '.env.example');
    $env = $parser->parse(<<<'ENV'
APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=
APP_ENV=production
AWS_ACCESS_KEY_ID=AKIATESTINGONLYNOTREAL
DB_PASSWORD=not-the-sample
ENV, '.env');

    $issues = $checker->diff($example, $env, false);
    $errors = array_filter($issues, static fn ($issue) => $issue->severity === 'error');
    expect($errors === [], 'clean pair produced errors: ' . json_encode(array_map(static fn ($issue) => $issue->message, $issues)));
    expect(codes($issues) === [] || !in_array('committed_secret', codes($issues), true), 'doc aws key is not a leak');
});

test('flags a live-looking secret inside the example file', function () use ($parser, $checker): void {
    $token = 'ghp_' . str_repeat('a', 36);
    $example = $parser->parse("GITHUB_TOKEN={$token}\nAPP_KEY=\n", '.env.example');
    $env = $parser->parse("GITHUB_TOKEN=rotated\nAPP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=\n", '.env');
    $issues = $checker->diff($example, $env, false);
    $secrets = array_values(array_filter($issues, static fn ($issue) => $issue->code === 'committed_secret'));
    expect(count($secrets) === 1, 'one committed secret, got ' . count($secrets));
    expect($secrets[0]->file === '.env.example', 'secret is attributed to the example');
});

test('secrets command finds a private key and skips placeholders', function () use ($parser, $checker): void {
    $doc = $parser->parse(<<<'ENV'
MAIL_PASSWORD=changeme
CERT="-----BEGIN PRIVATE KEY-----"
ENV, 'sample.env');
    $issues = $checker->secrets($doc);
    expect(codes($issues) === ['committed_secret'], 'got ' . json_encode(codes($issues)));
    expect($issues[0]->key === 'CERT', 'key name');
});

test('shipped good example exits 0 and the broken one exits 1', function () use ($root): void {
    [$code, $out] = runBin(['check', '--example', $root . '/examples/app.env.example', '--env', $root . '/examples/app.env', '--format', 'json']);
    expect($code === 0, "good pair exit {$code}: {$out}");
    $report = json_decode($out, true);
    expect(is_array($report) && $report['ok'] === true && $report['errors'] === 0, 'good json');

    [$badCode, $badOut] = runBin(['check', '--example', $root . '/examples/app.env.example', '--env', $root . '/examples/app.env.broken', '--format=json']);
    expect($badCode === 1, "broken pair exit {$badCode}: {$badOut}");
    $bad = json_decode($badOut, true);
    expect($bad['ok'] === false, 'broken not ok');
    $badCodes = array_column($bad['issues'], 'code');
    expect(in_array('missing_key', $badCodes, true), 'broken missing key');
    expect(in_array('app_key_empty', $badCodes, true), 'broken app key');
    expect(in_array('stale_placeholder', $badCodes, true), 'broken placeholder');
});

test('strict mode fails the clean pair when warnings exist only if there are warnings', function () use ($root): void {
    [$code] = runBin(['check', '--example', $root . '/examples/app.env.example', '--env', $root . '/examples/app.env', '--strict']);
    expect($code === 0, "strict on a clean pair should stay 0, got {$code}");
});

test('cli usage and lint', function () use ($root): void {
    [$help, $helpOut] = runBin(['help']);
    expect($help === 0 && str_contains($helpOut, 'Exit codes'), 'help');

    [$unknown] = runBin(['nope']);
    expect($unknown === 2, 'unknown command');

    [$missing, $missingOut] = runBin(['check', '--env', $root . '/does-not-exist.env', '--example', $root . '/examples/app.env.example']);
    expect($missing === 2 && str_contains($missingOut, 'unreadable_file'), "missing file {$missing}: {$missingOut}");

    $dir = sys_get_temp_dir() . '/env-doctor-' . bin2hex(random_bytes(4));
    mkdir($dir);
    file_put_contents($dir . '/bad.env', "A=1\nA=2\nNAME=Ada Lovelace\n");
    [$lint, $lintOut] = runBin(['lint', $dir . '/bad.env']);
    expect($lint === 0, "lint exit {$lint}\n{$lintOut}");
    expect(str_contains($lintOut, 'duplicate_key') && str_contains($lintOut, 'unquoted_whitespace'), $lintOut);
    expect(!str_contains($lintOut, 'Usage:'), "help leaked into lint output\n{$lintOut}");

    [$lintStrict, $strictOut] = runBin(['lint', $dir . '/bad.env', '--strict']);
    expect($lintStrict === 1, "strict lint exit {$lintStrict}\n{$strictOut}");
});

test('library entrypoint returns the process code', function (): void {
    $code = (new Cli())->run(['env-doctor', 'help']);
    expect($code === 0, 'library help');
});

fwrite(STDOUT, "\n{$passed} passed, {$failures} failed\n");
exit($failures === 0 ? 0 : 1);
