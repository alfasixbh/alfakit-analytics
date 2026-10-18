<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAnalytics\Http\Controllers;

use AlfaSix\Alfakit\Localization\TextDirection;
use AlfaSix\AlfakitAnalytics\AnalyticsPlugin;
use AlfaSix\AlfakitAnalytics\Models\Site;
use AlfaSix\AlfakitAnalytics\Support\Period;
use AlfaSix\AlfakitAnalytics\Support\Report;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\URL;

/**
 * `GET /alfakit-analytics/share/{site}?token=…&signature=…`: the read-only dashboard of a
 * shared site, rendered on the server (no panel, no sign-in). The link is signed and
 * carries the site's share token, so turning sharing off or rotating the token revokes
 * every link. Period and filter parameters are not part of the signature.
 */
class SharedDashboardController
{
    /**
     * Check the signature over the signed parameters only (`token`, `expires`): the period
     * and filters (`filter[source]=…`) may change freely.
     */
    public static function hasValidSignature(Request $request, int $site): bool
    {
        $signed = array_filter([
            'expires' => $request->query('expires'),
            'token' => $request->query('token'),
        ], static fn (mixed $value): bool => is_string($value) && $value !== '');
        $signature = $request->query('signature');

        if (! isset($signed['token']) || ! is_string($signature) || $signature === '') {
            return false;
        }

        // The parameters in the order `URL::signedRoute()` signed them (sorted), signature last.
        ksort($signed);
        $url = route('alfakit-analytics.share', ['site' => $site], absolute: true).'?'.http_build_query([...$signed, 'signature' => $signature]);

        return URL::hasValidSignature(Request::create($url));
    }

    public function __invoke(Request $request, int $site): Response
    {
        abort_unless(self::hasValidSignature($request, $site), 403);

        $model = Site::query()->find($site);
        $token = $request->query('token');

        abort_if(
            $model === null || ! $model->is_shared || $model->share_token === null || ! is_string($token) || ! hash_equals($model->share_token, $token),
            404,
        );

        $signed = ['site' => $model->getKey(), 'token' => $model->share_token];
        $expires = $request->query('expires');

        // Links keep their signature (and expiry) while the period and filters change.
        $report = Report::fromQuery($model, $request->query(), static function (array $query) use ($signed, $expires): string {
            $url = is_numeric($expires)
                ? URL::temporarySignedRoute('alfakit-analytics.share', (int) $expires, $signed)
                : URL::signedRoute('alfakit-analytics.share', $signed);

            return $query === [] ? $url : $url.'&'.http_build_query($query);
        });

        $panels = [];

        foreach (array_keys(Report::PANELS) as $panel) {
            $panels[$panel] = [];

            foreach ($report->panelDimensions($panel) as $dimension) {
                $panels[$panel][$dimension] = $report->breakdown($dimension, 10);
            }
        }

        return response()->view('alfakit-analytics::shared', [
            'report' => $report,
            'kpis' => $report->kpis(),
            'current' => $report->stats()->currentVisitors(),
            'chart' => $report->chart('visitors'),
            'panels' => $panels,
            'presets' => Period::PRESETS,
            'direction' => TextDirection::detect(app()->getLocale())->isRtl() ? 'rtl' : 'ltr',
            // "Powered by AlfaKit · Alfa Six" (`AnalyticsPlugin::poweredBy()`), off by default.
            'poweredBy' => AnalyticsPlugin::get()->getPoweredByHtml(),
        ])->withHeaders([
            'X-Robots-Tag' => 'noindex, nofollow',
            'Referrer-Policy' => 'no-referrer',
            'Cache-Control' => 'private, no-store',
            'X-Frame-Options' => 'SAMEORIGIN',
        ]);
    }
}
