<?php
/**
 * End-to-end: drive the REAL data.json bracket from the round of 32 to the
 * final, proving the fetcher's propagation (kc_propagate_bracket) fills every
 * knockout slot — semis, FINAL, and the THIRD-PLACE play-off — and always
 * places the team the score dictates. Network-free: we synthesise results.
 */

$data = load_json('data.json');

// Index + helpers over the real bracket.
$byId = [];
foreach ($data['matches'] as $i => $m) $byId[$m['id']] = $i;
$ko = array_values(array_filter($data['matches'], fn($m) => ($m['phase'] ?? '') !== 'group'));

t('fixture: bracket has the expected knockout shape', function () use ($data) {
    $phases = [];
    foreach ($data['matches'] as $m) {
        $p = $m['phase'] ?? '';
        $phases[$p] = ($phases[$p] ?? 0) + 1;
    }
    eq(16, $phases['round32'] ?? 0, 'round of 32');
    eq(8,  $phases['round16'] ?? 0, 'round of 16');
    eq(4,  $phases['quarter'] ?? 0, 'quarter-finals');
    eq(2,  $phases['semi'] ?? 0, 'semi-finals');
    eq(1,  $phases['third'] ?? 0, 'third-place play-off');
    eq(1,  $phases['final'] ?? 0, 'final');
});

t('fixture: every round-of-32 slot is already a concrete team', function () use ($data) {
    foreach ($data['matches'] as $m) {
        if (($m['phase'] ?? '') !== 'round32') continue;
        ok(!kc_is_placeholder($m['home']), "#{$m['id']} home concrete ({$m['home']})");
        ok(!kc_is_placeholder($m['away']), "#{$m['id']} away concrete ({$m['away']})");
    }
});

/**
 * Deterministic synthetic result for a match id. A handful of ids are forced to
 * a penalty shootout so the level-score + pens path is exercised all the way up
 * the bracket; the rest are decisive. Pen tallies are never tied.
 */
$PEN_TIES = [73, 91, 101]; // a round-of-32, a quarter, and a semi go to pens
$synth = function ($id) use ($PEN_TIES) {
    if (in_array($id, $PEN_TIES, true)) {
        $homePens = ($id % 2 === 0) ? 5 : 4;
        $awayPens = ($id % 2 === 0) ? 4 : 5;
        return ['home' => 1, 'away' => 1, 'status' => 'FT', 'penHome' => $homePens, 'penAway' => $awayPens];
    }
    // even id -> home wins, odd id -> away wins (both decisive)
    return ($id % 2 === 0) ? ['home' => 2, 'away' => 0, 'status' => 'FT']
                           : ['home' => 0, 'away' => 1, 'status' => 'FT'];
};

t('drive the whole bracket: every knockout slot resolves to a real team', function () use (&$data, $ko, $synth) {
    // Remember the original placeholder wiring before we mutate $data.
    $origSlot = []; // matchId => [side => label]
    foreach ($ko as $m) {
        foreach (['home', 'away'] as $side) {
            if (kc_is_placeholder($m[$side])) $origSlot[$m['id']][$side] = $m[$side];
        }
    }

    // Simulate the routine running repeatedly: each pass we record a result for
    // every match whose two teams are now known, then propagate. Loops until the
    // bracket stops changing — exactly how the live fetcher would behave day to day.
    $scores = [];
    for ($pass = 0; $pass < 12; $pass++) {
        $added = false;
        foreach ($data['matches'] as $m) {
            if (($m['phase'] ?? '') === 'group') continue;
            if (kc_is_placeholder($m['home']) || kc_is_placeholder($m['away'])) continue;
            if (isset($scores[(string) $m['id']])) continue;
            $scores[(string) $m['id']] = $synth($m['id']);
            $added = true;
        }
        kc_propagate_bracket($data, $scores);
        if (!$added) break;
    }

    // 1) Nothing is left as a placeholder anywhere in the knockout tree.
    foreach ($data['matches'] as $m) {
        if (($m['phase'] ?? '') === 'group') continue;
        ok(!kc_is_placeholder($m['home']), "#{$m['id']} home still placeholder: {$m['home']}");
        ok(!kc_is_placeholder($m['away']), "#{$m['id']} away still placeholder: {$m['away']}");
    }

    // 2) Every slot that started as "Winner/Loser Match N" now holds exactly the
    //    team that kc_decide() says won/lost match N. This validates the entire
    //    cascade — including the final and the third-place play-off — edge by edge.
    $byId = [];
    foreach ($data['matches'] as $i => $m) $byId[$m['id']] = $i;
    foreach ($origSlot as $mid => $sides) {
        foreach ($sides as $side => $label) {
            preg_match('/^(Winner|Loser) Match (\d+)$/', $label, $mm);
            $src = $data['matches'][$byId[(int) $mm[2]]];
            $d = kc_decide($src, $scores[(string) (int) $mm[2]]);
            ok($d !== null, "source match {$mm[2]} for #$mid/$side should be decided");
            $expected = $mm[1] === 'Winner' ? $d['winner'] : $d['loser'];
            eq($expected, $data['matches'][$byId[$mid]][$side], "#$mid $side ($label)");
        }
    }
});

t('final and third place are wired to the two semi-finals', function () use ($data) {
    // Find the semis, final, third by phase, then re-derive from a clean copy so
    // we check the WIRING (labels), independent of the simulation above.
    $fresh = load_json('data.json');
    $byPhase = fn($p) => array_values(array_filter($fresh['matches'], fn($m) => ($m['phase'] ?? '') === $p));
    $semis = $byPhase('semi');
    $final = $byPhase('final')[0];
    $third = $byPhase('third')[0];
    $semiIds = array_map(fn($m) => $m['id'], $semis);
    sort($semiIds);

    // Final should reference the WINNERS of both semis; third the LOSERS.
    foreach (['home', 'away'] as $side) {
        ok(preg_match('/^Winner Match (\d+)$/', $final[$side], $mm), "final $side is a Winner slot");
        ok(in_array((int) $mm[1], $semiIds, true), "final $side feeds from a semi");
        ok(preg_match('/^Loser Match (\d+)$/', $third[$side], $ml), "third $side is a Loser slot");
        ok(in_array((int) $ml[1], $semiIds, true), "third $side feeds from a semi");
    }
});
