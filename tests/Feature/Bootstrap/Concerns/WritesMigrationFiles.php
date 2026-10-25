<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\Bootstrap\Concerns;

/**
 * Fake published migration files in the Testbench skeleton, which lives under vendor/ and keeps files between runs.
 */
trait WritesMigrationFiles
{
    protected function removeMigrationFiles(): void
    {
        foreach (glob(database_path('migrations/2026_01_0*.php')) ?: [] as $file) {
            @unlink($file);
        }
    }

    /**
     * An old update migration that would fail if it ever ran, because the column already exists.
     */
    protected function crashingFile(string $name = 'update_analytics_events_table_for_custom_events', string $stamp = '2026_01_01_000000'): string
    {
        $path = database_path("migrations/{$stamp}_{$name}.php");

        file_put_contents($path, <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('analytics_events', fn (Blueprint $table) => $table->string('name', 128)->nullable());
    }
};
PHP);

        return $path;
    }

    protected function harmlessFile(string $name, string $stamp = '2026_01_01_000000'): string
    {
        $path = database_path("migrations/{$stamp}_{$name}.php");

        file_put_contents($path, "<?php\n\nreturn new class extends \\Illuminate\\Database\\Migrations\\Migration {};\n");

        return $path;
    }

    protected function markRan(string $name): void
    {
        $repository = app('migrator')->getRepository();

        if (! $repository->repositoryExists()) {
            $repository->createRepository();
        }

        $repository->log($name, 1);
    }

    /**
     * Publish the current update migration the way vendor:publish does, under a timestamped name.
     */
    protected function publishCurrentMigration(): string
    {
        $path = database_path('migrations/2026_01_02_000000_update_analytics_tables_rev_2.php');

        copy(__DIR__.'/../../../../database/migrations/update_analytics_tables_rev_2.php.stub', $path);

        return $path;
    }
}
