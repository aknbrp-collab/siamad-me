<?php

namespace App\Providers;

use App\Enums\Role;
use App\Http\Middleware\EnsurePasswordIsChanged;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\EnsureUserIsActive;
use App\Models\User;
use App\Support\ApiDocs;
use App\Support\Branding;
use App\Support\Restores;
use App\Support\Upload;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Console\ServeCommand;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
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

        // The app name set on Tampilan aplikasi (v6 D36) for the browser tab and emails. Skipped
        // quietly when the database is not there yet (first install, some artisan commands).
        $this->app->booted(fn () => rescue(fn () => Branding::applyToConfig(), report: false));

        // API rate limits (v6 D37): login 5 a minute per ID number and address; 60 a minute per token.
        RateLimiter::for('api-login', fn (Request $request) => Limit::perMinute(5)->by(mb_strtolower((string) $request->input('username')).'|'.$request->ip()));
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(60)->by($request->user()?->currentAccessToken()?->getKey() ?? $request->ip()));

        // API docs (/docs/api, v6 D38): Superadmin, or anyone when API_DOCS_PUBLIC=true.
        ApiDocs::configure();
        Gate::define('viewApiDocs', fn (?User $user = null) => (bool) config('siakad.api_docs_public') || ($user?->hasRole(Role::Superadmin) ?? false));

        // Livewire's temporary upload takes the largest file any field allows (a backup to restore may be
        // larger than the usual limit, data-manage D51); every field still checks its own limit.
        config(['livewire.temporary_file_upload.rules' => ['required', 'file', 'max:'.max(Upload::maxKb(), Restores::maxMb() * 1024)]]);

        // `artisan serve` starts PHP with only a few environment variables. Without TEMP/TMP, PHP on
        // Windows stores uploads in C:\Windows, which it may not write, and every upload fails (v7 D42).
        if ($this->app->runningInConsole()) {
            ServeCommand::$passthroughVariables = array_values(array_unique([...ServeCommand::$passthroughVariables, ...Upload::TEMP_VARIABLES]));
        }
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
