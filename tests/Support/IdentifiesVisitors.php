<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Support;

use Illuminate\Testing\TestResponse;

trait IdentifiesVisitors
{
    /**
     * Run the handshake and identify calls for a visitor cookie, sending the sample device signals.
     *
     * @param  array<string, int|string>  $signals
     */
    protected function identifyAs(string $visitor, array $signals = []): TestResponse
    {
        $handshake = $this->withCredentials()->withCookie('spa_analytics_vid', $visitor)
            ->postJson(route('spa-analytics.identity.handshake'))
            ->assertOk()
            ->json();

        $body = PayloadEncoder::body(
            PayloadEncoder::plaintext(PayloadEncoder::sample($signals)),
            $handshake['nonce'],
            $handshake['key'],
        );

        $this->withCookie('spa_analytics_vid', $visitor);

        return $this->call(
            'POST',
            route('spa-analytics.identity.identify'),
            [],
            $this->prepareCookiesForRequest(),
            [],
            ['CONTENT_TYPE' => 'application/octet-stream'],
            $body,
        );
    }
}
