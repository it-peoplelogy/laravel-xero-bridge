<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\Http\Controllers;

use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Peoplelogy\XeroBridge\Events\XeroWebhookSignatureFailed;
use Peoplelogy\XeroBridge\Jobs\ProcessXeroWebhook;
use Peoplelogy\XeroBridge\Support\XeroConfig;
use Peoplelogy\XeroBridge\Webhooks\WebhookEnvelope;
use Peoplelogy\XeroBridge\Webhooks\WebhookSignature;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Receives Xero webhooks.
 *
 * Xero's intent-to-receive rules, all of which this has to satisfy:
 *   - respond within 5 SECONDS with a 2xx
 *   - NO cookies in the response
 *   - 401 for an invalid signature
 * Xero deliberately sends both correctly- and incorrectly-signed payloads
 * during validation, so getting either wrong fails the whole check.
 *
 * The handler therefore does the least possible work: verify, take the
 * uniqueness lock, queue, note that the events are queued, return. Nothing is
 * processed inline.
 */
final class XeroWebhookController
{
    public function __construct(
        private readonly XeroConfig $config,
        private readonly BusDispatcher $bus,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(Request $request): Response
    {
        // The RAW bytes, exactly as they arrived. Re-encoding $request->all()
        // changes whitespace and key order, and the signature would never
        // match.
        $raw = $request->getContent();

        $valid = WebhookSignature::isValid(
            $raw,
            $request->header(WebhookSignature::HEADER),
            $this->config->webhookKey(),
        );

        if (! $valid) {
            $this->announceSignatureFailure($request, $raw);

            // Xero expects exactly this for a bad signature. An unconfigured
            // signing key lands here too: it fails closed.
            return new Response('', 401);
        }

        $payload = json_decode($raw, true);

        if (! is_array($payload)) {
            // Signed by Xero, so it is genuinely from them -- returning
            // anything but a 2xx would count against the subscription.
            $this->logger->critical('xero-bridge: a correctly signed Xero webhook could not be parsed.', [
                'length' => strlen($raw),
            ]);

            return new Response('', 200);
        }

        $envelope = WebhookEnvelope::fromArray($payload);

        // The intent-to-receive ping carries an empty events array. Answer
        // 200 and queue nothing.
        if ($envelope->isIntentToReceive()) {
            return new Response('', 200);
        }

        $job = new ProcessXeroWebhook($payload);

        if (($queue = $this->config->get('webhooks.queue')) !== null) {
            $job->onQueue((string) $queue);
        }

        if (($connection = $this->config->get('webhooks.connection')) !== null) {
            $job->onConnection((string) $connection);
        }

        $lock = $this->acquireLock($job);

        if ($lock === false) {
            return $this->answerRetry($job, count($envelope->events));
        }

        try {
            $this->bus->dispatch($job);
        } catch (Throwable $e) {
            // Not queued, so not accepted. A 500 makes Xero retry rather than
            // dropping the events silently. On the sync driver the job ran
            // inside dispatch(), so this is also where a listener's exception
            // arrives -- and a retry is the right answer to that too.
            $this->logger->error(
                'xero-bridge: could not queue a Xero webhook, or (on the sync driver) a listener failed '
                .'while processing it.',
                ['exception' => $e->getMessage(), 'exception_class' => $e::class],
            );

            // Give the lock back, so Xero's next retry queues the events at
            // once instead of being turned away until the lock expires.
            if ($lock !== null) {
                $this->releaseLock($lock, $job);
            }

            return new Response('', 500);
        }

        // Only now is it true that these events are queued -- on the sync
        // driver, that their listeners have run -- so only now may a retry
        // that finds the lock held be answered 200. Never written without the
        // lock: with no lock held, a retry never reaches answerRetry().
        if ($lock !== null) {
            $this->markQueued($job);
        }

        return new Response('', 200);
    }

    /**
     * Answer a delivery whose events another delivery holds the lock for.
     *
     * Xero never resends a delivery it was answered 200 for, so a 200 here is
     * only true once the holder's push is known to have succeeded -- the
     * marker markQueued() leaves. Until then the holder may still fail: Xero
     * gives up on a request after 5 seconds and retries at once, so this
     * retry can arrive while the first push is still in flight, or, on the
     * sync driver, while its listeners still run. Answered 200 then, a push
     * that went on to fail would lose the events, because the holder's 500
     * goes to a request Xero has already abandoned. A 503 instead makes Xero
     * retry on its own schedule, by when the holder's outcome is known: queued,
     * and the retry is answered 200; failed, the lock is free, and the retry
     * queues the events itself. The worst case is a duplicate, never a loss.
     */
    private function answerRetry(ProcessXeroWebhook $job, int $events): Response
    {
        if ($this->isQueued($job)) {
            // A retry of a delivery whose job is queued or running: the first
            // one's 200 was late, not lost. Queuing it again is exactly the
            // duplicate the lock exists to stop.
            $this->logger->info(
                'xero-bridge: a delivery of these Xero webhook events is already queued or running, '
                .'so this one was answered 200 without queuing them again.',
                ['events' => $events],
            );

            return new Response('', 200);
        }

        $this->logger->info(
            'xero-bridge: the first delivery of these Xero webhook events is not confirmed queued yet; '
            .'asking Xero to retry later.',
            ['events' => $events],
        );

        return new Response('', 503);
    }

    /**
     * Record that this delivery's events are queued, beside the lock -- in
     * the store the job's uniqueVia() names, so every web server that can see
     * the lock can see this -- for as long as the lock can be held.
     *
     * A failure is logged and the 200 still goes out: the events ARE queued,
     * and a 500 now would only make Xero send them again. The cost is that a
     * retry arriving while the lock is held is answered 503 rather than 200,
     * and queued again once the lock is free.
     */
    private function markQueued(ProcessXeroWebhook $job): void
    {
        try {
            $job->uniqueVia()->put($this->queuedKey($job), true, $job->uniqueFor());
        } catch (Throwable $e) {
            $this->logger->warning(
                'xero-bridge: could not record that a Xero webhook was queued, so a retry of the same events '
                .'that arrives while its lock is held is answered 503 and queued again later: a possible '
                .'duplicate, never a loss.',
                ['exception' => $e->getMessage(), 'exception_class' => $e::class],
            );
        }
    }

    /**
     * Whether a delivery of these events is known to have been queued.
     * Unreadable counts as no: a 200 that is not known to be true could lose
     * the events, where a 503 costs at most a later duplicate.
     */
    private function isQueued(ProcessXeroWebhook $job): bool
    {
        try {
            return $job->uniqueVia()->has($this->queuedKey($job));
        } catch (Throwable) {
            return false;
        }
    }

    /** Keyed on the events, as the lock is, so a retry finds it whatever its entropy. */
    private function queuedKey(ProcessXeroWebhook $job): string
    {
        return 'xero-bridge:webhook-queued:'.$job->uniqueId();
    }

    /**
     * Fire XeroWebhookSignatureFailed without letting a listener change the
     * answer.
     *
     * Xero's intent-to-receive check sends incorrectly signed payloads on
     * purpose and requires a 401 for each, so a 500 fails it as surely as
     * accepting them would. A listener that throws -- a queued one while the
     * queue is down, a counter kept in the cache while the cache is down, or
     * a plain bug -- is logged instead, and the 401 goes out regardless. The
     * listener still runs inside this request, so it has to be fast or
     * queued.
     */
    private function announceSignatureFailure(Request $request, string $raw): void
    {
        try {
            XeroWebhookSignatureFailed::dispatch(
                $request->header(WebhookSignature::HEADER),
                strlen($raw),
                $request->ip(),
            );
        } catch (Throwable $e) {
            $this->logger->error(
                'xero-bridge: a XeroWebhookSignatureFailed listener failed; answered 401 regardless.',
                ['exception' => $e->getMessage(), 'exception_class' => $e::class],
            );
        }
    }

    /**
     * Take the uniqueness lock ProcessXeroWebhook declares, in the store its
     * uniqueVia() names.
     *
     * Taken here because the Bus dispatcher never takes it; see the job's
     * docblock. Switching to ProcessXeroWebhook::dispatch(), which does, would
     * be worse than the bug: PendingDispatch keeps the lock when the push
     * fails, so Xero's immediate retry would be answered 200 and the events
     * lost -- and it does not tell the caller whether it skipped the job.
     *
     * On Laravel 13 acquiring also records the lock's owner on the job, before
     * it is serialized, so the worker releases this very lock and no other;
     * 11 and 12 release by key.
     *
     * @return UniqueLock|false|null the lock when this delivery now holds
     *                               it; false when an earlier delivery of the
     *                               same events holds it; null when it could
     *                               not be taken at all
     */
    private function acquireLock(ProcessXeroWebhook $job): UniqueLock|false|null
    {
        try {
            $lock = new UniqueLock($job->uniqueVia());

            return $lock->acquire($job) ? $lock : false;
        } catch (Throwable $e) {
            // Fail OPEN: queue it without the lock. The cost is that a retry
            // of the same events may be queued twice, which listeners must
            // tolerate anyway. Answering 500 instead would turn an outage of
            // the lock store into an outage of the webhook.
            $this->logger->warning(
                'xero-bridge: could not take the uniqueness lock for a Xero webhook, so it is being queued '
                .'without one; a retry of the same events may be queued twice.',
                ['exception' => $e->getMessage(), 'exception_class' => $e::class],
            );

            return null;
        }
    }

    /**
     * Give the lock back after a failed dispatch, so Xero's retry can queue
     * the events.
     *
     * The queued marker goes first, before the lock is released. A failed
     * push proves nothing is queued, so no marker may outlive it: one an
     * earlier delivery of the same events left would answer 200 for retries
     * that arrive while the next attempt's push is still in flight. Forgotten
     * after the lock, it could delete the marker of the very retry that has
     * just queued them. A failure to forget is not logged: a marker left
     * behind only ever says that an earlier delivery of these events was
     * queued, which stays true.
     *
     * Each in its own try, so a failure here cannot replace the 500. CRITICAL
     * rather than an error: until the lock expires every retry of these
     * events is turned away with a 503, and a store that cannot give a lock
     * back is the store token refreshes lock in too.
     */
    private function releaseLock(UniqueLock $lock, ProcessXeroWebhook $job): void
    {
        try {
            $job->uniqueVia()->forget($this->queuedKey($job));
        } catch (Throwable) {
            // See the docblock: a marker left behind is still true.
        }

        try {
            $lock->release($job);
        } catch (Throwable $e) {
            $this->logger->critical(
                'xero-bridge: could not release the uniqueness lock of a Xero webhook that was not queued; '
                .'until it expires, Xero\'s retries of the same events are answered 503 without being queued.',
                [
                    'exception' => $e->getMessage(),
                    'exception_class' => $e::class,
                    'unique_for' => $job->uniqueFor(),
                ],
            );
        }
    }
}
