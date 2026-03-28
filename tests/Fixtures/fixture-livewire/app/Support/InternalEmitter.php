<?php

namespace App\Support;

final class InternalEmitter
{
    public function notify(): void
    {
        $this->emit('legacy.internal');
    }

    private function emit(string $event): void
    {
    }
}