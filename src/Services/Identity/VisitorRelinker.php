<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Services\Identity;

use FojleRabbiRabib\LaravelSpaAnalytics\Data\Identity\Fingerprint;
use FojleRabbiRabib\LaravelSpaAnalytics\Data\Identity\VisitorIdentity;
use FojleRabbiRabib\LaravelSpaAnalytics\Events\VisitorRelinked;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsEvent;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsSession;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\VisitorFingerprint;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\VisitorLink;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Tracking\SessionMerger;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class VisitorRelinker
{
    private const CANDIDATE_CAP = 50;

    public function __construct(
        private readonly FingerprintMatcher $matcher,
        private readonly SessionMerger $merger,
        private readonly int $lockWaitSeconds = 3,
    ) {}

    /**
     * Adopt the id of the single known visitor matching a first-time fingerprint.
     *
     * Returns the adopted id, or null when re-linking is off, the visitor was already identified, or the
     * match is missing or ambiguous (zero, several, or more candidates than the cap). A visitor that was
     * already re-linked gets the same id back without moving rows or dispatching VisitorRelinked again.
     * Sessions that now fall within the timeout of each other are merged.
     */
    public function relink(VisitorIdentity $identity): ?string
    {
        $fingerprint = $identity->fingerprint;

        if ($fingerprint === null || ! config('spa-analytics.identity.relink')) {
            return null;
        }

        if (VisitorFingerprint::query()->where('visitor_id', $identity->id)->exists()) {
            return null;
        }

        $adopted = $this->linkedTo($identity->id) ?? $this->singleMatch($identity->id, $fingerprint);

        if ($adopted === null) {
            return null;
        }

        $fresh = false;

        $adopted = Cache::lock('session:'.$identity->id, 10)->block($this->lockWaitSeconds, function () use ($identity, $adopted, &$fresh): string {
            return Cache::lock('session:'.$adopted, 10)->block($this->lockWaitSeconds, function () use ($identity, $adopted, &$fresh): string {
                $existing = $this->linkedTo($identity->id);

                if ($existing !== null) {
                    return $existing;
                }

                DB::transaction(function () use ($identity, $adopted): void {
                    $moved = AnalyticsSession::query()->where('visitor_id', $identity->id)->pluck('id')->all();

                    AnalyticsEvent::query()->where('visitor_id', $identity->id)->update(['visitor_id' => $adopted]);
                    AnalyticsSession::query()->where('visitor_id', $identity->id)->update([
                        'visitor_id' => $adopted,
                        'is_new_visitor' => false,
                    ]);

                    $this->merger->merge($adopted, $moved);

                    VisitorLink::query()->create(['visitor_id' => $identity->id, 'linked_to' => $adopted, 'created_at' => now()]);
                    VisitorLink::query()->where('linked_to', $identity->id)->update(['linked_to' => $adopted]);
                });

                $fresh = true;

                return $adopted;
            });
        });

        if ($fresh) {
            VisitorRelinked::dispatch($identity->id, $adopted);
        }

        return $adopted;
    }

    private function linkedTo(string $visitorId): ?string
    {
        $linkedTo = VisitorLink::query()->where('visitor_id', $visitorId)->value('linked_to');

        return is_string($linkedTo) ? $linkedTo : null;
    }

    private function singleMatch(string $currentId, Fingerprint $fingerprint): ?string
    {
        $candidates = VisitorFingerprint::query()
            ->where('stable_hash', $fingerprint->stableHash)
            ->where('visitor_id', '!=', $currentId)
            ->limit(self::CANDIDATE_CAP + 1)
            ->get();

        if ($candidates->count() > self::CANDIDATE_CAP) {
            return null;
        }

        $matches = $candidates->filter(fn (VisitorFingerprint $row): bool => $this->matcher->matches(
            $fingerprint,
            new Fingerprint($row->stable_hash, $row->canvas_hash, $row->audio_hash, $row->webgl_hash, $row->tls_hash),
        ));

        return $matches->count() === 1 ? $matches->first()->visitor_id : null;
    }
}
