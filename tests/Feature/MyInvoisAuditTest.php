<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Peoplelogy\XeroBridge\MyInvois\IdType;
use Peoplelogy\XeroBridge\MyInvois\Models\MyInvoisValidation;
use Peoplelogy\XeroBridge\MyInvois\MyInvoisAudit;
use Peoplelogy\XeroBridge\MyInvois\MyInvoisClient;
use Peoplelogy\XeroBridge\Support\TableGuard;

/**
 * The LHDN TIN verdict record, and the privacy position it rests on.
 */
class AuditBuyer extends Model
{
    protected $table = 'audit_buyers';

    protected $guarded = [];

    public $timestamps = false;
}

const AUDIT_SANDBOX = 'https://preprod-api.myinvois.hasil.gov.my';

beforeEach(function () {
    config()->set('myinvois.enabled', true);
    config()->set('myinvois.environment', 'sandbox');
    config()->set('myinvois.client_id', 'test-myinvois-client-id');
    config()->set('myinvois.client_secret', 'test-myinvois-client-secret');
    config()->set('myinvois.audit.enabled', true);

    app(TableGuard::class)->flush();

    Schema::create('audit_buyers', function ($table) {
        $table->id();
        $table->string('name')->nullable();
    });
});

function fakeVerdict(int $status = 200, array $headers = []): void
{
    Http::fake([
        AUDIT_SANDBOX.'/connect/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
        AUDIT_SANDBOX.'/api/v1.0/taxpayer/validate/*' => Http::response('', $status, $headers),
    ]);
}

function auditClient(): MyInvoisClient
{
    return app(MyInvoisClient::class);
}

/**
 * Spy on the log the audit writes to. The audit and the client holding it are
 * singletons that keep the logger they were built with, so both are rebuilt.
 */
function auditLogSpy(): void
{
    Log::spy();
    app()->forgetInstance(MyInvoisAudit::class);
    app()->forgetInstance(MyInvoisClient::class);
}

/*
|--------------------------------------------------------------------------
| Recording a verdict
|--------------------------------------------------------------------------
*/

it('records a positive verdict', function () {
    fakeVerdict(200, ['correlationId' => 'corr-123']);

    expect(auditClient()->validate('C25845632020', IdType::BRN, '201901234567'))->toBeTrue();

    $row = MyInvoisValidation::sole();

    expect($row->verdict)->toBeTrue()
        ->and($row->id_type)->toBe('BRN')
        ->and($row->http_status)->toBe(200)
        ->and($row->environment)->toBe('sandbox')
        ->and($row->rules_version)->toBe(MyInvoisAudit::CURRENT_RULES_VERSION)
        ->and($row->check_count)->toBe(1);
});

it('records a negative verdict too', function () {
    // An audit that only holds the yeses is not an audit. "LHDN has no record
    // of this pair" is exactly the answer somebody will ask about later.
    fakeVerdict(404);

    expect(auditClient()->validate('C1', IdType::BRN, '999'))->toBeFalse();

    expect(MyInvoisValidation::sole()->verdict)->toBeFalse();
    expect(MyInvoisValidation::sole()->http_status)->toBe(404);
});

it('captures the correlation id LHDN support asks for', function () {
    // Until now this was read only when building an exception, so every actual
    // verdict threw it away.
    fakeVerdict(200, ['correlationId' => 'corr-abc']);

    auditClient()->validate('C1', IdType::BRN, '2');

    expect(MyInvoisValidation::sole()->correlation_id)->toBe('corr-abc');

    // Also readable directly, for a caller that wants to log it without
    // switching the audit table on.
    expect(auditClient()->lastCorrelationId())->toBe('corr-abc');
});

it('counts a re-check instead of adding a row', function () {
    fakeVerdict(200);

    $client = auditClient();
    $client->validate('C1', IdType::BRN, '2');
    $client->validate('C1', IdType::BRN, '2');
    $client->validate('C1', IdType::BRN, '2');

    expect(MyInvoisValidation::count())->toBe(1)
        ->and(MyInvoisValidation::sole()->check_count)->toBe(3);
});

it('attaches the verdict to the buyer when one is named', function () {
    fakeVerdict(200);

    $buyer = AuditBuyer::create(['name' => 'Acme']);

    auditClient()->for($buyer)->validate('C1', IdType::BRN, '2');

    $row = MyInvoisValidation::sole();

    expect($row->owner_type)->toBe(AuditBuyer::class)
        ->and($row->owner_id)->toBe('1');
});

/*
|--------------------------------------------------------------------------
| Privacy -- the gate on this whole half
|--------------------------------------------------------------------------
*/

it('never stores the TIN or the identifier', function () {
    fakeVerdict(200);

    auditClient()->validate('C25845632020', IdType::BRN, '201901234567');

    $encoded = json_encode(MyInvoisValidation::sole()->toArray());

    expect($encoded)->not->toContain('C25845632020')
        ->not->toContain('201901234567');
});

it('keeps only the last four characters of the TIN, for humans', function () {
    fakeVerdict(200);

    auditClient()->validate('C25845632020', IdType::BRN, '201901234567');

    expect(MyInvoisValidation::sole()->tin_last4)->toBe('2020');
});

