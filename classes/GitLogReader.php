<?php

namespace Grav\Plugin\PageHistory;

use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Process;

/**
 * Plain-PHP, read-only reader over a git repository's log/show for a single
 * scoped file or directory. No Grav-object dependencies — takes a repo
 * location (or discovers one by walking up from a file) and a scope root,
 * nothing else.
 */
class GitLogReader
{
    private const RECORD_SEP = "\x1e";
    private const FIELD_SEP = "\x1f";
    private const LOG_FORMAT = '%H' . self::FIELD_SEP . '%h' . self::FIELD_SEP . '%an'
        . self::FIELD_SEP . '%ad' . self::FIELD_SEP . '%s' . self::RECORD_SEP;

    /** @var string|null configured absolute path to the git dir (bare or .git); null = discover per file */
    private ?string $gitDir;

    /** @var string|null configured absolute work-tree/content-root path; null when using discovery */
    private ?string $workTree;

    /** @var string repo-relative root every resolved path must stay inside of, '' = no restriction */
    private string $scopeRoot;

    private bool $followRenames;

    private string $gitBinaryConfig;

    private ?string $resolvedBinary = null;

    /** @var array<string, array{0: ?string, 1: ?string}> memoised discover() results, keyed by anchor dir */
    private array $discoveryCache = [];

    public function __construct(array $config = [])
    {
        $this->gitDir = ($config['git_dir'] ?? '') !== '' ? rtrim($config['git_dir'], '/') : null;
        $this->workTree = ($config['work_tree'] ?? '') !== '' ? rtrim($config['work_tree'], '/') : null;
        $this->scopeRoot = trim((string) ($config['scope_root'] ?? 'pages'), '/');
        $this->followRenames = (bool) ($config['follow_renames'] ?? false);
        $this->gitBinaryConfig = ($config['git_binary'] ?? '') !== '' ? $config['git_binary'] : 'git';
    }

    /**
     * Commit log for a single file, most recent first.
     *
     * @return array<int, array{hash: string, short_hash: string, author: string, date: string, subject: string}>|null
     *   null means "no history available" — not in a repo, outside scope, git missing, or no commits.
     */
    public function log(string $absoluteFilePath, array $options = []): ?array
    {
        $rel = $this->resolveScopedPath($absoluteFilePath);
        if ($rel === null) {
            return null;
        }

        [$gitDir, $workTree] = $this->resolveRepo($absoluteFilePath);
        if ($gitDir === null) {
            return null;
        }

        $args = ['log', '--date=iso-strict', '--format=' . self::LOG_FORMAT];
        if ($options['follow'] ?? $this->followRenames) {
            $args[] = '--follow';
        }
        if (!empty($options['max_count'])) {
            $args[] = '--max-count=' . (int) $options['max_count'];
        }
        if (!empty($options['skip'])) {
            $args[] = '--skip=' . (int) $options['skip'];
        }
        $args[] = '--';
        $args[] = $rel;

        $output = $this->run($gitDir, $workTree, $args);

        return $output === null ? null : $this->parseLog($output);
    }

    /**
     * Raw unified diff for a single commit's changes to one file.
     * Returns '' for a commit with no textual change to this path (e.g. a
     * pure rename detected by --follow), null for "no history available".
     */
    public function show(string $absoluteFilePath, string $ref): ?string
    {
        if (!preg_match('/^[0-9a-f]{7,40}$/', $ref)) {
            return null;
        }

        $rel = $this->resolveScopedPath($absoluteFilePath);
        if ($rel === null) {
            return null;
        }

        [$gitDir, $workTree] = $this->resolveRepo($absoluteFilePath);
        if ($gitDir === null) {
            return null;
        }

        return $this->run($gitDir, $workTree, ['show', '--format=', $ref, '--', $rel]);
    }

    /**
     * Current HEAD sha, or null if unresolvable / repo has no commits yet.
     * $anchorPath is only needed when git_dir isn't configured (discovery mode).
     */
    public function headSha(?string $anchorPath = null): ?string
    {
        [$gitDir, $workTree] = $this->resolveRepo($anchorPath);
        if ($gitDir === null) {
            return null;
        }

        $output = $this->run($gitDir, $workTree, ['rev-parse', 'HEAD']);
        if ($output === null) {
            return null;
        }

        $sha = trim($output);

        return $sha === '' ? null : $sha;
    }

