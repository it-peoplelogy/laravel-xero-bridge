<?php

declare(strict_types=1);

use Illuminate\Bus\UniqueLock;
use Illuminate\Cache\ArrayStore;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Peoplelogy\XeroBridge\Events\XeroWebhookReceived;
use Peoplelogy\XeroBridge\Jobs\ProcessXeroWebhook;
use Peoplelogy\XeroBridge\Tests\Support\SignedWebhook;

/**
 * The uniqueness lock on the envelope job, end to end through the route.
 *
 * ProcessXeroWebhook has declared ShouldBeUnique since 1.4.0, but Laravel only
 * takes that lock on the PendingDispatch path and the controller queues
 * through the Bus dispatcher, so until 1.5.0 the lock was never taken: a retry
 * of a slow 200 queued the same events a second time.
 */

/**
 * One delivery of the same invoice event. Xero varies `entropy` between
 * deliveries of the same events, so two deliveries differ in bytes and in
 * signature while carrying identical events.
 */
function webhookLockBody(string $entropy, string $resourceId = 'inv-1'): string
{
    return (string) json_encode([
        'events' => [[
            'resourceUrl' => "https://api.xero.com/api.xro/2.0/Invoices/{$resourceId}",
            'resourceId' => $resourceId,
            'eventDateUtc' => '2026-09-28T01:15:39.902',
            'eventType' => 'UPDATE',
            'eventCategory' => 'INVOICE',
            'tenantId' => 'tenant-1',
            'tenantType' => 'ORGANISATION',
        ]],
        'firstEventSequence' => 1,
        'lastEventSequence' => 1,
        'entropy' => $entropy,
    ]);
}

/**
 * The cache key the job's lock is held under, exactly as Laravel builds it.
 *
 * Through reflection because getKey() is public and static only in later
 * Laravel 11 releases; earlier ones, which the lowest-dependency CI leg can
 * resolve, have it as a protected instance method.
 */
function webhookLockKey(string $raw): string
{
    $job = new ProcessXeroWebhook(json_decode($raw, true));
    $getKey = new ReflectionMethod(UniqueLock::class, 'getKey');

    return $getKey->isStatic()
        ? $getKey->invoke(null, $job)
        : $getKey->invoke(new UniqueLock(Cache::store()), $job);
}

/**
 * Hold the lock in a store whose locks work but whose cache entries cannot be
 * written ('put') or read ('get'): the controller's proof that a delivery was
 * queued is a cache entry beside the lock.
 */
function webhookProofStoreFailing(string $method): void
{
    Cache::extend('webhook_proof_down', fn () => Cache::repository(new class($method) extends ArrayStore
    {
        public function __construct(private readonly string $failing)
        {
            parent::__construct();
        }

        public function put($key, $value, $seconds)
        {
            if ($this->failing === 'put') {
                throw new RuntimeException('cache write failed');
            }

            return parent::put($key, $value, $seconds);
        }

        public function get($key)
        {
            if ($this->failing === 'get') {
                throw new RuntimeException('cache read failed');
            }

            return parent::get($key);
        }
    }));

    config()->set('cache.stores.webhook_proof_down', ['driver' => 'webhook_proof_down']);
    config()->set('xero-bridge.tokens.lock_store', 'webhook_proof_down');
}

/*
|--------------------------------------------------------------------------
| A retry of the same events is not queued twice
|--------------------------------------------------------------------------
*/

it('queues the same events once when Xero retries a slow delivery', function () {
    Queue::fake();
    Log::spy();

    $first = webhookLockBody('first-delivery');
    $retry = webhookLockBody('retry-with-new-entropy');

    expect($retry)->not->toBe($first);

    // The retry still gets its 200: the first one's push succeeded, so the
    // events ARE queued.
    SignedWebhook::post('/xero/webhook', $first)->assertOk();
    SignedWebhook::post('/xero/webhook', $retry)->assertOk();

    Queue::assertPushed(ProcessXeroWebhook::class, 1);

    Log::shouldHaveReceived('info')->once()->withArgs(
        fn (string $message, array $context = []): bool => str_contains($message, 'already queued or running')
            && $context['events'] === 1,
    );
});

