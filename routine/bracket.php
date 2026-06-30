<?php
/**
 * bracket.php — pure, network-free knockout helpers.
 *
 * Shared by fetch-results.php (which advances the bracket after pulling scores)
 * and by the tests under tests/. No I/O, no globals, no ESPN: just transforms
 * over the in-memory $data / $scores arrays, so it's trivially unit-testable.
 *
 * Functions are prefixed `kc_` (knockout) to avoid clashing with the local
 * helpers the fetch scripts already define.
 */

/** True if a slot is still a placeholder rather than a concrete team. */
function kc_is_placeholder($name) {
    return (bool) preg_match('/(Winner|Runner-up|Group|Place|Match|Loser|TBD)/i', (string) $name);
}

/**
 * Decide a finished tie from its score.
 *
 * Returns ['winner' => name, 'loser' => name] when the result settles it, or
 * null when it can't yet — not full time, the two teams aren't both known, or
 * a level score with no usable shootout. We NEVER guess a winner: a draw with
 * no (or tied) penalties stays unresolved.
 *
 * @param array      $srcMatch a data.json match (needs 'home','away')
 * @param array|null $score    the scores.json entry for that match, or null
 */
function kc_decide($srcMatch, $score) {
    if (!is_array($score) || ($score['status'] ?? '') !== 'FT') return null;
    if (kc_is_placeholder($srcMatch['home']) || kc_is_placeholder($srcMatch['away'])) return null;

    $h = $score['home'] ?? null;
    $a = $score['away'] ?? null;
    if (!is_int($h) || !is_int($a)) return null;

    if ($h === $a) {
        // Level after full time -> decided on penalties. Use the shootout tally
        // if we recorded one; otherwise the winner is unknown.
        $ph = $score['penHome'] ?? null;
        $pa = $score['penAway'] ?? null;
        if (!is_int($ph) || !is_int($pa) || $ph === $pa) return null;
        $homeWon = $ph > $pa;
    } else {
        $homeWon = $h > $a;
    }

    return [
        'winner' => $homeWon ? $srcMatch['home'] : $srcMatch['away'],
        'loser'  => $homeWon ? $srcMatch['away'] : $srcMatch['home'],
    ];
}

/**
 * Resolve a single "Winner Match N" / "Loser Match N" token to a team name,
 * or null if match N isn't a decided tie between two concrete teams yet.
 *
 * @param string $val    the slot text
 * @param array  $byId   match id => index into $data['matches']
 * @param array  $data   the full data structure
 * @param array  $scores scores keyed by match-id string
 */
function kc_resolve_slot($val, array $byId, array $data, array $scores) {
    if (!preg_match('/^(Winner|Loser) Match (\d+)$/', (string) $val, $mm)) return null;
    $which = $mm[1];
    $srcId = (int) $mm[2];
    if (!isset($byId[$srcId])) return null;
    $d = kc_decide($data['matches'][$byId[$srcId]], $scores[(string) $srcId] ?? null);
    if ($d === null) return null;
    return $which === 'Winner' ? $d['winner'] : $d['loser'];
}

/**
 * Carry knockout winners/losers forward: replace every "Winner Match N" /
 * "Loser Match N" slot with the real team once match N is decided. Iterates to
 * a fixed point, so a slot resolved in one pass can feed the next round in the
 * same call (round of 32 -> 16 -> quarter -> semi -> final / third place).
 *
 * Mutates $data['matches'] in place. Returns id => ["side: old -> new", ...].
 */
function kc_propagate_bracket(array &$data, array $scores) {
    $byId = [];
    foreach ($data['matches'] as $i => $m) $byId[$m['id']] = $i;

    $changes = [];
    do {
        $changedThisPass = false;
        foreach ($data['matches'] as &$m) {
            foreach (['home', 'away'] as $side) {
                $r = kc_resolve_slot($m[$side], $byId, $data, $scores);
                if ($r !== null && $r !== $m[$side]) {
                    $changes[$m['id']][] = "$side: {$m[$side]} -> $r";
                    $m[$side] = $r;
                    $changedThisPass = true;
                }
            }
        }
        unset($m);
    } while ($changedThisPass);

    return $changes;
}
