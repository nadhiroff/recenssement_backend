<?php
declare(strict_types=1);

namespace App\Core;

final class ApiException extends \RuntimeException
{
    public function __construct(
        public readonly int $http,
        public readonly string $codeErreur,
        string $message,
        public readonly array $extra = []
    ) {
        parent::__construct($message);
    }
}