it('queues deliveries of different events separately', function () {
    Queue::fake();

    SignedWebhook::post('/xero/webhook', webhookLockBody('first', 'inv-1'))->assertOk();
    SignedWebhook::post('/xero/webhook', webhookLockBody('second', 'inv-2'))->assertOk();

    Queue::assertPushed(ProcessXeroWebhook::class, 2);
});

/*
|--------------------------------------------------------------------------
| The lock never costs an event
|--------------------------------------------------------------------------
*/

it('gives the lock back when the job cannot be queued, so the retry is queued', function () {
    // Kept past the failed push, the lock would turn every retry of these
    // events away until it expired.
    $this->mock(Dispatcher::class)
        ->shouldReceive('dispatch')
        ->twice()
        ->andReturnUsing(
            fn () => throw new RuntimeException('queue down'),
            fn () => null,
        );

    SignedWebhook::post('/xero/webhook', webhookLockBody('first'))->assertStatus(500);
    SignedWebhook::post('/xero/webhook', webhookLockBody('retry'))->assertOk();
});

it('still queues the webhook when the lock cannot be taken', function () {
    Queue::fake();
    Log::spy();

    // A database lock store whose table is missing: taking a lock throws, as
    // it does whenever the lock store is down.
    config()->set('cache.stores.webhook_locks_down', [
        'driver' => 'database',
        'connection' => null,
        'table' => 'cache',
        'lock_connection' => null,
        'lock_table' => 'no_such_cache_locks',
    ]);
    config()->set('xero-bridge.tokens.lock_store', 'webhook_locks_down');

    SignedWebhook::post('/xero/webhook', webhookLockBody('first'))->assertOk();
    SignedWebhook::post('/xero/webhook', webhookLockBody('retry'))->assertOk();

    // Without a lock the retry is queued too: a duplicate, which listeners
    // must tolerate, rather than a loss.
    Queue::assertPushed(ProcessXeroWebhook::class, 2);

    Log::shouldHaveReceived('warning')->twice()->withArgs(
        fn (string $message, array $context = []): bool => str_contains($message, 'could not take the uniqueness lock')
            && $context['exception_class'] !== '',
    );
});

it('says so, and still answers 500, when the lock cannot be given back', function () {
    Log::spy();
    healthyLockStore();

    $this->mock(Dispatcher::class)
        ->shouldReceive('dispatch')
        ->once()
        ->andReturnUsing(function () {
            // The lock store goes away between taking the lock and giving it
            // back.
            Schema::drop('cache_locks');

            throw new RuntimeException('queue down');
        });

    SignedWebhook::post('/xero/webhook', webhookLockBody('first'))->assertStatus(500);

    Log::shouldHaveReceived('critical')->once()->withArgs(
        fn (string $message, array $context = []): bool => str_contains($message, 'could not release the uniqueness lock')
            && $context['unique_for'] === 900,
    );
});

