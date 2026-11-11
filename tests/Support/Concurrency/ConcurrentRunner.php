<?php

namespace Tests\Support\Concurrency;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Runs one operation in several PHP processes at the same instant, against the real MySQL/MariaDB the test is connected to, and returns what each process reported.
 *
 * SQLite in memory is private to one process, so none of this works there: `available()` says so and the tests skip. The processes boot the application with the environment of
 * the parent (the database settings come from it), wait for a common start time, run the named operation of `Operations`, and print one JSON line.
 */
final class ConcurrentRunner
{
    /** How long the processes are given to boot before the common start time. */
    private const BOOT_SECONDS = 6.0;

    public static function available(): bool
    {
        return in_array((string) config('database.default'), ['mysql', 'mariadb'], true)
            && (string) config('database.connections.' . config('database.default') . '.driver') === 'mysql'
            && getenv('CONCURRENCY_TESTS') !== '0';
    }

    /**
     * @param list<array<string, mixed>> $arguments one entry per process (its own arguments for the operation). An entry may
     *                                              carry its own `op` to run a DIFFERENT operation in that process, which is
     *                                              how two different actions on one row are raced against each other
     *                                              rather than merely run one after the other.
     * @return list<array{ok: bool, result?: mixed, error?: string, message?: string, started_at: float, finished_at: float, pid: int}>
     */
    public static function run(string $operation, array $arguments): array
    {
        $startAt = microtime(true) + self::BOOT_SECONDS;
        $processes = [];
        foreach ($arguments as $index => $args) {
            $own = (string) ($args['op'] ?? $operation);
            unset($args['op']);
            $spec = json_encode(['op' => $own, 'args' => $args, 'start_at' => $startAt, 'index' => $index], JSON_THROW_ON_ERROR);
            $command = [PHP_BINARY];
            $prepend = (string) ini_get('auto_prepend_file');
            if ($prepend !== '') {
                $command[] = '-d';
                $command[] = 'auto_prepend_file=' . $prepend;
            }
            $command[] = __DIR__ . '/worker.php';
            $command[] = $spec;
            $process = new Process($command, base_path(), self::environment(), null, 120);
            $process->start();
            $processes[$index] = $process;
        }

        $reports = [];
        foreach ($processes as $index => $process) {
            $process->wait();
            $lines = array_values(array_filter(array_map('trim', explode("\n", $process->getOutput()))));
            $last = $lines === [] ? '' : $lines[array_key_last($lines)];
            $decoded = json_decode($last, true);
            if (!is_array($decoded)) {
                throw new RuntimeException("Worker {$index} gave no report. Exit code {$process->getExitCode()}. Output: " . substr($process->getOutput(), 0, 500) . ' Errors: ' . substr($process->getErrorOutput(), 0, 1500));
            }
            $reports[] = $decoded;
        }

        return $reports;
    }

    /** The settings phpunit.mysql.xml gives the test process through <server>, which a child process does not inherit. */
    private static function environment(): array
    {
        return ['APP_ENV' => 'testing', 'BCRYPT_ROUNDS' => '4', 'CACHE_DRIVER' => 'array', 'MAIL_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'SESSION_DRIVER' => 'array'];
    }

    /** True when the reports show at least two operations whose running windows overlapped. */
    public static function overlapped(array $reports): bool
    {
        foreach ($reports as $i => $a) {
            foreach ($reports as $j => $b) {
                if ($i < $j && $a['started_at'] < $b['finished_at'] && $b['started_at'] < $a['finished_at']) {
                    return true;
                }
            }
        }

        return false;
    }
}
