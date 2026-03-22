<?php

declare(strict_types=1);

use AppContainer\Rector\Rules\L8ToL9\HttpKernelMiddlewareRector;
use Rector\Config\RectorConfig;

return static function (RectorConfig $rectorConfig): void {
    $rectorConfig->rule(HttpKernelMiddlewareRector::class);
};
