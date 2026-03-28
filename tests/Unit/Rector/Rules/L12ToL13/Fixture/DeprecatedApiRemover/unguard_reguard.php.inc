<?php

use Illuminate\Database\Eloquent\Model;

Model::unguard();
$order->fill($data);
Model::reguard();
-----
<?php

use Illuminate\Database\Eloquent\Model;
$order->fill($data);
