<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(
            \App\Interfaces\ProductRepositoryInterface::class,
            \App\Repositories\ProductRepository::class
        );
        $this->app->bind(
            \App\Interfaces\HoldRepositoryInterface::class,
            \App\Repositories\HoldRepository::class
        );
        $this->app->bind(
            \App\Interfaces\IdempotencyRepositoryInterface::class,
            \App\Repositories\IdempotencyRepository::class
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
