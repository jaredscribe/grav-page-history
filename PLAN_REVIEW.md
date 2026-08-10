# Review of PAGE_HISTORY_PLUGIN_PLAN.md

Reviewed against: this install (Grav 2.0.15), the deployed VPS layout, the three
Grav revision plugins named in `product_research.md`, Git-Sync's actual source,
and Gollum's route design.

The plan's core judgements hold up — plugin not theme mod, read-only, its own
repo, `scope_root` containment, argv-style process invocation. What follows is
one finding that invalidates §3 as written, one whole layer the plan is missing,
two factual errors, and a set of smaller corrections.

---

## 1. Blocking: repo-root auto-discovery finds nothing in production

§3 says "auto-discover by walking up from the resolved file path looking for a
`.git` directory." Verified on the VPS: there is no `.git` anywhere in the
deployed content tree. The post-receive hook does

```sh
GIT_WORK_TREE="$HOME/oregontrailblazers.org"
GIT_DIR="$HOME/repositories/oregontrailblazers.org.git"
export GIT_WORK_TREE GIT_DIR
git checkout -f master
```

which is the standard bare-repo deploy: the work tree is a plain checkout with
its git metadata living somewhere else entirely. `find ~/oregontrailblazers.org
-maxdepth 6 -name .git` returns only `v01/grav/.git` — the getgrav/grav clone,
whose history is Grav's own releases, not this site's content.

So walk-up discovery either finds nothing (→ §4's "graceful no-op", i.e. the
feature silently does not exist on the only install that matters) or, worse,
finds `v01/grav/.git` and renders Grav's release history under a content page.

**Consequences for the design:**

- A `git_dir` config option (explicit absolute path to the bare repo) is
  **mandatory**, not a nicety. Walk-up discovery becomes the *fallback* for dev
  checkouts, not the primary mechanism. Read-only `log`/`show` work fine against
  a bare repo — no work tree needed.
- With a bare `git_dir` there is no work tree to `realpath()` against, so §3's
  "guard with realpath-containment check, not string prefix matching" is
  unimplementable as stated. Containment must be computed in **repo-relative**
  terms: `realpath($page->filePath())` → strip the configured *work-tree root*
  (a separate config value from `git_dir`) → normalise → compare against
  `scope_root` with segment-boundary matching (`user/pages` must not match
  `user/pages-secret`). Realpath still does the symlink-resolution work on the
  local side; the prefix comparison happens after, on the repo-relative path.
- Config shape, then:

  ```yaml
  git_dir: ''      # bare repo path; empty = walk up from the page file
  work_tree: ''    # what git paths are relative to; empty = dirname(git_dir)/..
  scope_root: 'pages'   # repo-relative, NOT filesystem-absolute
  ```

  Note `scope_root: pages` rather than the plan's `user/pages` — the deploy repo
  here is `user/` itself (`grav/user/.git`, confirmed locally), so repo-relative
  paths start at `pages/`, `config/`, `themes/`. The plan's default assumes the
  repo root is the Grav root. Make the default configurable and document both.

**Unsettled input — this is the one decision that is yours, not mine.** The hook
currently checks out to `$HOME/oregontrailblazers.org` (the docroot, which also
holds the coming-soon `index.html`, `v01/`, `v02/`), while CLAUDE.md states the
target as `~/oregontrailblazers.org/v01/grav/user`. The bare repo also has no
HEAD yet — nothing has ever been pushed. Until that layout is settled the
correct `git_dir`/`work_tree` defaults can't be pinned. Everything above is
written assuming the bare repo eventually checks out to a tree whose root
corresponds to `grav/user/`.

**The other half of the production problem: this plugin's own code never
reaches the VPS either.** `user/.gitignore` is `/*` with un-ignores only for
`/pages`, `/config`, `/themes` — `plugins/` is untracked in the deploy repo, so
`git push dreamhost master` will never put `page-history/` on the server no
matter how `git_dir` is configured. The established convention here is a
dedicated bare repo per plugin plus a manual clone/pull on the VPS —
`~/repositories/` already holds `grav_plugin_wikilinks.git` and
`grav_plugin_preview_panel.git`, and `preview-panel/CLAUDE.md` documents the
same gap in its own words. Plan for that: own repo, own bare remote, manual
`git pull` into `v01/grav/user/plugins/page-history/` on deploy. §1's config
work fixes the plugin's *view* of the repo; this fixes the plugin's *presence*
on the box, and both are needed before the feature exists in production.

