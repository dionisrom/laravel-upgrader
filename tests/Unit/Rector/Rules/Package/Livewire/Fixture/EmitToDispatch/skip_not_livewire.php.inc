<?php

class InternalEmitter
{
    public function notify(): void
    {
        $this->emit('legacy.event');
        $this->emitSelf('legacy.self');
    }

    private function emit(string $event): void
    {
    }

    private function emitSelf(string $event): void
    {
    }
}