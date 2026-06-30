<?php
/**
 * fetch-results.php — update scores.json (and advance knockout teams in data.json)
 * from ESPN's free, no-key World Cup feed.
 *
 *   league code: fifa.world
 *   endpoint:    https://site.api.espn.com/apis/site/v2/sports/soccer/fifa.world/scoreboard?dates=YYYYMMDD
 *
 * Join key: exact UTC kickoff timestamp (data.json `utc` == ESPN event date),
 * with a team-name alias map to orient home/away and a date+venue fallback.
 *
 * Usage:   php routine/fetch-results.php            # apply changes
 *          php routine/fetch-results.php --dry-run  # show what would change, write nothing
 *
 * Exit codes: 0 ok (changed or not), 1 hard error (e.g. could not read data files).
 */

date_default_timezone_set('UTC');

require_once __DIR__ . '/bracket.php';   // kc_propagate_bracket(), network-free

$ROOT      = dirname(__DIR__);
$DATA_FILE = $ROOT . '/data.json';
$SCORES_FILE = $ROOT . '/scores.json';
$DRY = in_array('--dry-run', $argv, true);

// Tournament window (UTC). We only fetch up to "tomorrow" to stay small.
$WINDOW_START = '2026-06-11';
$WINDOW_END   = '2026-07-19';

/* ------------------------------------------------------------------ helpers */

function fail($msg) { fwrite(STDERR, "ERROR: $msg\n"); exit(1); }

function read_json($path, $allowMissing = false) {
    if (!file_exists($path)) {
        if ($allowMissing) return [];
        fail("missing file: $path");
    }
    $raw = file_get_contents($path);
    $j = json_decode($raw, true);
    if ($j === null && trim($raw) !== '') fail("invalid JSON in $path");
    return $j ?: [];
}

/** Stable, pretty JSON write — only touches disk if content actually changed. */
function write_json_if_changed($path, $data, $dry) {
    $new = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    $old = file_exists($path) ? file_get_contents($path) : '';
    if ($new === $old) return false;
    if (!$dry) file_put_contents($path, $new);
    return true;
}

/** Normalise a team name for comparison: lowercase, strip accents/punct, & -> and. */
function norm($s) {
    $s = mb_strtolower(trim($s), 'UTF-8');
    $s = str_replace('&', ' and ', $s);
    // strip accents
    $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
    $s = strtolower($s);
    $s = preg_replace('/[^a-z0-9]+/', ' ', $s); // drop apostrophes, hyphens, etc.
    return trim(preg_replace('/\s+/', ' ', $s));
}

// ESPN/alt spellings (normalised) -> our canonical name in data.json
$ALIASES = [
    'united states'   => 'USA',
    'usa'             => 'USA',
    'turkey'          => 'Türkiye',
    'turkiye'         => 'Türkiye',
    'czech republic'  => 'Czechia',
    'czechia'         => 'Czechia',
    'cote d ivoire'   => 'Ivory Coast',
    'ivory coast'     => 'Ivory Coast',
    'congo dr'        => 'DR Congo',
    'dr congo'        => 'DR Congo',
    'cabo verde'      => 'Cape Verde',
    'cape verde'      => 'Cape Verde',
    'korea republic'  => 'South Korea',
    'south korea'     => 'South Korea',
    'ir iran'         => 'Iran',
    'iran'            => 'Iran',
    'curacao'         => 'Curaçao',
    'bosnia and herzegovina' => 'Bosnia and Herzegovina',
    'bosnia herzegovina'     => 'Bosnia and Herzegovina',
];

/** Resolve an ESPN team name to our canonical name, or null if it's a placeholder/TBD. */
function canon_team($espnName, $ourNormIndex, $aliases) {
    if ($espnName === '' ) return null;
    $low = mb_strtolower($espnName, 'UTF-8');
    if (strpos($low, 'tbd') !== false || strpos($low, 'winner') !== false ||
        strpos($low, 'runner') !== false || strpos($low, 'group') !== false) {
        return null; // ESPN still showing a placeholder
    }
    $n = norm($espnName);
    if (isset($aliases[$n])) return $aliases[$n];
    if (isset($ourNormIndex[$n])) return $ourNormIndex[$n];
    return null; // unknown -> don't guess
}

function is_placeholder($name) {
    return (bool) preg_match('/(Winner|Runner-up|Group|Place|Match|Loser|TBD)/i', $name);
}

