<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAnalytics\Http\Controllers;

use AlfaSix\AlfakitAnalytics\Models\Site;
use AlfaSix\AlfakitAnalytics\Support\Hit;
use AlfaSix\AlfakitAnalytics\Support\Recorder;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;

/**
 * `POST /alfakit-analytics/event`: a page view or custom event from the snippet. The body
 * is JSON (sent as `text/plain` by `sendBeacon`, so no CORS preflight is needed):
 * `{k: site key, n: event name, u: page URL, r: referrer, p: {properties}}`.
 *
 * Stateless (no session, no cookies, no CSRF token) and rate limited per IP address.
 * Accepted hits answer 202, also when they are dropped (bots, Do Not Track, foreign
 * hostnames), so the response never tells a script whether it was counted.
 */
class CollectController
{
    /** Largest request body, in bytes. */
    public const MAX_BODY = 8192;

    public function __invoke(Request $request, Recorder $recorder): Response|JsonResponse
    {
        $body = $request->getContent();

        if (strlen($body) > self::MAX_BODY) {
            return $this->cors(response()->json(['message' => 'Payload too large.'], 413));
        }

        $payload = json_decode($body, true);

        if (! is_array($payload)) {
            $payload = $request->all();
        }

        $max = max(0, (int) config('alfakit-analytics.max_properties', 30));

        $validator = Validator::make($payload, [
            'k' => ['required', 'string', 'max:32'],
            'n' => ['nullable', 'string', 'max:'.Recorder::MAX_NAME_LENGTH],
            'u' => ['required', 'string', 'max:4096', 'regex:#^https?://#i'],
            'r' => ['nullable', 'string', 'max:4096'],
            'p' => ['nullable', 'array', 'max:'.$max],
            'p.*' => ['nullable', static function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_scalar($value)) {
                    $fail('Properties must be strings, numbers or booleans.');
                }
            }],
        ]);

        if ($validator->fails()) {
            return $this->cors(response()->json(['message' => 'Invalid hit.', 'errors' => $validator->errors()], 422));
        }

        /** @var array{k: string, n?: string|null, u: string, r?: string|null, p?: array<string, scalar|null>|null} $data */
        $data = $validator->validated();
        $site = Site::query()->where('key', $data['k'])->first();

        if ($site === null) {
            return $this->cors(response()->json(['message' => 'Unknown site.'], 404));
        }

        $recorder->record($site, Hit::fromRequest(
            $request,
            name: $data['n'] ?? 'pageview',
            url: $data['u'],
            referrer: $data['r'] ?? null,
            properties: $data['p'] ?? [],
        ));

        return $this->cors(response('', 202));
    }

    /**
     * The CORS preflight of browsers that send JSON (`fetch` with a content type).
     */
    public function preflight(): Response
    {
        return $this->cors(response('', 204))
            ->header('Access-Control-Allow-Methods', 'POST, OPTIONS')
            ->header('Access-Control-Allow-Headers', 'Content-Type')
            ->header('Access-Control-Max-Age', '86400');
    }

    /**
     * @template T of Response|JsonResponse
     *
     * @param  T  $response
     * @return T
     */
    protected function cors(Response|JsonResponse $response): Response|JsonResponse
    {
        $response->headers->set('Access-Control-Allow-Origin', '*');
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
