<?php

namespace App\Http\Livewire;

use Livewire\Component;

/**
 * Livewire V2 Counter component.
 *
 * Uses V2 lifecycle hooks and un-typed public properties — migration targets for
 * the Livewire V2→V3 package rule set.
 */
class Counter extends Component
{
    public $count = 0;

    public $step = 1;

    public function mount(int $initialCount = 0, int $step = 1): void
    {
        $this->count = $initialCount;
        $this->step  = $step;
    }

    public function increment(): void
    {
        $this->count += $this->step;
    }

    public function decrement(): void
    {
        $this->count -= $this->step;
    }

    public function reset(): void
    {
        $this->count = 0;
    }

    public function hydrate(): void
    {
        // V2 lifecycle hook — dehydrate/hydrate pattern
    }

    public function dehydrate(): void
    {
        // V2 lifecycle hook
    }

    public function render()
    {
        return view('livewire.counter');
    }
}
