<?php

declare(strict_types=1);

use AppContainer\Rector\Rules\L10ToL11\RateLimiterMigrationAuditor;
use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withRules([RateLimiterMigrationAuditor::class]);
