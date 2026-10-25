<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\Bootstrap;

use FojleRabbiRabib\LaravelSpaAnalytics\Console\CleanMigrationsCommand;
use FojleRabbiRabib\LaravelSpaAnalytics\Console\PruneCommand;
use FojleRabbiRabib\LaravelSpaAnalytics\Console\RollupCommand;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsEvent;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsRollup;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsSession;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\VisitorFingerprint;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\VisitorLink;
use FojleRabbiRabib\LaravelSpaAnalytics\Tests\TestCase;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;

/**
 * What an app or another package gets when it uses or extends the package classes.
 */
class ExtensionPointsTest extends TestCase
{
    /**
     * @return array<string, array{Model, string}>
     */
    private function models(): array
    {
        return [
            'event' => [new AnalyticsEvent, 'analytics_events'],
            'rollup' => [new AnalyticsRollup, 'analytics_rollups'],
            'session' => [new AnalyticsSession, 'analytics_sessions'],
            'fingerprint' => [new VisitorFingerprint, 'analytics_visitor_fingerprints'],
            'link' => [new VisitorLink, 'analytics_visitor_links'],
            'event subclass' => [new class extends AnalyticsEvent {}, 'analytics_events'],
            'rollup subclass' => [new class extends AnalyticsRollup {}, 'analytics_rollups'],
            'session subclass' => [new class extends AnalyticsSession {}, 'analytics_sessions'],
            'fingerprint subclass' => [new class extends VisitorFingerprint {}, 'analytics_visitor_fingerprints'],
            'link subclass' => [new class extends VisitorLink {}, 'analytics_visitor_links'],
        ];
    }

    public function test_every_model_and_subclass_keeps_its_table_timestamps_and_mass_assignment(): void
    {
        foreach ($this->models() as $label => [$model, $table]) {
            $this->assertSame($table, $model->getTable(), $label);
            $this->assertFalse($model->usesTimestamps(), $label);
            $this->assertFalse($model->totallyGuarded(), $label);
            $this->assertSame(['anything' => 1], $model->fill(['anything' => 1])->getAttributes(), $label);
        }
    }

    public function test_a_subclass_can_change_its_own_table_timestamps_and_guarding(): void
    {
        $own = new #[Table(name: 'my_events')] class extends AnalyticsEvent {};
        $named = new class extends AnalyticsEvent
        {
            protected $table = 'renamed_events';
        };
        $dated = new class extends AnalyticsEvent
        {
            public $timestamps = true;
        };
        $locked = new class extends AnalyticsEvent
        {
            protected $guarded = ['*'];
        };

        $this->assertSame('my_events', $own->getTable());
        $this->assertFalse($own->usesTimestamps());
        $this->assertSame('renamed_events', $named->getTable());
        $this->assertTrue($dated->usesTimestamps());
        $this->assertTrue($locked->totallyGuarded());
    }

    /**
     * @return array<string, array{Command, string, array<int, string>}>
     */
    private function commands(): array
    {
        return [
            'rollup' => [new RollupCommand, 'spa-analytics:rollup', ['since', 'period', 'purge-disabled']],
            'prune' => [new PruneCommand, 'spa-analytics:prune', []],
            'clean' => [new CleanMigrationsCommand, 'spa-analytics:clean-migrations', []],
            'rollup subclass' => [new class extends RollupCommand {}, 'spa-analytics:rollup', ['since', 'period', 'purge-disabled']],
            'prune subclass' => [new class extends PruneCommand {}, 'spa-analytics:prune', []],
            'clean subclass' => [new class extends CleanMigrationsCommand {}, 'spa-analytics:clean-migrations', []],
        ];
    }

    public function test_every_command_and_subclass_keeps_its_name_description_and_options(): void
    {
        foreach ($this->commands() as $label => [$command, $name, $options]) {
            $this->assertSame($name, $command->getName(), $label);
            $this->assertNotSame('', $command->getDescription(), $label);

            foreach ($options as $option) {
                $this->assertTrue($command->getDefinition()->hasOption($option), $label.' '.$option);
            }
        }
    }

    public function test_the_package_commands_are_registered(): void
    {
        $all = Artisan::all();

        foreach (['spa-analytics:rollup', 'spa-analytics:prune', 'spa-analytics:clean-migrations'] as $name) {
            $this->assertArrayHasKey($name, $all);
        }
    }
}
