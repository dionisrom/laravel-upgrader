<?php

use Illuminate\Database\Eloquent\Model;

Model::unguard();

$user = new \App\Models\User();
$user->fill(['name' => 'Alice', 'email' => 'alice@example.com']);

Model::reguard();
-----
<?php

use Illuminate\Database\Eloquent\Model;

$user = new \App\Models\User();
$user->fill(['name' => 'Alice', 'email' => 'alice@example.com']);
