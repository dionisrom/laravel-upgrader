<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Livewire::component('counter', \App\Http\Livewire\Counter::class);
        Livewire::component('contact-form', \App\Http\Livewire\ContactForm::class);
        Livewire::component('data-table', \App\Http\Livewire\DataTable::class);
    }
}
