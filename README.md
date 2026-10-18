# AlfaKit Analytics

<p><picture><source media="(prefers-color-scheme: dark)" srcset="../../../art/alfasix/alfa-six-micro-white.svg"><img src="../../../art/alfasix/alfa-six-micro-navy.svg" alt="" width="24" height="18" align="top"></picture> <sub>by <a href="https://alfasixbh.com">Alfa Six</a></sub></p>

Privacy-first web analytics for the public site of your Laravel app, viewed in an [AlfaKit](https://alfakit.dev) panel, in the spirit of Plausible and Fathom: no cookies, no storage, no persistent identifiers, and no IP addresses stored. It renders in React, Vue and Svelte.

## Installation

```bash
composer require alfasix/alfakit-analytics
php artisan vendor:publish --tag=alfakit-analytics-migrations
php artisan migrate
```

## Register

```php
use AlfaSix\AlfakitAnalytics\AnalyticsPlugin;

$panel->plugin(
    AnalyticsPlugin::make()
        ->authorize(fn (User $user): bool => $user->is_admin)
        ->navigationGroup('Marketing'),
);
```

Add a site under **Analytics → Sites**, then put its snippet on your pages:

```blade
@alfakitAnalytics('your-site-key')
```

or, on Inertia pages, `<AlfakitAnalytics siteKey="your-site-key" />` from `@alfakit/react`, `@alfakit/vue` or `@alfakit/svelte`. Track events from the browser (`trackAnalyticsEvent('Signup', { plan: 'pro' })`, `window.alfakit.track(…)`) or the server:

```php
use AlfaSix\AlfakitAnalytics\Analytics;

Analytics::track('Signup', ['plan' => 'pro']);
```

Make sure the Laravel scheduler runs: the plugin aggregates daily rollups hourly.

## Features

- Snippet under 2 KB, React / Vue / Svelte components, server-side tracking and a page-view middleware
- Do Not Track and Global Privacy Control respected, bot filtering, rate-limited stateless endpoint
- Daily-rotating salted visitor hashes; old salts are deleted
- Sources, channels, referrers, UTM campaigns, countries (optional resolver), devices, browsers, OS
- Dashboard with visitors now, KPIs, a chart, comparison with the previous period and click-to-filter top lists
- Goals (page or event) with conversion rates, custom event property breakdowns
- Daily rollups with retention, on MySQL, PostgreSQL and SQLite
- Tenant-scoped sites, `authorize()` and policies, CSV / XLSX exports, signed shared dashboards

See the [documentation](https://alfakit.dev/docs/plugins/analytics), including what is and isn't collected.

## License

MIT. See [LICENSE.md](LICENSE.md).
