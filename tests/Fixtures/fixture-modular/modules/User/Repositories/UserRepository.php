<?php

namespace Modules\User\Repositories;

use Modules\User\Models\User;

final class UserRepository
{
    public function make(): User
    {
        return new User();
    }
}