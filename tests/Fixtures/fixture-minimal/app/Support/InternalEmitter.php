<?php

namespace App\Support;

final class InternalEmitter
{
    public function notify(): void
    {
        $this->emit('minimal.fixture');
    }

    private function emit(string $event): void
    {
    }
}