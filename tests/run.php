<?php
declare(strict_types=1);

// Usage : php tests/run.php [lot1 lot2 lot3 ...]
require __DIR__ . '/lib.php';

reinitialiserBase();
demarrerServeur();
@unlink(BASE_PATH . '/storage/logs/otp.log');

$suites = array_slice($argv, 1) ?: array_map(static fn(string $f): string => basename($f, '.php'), glob(__DIR__ . '/suites/*.php'));
sort($suites);
foreach ($suites as $s) {
    require __DIR__ . "/suites/$s.php";
}

$t = $GLOBALS['tests'];
file_put_contents(BASE_PATH . '/storage/logs/tests_derniere_execution.json', json_encode($t, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
echo "\n{$t['ok']} réussis, {$t['ko']} échoués\n";
exit($t['ko'] === 0 ? 0 : 1);
