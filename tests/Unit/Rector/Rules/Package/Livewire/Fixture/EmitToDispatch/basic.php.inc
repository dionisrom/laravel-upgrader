<?php

use Livewire\Component;

class OrderComponent extends Component
{
    public function placeOrder(): void
    {
        $this->emit('order.placed', $this->orderId);
        $this->emitSelf('refreshComponent');
    }
}
-----
<?php

use Livewire\Component;

class OrderComponent extends Component
{
    public function placeOrder(): void
    {
        $this->dispatch('order.placed', $this->orderId);
        $this->dispatch('refreshComponent');
    }
}
