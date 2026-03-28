<?php

declare(strict_types=1);

use AppContainer\Rector\Rules\L12ToL13\DeprecatedApiRemoverRector;
use Rector\Config\RectorConfig;

return static function (RectorConfig $rectorConfig): void {
    $rectorConfig->rule(DeprecatedApiRemoverRector::class);
};
