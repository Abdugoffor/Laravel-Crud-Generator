<?php
namespace DataTable\CrudGenerator;

use Illuminate\Support\ServiceProvider;

class CrudGeneratorServiceProvider extends ServiceProvider
{
    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                SimpleCrud::class,
                SimpleApiCrud::class
            ]);
        }
    }

    public function register()
    {
        //
    }
}