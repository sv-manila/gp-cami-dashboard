<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Runs the documented v1 endpoints from the docs page, so the examples are
 * executable instead of copy-paste-into-curl.
 *
 * The call is proxied server-side rather than made from the browser: the gp-cami
 * app is a different origin with no CORS allowance for this one, and a bearer
 * token typed into a form should not end up in a cross-origin request the page
 * can be tricked into replaying.
 */
class ApiPlaygroundController extends Controller
{
    /**
     * Only the two endpoints the docs describe. An open proxy taking an
     * arbitrary path would let this route reach anything the dashboard host can
     * see, which is a much larger surface than the page it lives on.
     */
    private const ENDPOINTS = [
        'identity-search'   => '/api/v1/identity-search',
        'credential-search' => '/api/v1/credential-search',
    ];

    private const TIMEOUT_SECONDS = 30;

    public function proxy(Request $request)
    {
        $validated = $request->validate([
            'endpoint' => ['required', 'string', 'in:' . implode(',', array_keys(self::ENDPOINTS))],
            'token'    => ['nullable', 'string', 'max:500'],
            'body'     => ['required', 'string', 'max:20000'],
        ]);

        $payload = json_decode($validated['body'], true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return response()->json([
                'error' => 'Request body is not valid JSON: ' . json_last_error_msg(),
            ], 422);
        }

        $url = rtrim(config('gpcami.api_base'), '/') . self::ENDPOINTS[$validated['endpoint']];
        $started = microtime(true);

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->acceptJson()
                ->when($validated['token'] ?? null, fn ($c, $t) => $c->withToken($t))
                ->post($url, $payload);
        } catch (\Throwable $e) {
            // The exception message carries the target host, which is fine here
            // — it is the value the operator typed into GPCAMI_API_BASE — but
            // the token must never appear in it.
            return response()->json([
                'error' => 'Could not reach the gp-cami API at ' . $url . '. Is it running? (' . class_basename($e) . ')',
            ], 502);
        }

        return response()->json([
            'status'  => $response->status(),
            'ms'      => (int) round((microtime(true) - $started) * 1000),
            'url'     => $url,
            'body'    => $response->json() ?? $response->body(),
        ]);
    }
}
