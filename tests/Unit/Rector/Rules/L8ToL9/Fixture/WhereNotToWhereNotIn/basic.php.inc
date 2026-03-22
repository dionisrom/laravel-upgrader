<?php

use Illuminate\Validation\Rule;

$rules = [
    'email' => Rule::unique('users', 'email')->whereNot('status', 'inactive'),
];
-----
<?php

use Illuminate\Validation\Rule;

$rules = [
    'email' => Rule::unique('users', 'email')->whereNotIn('status', ['inactive']),
];
