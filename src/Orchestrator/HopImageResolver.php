<?php

declare(strict_types=1);

namespace App\Orchestrator;

use App\Composer\PhpConstraintDetector;

final class HopImageResolver
{
    /**
     * @param array<string, string> $hopImages
     * @param array<string, list<string>> $supportedPhpBasesByHop
     */
    public function __construct(
        private readonly array $hopImages,
        private readonly array $supportedPhpBasesByHop = [
            '8:9' => ['8.1', '8.2', '8.3'],
            '9:10' => ['8.1', '8.2', '8.3'],
            '10:11' => ['8.2', '8.3'],
            '11:12' => ['8.2', '8.3'],
            '12:13' => ['8.3'],
        ],
        private readonly ?string $phpConstraint = null,
        private readonly ?PhpConstraintDetector $phpConstraintDetector = null,
    ) {}

    /**
     * @return array{dockerImage: string, phpBase: ?string}
     */
    public function resolve(string $hopKey): array
    {
        if (!isset($this->hopImages[$hopKey])) {
            throw new InvalidHopException(sprintf('No hop path is defined for %s.', str_replace(':', ' → ', $hopKey)));
        }

        $baseImage = $this->hopImages[$hopKey];

        if ($this->phpConstraint === null) {
            return ['dockerImage' => $baseImage, 'phpBase' => null];
        }

        $supportedPhpBases = $this->supportedPhpBasesByHop[$hopKey] ?? [];
        $detector = $this->phpConstraintDetector ?? new PhpConstraintDetector();
        $selectedPhpBase = $detector->selectSupportedPhpBase($this->phpConstraint, $supportedPhpBases);

        if ($selectedPhpBase === null) {
            throw new InvalidHopException(sprintf(
                'No compatible PHP runtime is available for hop %s and application constraint "%s". Supported hop runtimes: %s.',
                str_replace(':', ' → ', $hopKey),
                $this->phpConstraint,
                implode(', ', $supportedPhpBases),
            ));
        }

        return [
            'dockerImage' => sprintf('%s:php%s', $baseImage, $selectedPhpBase),
            'phpBase' => $selectedPhpBase,
        ];
    }
}