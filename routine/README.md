# World Cup results auto-updater

A local routine that updates `scores.json` and advances knockout teams in
`data.json` from ESPN's free `fifa.world` feed, then commits and pushes
(GitHub Pages redeploys automatically). No API key, no LLM in the loop.

## Files
- `fetch-results.php` — pulls results + bracket from ESPN and writes the JSON.
- `run.sh` — headless runner (cron/launchd calls this): pull → php → commit → push.
- `update-prompt.md` — optional Claude Code fallback for odd cases; not used by `run.sh`.
- `logs/` — one timestamped log per run (gitignored).

## 1. Test it interactively first
```bash
php routine/fetch-results.php --dry-run   # show changes, write nothing
php routine/fetch-results.php             # actually update the JSON
bash routine/run.sh                       # full run: update + commit + push
git log -1 --stat                         # confirm it committed/pushed sensibly
```
Run these once before scheduling so you can watch what happens and confirm
the ESPN team names all map (any unmapped team simply won't appear in
`--dry-run` output — add it to `$ALIASES` in `fetch-results.php`).

## 2a. Schedule with cron (simplest)
```bash
crontab -e
# add — runs daily at 09:00:
0 9 * * * /bin/bash /Users/ricardo/work/spotwilliams/cosito-mundial/routine/run.sh
```

## 2b. Or launchd (preferred on macOS; survives reboots, better env)
Create `~/Library/LaunchAgents/com.spotwilliams.worldcup.plist`:
```xml
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN"
  "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0"><dict>
  <key>Label</key><string>com.spotwilliams.worldcup</string>
  <key>ProgramArguments</key>
  <array>
    <string>/bin/bash</string>
    <string>/Users/ricardo/work/spotwilliams/cosito-mundial/routine/run.sh</string>
  </array>
  <key>StartCalendarInterval</key>
  <dict><key>Hour</key><integer>9</integer><key>Minute</key><integer>0</integer></dict>
  <key>StandardErrorPath</key>
  <string>/Users/ricardo/work/spotwilliams/cosito-mundial/routine/launchd.err</string>
</dict></plist>
```
Then: `launchctl load ~/Library/LaunchAgents/com.spotwilliams.worldcup.plist`

## Gotchas
- **PATH:** cron/launchd start with a bare PATH. `run.sh` sets a sensible one;
  if `php` or `git` isn't found, run `which php git` and add their dirs to the
  PATH line in `run.sh`.
- **git push auth:** cron has no `ssh-agent`. If your SSH key has a passphrase,
  add to `~/.ssh/config`:
  ```
  Host github.com
    UseKeychain yes
    AddKeysToKeychain yes
  ```
  (run one manual `git push` first so macOS stores the passphrase), or use an
  HTTPS remote with a credential helper. launchd generally handles this better
  than cron.
- The machine must be awake at the scheduled time for the job to fire.