it('answers 503 to a retry that arrives while the first delivery is still being queued', function (bool $pushFails, int $firstStatus) {
    // Xero stops waiting at 5 seconds and retries at once, so its retry can
    // arrive while the first push is still in flight. Xero never resends a
    // delivery it was answered 200 for, so a 200 then would be a promise the
    // first push might not keep -- and a push that then failed would lose the
    // events, its 500 going to a request Xero had already abandoned.
    Log::spy();

    $retryStatus = null;
    $pushed = 0;

    $this->mock(Dispatcher::class)
        ->shouldReceive('dispatch')
        ->andReturnUsing(function () use (&$retryStatus, &$pushed, $pushFails) {
            if ($retryStatus === null) {
                $retryStatus = SignedWebhook::post('/xero/webhook', webhookLockBody('retry-during-push'))->status();

                if ($pushFails) {
                    throw new RuntimeException('queue push timed out');
                }
            }

            $pushed++;

            return null;
        });

    expect(SignedWebhook::post('/xero/webhook', webhookLockBody('first'))->status())->toBe($firstStatus)
        ->and($retryStatus)->toBe(503);

    // Xero's next retry, once the outcome is known: queued now if the first
    // push failed; answered 200, and not queued again, if it succeeded.
    SignedWebhook::post('/xero/webhook', webhookLockBody('next-retry'))->assertOk();

    expect($pushed)->toBe(1);

    // Filtered first, then counted: the other variant's next retry logs an
    // info line of its own.
    Log::shouldHaveReceived('info')->withArgs(
        fn (string $message, array $context = []): bool => str_contains($message, 'not confirmed queued yet')
            && $context['events'] === 1,
    )->once();
})->with([
    'and the push then fails' => [true, 500],
    'and the push then succeeds' => [false, 200],
]);

it('forgets an earlier delivery\'s proof once a later push of the same events fails', function () {
    // A failed push leaves nothing proven: a retry that arrives during the
    // next attempt is not answered 200 on the strength of an older delivery.
    $raw = webhookLockBody('first');
    $retryStatus = null;

    $this->mock(Dispatcher::class)
        ->shouldReceive('dispatch')
        ->andReturnUsing(
            fn () => null,
            fn () => throw new RuntimeException('queue down'),
            function () use (&$retryStatus) {
                $retryStatus = SignedWebhook::post('/xero/webhook', webhookLockBody('retry-during-push'))->status();
            },
        );

    SignedWebhook::post('/xero/webhook', $raw)->assertOk();

    // The worker runs the first job and gives the lock back.
    Cache::store()->lock(webhookLockKey($raw))->forceRelease();

    SignedWebhook::post('/xero/webhook', webhookLockBody('second'))->assertStatus(500);
    SignedWebhook::post('/xero/webhook', webhookLockBody('third'))->assertOk();

    expect($retryStatus)->toBe(503);
});

it('answers 503 to a retry that arrives while a sync-driver listener still runs, so a failure loses nothing', function () {
    // On the sync driver the listeners run inside the first request: one that
    // runs past Xero's 5 seconds and then throws is this race on the queue it
    // actually has. The suite's queue is sync.
    $runs = 0;
    $retryStatus = null;

    Event::listen(XeroWebhookReceived::class, function () use (&$runs, &$retryStatus) {
        if (++$runs === 1) {
            $retryStatus = SignedWebhook::post('/xero/webhook', webhookLockBody('retry-during-listener'))->status();

            throw new RuntimeException('downstream failed after 6 seconds');
        }
    });

    SignedWebhook::post('/xero/webhook', webhookLockBody('first'))->assertStatus(500);

    expect($retryStatus)->toBe(503);

    // Xero's next retry runs the listeners again: the events were not lost.
    SignedWebhook::post('/xero/webhook', webhookLockBody('next-retry'))->assertOk();

    expect($runs)->toBe(2);
});

it('never answers a retry 200 without proof that the first delivery was queued', function (string $failing) {
    // The proof is a cache entry beside the lock. A store that locks but
    // cannot write or read it leaves the push unproven, so the retry gets a
    // 503 -- at worst a duplicate once the lock is free, never a loss.
    Queue::fake();
    Log::spy();
    webhookProofStoreFailing($failing);

    // The first delivery IS queued, so its 200 stands.
    SignedWebhook::post('/xero/webhook', webhookLockBody('first'))->assertOk();
    SignedWebhook::post('/xero/webhook', webhookLockBody('retry'))->assertStatus(503);

    Queue::assertPushed(ProcessXeroWebhook::class, 1);

    if ($failing === 'put') {
        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $message, array $context = []): bool => str_contains($message, 'could not record that a Xero webhook was queued')
                && $context['exception'] === 'cache write failed',
        )->once();
    }
})->with([
    'the proof cannot be written' => ['put'],
    'the proof cannot be read' => ['get'],
]);

