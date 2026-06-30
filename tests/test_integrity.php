<?php
/**
 * Structural checks on the live data.json / scores.json so a bad hand-edit or a
 * fetcher bug is caught before it ships: unique ids, valid references, sane
 * scores, and flags/venues for every concrete team and city.
 */

$data   = load_json('data.json');
$scores = load_json('scores.json');

$ids = array_column($data['matches'], 'id');

t('data: top-level sections present', function () use ($data) {
    foreach (['venues', 'flags', 'groups', 'matches'] as $k) ok(isset($data[$k]), "has $k");
    eq(12, count($data['groups']), '12 groups');
});

t('data: every match has the required fields', function () use ($data) {
    foreach ($data['matches'] as $m) {
        foreach (['id', 'phase', 'home', 'away', 'utc', 'city'] as $k) {
            ok(array_key_exists($k, $m), "match #" . ($m['id'] ?? '?') . " has $k");
        }
    }
});

t('data: match ids are unique', function () use ($ids) {
    eq(count($ids), count(array_unique($ids)), 'no duplicate ids');
});

t('data: every "Winner/Loser Match N" points at a real, EARLIER match', function () use ($data, $ids) {
    $idset = array_flip($ids);
    foreach ($data['matches'] as $m) {
        foreach (['home', 'away'] as $side) {
            if (preg_match('/^(?:Winner|Loser) Match (\d+)$/', $m[$side], $mm)) {
                $ref = (int) $mm[1];
                ok(isset($idset[$ref]), "#{$m['id']} $side references existing match $ref");
                ok($ref < $m['id'], "#{$m['id']} $side references an earlier match ($ref < {$m['id']})");
            }
        }
    }
});

t('data: group-stage matches are fully concrete (no placeholders)', function () use ($data) {
    foreach ($data['matches'] as $m) {
        if (($m['phase'] ?? '') !== 'group') continue;
        ok(!kc_is_placeholder($m['home']) && !kc_is_placeholder($m['away']),
            "group #{$m['id']} has real teams");
    }
});

t('data: every concrete team has a flag and every city has a venue', function () use ($data) {
    foreach ($data['matches'] as $m) {
        ok(isset($data['venues'][$m['city']]), "venue for {$m['city']}");
        foreach (['home', 'away'] as $side) {
            if (kc_is_placeholder($m[$side])) continue;
            ok(isset($data['flags'][$m[$side]]), "flag for {$m[$side]}");
        }
    }
});

t('data: each group lists exactly four teams that all have flags', function () use ($data) {
    foreach ($data['groups'] as $g => $teams) {
        eq(4, count($teams), "group $g has 4 teams");
        foreach ($teams as $tm) ok(isset($data['flags'][$tm]), "flag for $tm (group $g)");
    }
});

t('scores: keys are numeric ids that exist in data.json', function () use ($scores, $ids) {
    $idset = array_flip($ids);
    foreach ($scores as $key => $_) {
        ok(ctype_digit((string) $key), "score key '$key' is numeric");
        ok(isset($idset[(int) $key]), "score key $key matches a real match");
    }
});

t('scores: home/away are non-negative ints and status is valid', function () use ($scores) {
    foreach ($scores as $key => $s) {
        ok(is_int($s['home']) && $s['home'] >= 0, "#$key home is a non-negative int");
        ok(is_int($s['away']) && $s['away'] >= 0, "#$key away is a non-negative int");
        ok(in_array($s['status'] ?? '', ['NS', 'LIVE', 'FT'], true), "#$key status valid");
    }
});

t('scores: penalties only appear on a level full-time result and are decisive', function () use ($scores) {
    foreach ($scores as $key => $s) {
        $hasPen = array_key_exists('penHome', $s) || array_key_exists('penAway', $s);
        if (!$hasPen) continue;
        ok(array_key_exists('penHome', $s) && array_key_exists('penAway', $s), "#$key has both pen fields");
        eq('FT', $s['status'] ?? '', "#$key pens imply FT");
        eq($s['home'], $s['away'], "#$key pens imply a level score");
        ok(is_int($s['penHome']) && is_int($s['penAway']), "#$key pen values are ints");
        ok($s['penHome'] !== $s['penAway'], "#$key shootout has a winner");
    }
});

t('consistency: propagating real scores onto real data is already a fixed point', function () use ($data, $scores) {
    // The committed data.json should already reflect every decided tie, so a
    // fresh propagation must produce no further changes.
    $changes = kc_propagate_bracket($data, $scores);
    eq([], $changes, 'committed bracket is up to date with committed scores');
});
