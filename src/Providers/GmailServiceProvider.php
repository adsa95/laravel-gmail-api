<?php

namespace MartijnWagena\Gmail\Providers;

use Illuminate\Support\ServiceProvider;

class GmailServiceProvider extends ServiceProvider {

    public function boot()
    {
        $this->publishes([
            __DIR__ . '/../../config/gmail.php' => config_path('gmail.php'),
        ], 'config');
    }

    public function register()
    {
        $this->mergeConfigFrom(
            __DIR__.'/../../config/gmail.php', 'gmail'
        );
    }

    public function provides()
    {
        return ['gmail'];
    }


}