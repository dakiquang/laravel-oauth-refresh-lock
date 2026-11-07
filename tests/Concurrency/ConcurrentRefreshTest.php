<?php

declare(strict_types=1);

namespace Dakiquang\OAuthRefreshLock\Tests\Concurrency;

use Carbon\CarbonImmutable;
use Dakiquang\OAuthRefreshLock\Classifiers\DefaultErrorClassifier;
use Dakiquang\OAuthRefreshLock\RefreshCoordinator;
use Dakiquang\OAuthRefreshLock\RefreshedToken;
use Dakiquang\OAuthRefreshLock\StoredToken;
use Dakiquang\OAuthRefreshLock\Stores\EloquentTokenStore;
use Dakiquang\OAuthRefreshLock\Tests\TestCase;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Throwable;

/**
 * Real processes, real locks: N forked workers ask for the same expiring
 * token at the same moment. The provider must be called exactly once and
 * every worker must end up with the token that call produced.
 */
class ConcurrentRefreshTest extends TestCase
{
    private const WORKERS = 8;

    private const ROUNDS = 3;

    private const KEY = 'provider:tenant-1';

    private string $dir;

    protected function setUp(): void
    {
        if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('pcntl and posix are required for the concurrency tests.');
        }

        $this->dir = sys_get_temp_dir().'/oauth-refresh-lock-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
        touch($this->dir.'/shared.sqlite');

        parent::setUp();

        Schema::connection('shared')->dropIfExists('oauth_refresh_tokens');
        Schema::connection('shared')->dropIfExists('cache_locks');
        $migration = require __DIR__.'/../../database/migrations/create_oauth_refresh_tokens_table.php.stub';
        $migration->up();
        Schema::connection('shared')->create('cache_locks', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->string('owner');
            $table->integer('expiration');
        });
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        foreach (glob($this->dir.'/*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->dir);
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $config = $app['config'];
        $mysqlHost = getenv('OAUTH_TEST_MYSQL_HOST');

        $config->set('database.connections.shared', is_string($mysqlHost) && $mysqlHost !== '' ? [
            'driver' => 'mysql',
            'host' => $mysqlHost,
            'port' => getenv('OAUTH_TEST_MYSQL_PORT') ?: '3306',
            'database' => getenv('OAUTH_TEST_MYSQL_DATABASE') ?: 'testing',
            'username' => getenv('OAUTH_TEST_MYSQL_USER') ?: 'root',
            'password' => getenv('OAUTH_TEST_MYSQL_PASSWORD') ?: '',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
        ] : [
            'driver' => 'sqlite',
            'database' => $this->dir.'/shared.sqlite',
            'prefix' => '',
            'busy_timeout' => 10000,
            'journal_mode' => 'wal',
        ]);
        $config->set('oauth-refresh-lock.connection', 'shared');

        $config->set('cache.stores.database', [
            'driver' => 'database',
            'connection' => 'shared',
            'table' => 'cache',
            'lock_connection' => 'shared',
            'lock_table' => 'cache_locks',
        ]);

        $config->set('database.redis.client', 'phpredis');
        $config->set('database.redis.default', [
            'host' => getenv('REDIS_HOST') ?: '127.0.0.1',
            'port' => getenv('REDIS_PORT') ?: '6379',
            'database' => '15',
        ]);
        $config->set('cache.stores.redis', ['driver' => 'redis', 'connection' => 'default']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function lockStores(): iterable
    {
        yield 'database lock' => ['database'];
        yield 'redis lock' => ['redis'];
    }

    #[DataProvider('lockStores')]
    public function test_one_refresh_and_one_token_for_all_workers(string $lockStore): void
    {
        if ($lockStore === 'redis' && ! $this->redisAvailable()) {
            $this->markTestSkipped('Redis is not reachable; set REDIS_HOST/REDIS_PORT to run this case.');
        }

        for ($round = 1; $round <= self::ROUNDS; $round++) {
            $this->runRound($lockStore, $round);
        }
    }

    private function runRound(string $lockStore, int $round): void
    {
        $store = new EloquentTokenStore;
        $store->put(self::KEY, new StoredToken('access-old', 'refresh-old', CarbonImmutable::now()->addSeconds(10)));

        $barrier = "{$this->dir}/start-{$round}";
        $refreshLog = "{$this->dir}/refreshes-{$round}";
        $resultLog = "{$this->dir}/results-{$round}";

        $children = [];
        for ($i = 0; $i < self::WORKERS; $i++) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->fail('fork failed');
            }
            if ($pid === 0) {
                $this->worker($lockStore, $barrier, $refreshLog, $resultLog);
            }
            $children[] = $pid;
        }

        touch($barrier);
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
        }

        DB::purge('shared');

        $refreshes = $this->lines($refreshLog);
        $results = $this->lines($resultLog);
        $errors = array_values(array_filter($results, fn (string $line): bool => str_contains($line, 'ERR')));
        $tokens = array_unique(array_map(fn (string $line): string => explode(' ', $line, 2)[1] ?? '', $results));

        $this->assertSame([], $errors, "round {$round}: workers failed");
        $this->assertCount(self::WORKERS, $results, "round {$round}: every worker reports a token");
        $this->assertCount(1, $refreshes, "round {$round}: the provider is called exactly once");
        $this->assertCount(1, $tokens, "round {$round}: every worker gets the same access token");
        $this->assertSame('access-from-'.trim($refreshes[0]), reset($tokens));
        $this->assertSame(1, $store->get(self::KEY)?->version, "round {$round}: version bumped once");
    }

    /**
     * Runs in the forked child and never returns.
     */
    private function worker(string $lockStore, string $barrier, string $refreshLog, string $resultLog): never
    {
        $line = getmypid().' ';

        try {
            // Never share the parent's sockets or file handles.
            DB::purge('shared');
            if ($lockStore === 'redis') {
                app('redis')->purge('default');
            }

            $deadline = microtime(true) + 10;
            while (! file_exists($barrier) && microtime(true) < $deadline) {
                usleep(1000);
            }

            $locks = Cache::store($lockStore)->getStore();
            if (! $locks instanceof LockProvider) {
                throw new \RuntimeException("{$lockStore} has no locks");
            }

            $coordinator = new RefreshCoordinator(new EloquentTokenStore, $locks, new DefaultErrorClassifier, waitSeconds: 15);

            $line .= $coordinator->for(self::KEY)->accessToken(function (StoredToken $token) use ($refreshLog): RefreshedToken {
                $me = (string) getmypid();
                file_put_contents($refreshLog, $me."\n", FILE_APPEND | LOCK_EX);
                usleep(300_000); // a slow token endpoint widens the race window

                return RefreshedToken::fromExpiresIn('access-from-'.$me, 'refresh-from-'.$me, 3600);
            });
        } catch (Throwable $e) {
            $line .= 'ERR '.$e::class.': '.$e->getMessage();
        }

        file_put_contents($resultLog, str_replace("\n", ' ', $line)."\n", FILE_APPEND | LOCK_EX);

        // Skip PHPUnit's and Laravel's shutdown handlers in the child.
        posix_kill(getmypid(), SIGKILL);
        exit(0);
    }

    /**
     * @return list<string>
     */
    private function lines(string $file): array
    {
        return file_exists($file) ? array_values(array_filter(array_map('trim', file($file) ?: []))) : [];
    }

    private function redisAvailable(): bool
    {
        if (! extension_loaded('redis')) {
            return false;
        }

        try {
            app('redis')->connection('default')->ping();

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
