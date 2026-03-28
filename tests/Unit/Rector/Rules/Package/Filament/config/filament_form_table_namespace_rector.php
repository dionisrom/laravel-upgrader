<?php

declare(strict_types=1);

use AppContainer\Rector\Rules\Package\Filament\FilamentFormTableNamespaceRector;
use Rector\Config\RectorConfig;

return static function (RectorConfig $rectorConfig): void {
    $rectorConfig->rule(FilamentFormTableNamespaceRector::class);
};
