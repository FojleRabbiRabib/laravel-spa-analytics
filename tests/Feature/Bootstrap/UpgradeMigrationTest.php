<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\Bootstrap;

use FojleRabbiRabib\LaravelSpaAnalytics\Tests\TestCase;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

class UpgradeMigrationTest extends TestCase
{
    private const TABLES = ['analytics_events', 'analytics_sessions', 'analytics_rollups', 'analytics_visitor_fingerprints', 'analytics_visitor_links'];

    private function migration(string $name): Migration
    {
        return include __DIR__.'/../../../database/migrations/'.$name.'.php.stub';
    }

    /**
     * Column names, full types (with length), nullability and defaults, and index names and columns, of every package table.
     *
     * @return array<string, array<string, mixed>>
     */
    private function shape(): array
    {
        $shape = [];

        foreach (self::TABLES as $table) {
            foreach (Schema::getColumns($table) as $column) {
                $shape[$table.'.'.$column['name']] = ['type' => $column['type'], 'null' => $column['nullable'], 'default' => $column['default']];
            }

            foreach (Schema::getIndexes($table) as $index) {
                $shape[$table.'#'.$index['name']] = ['columns' => $index['columns'], 'unique' => $index['unique'], 'primary' => $index['primary']];
            }
        }

        ksort($shape);

        return $shape;
    }

    /**
     * Take the current tables back to the shape a release had, newest changes first. Level 1 is 0.6.x, 2 is 0.4.x and
     * 0.5.x, 3 is 0.3.x, 4 is 0.2.x and 5 is 0.1.x.
     */
    private function downgradeTo(int $level): void
    {
        if ($level >= 1) {
            Schema::table('analytics_events', fn (Blueprint $table) => $table->dropColumn('engaged_seconds'));
            Schema::table('analytics_rollups', fn (Blueprint $table) => $table->dropColumn('engaged_seconds'));
        }

        if ($level >= 2) {
            Schema::table('analytics_events', fn (Blueprint $table) => $table->dropColumn('file_extension'));
            Schema::table('analytics_sessions', fn (Blueprint $table) => $table->dropColumn('viewport'));
        }

        if ($level >= 3) {
            Schema::table('analytics_events', fn (Blueprint $table) => $table->dropColumn(['target_host', 'target_path', 'scroll_percent']));
        }

        if ($level >= 4) {
            Schema::drop('analytics_rollups');
        }

        if ($level >= 5) {
            $this->versionOneSchema();
        }
    }

    /**
     * The 0.1.0 events table and sessions table, before custom events and the session audience.
     */
    private function versionOneSchema(): void
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

        Schema::table('analytics_sessions', function (Blueprint $table) {
            $table->dropIndex(['started_at', 'country']);
            $table->dropIndex(['started_at', 'device_type']);
        });

        Schema::table('analytics_sessions', fn (Blueprint $table) => $table->dropColumn(['device_type', 'os', 'browser', 'browser_version', 'country']));
    }

    /**
     * Publishing again adds the rollups create migration for a version that had no rollups, and it runs before the update.
     */
    private function upgrade(int $level): void
    {
        if ($level >= 4) {
            $this->migration('create_analytics_rollups_table')->up();
        }

        $this->migration('update_analytics_tables_rev_2')->up();
    }

    /**
     * @return array<string, array{int}>
     */
    public static function releases(): array
    {
        return [
            '0.7.0 (nothing to change)' => [0],
            '0.6.x' => [1],
            '0.4.x and 0.5.x' => [2],
            '0.3.x' => [3],
            '0.2.x' => [4],
            '0.1.x' => [5],
        ];
    }

    public function test_a_fresh_install_is_not_changed_by_the_update_migration(): void
    {
        $fresh = $this->shape();

        $this->migration('update_analytics_tables_rev_2')->up();

        $this->assertSame($fresh, $this->shape());
    }

    #[DataProvider('releases')]
    public function test_every_release_upgrades_to_the_same_schema_as_a_fresh_install(int $level): void
    {
        $fresh = $this->shape();

        $this->downgradeTo($level);
        $this->upgrade($level);

        $this->assertSame($fresh, $this->shape());
    }

    #[DataProvider('releases')]
    public function test_running_the_update_again_changes_nothing(int $level): void
    {
        $this->downgradeTo($level);
        $this->upgrade($level);
        $upgraded = $this->shape();

        $this->migration('update_analytics_tables_rev_2')->up();

        $this->assertSame($upgraded, $this->shape());
    }

    #[DataProvider('releases')]
    public function test_the_upgrade_keeps_existing_page_views(int $level): void
    {
        $this->downgradeTo($level);
        DB::table('analytics_events')->insert(['visitor_id' => 'visitor', 'occurred_at' => '2026-03-02 09:00:00', 'path' => '/pricing', 'status' => 200]);

        $this->upgrade($level);

        $this->assertSame('/pricing', DB::table('analytics_events')->value('path'));
        $this->assertSame('page_view', DB::table('analytics_events')->value('type'));

        DB::table('analytics_events')->insert(['visitor_id' => 'visitor', 'occurred_at' => '2026-03-02 09:01:00', 'type' => 'goal', 'name' => 'signup', 'value' => 10]);

        $this->assertSame(2, DB::table('analytics_events')->count());
    }

    public function test_down_drops_nothing(): void
    {
        $fresh = $this->shape();

        $this->migration('update_analytics_tables_rev_2')->down();

        $this->assertSame($fresh, $this->shape());
    }
}
