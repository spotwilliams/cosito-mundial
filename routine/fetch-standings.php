<?php
/**
 * fetch-standings.php — fill knockout group placeholders in data.json from
 * ESPN's group STANDINGS (rank), not the scoreboard.
 *
 * Sibling to fetch-results.php (which advances the bracket from the scoreboard
 * once ESPN wires it). This one is deterministic and earlier: as soon as ESPN
 * marks a group's top two as advanced, it replaces:
 *
 *     "Winner Group E"     -> ESPN rank-1 team of group E
 *     "Runner-up Group C"  -> ESPN rank-2 team of group C
 *
 * It does NOT touch:
 *   - "3rd Place (A/B/C/D/F)" slots  — need FIFA's 3rd-place allocation table;
 *                                       let fetch-results.php fill them once
 *                                       ESPN allocates the bracket.
 *   - "Winner/Loser Match N" slots   — depend on knockout results.
 *
 * SOURCE OF TRUTH IS ESPN. A slot is only filled when ESPN's standings flags
 * that team `advanced == 1` (i.e. the group is decided). No guessing from
 * partial/live tables — we always wait for ESPN.
 *
 *   core API:
 *     groups:    https://sports.core.api.espn.com/v2/sports/soccer/leagues/fifa.world/seasons/2026/types/1/groups
 *     standings: .../types/1/groups/{id}/standings/0
 *     team:      .../seasons/2026/teams/{id}
 *
 * Usage:   php routine/fetch-standings.php            # apply changes
 *          php routine/fetch-standings.php --dry-run  # show changes, write nothing
 *
 * Exit codes: 0 ok (changed or not), 1 hard error (e.g. could not read data.json).
 */

date_default_timezone_set('UTC');

$ROOT      = dirname(__DIR__);
$DATA_FILE = $ROOT . '/data.json';
$DRY       = in_array('--dry-run', $argv, true);
$SEASON    = 2026;
$CORE      = "https://sports.core.api.espn.com/v2/sports/soccer/leagues/fifa.world/seasons/$SEASON";

/* ------------------------------------------------------------------ helpers */

function fail($msg) { fwrite(STDERR, "ERROR: $msg\n"); exit(1); }

/** Live progress line to STDERR — shows what's happening while the script runs.
 *  Unbuffered, so it appears in the terminal immediately (stdout below stays the
 *  machine-readable end-of-run summary). */
function progress($msg) { fwrite(STDERR, "» $msg\n"); }

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
    $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
    $s = strtolower($s);
    $s = preg_replace('/[^a-z0-9]+/', ' ', $s);
    return trim(preg_replace('/\s+/', ' ', $s));
}

// ESPN/alt spellings (normalised) -> our canonical name in data.json.
// Keep in sync with fetch-results.php.
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

/** Resolve an ESPN team name to our canonical data.json name, or null if unknown. */
function canon_team($espnName, $ourNormIndex, $aliases) {
    if ($espnName === '') return null;
    $n = norm($espnName);
    if (isset($aliases[$n]))      return $aliases[$n];
    if (isset($ourNormIndex[$n])) return $ourNormIndex[$n];
    return null; // unknown -> don't guess
}

function is_placeholder($name) {
    return (bool) preg_match('/(Winner|Runner-up|Group|Place|Match|Loser|TBD)/i', $name);
}

function http_get_json($url, $tries = 2) {
    $lastCode = 0; $lastErr = '';
    for ($attempt = 1; $attempt <= $tries; $attempt++) {
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
        $lastCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $lastErr  = curl_error($ch);
        if ($body !== false && $lastCode < 400) {
            $j = json_decode($body, true);
            if (is_array($j)) return $j;
            fwrite(STDERR, "WARN: bad JSON from $url\n");
            return null; // a 200 with junk won't improve on retry
        }
        // transient (timeout / 5xx) — brief backoff, then retry
        if ($attempt < $tries) usleep(500000);
    }
    fwrite(STDERR, "WARN: fetch failed ($lastCode) $lastErr for $url\n");
    return null;
}

/** Pull the numeric id off the end of an ESPN $ref path (ignoring the query string). */
function ref_id($ref) {
    $path = parse_url($ref, PHP_URL_PATH) ?: '';
    return basename($path);
}

/* --------------------------------------------------------------- load data */

progress("fetch-standings starting" . ($DRY ? " (dry-run)" : ""));
progress("loading data.json");
$data = read_json($DATA_FILE);
if (empty($data['matches'])) fail("no 'matches' array in data.json");
progress(count($data['matches']) . " matches loaded");

