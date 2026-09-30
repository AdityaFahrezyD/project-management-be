<?php

namespace Tests\Feature;

use App\Enums\TaskStatus;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

class ApiConcurrencyTest extends ApiTestCase
{
    public function test_mysql_mutations_wait_for_workspace_lock_and_do_not_partially_write_on_timeout(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Row locking memerlukan MySQL.');
        }
        $task = $this->task();
        $before = ActivityLog::count();
        $database = config('database.connections.mysql');
        $code = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Illuminate\Support\Facades\DB::statement('SET SESSION innodb_lock_wait_timeout = 1');
try {
    app(App\Services\TaskService::class)->status(
        App\Models\User::findOrFail($argv[1]),
        App\Models\Task::findOrFail($argv[2]),
        'done'
    );
    echo 'COMMITTED';
} catch (Illuminate\Database\QueryException $exception) {
    if (($exception->errorInfo[1] ?? null) !== 1205) {
        throw $exception;
    }
    echo 'LOCKED';
}
PHP;
        $process = new Process([PHP_BINARY, '-r', $code, '--', $this->manager->id, $task->id], base_path(), [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => $database['database'],
            'DB_HOST' => $database['host'], 'DB_PORT' => (string) $database['port'],
            'DB_USERNAME' => $database['username'], 'DB_PASSWORD' => $database['password'],
            'DB_URL' => '', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
        ]);
        $process->setTimeout(15);
        DB::beginTransaction();
        try {
            DB::table('workspaces')->where('id', $this->workspace->id)->lockForUpdate()->first();
            $process->mustRun();
            $this->assertSame('LOCKED', $process->getOutput());
            $this->assertSame(TaskStatus::Todo, $task->fresh()->status);
            $this->assertSame($before, ActivityLog::count());
        } finally {
            DB::rollBack();
        }
        $process->mustRun();
        $this->assertSame('COMMITTED', $process->getOutput());
        $this->assertSame(TaskStatus::Done, $task->fresh()->status);
        $this->assertSame(1, $task->statusHistories()->count());
    }
}