Related: the local `grav/user/` repo has **zero commits** (everything is staged,
nothing committed), and the bare repo has no HEAD. There is no real history to
develop against today, which makes §7 step 3's throwaway-fixture verification
the only available path rather than merely the theme-agnostic-by-choice one.

## 2. Missing layer: there are no routes

Every product in `product_research.md` — and Gollum, the stated model — exposes
history as **addressable URLs**. The plan's three integration points (Twig
function, shortcode, partial) are all *inline on the page being viewed*. That
gives no permalink to a revision, no diff between two arbitrary revisions, and
no site-wide changes view.

What the comparables do:

| Product | Routes |
|---|---|
| Gollum | `/history/*`, `/history/<path>/<sha>`, `/compare/<path>/<v1>...<v2>`, `/(.+?)/([0-9a-f]{40})` (page at revision), `/latest_changes` with pagination |
| twelvetone history | `/admin/history/git-revisions?target=<slug>`, `/git-revisions-list`, `/git-revisions-last`; API `log()`, `diff($rev1,$rev2)` |
| MediaWiki | `?action=history`, `oldid=`, `diff=` with `cur | prev` links, Special:RecentChanges + Atom feed |
| Revisions Pro | Admin-panel sliding panel only — no front-end surface at all |
| admin-addon-revisions | Admin sidebar section only |

The two front-end-visible ones (Gollum, MediaWiki) are exactly the two that
converged on routes. Recommended additions to v1 scope, in value order:

1. **Per-page history route.** Gollum's shape adapted to Grav:
   `/<page-route>/history`, or a configurable prefix (`/history/<page-route>`)
   to avoid colliding with a real child page named `history`. Prefer the prefix
   form — it's collision-proof and matches Gollum.
2. **Site-wide recent changes** (`/history` with no path, or a configured
   route). This is nearly free — one `git log` over `scope_root` — and for a
   transparency site it is the single highest-value thing the plan is missing:
   it's the page that shows *the site as a whole* has nothing hidden, which
   inline per-page widgets never demonstrate.
3. **Diff between two arbitrary revisions** (`compare/<sha1>..<sha2>`), with
   MediaWiki's `cur | prev` links as the common case. §4's "expand-to-view diff
   per commit" only ever gives you `prev`.
4. **Atom/RSS feed of changes** — MediaWiki offers it, it's a trivial second
   Twig template over the same data, and it's the natural way for a watchdog to
   subscribe to policy edits.

**Implementation pattern is already in-tree.** `sitemap/sitemap.php` is the
local precedent: gate in `onPluginsInitialized` on `$uri->route()` matching the
configured route, then in `onPageInitialized` build a virtual page and pick a
template —

```php
$page = new Page;
$page->init(new \SplFileInfo(__DIR__ . '/pages/sitemap.md'));
unset($this->grav['page']);
$this->grav['page'] = $page;
$twig->template = "sitemap.$extension.twig";
```

(`sitemap.php:188-217`). Same mechanism gives history routes, and the
`$extension` trick gives the Atom feed for free off the same route.

