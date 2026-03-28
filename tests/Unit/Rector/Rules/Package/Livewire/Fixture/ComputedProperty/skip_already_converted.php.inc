<?php

// Already has #[Computed] — must not be double-converted (idempotent guard).
class OrderSummary extends Component
{
    #[\Livewire\Attributes\Computed]
    public function total(): float
    {
        return $this->items->sum('price');
    }
}
