<?php

declare(strict_types=1);

use Illuminate\Auth\GenericUser;
use Illuminate\Database\ClassMorphViolationException;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Event;
use Peoplelogy\XeroBridge\Events\XeroConnected;
use Peoplelogy\XeroBridge\Models\XeroConnection;
use Peoplelogy\XeroBridge\OAuth\Actor;

/*
| Actor is who was signed in, as plain scalars, and XeroConnected carries it.
| These pin the value itself and the two ways XeroConnected must stay
| compatible: with every 1.4 construction, and with an event put on the queue
| by 1.4, before the property existed.
*/

afterEach(function () {
    // Both are static on Relation, so a test that sets them would otherwise
    // decide the morph class of every model in every later test.
    Relation::morphMap([], false);
    Relation::requireMorphMap(false);
});

it('is null when nobody is signed in', function () {
    expect(Actor::from(null))->toBeNull()
        ->and(Actor::from(null, 'web'))->toBeNull();
});

it('keeps an integer id as an integer, with its type and guard', function () {
    $actor = Actor::from(new GenericUser(['id' => 42]), 'web');

    expect($actor)->toBeInstanceOf(Actor::class)
        ->and($actor->id)->toBe(42)
        ->and($actor->type)->toBe(GenericUser::class)
        ->and($actor->guard)->toBe('web');
});

it('keeps a string id, such as a ULID, as the same string', function () {
    $actor = Actor::from(new GenericUser(['id' => '01J9ZQ3K8M2N4P6R8T0V2X4Z6B']));

    expect($actor?->id)->toBe('01J9ZQ3K8M2N4P6R8T0V2X4Z6B')
        ->and($actor?->type)->toBe(GenericUser::class)
        ->and($actor?->guard)->toBeNull();
});

it('uses the class name of an Eloquent user that has no morph alias', function () {
    $actor = Actor::from((new User)->forceFill(['id' => 7]));

    expect($actor?->id)->toBe(7)
        ->and($actor?->type)->toBe(User::class);
});

it('uses the morph alias when the user model has one mapped', function () {
    // The value the host already stores in its own *_type columns.
    Relation::morphMap(['staff' => User::class]);

    expect(Actor::from((new User)->forceFill(['id' => 7]))?->type)->toBe('staff');
});

it('falls back to the class name when an enforced morph map leaves the user model out', function () {
    Relation::enforceMorphMap(['xero_connection' => XeroConnection::class]);

    $user = (new User)->forceFill(['id' => 7]);

    // The state the fallback exists for: asking the model itself throws.
    expect(fn () => $user->getMorphClass())->toThrow(ClassMorphViolationException::class);

    expect(Actor::from($user)?->type)->toBe(User::class);
});

it('gives null for an identifier that is neither an int nor a string', function (mixed $id) {
    expect(Actor::from(new GenericUser(['id' => $id]), 'web'))->toBeNull();
})->with([
    'null' => [null],
    'float' => [1.5],
    'bool' => [true],
    'array' => [[42]],
    'object' => [new stdClass],
]);

it('holds scalars only, and survives serialisation unchanged', function () {
    $user = new GenericUser(['id' => 42, 'password' => 'password-hash-sentinel']);
    $actor = Actor::from($user, 'web');

    foreach (get_object_vars($actor) as $value) {
        expect($value === null || is_scalar($value))->toBeTrue();
    }

    $payload = serialize($actor);
    $copy = unserialize($payload);

    expect($payload)->not->toContain('password-hash-sentinel')
        ->and($copy)->toEqual($actor)
        ->and($copy->id)->toBe(42)
        ->and($copy->type)->toBe(GenericUser::class)
        ->and($copy->guard)->toBe('web');
});

it('can be built directly, for a host test that fakes the event', function () {
    $actor = new Actor('01J9ZQ3K8M2N4P6R8T0V2X4Z6B', 'user');

    expect($actor->id)->toBe('01J9ZQ3K8M2N4P6R8T0V2X4Z6B')
        ->and($actor->type)->toBe('user')
        ->and($actor->guard)->toBeNull();
});

it('keeps every 1.4 way of building XeroConnected working, with no actor', function () {
    $connection = connection();

    $plain = new XeroConnected($connection);
    $positional = new XeroConnected($connection, true);
    $named = new XeroConnected(connection: $connection, wasRepointed: true);

    expect($plain->actor)->toBeNull()
        ->and($plain->wasRepointed)->toBeFalse()
        ->and($positional->wasRepointed)->toBeTrue()
        ->and($positional->actor)->toBeNull()
        ->and($named->wasRepointed)->toBeTrue()
        ->and($named->actor)->toBeNull();

    Event::fake([XeroConnected::class]);

    XeroConnected::dispatch($connection, true);

    Event::assertDispatched(XeroConnected::class, fn (XeroConnected $event) => $event->wasRepointed
        && $event->actor === null
        && $event->connection->is($connection));
});

it('carries the actor it is given, positionally or by name', function () {
    $connection = connection();
    $actor = new Actor(42, 'user', 'web');

    expect((new XeroConnected($connection, false, $actor))->actor)->toBe($actor)
        ->and((new XeroConnected(connection: $connection, actor: $actor))->actor)->toBe($actor);
});

it('reads an event queued by 1.4, with no actor in its payload, as actor null', function () {
    // A queued listener still waiting when the deploy lands was serialised by
    // the previous class: same name, connection and wasRepointed only. Built
    // here by taking the property out of a current payload.
    $current = serialize(new XeroConnected(connection(), true));

    $old = preg_replace(
        '/^(O:\d+:"[^"]+":)3:/',
        '${1}2:',
        str_replace('s:5:"actor";N;', '', $current),
    );

    expect($current)->toContain('s:5:"actor";N;')
        ->and($old)->not->toContain('s:5:"actor"')
        ->and($old)->toStartWith('O:'.strlen(XeroConnected::class).':"'.XeroConnected::class.'":2:{');

    $event = unserialize($old);

    // A promoted readonly property would be left uninitialised here, and this
    // first read would throw instead of giving null.
    expect($event)->toBeInstanceOf(XeroConnected::class)
        ->and($event->actor)->toBeNull()
        ->and($event->wasRepointed)->toBeTrue()
        ->and($event->connection->key)->toBe('default');
});
