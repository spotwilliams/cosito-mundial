<?php
/**
 * Tiny zero-dependency test runner.  Run:  php tests/run.php
 *
 * No framework on purpose — the project has no build step and these tests only
 * need plain assertions over JSON and the network-free bracket logic. Each
 * tests/test_*.php file registers cases with t(); helpers below do the rest.
 *
 * Exit code 0 = all passed, 1 = at least one failure (CI-friendly).
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

define('ROOT', dirname(__DIR__));

$GLOBALS['__t'] = ['pass' => 0, 'fail' => 0, 'fails' => []];

/** Register + immediately run one test case. */
function t(string $name, callable $fn): void {
    try {
        $fn();
        $GLOBALS['__t']['pass']++;
        fwrite(STDOUT, "  \033[32mok\033[0m   $name\n");
    } catch (Throwable $e) {
        $GLOBALS['__t']['fail']++;
        $GLOBALS['__t']['fails'][] = $name;
        fwrite(STDOUT, "  \033[31mFAIL\033[0m $name\n       " . $e->getMessage() . "\n");
    }
}

/** Assert a condition is truthy. */
function ok($cond, string $msg = 'expected truthy'): void {
    if (!$cond) throw new Exception($msg);
}

/** Assert strict equality (===), with a readable diff on failure. */
function eq($expected, $actual, string $msg = ''): void {
    if ($expected !== $actual) {
        throw new Exception(($msg ? "$msg — " : '') .
            'expected ' . var_export($expected, true) . ' but got ' . var_export($actual, true));
    }
}

/** Assert that $fn throws (used for "must reject bad input" cases). */
function throws(callable $fn, string $msg = 'expected an exception'): void {
    try { $fn(); } catch (Throwable $e) { return; }
    throw new Exception($msg);
}

/** Load + json_decode a repo file, failing the test if it's missing/invalid. */
function load_json(string $rel): array {
    $path = ROOT . '/' . $rel;
    ok(file_exists($path), "$rel exists");
    $j = json_decode(file_get_contents($path), true);
    ok(is_array($j), "$rel is valid JSON");
    return $j;
}

require_once ROOT . '/routine/bracket.php';

$files = glob(__DIR__ . '/test_*.php');
sort($files);
foreach ($files as $f) {
    fwrite(STDOUT, "\n" . basename($f) . "\n");
    require $f;
}

$t = $GLOBALS['__t'];
fwrite(STDOUT, "\n" . str_repeat('-', 40) . "\n");
fwrite(STDOUT, sprintf("%d passed, %d failed\n", $t['pass'], $t['fail']));
if ($t['fail'] > 0) {
    fwrite(STDOUT, "failed: " . implode(', ', $t['fails']) . "\n");
    exit(1);
}
fwrite(STDOUT, "\033[32mall green\033[0m\n");
exit(0);
