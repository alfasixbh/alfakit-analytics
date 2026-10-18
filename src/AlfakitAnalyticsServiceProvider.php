<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAnalytics;

use AlfaSix\AlfakitAnalytics\Commands\AggregateCommand;
use AlfaSix\AlfakitAnalytics\Contracts\ResolvesCountry;
use AlfaSix\AlfakitAnalytics\Http\Controllers\CollectController;
use AlfaSix\AlfakitAnalytics\Http\Controllers\ScriptController;
use AlfaSix\AlfakitAnalytics\Http\Controllers\SharedDashboardController;
use AlfaSix\AlfakitAnalytics\Http\Middleware\TrackPageViews;
use AlfaSix\AlfakitAnalytics\Support\HeaderCountryResolver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * The public side of the plugin: the collection endpoint, the snippet, shared dashboards,
 * the `@alfakitAnalytics` directive, the `alfakit-analytics` middleware and the hourly
 * aggregation. The panel dashboard is registered by `AnalyticsPlugin`.
 */
class AlfakitAnalyticsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(dirname(__DIR__).'/config/alfakit-analytics.php', 'alfakit-analytics');

        $this->app->bindIf(ResolvesCountry::class, HeaderCountryResolver::class);
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(dirname(__DIR__).'/resources/lang', 'alfakit-analytics');
        $this->loadViewsFrom(dirname(__DIR__).'/resources/views', 'alfakit-analytics');

        RateLimiter::for('alfakit-analytics', static fn (Request $request): Limit => Limit::perMinute(max(1, (int) config('alfakit-analytics.rate_limit', 120)))
            // The limiter key is a hash, so the IP address isn't kept in the cache either.
            ->by(hash('sha256', 'alfakit-analytics|'.$request->ip())));

        if (! $this->app->routesAreCached()) {
            $this->registerRoutes();
        }

        $this->app->make(Router::class)->aliasMiddleware('alfakit-analytics', TrackPageViews::class);

        Blade::directive('alfakitAnalytics', static fn (string $expression): string => '<?php echo \\'.Analytics::class.'::script('.$expression.'); ?>');

        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            if (config('alfakit-analytics.schedule', true)) {
                $schedule->command('alfakit-analytics:aggregate')->hourlyAt(5)->withoutOverlapping();
            }
        });

        if ($this->app->runningInConsole()) {
            $this->commands([AggregateCommand::class]);

            $this->publishes([
                dirname(__DIR__).'/config/alfakit-analytics.php' => config_path('alfakit-analytics.php'),
            ], 'alfakit-analytics-config');

            $this->publishesMigrations([
                dirname(__DIR__).'/database/migrations' => database_path('migrations'),
            ], 'alfakit-analytics-migrations');

            $this->publishes([
                dirname(__DIR__).'/resources/lang' => $this->app->langPath('vendor/alfakit-analytics'),
            ], 'alfakit-analytics-translations');
        }
    }

    protected function registerRoutes(): void
    {
        $prefix = trim((string) config('alfakit-analytics.route_prefix', 'alfakit-analytics'), '/');
        $middleware = array_values(array_filter((array) config('alfakit-analytics.middleware', []), is_string(...)));

        Route::prefix($prefix)->middleware($middleware)->group(static function (): void {
            Route::post('/event', CollectController::class)
                ->middleware('throttle:alfakit-analytics')
                ->name(Analytics::ROUTE_COLLECT);
            Route::options('/event', [CollectController::class, 'preflight']);
            Route::get('/script.js', ScriptController::class)->name(Analytics::ROUTE_SCRIPT);
            Route::get('/share/{site}', SharedDashboardController::class)
                ->whereNumber('site')
                ->middleware('throttle:60,1')
                ->name(Analytics::ROUTE_SHARE);
        });
    }
}
