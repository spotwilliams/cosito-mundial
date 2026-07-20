# cosito-mundial

A single-page FIFA World Cup 2026 fixtures board that auto-updates itself with
live scores and knockout-bracket progress — no server, no database, no API key.

The site is a static HTML page that reads two JSON files. A local scheduled job
pulls fresh results from ESPN's free feed, writes them into those JSON files, and
commits + pushes. GitHub Pages then redeploys automatically, so the published
page always shows current results.

## What it shows

- All 104 fixtures of the 2026 tournament (Canada · Mexico · USA).
- Each match in the **venue's local time** and in **Türkiye time (TRT)**.
- Three views: **By Day**, **By Groups**, **By Knockout**.
- Team search, hide-finished-matches filter, live clocks and countdown.
- Live/finished score badges; live matches highlighted and refreshed every 60s.

## How it works

```
ESPN fifa.world feed
        │
        ▼
routine/run.sh  ──►  fetch-results.php    (scores + bracket advancement)
                     fetch-standings.php  (group winners/runners-up)
        │
        ▼
   scores.json  +  data.json   ──git commit/push──►  GitHub Pages
        │
        ▼
 fifa-world-cup-2026.html  (browser fetches both JSON files, renders)
```

### The page

`fifa-world-cup-2026.html` is fully client-side (Tailwind via CDN). On load it
fetches:

- **`data.json`** — reference data: `venues`, `flags`, `groups`, and the 104
  `matches` (kickoff UTC, city, phase, teams). Source of truth for the schedule;
  edit it here.
- **`scores.json`** — per-match results keyed by match id:
  `{ "<id>": { home, away, status } }` where `status` is `NS` | `LIVE` | `FT`.
  Re-read every 60 seconds so live scores update without a reload.

### The auto-updater (`routine/`)

A local routine that keeps the JSON files current. No LLM in the loop.

- **`fetch-results.php`** — pulls results and bracket from ESPN, writes the JSON.
- **`fetch-standings.php`** — fills `Winner/Runner-up Group X` knockout slots from
  ESPN group standings, but only once ESPN confirms a group's top two advanced.
- **`bracket.php`** — network-free advancement logic (deciding ties, penalties,
  carrying winners/losers to the next round). Used by the fetchers and the tests.
- **`run.sh`** — headless runner (cron/launchd calls this):
  pull → run both PHP scripts → commit + push, only if the JSON changed.

Both scripts validate JSON on read and **only ever add/update** entries — never
delete — so a transient or empty ESPN response can't wipe recorded data. `run.sh`
always returns to a clean `main` before running and rebases before pushing, so a
failed run never commits conflict markers or loses data.

See [`routine/README.md`](routine/README.md) for setup (cron/launchd scheduling,
PATH and git-auth gotchas).

## Run it

Test the updater interactively before scheduling:

```bash
php routine/fetch-results.php --dry-run      # show score/bracket changes
php routine/fetch-standings.php --dry-run    # show group winner/runner-up changes
php routine/fetch-results.php                 # write the JSON
php routine/fetch-standings.php              # write the JSON
bash routine/run.sh                          # full run: update + commit + push
```

Serve the page locally (any static server works):

```bash
php -S localhost:8000        # open http://localhost:8000/fifa-world-cup-2026.html
```

## Tests

Plain-PHP tests (no framework) for the bracket/advancement logic and JSON
integrity live in [`tests/`](tests/):

```bash
php tests/run.php        # exit 0 = all passed, 1 = failure
```
