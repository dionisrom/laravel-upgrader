<?php

class OrderSummary extends Component
{
    public function getTotalProperty(): float
    {
        return $this->items->sum('price');
    }

    public function getStatusProperty(): string
    {
        return $this->isPaid ? 'paid' : 'pending';
    }
}
-----
<?php

class OrderSummary extends Component
{
    #[\Livewire\Attributes\Computed]
    public function total(): float
    {
        return $this->items->sum('price');
    }

    #[\Livewire\Attributes\Computed]
    public function status(): string
    {
        return $this->isPaid ? 'paid' : 'pending';
    }
}
