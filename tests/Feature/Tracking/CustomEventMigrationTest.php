<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\Tracking;

use FojleRabbiRabib\LaravelSpaAnalytics\Tests\TestCase;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CustomEventMigrationTest extends TestCase
{
    private function migration(): Migration
    {
        return include __DIR__.'/../../../database/migrations/update_analytics_events_table_for_custom_events.php.stub';
    }

    /**
     * Rebuild analytics_events exactly as the 0.1.0 migration created it.
     */
    private function installVersionOneSchema(): void
    {
        Schema::dropIfExists('analytics_events');

        Schema::create('analytics_events', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['page_view'])->default('page_view');
            $table->string('visitor_id', 36);
            $table->unsignedBigInteger('session_id')->nullable();
            $table->string('path', 512);
            $table->unsignedSmallInteger('status');
            $table->string('referrer_host')->nullable();
            $table->enum('referrer_type', ['direct', 'search', 'social', 'referral', 'campaign'])->default('direct');
            $table->string('utm_source')->nullable();
            $table->string('utm_medium')->nullable();
            $table->string('utm_campaign')->nullable();
            $table->string('utm_term')->nullable();
            $table->string('utm_content')->nullable();
            $table->string('language', 16)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->boolean('is_bot')->default(false);
            $table->timestamp('occurred_at');
            $table->json('properties')->nullable();

            $table->index('session_id');
            $table->index('occurred_at');
            $table->index(['visitor_id', 'occurred_at']);
            $table->index(['path', 'occurred_at']);
            $table->index(['type', 'occurred_at']);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function row(array $overrides = []): array
    {
        return [
            'visitor_id' => 'visitor',
            'occurred_at' => '2026-03-02 09:00:00',
            ...$overrides,
        ];
    }

    public function test_a_fresh_install_has_the_custom_event_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('analytics_events', ['name', 'value']));

        DB::table('analytics_events')->insert($this->row(['type' => 'goal', 'name' => 'purchase', 'value' => 49.5]));
        DB::table('analytics_events')->insert($this->row(['type' => 'custom', 'name' => 'clicked']));

        $this->assertSame(2, DB::table('analytics_events')->whereNull('path')->whereNull('status')->count());
    }

    public function test_the_upgrade_keeps_existing_page_views_and_allows_custom_rows(): void
    {
        $this->installVersionOneSchema();
        DB::table('analytics_events')->insert($this->row(['type' => 'page_view', 'path' => '/pricing', 'status' => 200]));

        $this->migration()->up();

        $this->assertTrue(Schema::hasColumns('analytics_events', ['name', 'value']));
        $this->assertSame('/pricing', DB::table('analytics_events')->value('path'));

        DB::table('analytics_events')->insert($this->row(['type' => 'goal', 'name' => 'purchase', 'value' => 10]));

        $this->assertSame(2, DB::table('analytics_events')->count());
        $this->assertSame('page_view', DB::table('analytics_events')->where('path', '/pricing')->value('type'));
    }

    public function test_down_removes_only_the_new_columns_and_keeps_rows(): void
    {
        DB::table('analytics_events')->insert($this->row(['type' => 'goal', 'name' => 'purchase']));

        $this->migration()->down();

        $this->assertFalse(Schema::hasColumn('analytics_events', 'name'));
        $this->assertFalse(Schema::hasColumn('analytics_events', 'value'));
        $this->assertSame(1, DB::table('analytics_events')->count());
    }
}