/*
|--------------------------------------------------------------------------
| Where the lock lives, and who gives it back
|--------------------------------------------------------------------------
*/

it('holds the lock in the store XERO_LOCK_STORE names', function () {
    // With web servers behind a load balancer, a retry can land on another
    // server, so the lock has to live where XERO_LOCK_STORE points -- not in
    // the default store, which is often `file`.
    Queue::fake();
    config()->set('cache.stores.webhook_locks', ['driver' => 'array']);
    config()->set('xero-bridge.tokens.lock_store', 'webhook_locks');

    $raw = webhookLockBody('first');

    SignedWebhook::post('/xero/webhook', $raw)->assertOk();

    expect(Cache::store('webhook_locks')->lock(webhookLockKey($raw), 10)->get())->toBeFalse()
        ->and(Cache::store()->lock(webhookLockKey($raw), 10)->get())->toBeTrue();
});

it('still queues a retry once when XERO_LOCK_STORE names the null store', function () {
    // The null store grants every lock at once. Held there, the lock would
    // let Xero's retry queue the same events a second time; held in the
    // default store, which can lock, it does not.
    Queue::fake();
    config()->set('cache.stores.webhook_null', ['driver' => 'null']);
    config()->set('xero-bridge.tokens.lock_store', 'webhook_null');

    SignedWebhook::post('/xero/webhook', webhookLockBody('first'))->assertOk();
    SignedWebhook::post('/xero/webhook', webhookLockBody('retry'))->assertOk();

    Queue::assertPushed(ProcessXeroWebhook::class, 1);
});

it('lets the worker give the lock back once the job has run', function () {
    Event::fake([XeroWebhookReceived::class]);

    Schema::create('jobs', function (Blueprint $table) {
        $table->id();
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });

    config()->set('queue.connections.database', [
        'driver' => 'database',
        'connection' => null,
        'table' => 'jobs',
        'queue' => 'default',
        'retry_after' => 90,
        'after_commit' => false,
    ]);
    config()->set('xero-bridge.webhooks.connection', 'database');

    $raw = webhookLockBody('first');

    SignedWebhook::post('/xero/webhook', $raw)->assertOk();
    SignedWebhook::post('/xero/webhook', webhookLockBody('retry'))->assertOk();

    expect(DB::table('jobs')->count())->toBe(1);

    // Exactly what `queue:work` does with the job it pops: Laravel's queue
    // handler runs it, then releases the lock the controller took.
    app('queue')->connection('database')->pop()->fire();

    Event::assertDispatchedTimes(XeroWebhookReceived::class, 1);

    expect(DB::table('jobs')->count())->toBe(0)
        ->and(Cache::store()->lock(webhookLockKey($raw), 10)->get())->toBeTrue();
});

it('lets the same events through again once their job has finished', function () {
    // The lock covers a retry storm, not history: a later delivery of the
    // same events is left to the dedupe table, when that is switched on.
    // The suite's queue is sync, so each job runs inside its request.
    Event::fake([XeroWebhookReceived::class]);

    SignedWebhook::post('/xero/webhook', webhookLockBody('first'))->assertOk();
    SignedWebhook::post('/xero/webhook', webhookLockBody('days-later'))->assertOk();

    Event::assertDispatchedTimes(XeroWebhookReceived::class, 2);
});

it('frees the lock when a listener fails on the sync driver, so the retry runs again', function () {
    $calls = 0;

    Event::listen(XeroWebhookReceived::class, function () use (&$calls) {
        if (++$calls === 1) {
            throw new RuntimeException('listener failed once');
        }
    });

    SignedWebhook::post('/xero/webhook', webhookLockBody('first'))->assertStatus(500);
    SignedWebhook::post('/xero/webhook', webhookLockBody('retry'))->assertOk();

    expect($calls)->toBe(2);
});
