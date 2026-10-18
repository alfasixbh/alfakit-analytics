<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAnalytics\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * `GET /alfakit-analytics/script.js`: the tracking snippet (under 2 KB, cached by browsers
 * for a day).
 */
class ScriptController
{
    public static function path(): string
    {
        return dirname(__DIR__, 3).'/resources/js/script.js';
    }

    public function __invoke(Request $request): Response
    {
        $script = (string) file_get_contents(self::path());
        $etag = '"'.substr(sha1($script), 0, 16).'"';

        if ($request->headers->get('If-None-Match') === $etag) {
            return response('', 304)->header('ETag', $etag);
        }

        return response($script, 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'public, max-age=86400',
            'ETag' => $etag,
            'X-Content-Type-Options' => 'nosniff',
            'Access-Control-Allow-Origin' => '*',
        ]);
    }
}
