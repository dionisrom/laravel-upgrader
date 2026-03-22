<?php

declare(strict_types=1);

use AppContainer\Rector\Rules\L8ToL9\ModelUnguardRector;
use Rector\Config\RectorConfig;

return static function (RectorConfig $rectorConfig): void {
    $rectorConfig->rule(ModelUnguardRector::class);
};
