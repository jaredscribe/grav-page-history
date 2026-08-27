# Grav Page History

A read-only, front-end-visible page history viewer for [Grav](https://getgrav.org),
backed by the git log of the page file itself — no revert, no writes, no
extra database. If your `pages/` directory is a git repo (or lives inside
one), this plugin exposes that history to visitors and editors alike.

## Why

Grav's admin panel has no built-in way to show a page's edit history on the
live site. Wikis like MediaWiki have a "History" tab for exactly this — this
plugin brings the same transparency to a git-backed Grav site: who changed
what, and when, sourced directly from `git log`.

## Features

- **Per-page history route** — `/<route_prefix>/<page-route>` lists the
  commits that touched a page's file, each linking to a diff on your git
  host if `remote_url` resolves one.
- **Site-wide recent changes** — `/<route_prefix>` lists recent commits
  across all pages.
- **Inline widget** — a `{{ page_history(page) }}` Twig function for
  themes that want to embed a "View history" block directly on a page,
  instead of linking out to the dedicated route.
- **No writes, no revert** — this plugin only reads `git log`. Reverting a
  page is a manual git operation for whoever holds write access to the
  repo.
- **Access-aware** — pages that are unpublished, non-routable, or carry an
  `access:` frontmatter key are skipped by default (`respect_page_access`),
  and any page can opt out individually with `history: false`.

## Requirements

- Grav `^2.0`
- PHP `^8.3`
- `pages/` (or whatever `scope_root` you configure) must live inside a git
  working tree the PHP process can read. A bare repo is supported too, via
  explicit `git_dir` / `work_tree` config.

## Installation

Clone into your Grav site's `user/plugins/` directory:

```bash
cd user/plugins
git clone https://github.com/jaredscribe/grav-page-history.git page-history
```

Or install via [GPM](https://learn.getgrav.org/17/plugins/plugin-tutorial#installing-a-plugin)
once published to the Grav package registry.

## Configuration

Copy `page-history.yaml` into your site's `user/config/plugins/` to
override any of these defaults:

```yaml
enabled: true

# Absolute path to the git repo (may be bare). Empty = walk up from the page
# file looking for .git.
git_dir: ''

# What paths inside git_dir are relative to. Empty = derived from git_dir
# for a non-bare repo; must be set explicitly for a bare repo.
work_tree: ''

# Repo-relative root the resolved file path must stay inside of.
scope_root: pages

# Route prefix for the history/recent-changes views.
route_prefix: /history

# Track file renames (git log --follow). Off by default.
follow_renames: false

# Skip history for unpublished/non-routable/access-restricted pages.
respect_page_access: true

# Max commits shown per history view.
pagination_count: 20
```

A single page can opt out regardless of global config:

```yaml
---
title: Some Page
history: false
---
```

## Usage

- Visit `/history` for site-wide recent changes.
- Visit `/history/<page-route>` for a single page's history.
- In a theme template, call `{{ page_history(page) }}` to render the
  inline widget for the current (or a given) page.

## Development

```bash
tests/build-fixture.sh   # builds a throwaway git fixture used by the test suite
```

See `PAGE_HISTORY_PLUGIN_PLAN.md` and `PLAN_REVIEW.md` for the design
rationale and known edge cases (renames, bare repos, page access rules).

## License

[GPL-3.0](LICENSE)
