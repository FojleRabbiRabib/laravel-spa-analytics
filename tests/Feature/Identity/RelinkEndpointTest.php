<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\Identity;

use FojleRabbiRabib\LaravelSpaAnalytics\Enums\IdentitySource;
use FojleRabbiRabib\LaravelSpaAnalytics\Events\VisitorIdentified;
use FojleRabbiRabib\LaravelSpaAnalytics\Events\VisitorRelinked;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\Event;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\Session;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\VisitorFingerprint;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\VisitorLink;
use FojleRabbiRabib\LaravelSpaAnalytics\Tests\Support\IdentifiesVisitors;
use FojleRabbiRabib\LaravelSpaAnalytics\Tests\TestCase;
use Illuminate\Support\Facades\Event as EventFacade;

class RelinkEndpointTest extends TestCase
{
    use IdentifiesVisitors;

    private const OLD = '11111111-1111-4111-8111-111111111111';

    private const NEW = '22222222-2222-4222-8222-222222222222';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('spa-analytics.identity.relink', true);
    }

    public function test_a_first_time_fingerprint_matching_one_known_visitor_is_relinked(): void
    {
        $this->identifyAs(self::OLD)->assertOk();
        Event::factory()->count(2)->create(['visitor_id' => self::NEW]);
        Session::factory()->create(['visitor_id' => self::NEW]);

        EventFacade::fake([VisitorRelinked::class, VisitorIdentified::class]);

        $response = $this->identifyAs(self::NEW);

        $response->assertOk()->assertExactJson([
            'id' => self::OLD,
            'source' => IdentitySource::Relinked->value,
        ]);
        $this->assertSame(self::OLD, $response->getCookie('spa_analytics_vid')->getValue());

        $this->assertSame(2, Event::query()->where('visitor_id', self::OLD)->count());
        $this->assertSame(self::OLD, Session::query()->sole()->visitor_id);
        $this->assertSame(self::OLD, VisitorLink::query()->where('visitor_id', self::NEW)->value('linked_to'));

        EventFacade::assertDispatched(VisitorRelinked::class, fn (VisitorRelinked $event): bool => $event->previousId === self::NEW && $event->visitorId === self::OLD);
        EventFacade::assertDispatched(VisitorIdentified::class, fn (VisitorIdentified $event): bool => $event->identity->id === self::OLD
            && $event->identity->source === IdentitySource::Relinked
            && $event->identity->fingerprint !== null);
    }

    public function test_the_adopted_visitor_keeps_a_single_fingerprint_row(): void
    {
        $this->identifyAs(self::OLD);
        $this->identifyAs(self::NEW);

        $this->assertSame(1, VisitorFingerprint::query()->count());
        $this->assertSame(self::OLD, VisitorFingerprint::query()->sole()->visitor_id);
    }

    public function test_two_matching_visitors_are_ambiguous_so_the_new_id_stays(): void
    {
        config()->set('spa-analytics.identity.relink', false);
        $this->identifyAs(self::OLD);
        $this->identifyAs('33333333-3333-4333-8333-333333333333');
        config()->set('spa-analytics.identity.relink', true);

        $this->assertSame(2, VisitorFingerprint::query()->count());

        $this->identifyAs(self::NEW)->assertOk()->assertExactJson([
            'id' => self::NEW,
            'source' => IdentitySource::Cookie->value,
        ]);
        $this->assertSame(0, VisitorLink::query()->count());
    }

    public function test_relinking_off_leaves_the_identity_alone(): void
    {
        config()->set('spa-analytics.identity.relink', false);
        $this->identifyAs(self::OLD);

        $this->identifyAs(self::NEW)->assertOk()->assertJsonPath('id', self::NEW);
    }

    public function test_a_different_device_is_not_relinked(): void
    {
        $this->identifyAs(self::OLD);

        $this->identifyAs(self::NEW, ['screenWidth' => 800, 'screenHeight' => 600])
            ->assertOk()
            ->assertJsonPath('id', self::NEW);
    }
}
