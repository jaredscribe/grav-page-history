# grav-plugin-page-history — implementation plan

A read-only, front-end-visible page history viewer for Grav, in the spirit of
MediaWiki's "View history" tab, backed by the page file's real git log — no
revert, no writes, no dependency on any particular theme or project.

This document is written to be portable: copy it into its own repo
(`grav-plugin-page-history` or similar) and it should need no edits specific
to the site it was first designed for.

> **Read PLAN_REVIEW.md alongside this.** It records a blocking finding against
> §3 (repo discovery fails on bare-repo deploys), a whole missing layer (routes:
> per-page history permalink, site-wide recent changes, compare-two-revisions —
> which every front-end-visible comparable has and this plan does not), and
> corrections to caching, access control, and ref handling. Inline corrections
> below are marked "(Corrected: ...)".

## 1. Plugin, not a theme mod

Ship it as a **plugin**, not a theme override. Reasons:

- It needs PHP-level access to `$page->filePath()` and to shell out to `git`
  — that's plugin-lifecycle territory (`onPagesInitialized`,
  `onTwigInitialized`), not template territory.
- Themes are presentation; this feature is data (git log for a specific
  file) plus a *default* presentation. Grav's own template-cascade already
  lets any theme override a plugin's default template by dropping a file at
  the same relative path (`templates/partials/page-history.html.twig`) in
  the theme — theme paths take precedence over plugin paths in Grav's twig
  loader stack. So "plugin" doesn't mean "theme can't restyle it"; it means
  the plugin ships a sane default and theme authors can override just the
  markup, same pattern used by `sitemap`, `feed`, and other official plugins.
- A plugin can be installed on any theme (Quark2 or otherwise) with zero
  theme edits — which is the explicit "theme-agnostic" requirement.

## 2. Borrowing from Git-Sync / other plugins

