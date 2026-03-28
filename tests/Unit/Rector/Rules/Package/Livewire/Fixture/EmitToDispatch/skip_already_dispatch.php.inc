<?php

// Already uses dispatch() — must not be changed (idempotent).
class OrderComponent
{
    public function placeOrder(): void
    {
        $this->dispatch('order.placed', $this->orderId);
        $this->dispatch('refreshComponent');
    }
}
