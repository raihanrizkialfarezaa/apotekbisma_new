<?php

namespace App\Providers;

use App\Models\Setting;
use App\Models\Produk;
use App\Models\RekamanStok;
use App\Observers\ProdukObserver;
use App\Observers\RekamanStokObserver;
use Carbon\Carbon;
use Illuminate\Support\ServiceProvider;
// use Illuminate\Support\Facades\URL;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        view()->composer('layouts.master', function ($view) {
            $view->with('setting', $this->cachedSetting());
        });
        view()->composer('layouts.auth', function ($view) {
            $view->with('setting', $this->cachedSetting());
        });
        view()->composer('auth.login', function ($view) {
            $view->with('setting', $this->cachedSetting());
        });
    }

    private function cachedSetting()
    {
        return \Illuminate\Support\Facades\Cache::remember(
            'setting_single',
            3600,
            fn () => Setting::first()
        );
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        config(['app.locale' => 'id']);
        Carbon::setLocale('id');
        date_default_timezone_set('Asia/Jakarta');

        // URL::forceScheme('https');
        
        Setting::saved(function () {
            \Illuminate\Support\Facades\Cache::forget('setting_single');
        });
        
        // Daftarkan observer untuk memastikan stok tidak pernah minus
        Produk::observe(ProdukObserver::class);
        
        // Observer untuk validasi konsistensi rekaman stok
        RekamanStok::observe(RekamanStokObserver::class);
    }
}
