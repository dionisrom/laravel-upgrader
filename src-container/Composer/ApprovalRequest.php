<?php

declare(strict_types=1);

namespace AppContainer\Composer;

/**
 * Represents a request for approval of a dependency change.
 */
final class ApprovalRequest
{
    /**
     * @param string $token Unique approval token
     * @param string $package The package being modified
     * @param string $action The action being requested (e.g., 'removal', 'replacement')
     * @param string $reason Explanation for the change
     * @param array<string, mixed> $impact Assessment of the change impact
     */
    public function __construct(
        public readonly string $token,
        public readonly string $package,
        public readonly string $action,
        public readonly string $reason,
        public readonly array $impact,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'token' => $this->token,
            'package' => $this->package,
            'action' => $this->action,
            'reason' => $this->reason,
            'impact' => $this->impact,
        ];
    }
}
