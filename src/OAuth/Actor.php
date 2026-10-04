<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\OAuth;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\ClassMorphViolationException;
use Illuminate\Database\Eloquent\Model;

/**
 * Who was signed in when something happened -- as three plain scalars.
 *
 * Deliberately NOT the user model. No event in this package uses
 * SerializesModels, so a model on an event is serialised whole into every
 * queued listener's payload, and into failed_jobs when that listener fails:
 * attributes and originals, which means the password hash and the plaintext
 * remember_token. Adding SerializesModels instead would re-fetch the user at
 * handle time and fail the job outright once the user was deleted. An id and
 * a type carry everything an audit trail needs, survive the queue unchanged,
 * and work for incrementing, uuid and ulid keys alike.
 *
 * Public API, like the events that carry it -- which is why it lives here,
 * beside TenantInfo and TokenResponse, and not under src/Support.
 */
final class Actor
{
    public function __construct(
        /**
         * The guard's auth identifier, of the type the guard returned it: an
         * int for an incrementing key, a string for a uuid or ulid. Some user
         * providers return numeric keys as strings, so compare loosely or cast.
         */
        public readonly int|string $id,
        /**
         * The morph alias when the user model has one mapped, so it matches
         * what the host already stores in its own *_type columns; otherwise the
         * class name.
         */
        public readonly string $type,
        /** The auth guard the user was signed in through, e.g. 'web' or 'sanctum'. */
        public readonly ?string $guard = null,
    ) {}

    /**
     * Null when nobody is signed in, and when the identifier is anything but
     * an int or a string. A value object or an array would bring back exactly
     * the serialisation problem this class exists to avoid.
     */
    public static function from(?Authenticatable $user, ?string $guard = null): ?self
    {
        if ($user === null) {
            return null;
        }

        $id = $user->getAuthIdentifier();

        if (! is_int($id) && ! is_string($id)) {
            return null;
        }

        return new self($id, self::typeOf($user), $guard);
    }

    private static function typeOf(Authenticatable $user): string
    {
        if (! $user instanceof Model) {
            return $user::class;
        }

        try {
            // Cast: a morph map keyed by a numeric string ('1' => User::class)
            // comes back from PHP as an int key.
            return (string) $user->getMorphClass();
        } catch (ClassMorphViolationException) {
            // Relation::enforceMorphMap() is on and the user model is not in
            // the map. Recording who connected must not fail over that, and
            // the class name is still an exact answer.
            return $user::class;
        }
    }
}
