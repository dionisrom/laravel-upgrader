<?php

declare(strict_types=1);

namespace App\Workspace\Exception;

use RuntimeException;

final class ConcurrentUpgradeException extends RuntimeException
{
}
