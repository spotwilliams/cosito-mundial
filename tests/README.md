# Tests

Plain-PHP tests — **no framework**, no Composer, no `vendor/`. The project has
no build step, and these checks only need assertions over JSON and the
network-free bracket logic, so a ~60-line runner is enough. (If this ever grows
real complexity, swapping in PHPUnit is straightforward — the cases are already
small pure functions.)

## Run

```bash
php tests/run.php
```

Exit code `0` = all passed, `1` = at least one failure (CI-friendly).

## What's covered

- **`test_bracket.php`** — unit tests for `routine/bracket.php`: deciding a tie,
  penalty shootouts, the "never guess a winner on an undecided draw" rule, and
  the fixed-point cascade that carries winners/losers to the next round.
- **`test_full_tournament.php`** — drives the **real `data.json`** bracket from
  the round of 32 through to the **final and third-place play-off** with
  synthesised results (some forced to penalties), asserting every
  `Winner/Loser Match N` slot resolves to exactly the team the score dictates.
- **`test_integrity.php`** — structural checks on the live `data.json` /
  `scores.json`: unique ids, references that point at real earlier matches,
  valid scores, penalties only on level full-time results, flags/venues for
  every team and city, and that the committed bracket already matches the
  committed scores.

## Why no `fetch-*.php` network test

The fetchers' advancement logic lives in `routine/bracket.php` and is fully
tested here without the network. The thin ESPN-fetch glue (HTTP + JSON shape)
is intentionally not unit-tested — it needs the live feed; run
`php routine/fetch-results.php --dry-run` to exercise it against ESPN.