Do **not** depend on Git-Sync or bundle any of it — this plugin should work
standalone on any git-tracked Grav install, including ones with no sync
plugin at all (e.g. a site whose pages folder happens to be a git repo
that's deployed by hand). But it's worth reusing patterns, not code, from
`trilbymedia/grav-plugin-git-sync`:

- **Git binary resolution**: don't assume `git` is on PATH. Config override →
  `which git`/`where git` fallback → verify with `git --version` → cache the
  result. (Corrected: Git-Sync does *not* do this — `Helper::getGitBinary()` is
  a bare `$config->get('plugins.git-sync.git.bin', 'git')` with no discovery and
  no caching. This is our own pattern, not a borrowed one. See PLAN_REVIEW.md §3.)
- **Process invocation**: use `Symfony\Component\Process\Process` with
  array-style arguments, never string-interpolated shell commands. Confirmed
  this is already a Grav core dependency (`composer.json: symfony/process
  ^7.0`), so no new dependency is introduced by requiring it. (Corrected:
  Git-Sync uses `exec()` on a string built with `escapeshellarg`, not Process —
  so this is what we do *instead of* Git-Sync's approach. Do borrow its
  `LC_ALL=C` prefix for locale-independent output. See PLAN_REVIEW.md §3.)
- **Explicitly do not borrow**: push/pull/commit/reset logic. This plugin
  is read-only by design — smaller attack surface, single responsibility,
  and it must keep working on installs where nothing is meant to write back
  to a remote.

No other bundled plugin in a typical Grav install (admin, flex-objects,
form, etc.) has directly reusable code for this — checked the `admin`
plugin's `Admin.php` for existing diff/history logic; the only `diff` hit is
an unrelated `DateTime::diff()` call used for backup-age display, not git
diffing.

## 3. Keeping it project- and theme-agnostic

Everything below is driven off Grav's own `Page` object at render time —
nothing is hardcoded to a folder layout, page-naming convention, or
project.

- **File path**: always resolve via `$page->filePath()` — this already
  accounts for whatever routing/folder-numbering convention (`01.home/`,
  modular pages, multilang variants, etc.) the current project uses. The
  plugin never constructs paths itself.
- **Repo root**: an explicit `git_dir` config option (absolute path to the repo,
  which may be **bare**) is the primary mechanism, with walk-up-from-the-file
  discovery as the fallback for dev checkouts. Auto-discovery alone is not
  sufficient: the standard bare-repo deploy (`GIT_WORK_TREE`/`GIT_DIR` +
  `git checkout -f` from a post-receive hook) leaves **no `.git` in the deployed
  work tree at all** — verified on this project's own VPS — so a
  discovery-only plugin silently renders nothing in production, or worse
  latches onto an unrelated nested repo. A separate `work_tree` option says what
  git paths are relative to. See PLAN_REVIEW.md §1.
- **Scope root (security-relevant, not just portability)**: config option
  `scope_root` (default: `user/pages`) that the resolved file path must stay
  inside of. If a project's git repo root is *wider* than the pages folder
  (e.g. the entire Grav core + user tree is one repo, which is a real,
  observed configuration), this plugin must never be able to `git log`
  arbitrary paths outside `scope_root` — reject and no-op instead of
  reading, say, plugin source or credentials that happen to live in the
  same repo. Because `git_dir` may be bare (no work tree to `realpath()`
  against), containment is computed in **repo-relative** terms: realpath the
  page file locally, strip `work_tree`, normalise, then compare against
  `scope_root` with segment-boundary matching (`pages` must not match
  `pages-secret`). Note `scope_root` is repo-relative, so on an install whose
  repo root *is* `user/` the correct default is `pages`, not `user/pages`.
  See PLAN_REVIEW.md §1.
- **No theme assumptions**: three integration points, theme picks whichever
  fits (or none):
  1. A Twig function `page_history(page, options)` a theme template can
     call explicitly.
  2. A **shortcode** `[page-history]` (soft dependency on the already-common
     `shortcode-core` plugin — check it's enabled before registering;
     degrade to "Twig function only" if absent). This is the most
     theme-agnostic option of all, since a content author can drop it
     straight into a page's Markdown body without touching any template.
  3. A default Twig partial (`templates/partials/page-history.html.twig`)
     with minimal, semantic, near-unstyled markup (`<details>` + `<ul>` or
     a small `<table>`) plus one optional CSS file gated behind a config
     flag (`include_css: true` by default, easy to turn off for themes that
     want to restyle from scratch).
  Nothing auto-injects itself into arbitrary theme layouts — that's the one
  approach that reliably breaks theme-agnosticism (fragile output-buffer
  hacking to splice HTML into someone else's template), so it's deliberately
  excluded.

## 4. Scope for v1 (read-only, matches the "no revert" requirement)

- `git log --follow -- <path>` for the resolved file: short hash, author
  name (togglable off for privacy via config), relative + absolute date,
  commit subject.
- Expand-to-view diff per commit: v1 renders the raw unified diff
  (`git show --format= -- <path>`) in a `<pre>` block — no syntax-highlighted
  diff rendering in v1, to avoid adding a diff-rendering dependency
  (`sebastian/diff` or similar isn't part of Grav's production vendor tree;
  defer pretty rendering to v2 if wanted).
- No write operations anywhere in the plugin. No revert button, no edit
  action — this is intentionally the transparency-only half of the earlier
  "visible but not revertible" requirement.
- Graceful no-op (render nothing, or a configurable "no history available"
  string) when: git isn't installed, the resolved path isn't inside a repo,
  the path is outside `scope_root`, or the file has no commits yet.
- Cache the git-log/diff output in Grav's cache layer, keyed by page route +
  file mtime, so shelling out doesn't happen on every page view.

## 5. Security notes

- Every git invocation is a read-only subcommand (`log`, `show`) with the
  file path passed as a discrete Process argument, never interpolated into
  a shell string — no command-injection surface even though the path
  ultimately derives from routing.
- `scope_root` containment check (above) is the main defense against this
  plugin becoming a way to read git history for files outside the content
  tree, on installs where the repo boundary is wider than `user/pages`.
- Consider a config-level path/pattern exclude list, in case a project ever
  wants specific pages opted out of public history display even though the
  underlying files are tracked.

## 6. File layout

```
page-history/
  page-history.yaml              # default config
  blueprints.yaml                 # admin config UI, optional — degrade if Admin absent
  page-history.php                # plugin class: event subscriptions, twig fn + shortcode registration
  classes/
    GitLogReader.php              # pure PHP: repo-root discovery, scope check, log()/show() via Process
  templates/
    partials/
      page-history.html.twig      # default, override-friendly markup
  assets/
    page-history.css              # optional, config-gated
  languages/
    en.yaml
```

`GitLogReader` should be written as a plain PHP class taking a repo root and
a relative path in its constructor/methods, with no Grav-object
dependencies — makes it unit-testable in isolation and reusable if this
logic is ever wanted outside Grav.

## 7. Build order

1. Scaffold plugin skeleton, no-op Twig function returning a placeholder.
2. `GitLogReader`: repo-root discovery, scope-root containment guard,
   `log()` and `show()` via `Symfony\Process`, unit tests against a throwaway
   git repo fixture (not against any specific project).
3. Wire into Twig function + default partial; verify on a plain Grav
   skeleton install (not a themed/production site) to keep development
   itself theme-agnostic.
4. Add shortcode registration behind the shortcode-core soft-dependency
   check.
5. Add diff expand/collapse (raw unified diff, v1).
6. Add config blueprint + Admin integration, degrading cleanly when Admin
   isn't installed.
7. Add caching.
8. Write README + install instructions, publish as its own repo, optionally
   submit to the Grav plugin directory.
