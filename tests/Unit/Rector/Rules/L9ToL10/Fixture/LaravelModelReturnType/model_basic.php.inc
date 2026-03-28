<?php

class User extends Model
{
    public function toArray()
    {
        return ['extra' => true];
    }

    protected static function boot()
    {
        // boot logic
    }
}
-----
<?php

class User extends Model
{
    public function toArray(): array
    {
        return ['extra' => true];
    }

    protected static function boot(): void
    {
        // boot logic
    }
}
