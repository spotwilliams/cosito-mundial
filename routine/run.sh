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
echo "[$(date '+%Y-%m-%d %H:%M:%S')] run started" >> "$LOG"

# Refresh local main so we don't push on top of a stale tree.
git pull --rebase --autostash origin main >> "$LOG" 2>&1 || true

# Fast path: pull results + advance bracket straight from ESPN's free feed (no LLM).
php routine/fetch-results.php >> "$LOG" 2>&1

# Commit & push only if the JSON actually changed.
if [ -n "$(git status --porcelain scores.json data.json)" ]; then
  git add scores.json data.json
  git commit -m "Update World Cup results ($(date '+%Y-%m-%d %H:%M'))" >> "$LOG" 2>&1
  git push >> "$LOG" 2>&1
  echo "[$(date '+%Y-%m-%d %H:%M:%S')] pushed changes" >> "$LOG"
else
  echo "[$(date '+%Y-%m-%d %H:%M:%S')] no changes" >> "$LOG"
fi