// index: our canonical team name (normalised) -> name, from group-stage matches
$ourNormIndex = [];
foreach ($data['matches'] as $m) {
    foreach (['home', 'away'] as $side) {
        if (!is_placeholder($m[$side])) $ourNormIndex[norm($m[$side])] = $m[$side];
    }
}

/* ------------------------------------------------------ fetch ESPN standings */

progress("fetching ESPN group list");
$groupList = http_get_json("$CORE/types/1/groups");
if ($groupList === null || empty($groupList['items'])) fail("could not fetch group list from ESPN");
progress(count($groupList['items']) . " group(s) to inspect");

$teamNameCache = []; // espn team id -> ESPN displayName
function team_name($teamRef, &$cache, $core) {
    $id = ref_id($teamRef);
    if ($id === '') return '';
    if (isset($cache[$id])) return $cache[$id];
    $t = http_get_json("$core/teams/$id");
    return $cache[$id] = ($t['displayName'] ?? '');
}

// group letter -> ['winner' => name|null, 'runner' => name|null]
// Only set when ESPN flags that team advanced == 1 (group decided).
$confirmed = [];
$unmapped  = [];   // ESPN names we couldn't map to data.json
$groupsSeen = 0;

foreach ($groupList['items'] as $gItem) {
    $gid = ref_id($gItem['$ref'] ?? '');
    if ($gid === '') continue;

    $group = http_get_json("$CORE/types/1/groups/$gid");
    if ($group === null) continue;

    // "Group A" -> "A"
    if (!preg_match('/Group\s+([A-Z])/i', $group['name'] ?? '', $mm)) continue;
    $letter = strtoupper($mm[1]);
    $groupsSeen++;
    progress("  Group $letter: reading standings");

    $st = http_get_json("$CORE/types/1/groups/$gid/standings/0");
    if ($st === null || empty($st['standings'])) { progress("  Group $letter: no standings yet"); continue; }

    foreach ($st['standings'] as $row) {
        $rank = null; $advanced = null;
        foreach ($row['records'][0]['stats'] ?? [] as $s) {
            if ($s['name'] === 'rank')     $rank     = (int) $s['value'];
            if ($s['name'] === 'advanced') $advanced = (int) $s['value'];
        }
        if ($rank !== 1 && $rank !== 2) continue; // only winner / runner-up slots
        if ($advanced !== 1) continue;            // wait for ESPN to confirm

        $espnName = team_name($row['team']['$ref'] ?? '', $teamNameCache, $CORE);
        $name = canon_team($espnName, $ourNormIndex, $ALIASES);
        if ($name === null) { if ($espnName !== '') $unmapped[$espnName] = true; continue; }

        $confirmed[$letter][$rank === 1 ? 'winner' : 'runner'] = $name;
        progress("  Group $letter: " . ($rank === 1 ? 'winner' : 'runner-up') . " = $name");
    }
}

/* ------------------------------------------------------------- fill bracket */

progress("filling bracket placeholders from confirmed groups");
$changes = []; // id => "old -> new"

foreach ($data['matches'] as $i => &$match) {
    foreach (['home', 'away'] as $side) {
        $val = $match[$side];
        if (preg_match('/^Winner Group ([A-L])$/', $val, $m)) {
            $name = $confirmed[$m[1]]['winner'] ?? null;
        } elseif (preg_match('/^Runner-up Group ([A-L])$/', $val, $m)) {
            $name = $confirmed[$m[1]]['runner'] ?? null;
        } else {
            continue; // not a group-rank placeholder we handle
        }
        if ($name !== null && $name !== $val) {
            $changes[$match['id']][] = "$side: $val -> $name";
            $match[$side] = $name;
            progress("  match #{$match['id']} $side: $val -> $name");
        }
    }
}
unset($match);

/* ------------------------------------------------------------------ output */

progress("writing data.json" . ($DRY ? " (dry-run: no disk writes)" : ""));
$written = write_json_if_changed($DATA_FILE, $data, $DRY);
progress("data.json " . ($written ? "changed" : "unchanged"));

$tag = $DRY ? '[dry-run] ' : '';
echo $tag . "groups read: $groupsSeen, confirmed slots: " .
     array_sum(array_map('count', $confirmed)) . "\n";

if ($changes) {
    echo $tag . "bracket slots filled (" . count($changes) . " matches):\n";
    foreach ($changes as $id => $list) {
        echo "  #$id " . implode('; ', $list) . "\n";
    }
} else {
    echo $tag . "no bracket changes\n";
}

if ($unmapped) {
    fwrite(STDERR, "WARN: unmapped ESPN team names (add to \$ALIASES): " .
        implode(', ', array_keys($unmapped)) . "\n");
}

echo $tag . ($written ? "data.json changed\n" : "nothing to write\n");

exit(0);