it('uses a KEYED hash, not a bare one', function () {
    // THE REGRESSION GUARD. A Malaysian registration number is twelve digits,
    // so a bare sha256 is exhausted on a laptop in under a second -- anyone
    // with the table would have the identifiers. If somebody ever "simplifies"
    // the HMAC back to hash('sha256', ...), this fails.
    $audit = app(MyInvoisAudit::class);

    $subject = implode('|', [
        config('myinvois.base_urls.sandbox'),
        'C25845632020',
        'BRN',
        '201901234567',
    ]);

    expect($audit->subjectHash('C25845632020', IdType::BRN, '201901234567'))
        ->not->toBe(hash('sha256', $subject));
});

it('produces a different hash under a different application key', function () {
    // The other half of the same property: the key is load-bearing, so a
    // stolen database alone is not enough.
    $audit = app(MyInvoisAudit::class);
    $before = $audit->subjectHash('C1', IdType::BRN, '2');

    config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

    expect($audit->subjectHash('C1', IdType::BRN, '2'))->not->toBe($before);
});

it('separates sandbox and production subjects', function () {
    // A sandbox verdict must never be read as evidence about a real taxpayer.
    $audit = app(MyInvoisAudit::class);
    $sandbox = $audit->subjectHash('C1', IdType::BRN, '2');

    config()->set('myinvois.environment', 'production');

    expect($audit->subjectHash('C1', IdType::BRN, '2'))->not->toBe($sandbox);
});

/*
|--------------------------------------------------------------------------
| Erasure
|--------------------------------------------------------------------------
*/

it('forgets every verdict about one subject', function () {
    fakeVerdict(200);

    auditClient()->validate('C1', IdType::BRN, '2');

    expect(app(MyInvoisAudit::class)->forgetSubject('C1', IdType::BRN, '2'))->toBe(1)
        ->and(MyInvoisValidation::count())->toBe(0);
});

it('forgets every verdict attached to a buyer', function () {
    // The usual hook: call this from the buyer model's deleting event, so
    // erasing a customer erases what was checked about them.
    fakeVerdict(200);

    $buyer = AuditBuyer::create(['name' => 'Acme']);
    auditClient()->for($buyer)->validate('C1', IdType::BRN, '2');

    expect(app(MyInvoisAudit::class)->forgetOwner($buyer))->toBe(1)
        ->and(MyInvoisValidation::count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Staleness and the disabled default
|--------------------------------------------------------------------------
*/

it('marks a verdict recorded under older rules as stale', function () {
    // A "valid" from before 1 August 2026 answered a weaker question: LHDN did
    // not yet check the TIN and the identifier as a pair.
    fakeVerdict(200);

    auditClient()->validate('C1', IdType::BRN, '2');

    $row = MyInvoisValidation::sole();
    $row->update(['rules_version' => 1]);

    expect($row->fresh()->isStale(MyInvoisAudit::CURRENT_RULES_VERSION))->toBeTrue();
});

it('records nothing when the audit is off', function () {
    config()->set('myinvois.audit.enabled', false);

    fakeVerdict(200);

    expect(auditClient()->validate('C1', IdType::BRN, '2'))->toBeTrue();
    expect(MyInvoisValidation::count())->toBe(0);
});

it('records nothing, and still validates, when the table is absent', function () {
    config()->set('myinvois.audit.table', 'not_a_real_table');
    app(TableGuard::class)->flush();

    fakeVerdict(200);

    expect(auditClient()->validate('C1', IdType::BRN, '2'))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| A lost verdict is reported; a race that was won is not
|--------------------------------------------------------------------------
*/

it('reports a verdict it could not record rather than taking it for a race', function () {
    auditLogSpy();
    fakeVerdict(200);

    // A required column arriving empty -- the same NOT NULL failure SQLite
    // reports for a column a host added without a default. It shares
    // SQLSTATE 23000 with a duplicate, which is how it used to vanish in
    // silence.
    MyInvoisValidation::creating(function (MyInvoisValidation $row): void {
        $row->setAttribute('environment', null);
    });

    // Recording still never takes down the validation it records.
    expect(auditClient()->validate('C1', IdType::BRN, '2'))->toBeTrue()
        ->and(MyInvoisValidation::count())->toBe(0);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message) => str_contains($message, 'could not record a MyInvois verdict'))
        ->once();
});

it('stays quiet when another process recorded the same subject first', function () {
    auditLogSpy();
    fakeVerdict(200);

    // The other process's row lands between this one's read and its insert,
    // so the insert hits the unique subject_hash: the constraint did its job.
    MyInvoisValidation::creating(function (MyInvoisValidation $row): void {
        MyInvoisValidation::query()->insert($row->getAttributes());
    });

    expect(auditClient()->validate('C1', IdType::BRN, '2'))->toBeTrue()
        ->and(MyInvoisValidation::count())->toBe(1);

    Log::shouldNotHaveReceived('warning');
});

it('prunes verdicts nobody has re-checked', function () {
    fakeVerdict(200);

    auditClient()->validate('C1', IdType::BRN, '2');

    MyInvoisValidation::query()->update(['last_checked_at' => now()->subDays(500)]);

    expect((new MyInvoisValidation)->prunable()->count())->toBe(1);
});
