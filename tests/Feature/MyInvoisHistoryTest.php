<?php

declare(strict_types=1);

use Illuminate\Auth\GenericUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Peoplelogy\XeroBridge\MyInvois\IdType;
use Peoplelogy\XeroBridge\MyInvois\Models\MyInvoisValidation;
use Peoplelogy\XeroBridge\MyInvois\Models\MyInvoisValidationHistory;
use Peoplelogy\XeroBridge\MyInvois\MyInvoisAudit;
use Peoplelogy\XeroBridge\MyInvois\MyInvoisClient;
use Peoplelogy\XeroBridge\MyInvois\MyInvoisException;
use Peoplelogy\XeroBridge\MyInvois\MyInvoisHistory;
use Peoplelogy\XeroBridge\Support\TableGuard;

/**
 * The LHDN TIN check history: every attempt, one row each, never updated.
 */
class HistoryBuyer extends Model
{
    protected $table = 'history_buyers';

    protected $guarded = [];

    public $timestamps = false;
}

const HISTORY_SANDBOX = 'https://preprod-api.myinvois.hasil.gov.my';

const HISTORY_TIN = 'C25845632020';

const HISTORY_BRN = '201901234567';

const HISTORY_NRIC = '900101145678';

beforeEach(function () {
    config()->set('myinvois.enabled', true);
    config()->set('myinvois.environment', 'sandbox');
    config()->set('myinvois.client_id', 'test-myinvois-client-id');
    config()->set('myinvois.client_secret', 'test-myinvois-client-secret');
    config()->set('myinvois.history.enabled', true);

    app(TableGuard::class)->flush();

    Schema::create('history_buyers', function ($table) {
        $table->id();
        $table->string('name')->nullable();
    });
});

/**
 * @param  int|Closure  $validate  the validate endpoint's status, or a closure answering it
 */
function historyFake(int|Closure $validate = 200, array $headers = [], ?array $token = null, int $tokenStatus = 200): void
{
    Http::fake([
        HISTORY_SANDBOX.'/connect/token' => Http::response(
            $token ?? ['access_token' => 'tok', 'expires_in' => 3600],
            $tokenStatus,
        ),
        HISTORY_SANDBOX.'/api/v1.0/taxpayer/validate/*' => $validate instanceof Closure
            ? $validate
            : Http::response('', $validate, $headers),
    ]);
}

function historyClient(): MyInvoisClient
{
    return app(MyInvoisClient::class);
}

/** Run a check that is expected to throw, and hand back what it threw. */
function historyFailure(callable $check): MyInvoisException|ConnectionException
{
    try {
        $check();
    } catch (MyInvoisException|ConnectionException $e) {
        return $e;
    }

    throw new RuntimeException('The check was expected to throw.');
}

/*
|--------------------------------------------------------------------------
| Every attempt, one row each
|--------------------------------------------------------------------------
*/

it('records a match', function () {
    historyFake(200, ['correlationId' => 'corr-123']);

    expect(historyClient()->validate(HISTORY_TIN, IdType::BRN, HISTORY_BRN))->toBeTrue();

    $row = MyInvoisValidationHistory::sole();

    expect($row->outcome)->toBe(MyInvoisValidationHistory::MATCH)
        ->and($row->id_type)->toBe('BRN')
        ->and($row->http_status)->toBe(200)
        ->and($row->correlation_id)->toBe('corr-123')
        ->and($row->environment)->toBe('sandbox')
        ->and($row->rules_version)->toBe(MyInvoisAudit::CURRENT_RULES_VERSION)
        ->and($row->from_cache)->toBeFalse()
        ->and($row->error_code)->toBeNull()
        ->and($row->error)->toBeNull()
        ->and($row->duration_ms)->toBeInt()
        ->and($row->created_at)->not->toBeNull();
});

