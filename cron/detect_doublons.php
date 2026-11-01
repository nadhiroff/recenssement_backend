<?php
declare(strict_types=1);

// Tâche planifiée : php cron/detect_doublons.php
use App\Services\TacheService;

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/src/bootstrap.php';

try {
    echo json_encode(TacheService::lancer('detect_doublons'), JSON_UNESCAPED_UNICODE), PHP_EOL;
} catch (\Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