function http_get_json($url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (worldcup-updater; +https://github.com/spotwilliams/cosito-mundial)',
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    // curl_close() is a no-op since PHP 8.0 and deprecated in 8.5 — the handle
    // is freed automatically when $ch goes out of scope.
    if ($body === false || $code >= 400) {
        fwrite(STDERR, "WARN: fetch failed ($code) $err for $url\n");
        return null;
    }
    $j = json_decode($body, true);
    if (!is_array($j)) { fwrite(STDERR, "WARN: bad JSON from $url\n"); return null; }
    return $j;
}

/* --------------------------------------------------------------- load data */

$data   = read_json($DATA_FILE);
$scores = read_json($SCORES_FILE, true);
if (empty($data['matches'])) fail("no 'matches' array in data.json");

// index: our canonical team name (normalised) -> name
$ourNormIndex = [];
foreach ($data['matches'] as $m) {
    foreach (['home', 'away'] as $side) {
        if (!is_placeholder($m[$side])) $ourNormIndex[norm($m[$side])] = $m[$side];
    }
}

// index our matches by exact UTC timestamp for the join.
// NOTE: final group-round matches kick off simultaneously, so one timestamp
// maps to MANY matches — keep a list and disambiguate by team name below.
$byTs = [];
foreach ($data['matches'] as $i => $m) {
    $ts = strtotime($m['utc']);
    if ($ts !== false) $byTs[$ts][] = $i; // list of indices into $data['matches']
}

/* ----------------------------------------------------------- fetch & merge */

$today = new DateTime('now');
$end   = new DateTime(min($WINDOW_END, $today->format('Y-m-d')));
$end->modify('+1 day'); // include matches kicking off later today/tomorrow (UTC)
$cur   = new DateTime($WINDOW_START);

$scoreChanges = [];   // id => "H-A FT"
$advanceChanges = []; // id => "X vs Y"
$fetchedDays = 0; $failDays = 0;

while ($cur <= $end) {
    $ymd = $cur->format('Ymd');
    $cur->modify('+1 day');
    $url = "https://site.api.espn.com/apis/site/v2/sports/soccer/fifa.world/scoreboard?dates=$ymd";
    $j = http_get_json($url);
    if ($j === null) { $failDays++; continue; }
    $fetchedDays++;
    if (empty($j['events'])) continue;

    foreach ($j['events'] as $ev) {
        if (empty($ev['competitions'][0])) continue;
        $comp = $ev['competitions'][0];
        $evTs = strtotime($ev['date']);

        // ---- pull ESPN competitors (needed to disambiguate simultaneous matches) ----
        $cs = $comp['competitors'];
        $eh = null; $ea = null;
        foreach ($cs as $c) {
            if (($c['homeAway'] ?? '') === 'home') $eh = $c;
            elseif (($c['homeAway'] ?? '') === 'away') $ea = $c;
        }
        if (!$eh || !$ea) continue;

        $ehName = canon_team($eh['team']['displayName'] ?? '', $ourNormIndex, $ALIASES);
        $eaName = canon_team($ea['team']['displayName'] ?? '', $ourNormIndex, $ALIASES);

        // ---- find our match ----
        // Many matches can share one kickoff timestamp (final group round), so
        // pick the candidate whose team pair matches this ESPN event. With one
        // candidate, take it. Else fall back to same-day + venue.
        $idx = null;
        $candidates = $byTs[$evTs] ?? [];
        if (count($candidates) === 1) {
            $idx = $candidates[0];
        } elseif (count($candidates) > 1) {
            $espnPair = array_filter([$ehName ? norm($ehName) : null, $eaName ? norm($eaName) : null]);
            foreach ($candidates as $k) {
                $m = $data['matches'][$k];
                $ourPair = [norm($m['home']), norm($m['away'])];
                // match if both resolved ESPN teams are in our pair (order-agnostic)
                if (count($espnPair) === 2 && !array_diff($espnPair, $ourPair)) { $idx = $k; break; }
            }
        }
        if ($idx === null) {
            $evDay = gmdate('Y-m-d', $evTs);
            $venueCity = norm($comp['venue']['address']['city'] ?? '');
            foreach ($data['matches'] as $k => $m) {
                if (gmdate('Y-m-d', strtotime($m['utc'])) !== $evDay) continue;
                if ($venueCity && strpos(norm($m['city']), explode(' ', $venueCity)[0]) !== false) { $idx = $k; break; }
            }
        }
        if ($idx === null) continue;

        $match = &$data['matches'][$idx];
        $id = $match['id'];

        // ---- ADVANCEMENT: fill knockout placeholders from real ESPN teams ----
        if (is_placeholder($match['home']) && $ehName && is_placeholder($match['away']) && $eaName) {
            if ($match['home'] !== $ehName || $match['away'] !== $eaName) {
                $match['home'] = $ehName;
                $match['away'] = $eaName;
                $advanceChanges[$id] = "$ehName vs $eaName";
            }
        }

        // ---- RESULTS ----
        $state = $comp['status']['type']['state'] ?? ($ev['status']['type']['state'] ?? 'pre');
        $completed = $comp['status']['type']['completed'] ?? false;
        if ($state === 'pre') continue; // not started, nothing to record

        $hScore = isset($eh['score']) ? (int) $eh['score'] : null;
        $aScore = isset($ea['score']) ? (int) $ea['score'] : null;
        if ($hScore === null || $aScore === null) continue;

        // Orient ESPN home/away to OUR match's home/away by team name when known.
        $homeIsOurHome = true;
        if ($ehName && !is_placeholder($match['home'])) {
            $homeIsOurHome = (norm($ehName) === norm($match['home']));
        } elseif ($eaName && !is_placeholder($match['home'])) {
            $homeIsOurHome = (norm($eaName) !== norm($match['home']));
        }
        $outHome = $homeIsOurHome ? $hScore : $aScore;
        $outAway = $homeIsOurHome ? $aScore : $hScore;

        $status = ($state === 'post' || $completed) ? 'FT' : 'LIVE';

        // Penalty shootout: a level knockout is decided on penalties. ESPN sets
        // status name STATUS_FINAL_PEN and a per-competitor `shootoutScore`.
        // Record pen tallies (oriented to our home/away) so the result reads
        // e.g. 1 (3) - 1 (4) and the bracket can advance the real winner.
        $isPens = (($comp['status']['type']['name'] ?? '') === 'STATUS_FINAL_PEN');
        $hPen = ($isPens && isset($eh['shootoutScore']) && $eh['shootoutScore'] !== '') ? (int) $eh['shootoutScore'] : null;
        $aPen = ($isPens && isset($ea['shootoutScore']) && $ea['shootoutScore'] !== '') ? (int) $ea['shootoutScore'] : null;

        $existing = $scores[(string)$id] ?? null;
        // Don't overwrite a finished result with a non-final one.
        if ($existing && ($existing['status'] ?? '') === 'FT' && $status !== 'FT') continue;

        $new = ['home' => $outHome, 'away' => $outAway, 'status' => $status];
        if ($hPen !== null && $aPen !== null) {
            $new['penHome'] = $homeIsOurHome ? $hPen : $aPen;
            $new['penAway'] = $homeIsOurHome ? $aPen : $hPen;
        }
        if ($existing !== $new) {
            $scores[(string)$id] = $new;
            $pens = isset($new['penHome']) ? " (pens {$new['penHome']}-{$new['penAway']})" : '';
            $scoreChanges[$id] = "$outHome-$outAway $status$pens";
        }
        unset($match);
    }
}

/* ----------------------------------------------------- propagate bracket */
// Carry knockout winners/losers forward: replace "Winner Match N" / "Loser
// Match N" slots with the actual team once match N has a decisive result (or a
// recorded shootout). Source of truth is OUR scores — no ESPN dependency, so
// the bracket advances the moment a result lands. The logic lives in
// bracket.php so it can be exercised by tests without touching the network.
$bracketChanges = kc_propagate_bracket($data, $scores);

/* ------------------------------------------------------------------ output */

// keep scores.json ordered by numeric match id for clean diffs
uksort($scores, fn($a, $b) => (int)$a <=> (int)$b);

$scoresWritten = write_json_if_changed($SCORES_FILE, $scores, $DRY);
$dataWritten   = write_json_if_changed($DATA_FILE, $data, $DRY);

$tag = $DRY ? '[dry-run] ' : '';
echo $tag . "fetched $fetchedDays day(s)" . ($failDays ? ", $failDays failed" : '') . "\n";
if ($scoreChanges) {
    echo $tag . "scores updated (" . count($scoreChanges) . "): ";
    echo implode(', ', array_map(fn($id, $v) => "#$id $v", array_keys($scoreChanges), $scoreChanges)) . "\n";
} else {
    echo $tag . "no score changes\n";
}
if ($advanceChanges) {
    echo $tag . "teams advanced (" . count($advanceChanges) . "): ";
    echo implode(' | ', array_map(fn($id, $v) => "#$id $v", array_keys($advanceChanges), $advanceChanges)) . "\n";
}
if ($bracketChanges) {
    echo $tag . "bracket advanced (" . count($bracketChanges) . " matches):\n";
    foreach ($bracketChanges as $id => $list) echo "  #$id " . implode('; ', $list) . "\n";
}
echo $tag . ($scoresWritten || $dataWritten ? "files changed\n" : "nothing to write\n");

exit(0);
