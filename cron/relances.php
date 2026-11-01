<?php
declare(strict_types=1);

// Tâche planifiée : php cron/relances.php
use App\Services\TacheService;

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/src/bootstrap.php';

try {
    echo json_encode(TacheService::lancer('relances'), JSON_UNESCAPED_UNICODE), PHP_EOL;
} catch (\Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
