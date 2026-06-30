<?php
/**
 * Unit tests for the network-free bracket logic in routine/bracket.php:
 * deciding a tie, resolving a slot, and the fixed-point cascade — including
 * penalties and the "never guess" rule.
 */

$M = fn($id, $home, $away) => ['id' => $id, 'home' => $home, 'away' => $away];
$FT = fn($h, $a, $extra = []) => ['home' => $h, 'away' => $a, 'status' => 'FT'] + $extra;

// ---- kc_is_placeholder -----------------------------------------------------

t('placeholder: detects Winner/Loser/Group/3rd/TBD slots', function () {
    ok(kc_is_placeholder('Winner Match 73'));
    ok(kc_is_placeholder('Loser Match 101'));
    ok(kc_is_placeholder('Winner Group A'));
    ok(kc_is_placeholder('Runner-up Group C'));
    ok(kc_is_placeholder('3rd Place (A/B/C/D/F)'));
    ok(kc_is_placeholder('TBD'));
});

t('placeholder: concrete team names are not placeholders', function () {
    ok(!kc_is_placeholder('Brazil'));
    ok(!kc_is_placeholder('South Korea'));
    ok(!kc_is_placeholder('USA'));
});

// ---- kc_decide -------------------------------------------------------------

t('decide: regulation win picks the higher score', function () use ($M, $FT) {
    $d = kc_decide($M(1, 'Canada', 'South Africa'), $FT(1, 0));
    eq('Canada', $d['winner']);
    eq('South Africa', $d['loser']);

    $d = kc_decide($M(1, 'Brazil', 'Japan'), $FT(1, 2));
    eq('Japan', $d['winner']);
    eq('Brazil', $d['loser']);
});

t('decide: level score with penalties -> shootout winner', function () use ($M, $FT) {
    // Germany 1 (3) - 1 (4) Paraguay  => Paraguay through
    $d = kc_decide($M(74, 'Germany', 'Paraguay'), $FT(1, 1, ['penHome' => 3, 'penAway' => 4]));
    eq('Paraguay', $d['winner']);
    eq('Germany', $d['loser']);
});

t('decide: draw with NO penalties stays unresolved (never guess)', function () use ($M, $FT) {
    eq(null, kc_decide($M(74, 'Germany', 'Paraguay'), $FT(1, 1)));
});

t('decide: draw with tied penalties is rejected', function () use ($M, $FT) {
    eq(null, kc_decide($M(74, 'Germany', 'Paraguay'), $FT(1, 1, ['penHome' => 3, 'penAway' => 3])));
});

t('decide: non-final and missing scores are unresolved', function () use ($M) {
    eq(null, kc_decide($M(1, 'Brazil', 'Japan'), ['home' => 2, 'away' => 1, 'status' => 'LIVE']));
    eq(null, kc_decide($M(1, 'Brazil', 'Japan'), null));
    eq(null, kc_decide($M(1, 'Brazil', 'Japan'), ['status' => 'NS']));
});

t('decide: a placeholder team means the tie is not ready', function () use ($M, $FT) {
    eq(null, kc_decide($M(89, 'Winner Match 74', 'Paraguay'), $FT(2, 1)));
});

// ---- kc_propagate_bracket (cascade) ----------------------------------------

t('propagate: a finished tie fills the slot it feeds', function () use ($FT) {
    $data = ['matches' => [
        ['id' => 73, 'home' => 'South Africa', 'away' => 'Canada'],
        ['id' => 90, 'home' => 'Winner Match 73', 'away' => 'Winner Match 75'],
    ]];
    $changes = kc_propagate_bracket($data, ['73' => $FT(0, 1)]);
    eq('Canada', $data['matches'][1]['home']);
    eq('Winner Match 75', $data['matches'][1]['away']); // 75 not played -> untouched
    ok(isset($changes[90]));
});

t('propagate: cascades several rounds in one call (fixed point)', function () use ($FT) {
    // 4 R16-ish ties -> 2 quarters -> 1 final, plus a third-place from losers.
    $data = ['matches' => [
        ['id' => 1, 'home' => 'A', 'away' => 'B'],
        ['id' => 2, 'home' => 'C', 'away' => 'D'],
        ['id' => 3, 'home' => 'E', 'away' => 'F'],
        ['id' => 4, 'home' => 'G', 'away' => 'H'],
        ['id' => 5, 'home' => 'Winner Match 1', 'away' => 'Winner Match 2'],
        ['id' => 6, 'home' => 'Winner Match 3', 'away' => 'Winner Match 4'],
        ['id' => 7, 'home' => 'Winner Match 5', 'away' => 'Winner Match 6'],  // final
        ['id' => 8, 'home' => 'Loser Match 5',  'away' => 'Loser Match 6'],   // third place
    ]];
    $scores = [
        '1' => $FT(2, 0), // A
        '2' => $FT(0, 1), // D
        '3' => $FT(1, 1, ['penHome' => 4, 'penAway' => 5]), // F on pens
        '4' => $FT(3, 1), // G
        '5' => $FT(1, 0), // A over D  (loser D)
        '6' => $FT(0, 2), // G over F  (loser F)
        '7' => $FT(2, 1), // final: A champion
        '8' => $FT(1, 0), // third: D
    ];
    kc_propagate_bracket($data, $scores);
    eq('A', $data['matches'][4]['home']); // M5 home
    eq('D', $data['matches'][4]['away']); // M5 away
    eq('F', $data['matches'][5]['home']); // M6 home (pen winner)
    eq('G', $data['matches'][5]['away']); // M6 away
    eq('A', $data['matches'][6]['home']); // final home (winner M5)
    eq('G', $data['matches'][6]['away']); // final away (winner M6)
    eq('D', $data['matches'][7]['home']); // third home (loser M5)
    eq('F', $data['matches'][7]['away']); // third away (loser M6)
});

t('propagate: is idempotent (second run makes no changes)', function () use ($FT) {
    $data = ['matches' => [
        ['id' => 73, 'home' => 'South Africa', 'away' => 'Canada'],
        ['id' => 90, 'home' => 'Winner Match 73', 'away' => 'Morocco'],
    ]];
    $scores = ['73' => $FT(0, 1)];
    kc_propagate_bracket($data, $scores);
    $again = kc_propagate_bracket($data, $scores);
    eq([], $again);
});
