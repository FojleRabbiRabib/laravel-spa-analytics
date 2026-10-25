<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Services\Tracking;

class ClientScript
{
    /**
     * Where the assets tag publishes the script, relative to the public path.
     */
    public const PATH = 'vendor/laravel-spa-analytics/client.js';

    /**
     * The URL of the published script with a hash of its content, read on every call so a re-published script gets a
     * new URL at once. Without a readable published file the plain URL is returned; a page never fails over the hash.
     */
    public function url(): string
    {
        $url = asset(self::PATH);
        $version = $this->version();

        return $version === null ? $url : $url.'?v='.$version;
    }

    private function version(): ?string
    {
        $file = public_path(self::PATH);

        if (! is_file($file)) {
            return null;
        }

        try {
            $hash = hash_file('xxh128', $file);
        } catch (\Throwable) {
            return null;
        }

        return $hash === false ? null : substr($hash, 0, 12);
    }
}
