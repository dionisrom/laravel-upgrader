<?php

use App\Models\Order;

// This is a regular method call, not Model::unguard() — should be skipped.
$order->fill($data);
$order->save();
Order::query()->where('id', 1)->first();
