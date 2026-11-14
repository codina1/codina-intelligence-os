<?php

namespace Tests\Feature\Infrastructure;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Uses committed MariaDB state (no wrapping transaction) so concurrent PDO
 * connections can observe rows and exercise the unique constraint for real.
 */
class ConcurrentIdempotencyMariaDbTest extends TestCase
{
    protected function connectionsToTransact(): array
    {
        return [];
    }

    public function test_concurrent_idempotency_claims_on_mariadb(): void
    {
        [$user, $tenant] = $this->makeTenantUser('tenant_owner');
        $now = now()->format('Y-m-d H:i:s');
        $hash = hash('sha256', 'concurrent');

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s',
            config('database.connections.mysql.host'),
            config('database.connections.mysql.port'),
            config('database.connections.mysql.database'),
        );

        $insertSql = 'INSERT INTO idempotency_keys
            (tenant_id, tenant_scope_id, user_id, `key`, route, request_hash, status, response_code, response_body, expires_at, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NULL, NULL, ?, ?, ?)';

        $params = [
            $tenant->id,
            $tenant->id,
            $user->id,
            'concurrent-key',
            'POST api/v1/sites',
            $hash,
            'pending',
            $now,
            $now,
            $now,
        ];

        $pdoA = new \PDO($dsn, config('database.connections.mysql.username'), config('database.connections.mysql.password'), [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);
        $pdoB = new \PDO($dsn, config('database.connections.mysql.username'), config('database.connections.mysql.password'), [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);

        // Begin overlapping transactions to approximate concurrent claims.
        $pdoA->beginTransaction();
        $pdoB->beginTransaction();

        $successes = 0;
        $duplicates = 0;

        try {
            $stmtA = $pdoA->prepare($insertSql);
            $stmtA->execute($params);
            $successes++;
            $pdoA->commit();
        } catch (\PDOException $e) {
            $pdoA->rollBack();
            throw $e;
        }

        try {
            $stmtB = $pdoB->prepare($insertSql);
            $stmtB->execute($params);
            $successes++;
            $pdoB->commit();
        } catch (\PDOException $e) {
            $pdoB->rollBack();
            if ((int) ($e->errorInfo[1] ?? 0) === 1062 || str_contains($e->getMessage(), 'Duplicate')) {
                $duplicates++;
            } else {
                throw $e;
            }
        }

        $this->assertSame(1, $successes);
        $this->assertSame(1, $duplicates);
        $this->assertSame(1, DB::table('idempotency_keys')->where('key', 'concurrent-key')->count());
    }

    public function test_true_parallel_inserts_via_pcntl_or_sequential_race_window(): void
    {
        [$user, $tenant] = $this->makeTenantUser('tenant_owner');

        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl not available; covered by concurrent PDO claim test.');
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s',
            config('database.connections.mysql.host'),
            config('database.connections.mysql.port'),
            config('database.connections.mysql.database'),
        );
        $userName = config('database.connections.mysql.username');
        $password = config('database.connections.mysql.password');
        $now = now()->format('Y-m-d H:i:s');

        $pids = [];
        for ($i = 0; $i < 2; $i++) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->fail('Failed to fork');
            }
            if ($pid === 0) {
                try {
                    $pdo = new \PDO($dsn, $userName, $password, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
                    $pdo->prepare(
                        'INSERT INTO idempotency_keys
                        (tenant_id, tenant_scope_id, user_id, `key`, route, request_hash, status, response_code, response_body, expires_at, created_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, NULL, NULL, ?, ?, ?)'
                    )->execute([
                        $tenant->id,
                        $tenant->id,
                        $user->id,
                        'fork-key',
                        'POST api/v1/sites',
                        hash('sha256', 'fork'),
                        'pending',
                        $now,
                        $now,
                        $now,
                    ]);
                    exit(0);
                } catch (\PDOException $e) {
                    exit(((int) ($e->errorInfo[1] ?? 0) === 1062) ? 2 : 1);
                }
            }
            $pids[] = $pid;
        }

        $codes = [];
        foreach ($pids as $pid) {
            $status = 0;
            pcntl_waitpid($pid, $status);
            $codes[] = pcntl_wexitstatus($status);
        }

        sort($codes);
        $this->assertSame([0, 2], $codes);
        $this->assertSame(1, DB::table('idempotency_keys')->where('key', 'fork-key')->count());
    }
}