it('records a no-match as an answer, not a failure', function () {
    historyFake(404);

    expect(historyClient()->validate(HISTORY_TIN, IdType::BRN, '999999999999'))->toBeFalse();

    $row = MyInvoisValidationHistory::sole();

    expect($row->outcome)->toBe(MyInvoisValidationHistory::NO_MATCH)
        ->and($row->http_status)->toBe(404)
        ->and($row->error)->toBeNull();
});

it('adds a row for every check, where the verdict record counts', function () {
    // The difference between the two tables, and the reason this one exists.
    config()->set('myinvois.audit.enabled', true);
    historyFake(200);

    $client = historyClient();
    $client->validate(HISTORY_TIN, IdType::BRN, HISTORY_BRN);
    $client->validate(HISTORY_TIN, IdType::BRN, HISTORY_BRN);
    $client->validate(HISTORY_TIN, IdType::BRN, HISTORY_BRN);

    expect(MyInvoisValidationHistory::count())->toBe(3)
        ->and(MyInvoisValidation::count())->toBe(1)
        ->and(MyInvoisValidation::sole()->check_count)->toBe(3);
});

it('files rows under the verdict record\'s own keyed hash, so the two join', function () {
    config()->set('myinvois.audit.enabled', true);
    historyFake(200);

    historyClient()->validate(HISTORY_TIN, IdType::BRN, HISTORY_BRN);

    $hash = app(MyInvoisHistory::class)->subjectHash(HISTORY_TIN, IdType::BRN, HISTORY_BRN);

    expect(MyInvoisValidationHistory::sole()->subject_hash)->toBe($hash)
        ->and(MyInvoisValidation::sole()->subject_hash)->toBe($hash)
        ->and(MyInvoisValidationHistory::forSubject($hash)->count())->toBe(1);
});

