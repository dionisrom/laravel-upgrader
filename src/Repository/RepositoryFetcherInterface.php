<?php

declare(strict_types=1);

namespace App\Repository;

use App\Repository\Exception\AuthenticationException;
use App\Repository\Exception\ConcurrentUpgradeException;
use App\Repository\Exception\FetchTimeoutException;
use App\Repository\Exception\RepositoryNotFoundException;

interface RepositoryFetcherInterface
{
    /**
     * Clones or copies the repository into $targetPath.
     * MUST acquire an advisory lock before copying.
     * MUST throw ConcurrentUpgradeException if lock unavailable.
     * MUST NOT log $token in any output or exception message.
     *
     * @throws RepositoryNotFoundException
     * @throws AuthenticationException
     * @throws ConcurrentUpgradeException
     * @throws FetchTimeoutException
     */
    public function fetch(string $source, string $targetPath, ?string $token = null): FetchResult;
}
