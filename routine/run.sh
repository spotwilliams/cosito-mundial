#!/usr/bin/env bash
# Headless World Cup results updater — run by cron/launchd.
# Test interactively first:  bash routine/run.sh
set -euo pipefail

REPO="/Users/ricardo/work/spotwilliams/cosito-mundial"

# cron/launchd start with a minimal PATH. Add the dirs where `php`, `git`,
# and ssh live. Find yours with:  echo "$(dirname "$(which php)"):$(dirname "$(which git)")"
export PATH="$HOME/.local/bin:/opt/homebrew/bin:/usr/local/bin:/usr/bin:/bin:/usr/sbin:/sbin:$PATH"

cd "$REPO"

# One timestamped log file per run, under routine/logs/ (gitignored).
mkdir -p routine/logs
LOG="routine/logs/$(date '+%Y-%m-%d_%H%M%S').log"
log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*" >> "$LOG"; }
log "run started"

# Get back to a clean main, no matter what failed last run. A failed rebase
# (or push) can leave the tree mid-rebase or carrying a stale local commit;
# the updater MUST NOT run on a broken tree or it would commit conflict
# markers / lose the merge. So: never swallow a rebase failure — abort it,
# restore the working tree, and skip this run. Nothing is lost: any local
# results commit stays on the branch and the next run reconciles it.
sync_main() {
  if git pull --rebase --autostash origin main >> "$LOG" 2>&1; then
    return 0
  fi
  log "git pull --rebase failed — aborting rebase, leaving tree clean"
  git rebase --abort >> "$LOG" 2>&1 || true   # no-op if no rebase in progress
  return 1
}

if ! sync_main; then
  log "skipping run: could not reach a clean, up-to-date main"
  exit 1
fi

# Fast path: pull results + advance bracket straight from ESPN's free feed (no LLM).
# Both scripts validate JSON on read and only ever add/update entries — they
# never delete a recorded result, so a transient/empty ESPN response can't wipe data.
php routine/fetch-results.php >> "$LOG" 2>&1

# Fill knockout group placeholders (Winner/Runner-up Group X) from ESPN standings,
# but only once ESPN confirms each group's top two advanced. Complements the
# scoreboard-based advancement above; never guesses ahead of ESPN.
php routine/fetch-standings.php >> "$LOG" 2>&1

# Commit & push only if the JSON actually changed.
if [ -n "$(git status --porcelain scores.json data.json)" ]; then
  git add scores.json data.json
  git commit -m "Update World Cup results ($(date '+%Y-%m-%d %H:%M'))" >> "$LOG" 2>&1

  # Re-sync immediately before pushing: the fetch above can take minutes, during
  # which another run/machine may have advanced the remote. Rebasing our fresh
  # commit onto the latest remote avoids a rejected (stale) push. The commit is
  # already saved locally, so a failed rebase/push here loses nothing.
  if ! sync_main; then
    log "rebase before push failed — commit is safe locally, will retry next run"
    exit 1
  fi

  if git push >> "$LOG" 2>&1; then
    log "pushed changes"
  else
    log "push failed — commit is safe locally, will retry next run"
    exit 1
  fi
else
  log "no changes"
fi
