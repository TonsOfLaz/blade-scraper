<?php

namespace TonsOfLaz\BladeScraper;

use Illuminate\Support\ServiceProvider;

class BladeScraperServiceProvider extends ServiceProvider
{
    /**
     * Perform post-registration booting of services.
     *
     * @return void
     */
    public function boot()
    {
        //
    }

    /**
     * Register any package services.
     *
     * @return void
     */
    public function register()
    {
        $this->registerBladeScraper();
    }
    private function registerBladeScraper()
    {
        $this->app->bind('BladeScraper',function($app){
            return new BladeScraper($app);
        });
    }
}