    /**
     * Resolve an absolute file path to a repo-relative path, or null if it
     * can't be resolved into a repo, or falls outside scope_root.
     */
    public function resolveScopedPath(string $absoluteFilePath): ?string
    {
        [, $workTree] = $this->resolveRepo($absoluteFilePath);
        if ($workTree === null) {
            return null;
        }

        $realFile = realpath($absoluteFilePath);
        $realWorkTree = realpath($workTree);
        if ($realFile === false || $realWorkTree === false) {
            return null;
        }
        $realWorkTree = rtrim($realWorkTree, '/');

        if ($realFile !== $realWorkTree && !str_starts_with($realFile, $realWorkTree . '/')) {
            return null;
        }

        $rel = ltrim(substr($realFile, strlen($realWorkTree)), '/');

        if ($this->scopeRoot !== '' && $rel !== $this->scopeRoot && !str_starts_with($rel, $this->scopeRoot . '/')) {
            return null;
        }

        return $rel;
    }

    /**
     * @return array{0: ?string, 1: ?string} [gitDir, workTree], either may be null
     */
    public function resolveRepo(?string $anchorPath): array
    {
        if ($this->gitDir !== null) {
            return [$this->gitDir, $this->workTree];
        }

        if ($anchorPath === null) {
            return [null, null];
        }

        $anchorDir = is_dir($anchorPath) ? $anchorPath : dirname($anchorPath);
        $anchorDir = realpath($anchorDir) ?: $anchorDir;

        if (!array_key_exists($anchorDir, $this->discoveryCache)) {
            $this->discoveryCache[$anchorDir] = $this->discover($anchorDir);
        }

        return $this->discoveryCache[$anchorDir];
    }

    /**
     * Walk up from $dir looking for a .git directory (dev-checkout fallback
     * only — production bare-repo deploys have no .git in the work tree at
     * all, see PLAN_REVIEW.md §1).
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function discover(string $dir): array
    {
        while (true) {
            $candidate = $dir . '/.git';
            if (is_dir($candidate)) {
                return [$candidate, $dir];
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                return [null, null];
            }
            $dir = $parent;
        }
    }

    private function parseLog(string $output): array
    {
        $entries = [];
        foreach (explode(self::RECORD_SEP, $output) as $record) {
            $record = trim($record, "\n");
            if ($record === '') {
                continue;
            }
            $fields = explode(self::FIELD_SEP, $record);
            if (count($fields) < 5) {
                continue;
            }
            [$hash, $shortHash, $author, $date, $subject] = $fields;
            $entries[] = [
                'hash' => $hash,
                'short_hash' => $shortHash,
                'author' => $author,
                'date' => $date,
                'subject' => $subject,
            ];
        }

        return $entries;
    }

    private function run(string $gitDir, ?string $workTree, array $args): ?string
    {
        $binary = $this->resolveGitBinary();
        if ($binary === null) {
            return null;
        }

        $cmd = [$binary, '--git-dir=' . $gitDir];
        if ($workTree !== null) {
            $cmd[] = '--work-tree=' . $workTree;
        }
        $cmd = array_merge($cmd, $args);

        try {
            $process = new Process($cmd, $workTree, ['LC_ALL' => 'C']);
            $process->setTimeout(10);
            $process->run();
        } catch (ExceptionInterface $e) {
            return null;
        }

        return $process->isSuccessful() ? $process->getOutput() : null;
    }

    private function resolveGitBinary(): ?string
    {
        if ($this->resolvedBinary !== null) {
            return $this->resolvedBinary === '' ? null : $this->resolvedBinary;
        }

        try {
            $process = new Process([$this->gitBinaryConfig, '--version']);
            $process->setTimeout(5);
            $process->run();
            if ($process->isSuccessful()) {
                $this->resolvedBinary = $this->gitBinaryConfig;

                return $this->resolvedBinary;
            }
        } catch (ExceptionInterface $e) {
            // fall through
        }

        $this->resolvedBinary = '';

        return null;
    }
}