it('records a check answered from the result cache, and says so', function () {
    config()->set('myinvois.results.matched_ttl', 3600);
    historyFake(200);

    $client = historyClient();
    $client->validate(HISTORY_TIN, IdType::BRN, HISTORY_BRN);
    $client->validate(HISTORY_TIN, IdType::BRN, HISTORY_BRN);

    Http::assertSentCount(2); // the token and ONE validate call

    $rows = MyInvoisValidationHistory::orderBy('id')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->from_cache)->toBeFalse()
        ->and($rows[1]->from_cache)->toBeTrue()
        ->and($rows[1]->outcome)->toBe(MyInvoisValidationHistory::MATCH)
        ->and($rows[1]->http_status)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Failures are history too
|--------------------------------------------------------------------------
*/

it('records credentials LHDN refused, and still throws', function () {
    historyFake(token: ['error' => 'invalid_client'], tokenStatus: 400);

    $e = historyFailure(fn () => historyClient()->validate(HISTORY_TIN, IdType::BRN, HISTORY_BRN));

    $row = MyInvoisValidationHistory::sole();

    expect($e)->toBeInstanceOf(MyInvoisException::class)
        ->and($row->outcome)->toBe(MyInvoisValidationHistory::ERROR)
        ->and($row->http_status)->toBe(400)
        ->and($row->error_code)->toBe('invalid_client')
        ->and($row->error)->toContain('refused the credentials');
});

it('records a rate limit and an LHDN outage', function (int $status) {
    historyFake($status, ['Retry-After' => '30']);

    historyFailure(fn () => historyClient()->validate(HISTORY_TIN, IdType::BRN, HISTORY_BRN));

    $row = MyInvoisValidationHistory::sole();

    expect($row->outcome)->toBe(MyInvoisValidationHistory::ERROR)
        ->and($row->http_status)->toBe($status)
        ->and($row->error)->not->toBeNull();
})->with([
    'rate limited' => 429,
    'LHDN down' => 503,
]);

it('records a setting left blank, before anything is sent', function () {
    config()->set('myinvois.client_secret', '');
    historyFake(200);

    historyFailure(fn () => historyClient()->validate(HISTORY_TIN, IdType::BRN, HISTORY_BRN));

    $row = MyInvoisValidationHistory::sole();

    Http::assertNothingSent();

    expect($row->outcome)->toBe(MyInvoisValidationHistory::ERROR)
        ->and($row->error_code)->toBe('configuration')
        ->and($row->http_status)->toBeNull()
        ->and($row->error)->toContain('MYINVOIS_CLIENT_SECRET');
});

it('records a check whose environment setting is unreadable, still keyed', function () {
    config()->set('myinvois.environment', 'staging');
    historyFake(200);

    historyFailure(fn () => historyClient()->validate(HISTORY_TIN, IdType::BRN, HISTORY_BRN));

    $row = MyInvoisValidationHistory::sole();

    expect($row->environment)->toBe('staging')
        ->and($row->error_code)->toBe('configuration')
        ->and($row->subject_hash)->toHaveLength(64)
        ->and($row->subject_hash)->not->toBe(hash('sha256', 'environment:staging|'.HISTORY_TIN.'|BRN|'.HISTORY_BRN));
});

it('records a connection that never answered, with a person\'s numbers kept out of the message too', function () {
    // An HTTP client exception quotes the URL it failed on, and that URL holds
    // the TIN in its path and the ID number in its query string.
    config()->set('myinvois.http.retries', 1);
    historyFake(fn () => throw new ConnectionException(
        'cURL error 28: Operation timed out for '.HISTORY_SANDBOX.'/api/v1.0/taxpayer/validate/'
        .HISTORY_TIN.'?idType=NRIC&idValue='.HISTORY_NRIC
    ));

    $e = historyFailure(fn () => historyClient()->validate(HISTORY_TIN, IdType::NRIC, HISTORY_NRIC));

    $row = MyInvoisValidationHistory::sole();

    expect($e)->toBeInstanceOf(ConnectionException::class)
        ->and($row->outcome)->toBe(MyInvoisValidationHistory::ERROR)
        ->and($row->http_status)->toBeNull()
        ->and($row->error_code)->toBe('ConnectionException')
        ->and($row->tin)->toBeNull()
        ->and($row->error)->toContain('Operation timed out')
        ->toContain('/validate/[redacted]?idType=NRIC&idValue=[redacted]')
        ->not->toContain(HISTORY_TIN)
        ->not->toContain(HISTORY_NRIC);
});

it('files nothing for a check that never named a pair', function (string $tin, string $idType, string $idValue) {
    // A blank number or an id type LHDN does not accept is a bug in the
    // caller, which the exception reports. There was nothing to check.
    historyFake(200);

    historyFailure(fn () => historyClient()->validate($tin, $idType, $idValue));

    expect(MyInvoisValidationHistory::count())->toBe(0);
})->with([
    'blank TIN' => ['  ', 'BRN', HISTORY_BRN],
    'blank ID number' => [HISTORY_TIN, 'BRN', ''],
    'unknown id type' => [HISTORY_TIN, 'TAXCODE', HISTORY_BRN],
]);

/*
|--------------------------------------------------------------------------
| What a row keeps of the numbers -- fixed by the id type
|--------------------------------------------------------------------------
*/

it('keeps the full TIN and BRN for a company check', function () {
    historyFake(200);

    historyClient()->validate(HISTORY_TIN, IdType::BRN, HISTORY_BRN);

    $row = MyInvoisValidationHistory::sole();

    expect($row->tin)->toBe(HISTORY_TIN)
        ->and($row->id_value)->toBe(HISTORY_BRN)
        ->and($row->tin_last4)->toBe('2020');
});

it('keeps no number at all for a person\'s check, and still records it', function (IdType $type) {
    // A person's TIN and NRIC, passport or army number are personal data:
    // not stored, not even the last four characters.
    historyFake(200);

    historyClient()->validate('IG11223344556', $type, HISTORY_NRIC);

    $row = MyInvoisValidationHistory::sole();

    expect($row->id_type)->toBe($type->value)
        ->and($row->outcome)->toBe(MyInvoisValidationHistory::MATCH)
        ->and($row->tin)->toBeNull()
        ->and($row->tin_last4)->toBeNull()
        ->and($row->id_value)->toBeNull()
        ->and(json_encode($row->toArray()))
        ->not->toContain('IG11223344556')
        ->not->toContain(HISTORY_NRIC)
        ->not->toContain('4556')
        ->not->toContain('5678');
})->with([
    'NRIC' => IdType::NRIC,
    'passport' => IdType::PASSPORT,
    'army' => IdType::ARMY,
]);

it('still finds a person\'s checks by the keyed hash', function () {
    // The way to answer "every check of this person" -- and to erase them --
    // for a caller who holds the numbers the table does not.
    historyFake(200);

    historyClient()->validate('IG11223344556', IdType::NRIC, HISTORY_NRIC);
    historyClient()->validate('IG11223344556', IdType::NRIC, HISTORY_NRIC);

    $hash = app(MyInvoisHistory::class)->subjectHash('IG11223344556', IdType::NRIC, HISTORY_NRIC);

    expect(MyInvoisValidationHistory::forSubject($hash)->count())->toBe(2);
});

it('has no setting that could put a person\'s numbers back', function () {
    expect(MyInvoisHistory::keepsNumbers(IdType::BRN))->toBeTrue()
        ->and(MyInvoisHistory::keepsNumbers(IdType::NRIC))->toBeFalse()
        ->and(MyInvoisHistory::keepsNumbers(IdType::PASSPORT))->toBeFalse()
        ->and(MyInvoisHistory::keepsNumbers(IdType::ARMY))->toBeFalse()
        ->and(require __DIR__.'/../../config/myinvois.php')->history->not->toHaveKey('identifiers');
});

/*
|--------------------------------------------------------------------------
| Who checked, and what about
|--------------------------------------------------------------------------
*/

it('records the signed-in user who ran the check', function () {
    $this->actingAs(new GenericUser(['id' => 42]));
    historyFake(200);

    historyClient()->validate(HISTORY_TIN, IdType::BRN, HISTORY_BRN);

    $row = MyInvoisValidationHistory::sole();

    expect($row->actor_id)->toBe('42')
        ->and($row->actor_type)->toBe(GenericUser::class)
        ->and($row->actor_guard)->toBe('web');
});

it('records no user for a check nobody signed in ran', function () {
    historyFake(200);

    historyClient()->validate(HISTORY_TIN, IdType::BRN, HISTORY_BRN);

    $row = MyInvoisValidationHistory::sole();

    expect($row->actor_id)->toBeNull()
        ->and($row->actor_type)->toBeNull()
        ->and($row->actor_guard)->toBeNull();
});

it('attaches the check to the record it was about', function () {
    historyFake(200);
    $buyer = HistoryBuyer::create(['name' => 'Acme']);

    historyClient()->for($buyer)->validate(HISTORY_TIN, IdType::BRN, HISTORY_BRN);

    $row = MyInvoisValidationHistory::sole();

    expect($row->owner_type)->toBe(HistoryBuyer::class)
        ->and($row->owner_id)->toBe('1')
        ->and(MyInvoisValidationHistory::forOwner(HistoryBuyer::class, '1')->count())->toBe(1);
});

it('finds matches, no-matches and failures by scope', function () {
    historyFake(fn ($request) => Http::response('', str_contains($request->url(), 'idValue=404') ? 404 : 200));

    historyClient()->validate(HISTORY_TIN, IdType::BRN, '200');
    historyClient()->validate(HISTORY_TIN, IdType::BRN, '404');
    historyFailure(fn () => historyClient()->validate(HISTORY_TIN, 'TAXCODE', '1')); // never filed

    config()->set('myinvois.client_secret', '');
    historyFailure(fn () => historyClient()->validate(HISTORY_TIN, IdType::BRN, '500'));

    expect(MyInvoisValidationHistory::matched()->count())->toBe(1)
        ->and(MyInvoisValidationHistory::unmatched()->count())->toBe(1)
        ->and(MyInvoisValidationHistory::failed()->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Off by default, and never in the way
|--------------------------------------------------------------------------
*/

it('records nothing while off', function () {
    config()->set('myinvois.history.enabled', false);
    historyFake(200);

    expect(historyClient()->validate(HISTORY_TIN, IdType::BRN, HISTORY_BRN))->toBeTrue()
        ->and(MyInvoisValidationHistory::count())->toBe(0);
});

it('is off unless switched on', function () {
    expect(require __DIR__.'/../../config/myinvois.php')->history->enabled->toBeFalse();
});

it('records nothing, and still validates, when the table is absent', function () {
    config()->set('myinvois.history.table', 'not_a_real_table');
    app(TableGuard::class)->flush();
    historyFake(200);

    expect(historyClient()->validate(HISTORY_TIN, IdType::BRN, HISTORY_BRN))->toBeTrue();
});

it('reports a check it could not record, and still answers', function () {
    Log::spy();
    app()->forgetInstance(MyInvoisHistory::class);
    app()->forgetInstance(MyInvoisClient::class);
    historyFake(200);

    MyInvoisValidationHistory::creating(function (MyInvoisValidationHistory $row): void {
        $row->setAttribute('environment', null);
    });

    expect(historyClient()->validate(HISTORY_TIN, IdType::BRN, HISTORY_BRN))->toBeTrue()
        ->and(MyInvoisValidationHistory::count())->toBe(0);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message) => str_contains($message, 'could not record a MyInvois check in the history'))
        ->once();
});

it('files a failed check once, and throws what the client threw', function () {
    historyFake(503);

    $e = historyFailure(fn () => historyClient()->validate(HISTORY_TIN, IdType::BRN, HISTORY_BRN));

    expect($e)->toBeInstanceOf(MyInvoisException::class)
        ->and($e->statusCode())->toBe(503)
        ->and(MyInvoisValidationHistory::count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Erasure and retention
|--------------------------------------------------------------------------
*/

it('forgets every check of one subject', function () {
    historyFake(200);

    historyClient()->validate(HISTORY_TIN, IdType::BRN, HISTORY_BRN);
    historyClient()->validate(HISTORY_TIN, IdType::BRN, HISTORY_BRN);
    historyClient()->validate('C99999999999', IdType::BRN, HISTORY_BRN);

    expect(app(MyInvoisHistory::class)->forgetSubject(HISTORY_TIN, IdType::BRN, HISTORY_BRN))->toBe(2)
        ->and(MyInvoisValidationHistory::count())->toBe(1);
});

it('forgets every check attached to a record', function () {
    historyFake(200);
    $buyer = HistoryBuyer::create(['name' => 'Acme']);

    historyClient()->for($buyer)->validate(HISTORY_TIN, IdType::BRN, HISTORY_BRN);
    historyClient()->validate(HISTORY_TIN, IdType::BRN, HISTORY_BRN);

    expect(app(MyInvoisHistory::class)->forgetOwner($buyer))->toBe(1)
        ->and(MyInvoisValidationHistory::count())->toBe(1);
});

it('prunes checks older than the retention window', function () {
    config()->set('myinvois.history.retain_days', 30);
    historyFake(200);

    historyClient()->validate(HISTORY_TIN, IdType::BRN, HISTORY_BRN);
    historyClient()->validate(HISTORY_TIN, IdType::BRN, HISTORY_BRN);

    MyInvoisValidationHistory::query()->whereKey(1)->update(['created_at' => now()->subDays(31)]);

    expect((new MyInvoisValidationHistory)->prunable()->count())->toBe(1);
});
