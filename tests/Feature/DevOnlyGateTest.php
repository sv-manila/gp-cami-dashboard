<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Replaces the Laravel scaffold test, which asserted GET / returns 200 and
 * therefore always failed: DevOnly only allows the local/development
 * environments, and the suite runs as APP_ENV=testing.
 *
 * Widening DevOnly to allow "testing" would be the wrong fix. phpunit.xml does
 * not override GP_DB_HOST / SRC_DB_HOST, so a feature test that got through the
 * gate would connect to the real shared hub on app2.streamlineverify.local. The
 * gate failing closed under an unrecognised environment is the behaviour worth
 * locking in, so that is what these tests assert.
 */
class DevOnlyGateTest extends TestCase
{
    public function test_dashboard_is_forbidden_outside_dev_environments(): void
    {
        // The suite runs as APP_ENV=testing, which is not local/development.
        $this->assertSame('testing', app()->environment());

        $this->get('/')->assertStatus(403);
    }

    public function test_every_dashboard_route_is_behind_the_gate(): void
    {
        $uris = [
            '/',
            '/profile/1',
            '/stats/exact/gp_identity',
            '/match/credential/1',
            '/match/exclusion/1',
            '/features',
        ];

        foreach ($uris as $uri) {
            $this->get($uri)->assertStatus(403, "$uri should be gated by DevOnly");
        }
    }

    public function test_gate_allows_recognised_dev_environments(): void
    {
        // Guards against the gate being tightened to the point of blocking the
        // environments it exists to serve. No request is made, so no DB is touched.
        $middleware = new \App\Http\Middleware\DevOnly;

        foreach (['local', 'development'] as $env) {
            app()['env'] = $env;
            $passed = false;
            $middleware->handle(request(), function () use (&$passed) {
                $passed = true;

                return response('ok');
            });
            $this->assertTrue($passed, "DevOnly should allow the $env environment");
        }

        app()['env'] = 'testing';
    }
}
