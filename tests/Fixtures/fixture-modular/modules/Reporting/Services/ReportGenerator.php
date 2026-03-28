<?php

namespace Modules\Reporting\Services;

use Illuminate\Contracts\Events\Dispatcher;

final class ReportGenerator
{
    public function __construct(private readonly Dispatcher $events)
    {
    }

    /**
     * @return array<string, string>
     */
    public function generate(string $name): array
    {
        $this->events->dispatch('report.generated');

        return ['name' => $name];
    }
}