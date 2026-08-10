<?php

/**
 * Plain-PHP test for GitLogReader against a throwaway git fixture — no
 * PHPUnit, no Grav test harness (neither sibling plugin uses one).
 * Usage: tests/build-fixture.sh   (builds the fixture and runs this)
 *    or: php tests/GitLogReaderTest.php <fixture-dir> [path/to/vendor/autoload.php]
 */

$fixture = $argv[1] ?? null;
if ($fixture === null || !is_dir($fixture)) {
    fwrite(STDERR, "Usage: php GitLogReaderTest.php <fixture-dir> [autoload.php]\nRun tests/build-fixture.sh instead of calling this directly.\n");
    exit(2);
}

$autoload = $argv[2] ?? getenv('GRAV_VENDOR_AUTOLOAD') ?: null;
if ($autoload === null) {
    foreach ([
        '/home/adi/projects/oregontrailblazers.org/vendor/autoload.php',
        __DIR__ . '/../../../vendor/autoload.php',
    ] as $candidate) {
        if (is_file($candidate)) {
            $autoload = $candidate;
            break;
        }
    }
}
if ($autoload === null || !is_file($autoload)) {
    fwrite(STDERR, "Could not find a Composer autoload.php providing symfony/process. Pass it as the 2nd argument or set GRAV_VENDOR_AUTOLOAD.\n");
    exit(2);
}

require $autoload;
require __DIR__ . '/../classes/GitLogReader.php';

use Grav\Plugin\PageHistory\GitLogReader;

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, $extra = null): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "PASS: $label\n";
    } else {
        $fail++;
        echo "FAIL: $label" . ($extra !== null ? ' -- ' . print_r($extra, true) : '') . "\n";
    }
}

// --- 1. Discovery mode: no git_dir configured, walk up from the file ---
$reader = new GitLogReader(['scope_root' => 'pages']);
$homeFile = "$fixture/work/pages/02.home/default.md";
$log = $reader->log($homeFile);
check('discovery: log() returns entries for tracked file', is_array($log) && count($log) >= 2, $log);
check('discovery: most recent commit first', is_array($log) && str_contains($log[0]['subject'] ?? '', 'v3'), $log[0] ?? null);

// --- 2. show() on the most recent commit ---
$diff = $reader->show($homeFile, $log[0]['hash']);
check('show(): returns diff text containing the new content', is_string($diff) && str_contains($diff, 'Version 3'), $diff);

// --- 3. show(): ref validation rejects non-hex / option-injection-shaped refs ---
check('show(): rejects option-injection-shaped ref', $reader->show($homeFile, '--upload-pack=/bin/sh') === null);
check('show(): rejects non-hex ref', $reader->show($homeFile, 'HEAD; rm -rf /') === null);

// --- 4. scope_root containment: file outside scope_root ---
$secretFile = "$fixture/work/config/security-private.php";
check('scope: file outside scope_root returns null', $reader->log($secretFile) === null);

// --- 5. scope_root segment-boundary: 'pages' must not match 'pages-secret' ---
$pagesSecretFile = "$fixture/work/pages-secret/default.md";
check('scope: segment-boundary — pages-secret is NOT inside scope_root pages', $reader->log($pagesSecretFile) === null);

// --- 6. --follow renames: folder reorder must not jump into an unrelated same-named file ---
$readerFollow = new GitLogReader(['scope_root' => 'pages', 'follow_renames' => true]);
$logFollow = $readerFollow->log($homeFile);
$subjects = array_map(fn($e) => $e['subject'], $logFollow);
check(
    '--follow: history includes pre-rename commits (v1, v2) for the renamed page',
    in_array('Add home page v1', $subjects, true) && in_array('Update home page to v2', $subjects, true),
    $subjects
);

$readerNoFollow = new GitLogReader(['scope_root' => 'pages', 'follow_renames' => false]);
$logNoFollow = $readerNoFollow->log($homeFile);
$subjectsNoFollow = array_map(fn($e) => $e['subject'], $logNoFollow);
check(
    '--follow disabled: history stops at the rename, does not include pre-rename commits',
    !in_array('Add home page v1', $subjectsNoFollow, true),
    $subjectsNoFollow
);

// --- 7. Explicit git_dir + work_tree, bare repo + separate worktree (real VPS deploy shape) ---
$readerBare = new GitLogReader([
    'git_dir' => "$fixture/bare.git",
    'work_tree' => "$fixture/deployed",
    'scope_root' => 'pages',
]);
$deployedFile = "$fixture/deployed/pages/02.home/default.md";
$logBare = $readerBare->log($deployedFile);
check('bare+work_tree: log() works against deployed tree with no .git present', is_array($logBare) && count($logBare) >= 2, $logBare);

$headSha = $readerBare->headSha();
check('bare+work_tree: headSha() resolves', is_string($headSha) && preg_match('/^[0-9a-f]{40}$/', $headSha) === 1, $headSha);

// --- 8. Explicit git_dir (bare) with NO work_tree configured: must gracefully no-op, not guess ---
$readerBareNoWorkTree = new GitLogReader([
    'git_dir' => "$fixture/bare.git",
    'scope_root' => 'pages',
]);
check(
    'bare without work_tree: log() gracefully no-ops (null), does not guess a work tree',
    $readerBareNoWorkTree->log($deployedFile) === null
);

// --- 9. Repo with zero commits: headSha() must not throw, must return null ---
$readerEmpty = new GitLogReader(['scope_root' => 'pages']);
check('empty repo: headSha() returns null instead of throwing', $readerEmpty->headSha("$fixture/empty-work") === null);

// --- 10. Nonexistent file ---
check('nonexistent file: log() returns null', $reader->log("$fixture/work/pages/02.home/does-not-exist.md") === null);

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
