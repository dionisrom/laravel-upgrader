<?php

declare(strict_types=1);

use AppContainer\Rector\Rules\L9ToL10\LaravelModelReturnTypeRector;
use Rector\Config\RectorConfig;

return static function (RectorConfig $rectorConfig): void {
    $rectorConfig->rule(LaravelModelReturnTypeRector::class);
};
