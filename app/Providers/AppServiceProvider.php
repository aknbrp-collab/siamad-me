<?php

namespace App\Providers;

use App\Http\Middleware\EnsurePasswordIsChanged;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\EnsureUserIsActive;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        // Re-apply account and role checks to Livewire update requests,
        // not only to the page load.
        Livewire::addPersistentMiddleware([
            EnsureUserIsActive::class,
            EnsurePasswordIsChanged::class,
            EnsureRole::class,
        ]);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        Model::preventLazyLoading(! app()->isProduction());

        // PDFs (receipts): embed only the glyphs used (~30 KB instead of
        // ~1.3 MB each). Subsetting writes temp files, and the system temp dir
        // can be empty under some Windows web servers, so use our own. Set
        // here because dompdf reads these when the PDF is created.
        config([
            'dompdf.options.enable_font_subsetting' => true,
            'dompdf.options.temp_dir' => storage_path('framework/cache'),
        ]);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
