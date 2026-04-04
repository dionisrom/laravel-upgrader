<?php

declare(strict_types=1);

namespace AppContainer\Composer\Exception;

use AppContainer\Composer\ApprovalRequest;

/**
 * Exception thrown when approval is required but not granted.
 */
final class ApprovalRequiredException extends \RuntimeException
{
    /**
     * @param list<ApprovalRequest> $requiredApprovals
     */
    public function __construct(
        string $message,
        private readonly array $requiredApprovals = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * @return list<ApprovalRequest>
     */
    public function getRequiredApprovals(): array
    {
        return $this->requiredApprovals;
    }

    public static function fromRequest(ApprovalRequest $request): self
    {
        return new self(
            sprintf(
                "Approval required for %s of package '%s'\n" .
                "Reason: %s\n" .
                "Token: %s\n" .
                "Run 'upgrader approve %s' to approve.",
                $request->action,
                $request->package,
                $request->reason,
                $request->token,
                $request->token,
            ),
            [$request],
        );
    }

    /**
     * @param list<ApprovalRequest> $requests
     */
    public static function fromMultipleRequests(array $requests): self
    {
        $packageNames = array_map(
            static fn(ApprovalRequest $r): string => $r->package,
            $requests,
        );

        $message = sprintf(
            "Approval required for %d package changes:\n" .
            "%s\n\n" .
            "Use 'upgrader approve <token>' to approve each change, or\n" .
            "use 'upgrader approve-all' to approve all pending changes (not recommended).",
            count($requests),
            implode(', ', $packageNames),
        );

        return new self($message, $requests);
    }
}
