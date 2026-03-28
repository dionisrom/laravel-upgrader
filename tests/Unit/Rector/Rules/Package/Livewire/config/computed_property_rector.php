<?php

declare(strict_types=1);

use AppContainer\Rector\Rules\Package\Livewire\ComputedPropertyRector;
use Rector\Config\RectorConfig;

return static function (RectorConfig $rectorConfig): void {
    $rectorConfig->rule(ComputedPropertyRector::class);
};
