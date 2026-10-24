<?php

namespace App\Providers;

use App\Http\Middleware\Permiso;
use App\Http\Middleware\SinCampo;
use App\Http\Middleware\SoloAdmin;
use App\Http\Middleware\SoloMasume;
use App\Http\Middleware\SoloNed;
use App\Services\Ia\ExtractorOc;
use App\Services\Ia\OpenAiExtractorOc;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(ExtractorOc::class, OpenAiExtractorOc::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Las acciones de Livewire (/livewire/update) también respetan quién puede entrar a cada módulo.
        Livewire::addPersistentMiddleware([
            SoloMasume::class,
            SoloNed::class,
            SinCampo::class,
            // Si a alguien le quitan el rol de admin o un permiso con la pantalla abierta, sus acciones también se rechazan.
            SoloAdmin::class,
            Permiso::class,
        ]);
    }
}
