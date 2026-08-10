## Page history / revert from the admin panel

  I checked this install directly (grav/user/plugins/: admin, api, email,
  flex-objects, form, github-markdown-alerts, login, preview-panel,
  shortcode-core, sitemap, wikilinks) and grepped the Admin plugin and
  flex-objects source for any history/diff/revert code — there is none. Stock 
  Grav Admin has no built-in version history or revert UI. This is a common
  misconception (people assume it works like WordPress revisions) — it doesn't,
  even though your pages already live in a real git repo.

  To get it you need a plugin. None of these are installed yet; options, in
  order of fit:

  - Revisions Pro (official, paid) — History button in the toolbar, side-by-side
  diff, one-click restore, per-user/timestamp audit trail, covers pages and
  config. Most polished option.
  - admin-addon-revisions (free, community) — adds a Revisions section to Admin
  with diff + revert, but stores its own snapshots in a .revs/ folder per page
  rather than using your real git commits. Small project (94 commits, 11 stars);
  verify Grav 2.0 compatibility before installing.
  - Grav History Plugin (twelvetone) — currently beta/testers-only, and revert
  is explicitly listed as not yet supported (roadmap item). Also spins up its
  own separate embedded git repo rather than reading your actual history. Not
  usable today.
  - Git-Sync (official — the one named in your CLAUDE.md) — auto-commits Admin
  edits and can push/pull/reset the whole pages folder against a remote. It's
  the right plugin for the deploy workflow you're building, but it gives no 
  per-page diff/revert UI — it's sync infrastructure, not a history browser.

  Practical path: Git-Sync for the deploy/history-commit workflow (matches what
  you're already building), plus admin-addon-revisions for a free in-admin
  revert button, or pay for Revisions Pro if you want one polished plugin.

## Grav vs. Gollum
  Gollum is a git wiki by design: every save is a commit, and a front-end
  "History" page (rendered straight from git log/git diff) plus revert is core
  behavior — no plugin, visible to anyone. Grav is a general-purpose flat-file
  CMS; git-tracking your pages (which you've already set up) doesn't make Grav
  interpret that history for editorial purposes. There's no admin history UI
  without a plugin (see above), and no front-end history view exists at all —
  you'd have to hand-build a Twig template that shells to git log -- 
  path/to/page.md, essentially reimplementing a slice of what Gollum gives for
  free. Grav's tradeoff is a real theme/plugin ecosystem (Quark2, forms, etc.)
  that Gollum doesn't have.

## Notes

 - Admin revert / MediaWiki-style front-end history — covered above: needs a
  plugin for admin revert; front-end history has no existing plugin and would be
  custom work.

## Other CMS besides Gollum 
— DokuWiki (flat-file like Grav, but page
  history/diff/revert and a public history view are core, zero plugins) and

— Wiki.js (can use git as its actual storage backend, built-in 
  history/diff/revert UI, Markdown) are the closest matches to what you're
  asking for. Both are more wiki-native than Grav for this specific transparency
  requirement, at the cost of the general page-builder flexibility you've
  already invested in with Grav/Quark2.

