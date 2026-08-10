#!/usr/bin/env bash
# Builds a throwaway git repo fixture for GitLogReaderTest.php, then runs it.
# Usage: tests/build-fixture.sh [fixture-dir]
set -euo pipefail

FIXTURE="${1:-$(mktemp -d)}"
rm -rf "$FIXTURE"
mkdir -p "$FIXTURE/work/pages/01.home" "$FIXTURE/work/pages/02.other" "$FIXTURE/work/config"

cd "$FIXTURE/work"
git init -q -b master
git config user.email "test@example.com"
git config user.name "Test User"

printf -- "---\ntitle: Home\n---\nVersion 1\n" > pages/01.home/default.md
git add pages/01.home/default.md
git commit -q -m "Add home page v1"

printf -- "---\ntitle: Home\n---\nVersion 2\n" > pages/01.home/default.md
git add pages/01.home/default.md
git commit -q -m "Update home page to v2"

echo "secret: true" > config/security-private.php
git add config/security-private.php
git commit -q -m "Add secret config (outside scope_root)"

mkdir -p pages/pages-secret
echo "should not match scope_root 'pages' via prefix" > pages/pages-secret/default.md
git add pages/pages-secret/default.md
git commit -q -m "Add pages-secret sibling (segment-boundary test)"

git mv pages/01.home pages/02.home
git commit -q -m "Reorder: 01.home -> 02.home"
printf -- "---\ntitle: Home\n---\nVersion 3, after reorder\n" > pages/02.home/default.md
git add pages/02.home/default.md
git commit -q -m "Update home page to v3, after reorder"

# Bare repo + separate checked-out worktree, matching the real VPS deploy
# shape: GIT_WORK_TREE/GIT_DIR + `git checkout -f`, no .git in the work tree.
git clone -q --bare . "$FIXTURE/bare.git"
mkdir -p "$FIXTURE/deployed"
git --git-dir="$FIXTURE/bare.git" --work-tree="$FIXTURE/deployed" checkout -f master >/dev/null

# Repo with zero commits, for the headSha()-must-not-throw case.
mkdir -p "$FIXTURE/empty-work"
(cd "$FIXTURE/empty-work" && git init -q -b master && git config user.email "test@example.com" && git config user.name "Test User")

echo "Fixture built at: $FIXTURE"
FIXTURE_DIR="$FIXTURE" php "$(dirname "$0")/GitLogReaderTest.php" "$FIXTURE"
