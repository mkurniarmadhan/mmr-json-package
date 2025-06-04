<?php

namespace Mkurniarmadhan\MmrJsonPackage;

use Illuminate\Support\ServiceProvider;

class GenerateFromJsonServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->commands([
            Commands\GenerateFromJson::class,
        ]);
    }

    public function boot()
    {
        //
    }
}