One caveat on copying it: sitemap gates on `$uri->route() === $route` — exact
equality against a single configured route. `/history/<page-route>` is a prefix
with a variable tail, so the *gate* has to be prefix matching plus resolving the
remainder back to a real page, with a defined behaviour when it doesn't resolve
(404 is right — don't render an empty history for a route that never existed).
The virtual-page mechanism transfers unchanged; the route check does not.
`onPageNotFound` is the other viable hook for variable-tail routes and may be
cleaner than `onPageInitialized` here — decide during step 2, don't assume
sitemap's shape is the only option.

Keep the Twig function and shortcode from §3 — they're the right way to put a
"View history" link *on* a page. They just shouldn't be the whole surface.

Two route-layer details worth writing into the plan now:

- **`noindex` on history and diff routes.** Otherwise every revision of every
  page becomes a crawlable near-duplicate of the live page. MediaWiki
  `noindex`es `action=history` and old revisions for exactly this reason.
- **Old-revision rendering.** If you ever render a historical revision as HTML
  rather than as raw diff text, it must be visibly banner-marked as historical
  and must not be mistakable for the live page. v1 staying raw-`<pre>` (§4)
  sidesteps this entirely — good call, keep it, and note *why* it's a call.

## 3. Factual error: Git-Sync does not do what §2 says

§2 credits Git-Sync with two patterns. Both are wrong on inspection of
`trilbymedia/grav-plugin-git-sync`:

- **"use `Symfony\Component\Process\Process` with array-style arguments, never
  string-interpolated shell commands."** Git-Sync does the opposite.
  `GitSync::execute()` string-builds the command and calls `exec()`:

  ```php
  $command = $bin . ' -C ' . escapeshellarg($this->repositoryPath) . ' ' . $command;
  $command .= ' 2>&1';
  if (DIRECTORY_SEPARATOR === '/') { $command = 'LC_ALL=C ' . $command; }
  exec($command, $output, $returnValue);
  ```

  It's `escapeshellarg` discipline, not Process.
- **"auto-detects the `git` executable ... config override → `which git`/`where
  git` fallback → verify with `git --version` → cache the result."**
  `Helper::getGitBinary()` is a bare config lookup —
  `$config->get('plugins.git-sync.git.bin', 'git')`. No `which`, no caching.
  `Helper::isGitInstalled()` does run `exec($bin . ' --version')`, but the
  result isn't cached and the binary isn't discovered.

The *recommendations* are right — argv-style `Process` is strictly better than
`escapeshellarg`, and `symfony/process ^7.0` is confirmed present in this
install's `vendor/`, so no new dependency. But reattribute them: these are what
this plugin does **instead of** Git-Sync's approach, not patterns borrowed from
it. Leaving the claim as-is sends whoever implements from the plan looking for
code that doesn't exist.

Two things Git-Sync *is* genuinely worth copying:

- `LC_ALL=C` on the git invocation — locale-independent output is the
  difference between a parser that works and one that breaks on a differently
  configured server. Note this can't be copied as a *prefix* string once you're
  on argv-style `Process`; it's the env argument:
  `new Process([$git, ...], null, ['LC_ALL' => 'C'])`.
- `Helper::preventReadablePassword()` on anything logged. Not applicable to
  read-only ops, but the *habit* — scrub before logging — is.

## 4. Access control: `scope_root` guards paths, nothing guards page state

§5 treats path containment as the security model. It isn't sufficient. A
front-end history view over `git show` will happily surface:

- content of pages that are currently **unpublished** or **draft**;
- pages with `access:` frontmatter (login-plugin protected);
- **frontmatter itself** in every diff — `access:` rules, `published: false`,
  taxonomy, private notes, anything an editor put in a header and assumed
  wasn't rendered;
- content that was **deliberately removed**. On a political-advocacy site that's
  largely the point — but it should be an explicit decision per page, not an
  accident of the plugin's defaults.

Sitemap's own ignore logic is the canonical in-tree guard to copy
(`sitemap.php:299-307`):

```php
$protected_page = isset($header->access);
if ($page->routable() && $page->published() && !$config_ignored && !$page_ignored) { … }
```

Recommended:

- `respect_page_access: true` by default — no history for pages that are
  unpublished, non-routable, or carry `access:`.
- **Frontmatter opt-out**, `history: false` (mirroring sitemap's
  `sitemap: ignore: true`), in addition to §5's config-level exclude list. A
  page-level switch is what an editor can actually reach, and it's the
  MediaWiki-ish affordance.
- Consider a `strip_frontmatter` option for the diff view — show only the
  Markdown body's changes. Default off (transparency), but a site that puts
  anything sensitive in headers needs the lever.

## 5. Cache key: mtime is wrong in both directions

§4 proposes keying on "page route + file mtime."

- A deploy `git checkout -f` rewrites mtimes on files whose content did not
  change → cache misses on every deploy, for every page.
- History for a path can change without the file changing (amend, rebase,
  filter-repo, or a merge that brings in commits touching the path) → stale
  cache showing a history that no longer matches the repo.
- The **site-wide recent-changes** view from §2 above can't be keyed off any
  single page's mtime at all.

Key on **`HEAD` sha + repo-relative path** instead. `git rev-parse HEAD` is one
cheap subprocess per request, memoised per request, and it's exactly correct: if
HEAD hasn't moved, no history anywhere has changed. Combine with a short TTL as
a belt-and-braces measure.

One edge case this creates, and it is today's actual state of both repos:
`git rev-parse HEAD` **fails on a repo with no commits** (`fatal: Not a valid
object name HEAD` — that's the literal output from the bare repo on the VPS
right now). Treat a failed `rev-parse` as "no history available" per §4's
graceful no-op, not as an exception.

Related gotcha worth a line in the plan: **shortcode output is baked into
Grav's cached page HTML.** For per-page history that's benign (a commit touching
the page changes the page, which busts the page cache). For a shortcode that
renders *site-wide* recent changes on, say, the homepage, it is not — the
homepage cache won't invalidate when some other page is edited. Another argument
for recent-changes being a route, not a shortcode.

## 6. Smaller corrections

- **Revision identifiers must be validated before reaching argv.** Copy
  Gollum's route-level constraint — it embeds `([0-9a-f]{40})` directly in the
  route regex, so a malformed ref never reaches git at all. Validate
  `^[0-9a-f]{7,40}$` and reject everything else. Argv-style `Process` removes
  *shell* injection but not **option injection**: a path or ref beginning with
  `-` is still read by git as a flag. Always pass `--` before pathspecs, and
  prefix refs with the `<rev>` disambiguator or validate as above.
- **Author email, not just name.** §4 makes the author *name* togglable. Git's
  default log formats include the email; the plugin must use an explicit
  `--format` that never emits `%ae`/`%aE` unless deliberately enabled, and the
  default should be name-only or even initials. Committer identity is a
  separate field from author identity — decide which one is displayed.
- **`--follow` is riskier here than the plan assumes.** Every content file in a
  Grav tree is named `default.md`, and Grav's numeric-prefix ordering convention
  means reordering navigation (`01.home` → `02.home`) renames page paths
  wholesale. Rename detection is similarity-based across a tree of same-named,
  similar-content files. Following a folder rename is desirable; following into
  the *wrong* `default.md` is not. Not asserting it's broken — untested — but:
  make `--follow` config-gated with an off switch, and make "reorder the folders
  in the fixture repo and check the log doesn't jump pages" an explicit test
  case in §7 step 2.
- **Modular pages.** `$page->filePath()` on a modular page returns the container
  file; the visible content lives in child `_partial/` folders. The homepage of
  this site is a candidate. Decide explicitly: history for the container only
  (v1, simplest, honest if labelled) or aggregated across children.
- **Multi-language.** `default.en.md` vs `default.md` are separate files with
  separate histories. `filePath()` resolves this correctly per active language —
  worth one sentence saying so, since it's a place readers will suspect a bug.
- **Size delta per revision.** MediaWiki's `+1,234 / −56` next to each entry is
  the single most-read piece of information in a history list, and it's
  currently absent. Note the units differ: MediaWiki shows a *byte* delta of the
  page, while `--numstat`/`--shortstat` give added/deleted **lines**. Lines are
  arguably the better signal for prose diffs — just label them as lines. If you
  want true bytes it's `git cat-file -s <sha>:<path>`, one extra call per row.
- **Pagination.** Gollum paginates (`pagination_count`, default 10). A page with
  hundreds of commits will otherwise render one enormous list, and an attacker
  can ask for all of it repeatedly. Combine with `--max-count`.
- **File layout (§6)** should add `README.md`, `CHANGELOG.md`, `LICENSE`, and
  `composer.json` — required if this is ever submitted to the Grav plugin
  directory, per §7 step 8. Compare `sitemap/`, which also ships `hebe.json`.
  Note also that Grav accepts either a root `languages.yaml` or
  `languages/en.yaml`; wikilinks and sitemap both use the flat form.

## 7. Verified-correct claims (leave as-is)

- **§1's Twig loader ordering.** Confirmed in
  `system/src/Grav/Common/Twig/Twig.php:145-153`: `theme://templates` is merged
  into `$twig_paths` *before* `onTwigTemplatePaths` fires, and core templates
  come last. Theme templates do take precedence over plugin ones. The
  plugin-not-theme-mod argument stands on verified ground.
- **§2's "no reusable history/diff code in the bundled plugins."** Confirmed
  independently; `admin`'s only `diff` is `DateTime::diff()`.
- **§4's "no diff-rendering dependency available."** Confirmed — no
  `sebastian/diff` in this install's `vendor/`. Raw unified diff in a `<pre>` is
  the right v1.
- **Read-only by design.** Reinforced by the field: Revisions Pro is paid and
  admin-only; admin-addon-revisions snapshots into `.revs/` folders rather than
  reading real git; twelvetone spins up a *second*, embedded git repo and still
  hasn't shipped restore. A read-only reader over the repo you already have is
  a genuinely underserved niche, not a redundant one.

## 8. Build-order changes

Fold into §7:

- Step 2 gains the `git_dir` / `work_tree` config path and repo-relative
  containment, and the folder-reorder `--follow` fixture case.
- New step between 5 and 6: the route layer (per-page history route, site-wide
  recent changes, compare-two-revisions), built on sitemap's virtual-page
  pattern.
- Step 7 (caching) keys on HEAD sha, not mtime.
- New final step: `noindex` headers on history/diff routes.
