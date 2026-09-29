<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\Resources;

use InvalidArgumentException;
use Peoplelogy\XeroBridge\Exceptions\XeroBridgeException;
use Peoplelogy\XeroBridge\Exceptions\XeroValidationException;
use Throwable;

class Contacts extends Resource
{
    protected function endpoint(): string
    {
        return 'Contacts';
    }

    /** @return array<string, mixed>|null */
    public function find(string $contactId): ?array
    {
        if ($contactId === '' || str_contains($contactId, '/')) {
            throw new XeroBridgeException("[{$contactId}] is not a usable contact identifier.");
        }

        return $this->unwrapFirst(
            $this->client->get($this->endpoint().'/'.$this->pathSegment($contactId))
        );
    }

    /**
     * Exact-match lookup by email.
     *
     * Uses `where=EmailAddress=="..."`, which Xero documents as the optimised
     * form. It explicitly calls `EmailAddress.StartsWith(...)` an anti-pattern
     * and points at SearchTerm for substring matching instead.
     *
     * @return array<string, mixed>|null
     */
    public function findByEmail(string $email): ?array
    {
        return $this->findAllByEmail($email)[0] ?? null;
    }

    /**
     * Every contact with this email. Xero does not enforce uniqueness, so
     * duplicates are possible and silently taking the first can be wrong.
     *
     * @return list<array<string, mixed>>
     */
    public function findAllByEmail(string $email): array
    {
        $this->assertNoQuote($email, 'email address');

        return $this->unwrap($this->client->get($this->endpoint(), [
            'where' => sprintf('EmailAddress=="%s"', $email),
        ]));
    }

    /** @return list<array<string, mixed>> */
    public function findAllByName(string $name): array
    {
        $this->assertNoQuote($name, 'name');

        return $this->unwrap($this->client->get($this->endpoint(), [
            'where' => sprintf('Name=="%s"', $name),
        ]));
    }

    /** Case-insensitive substring search across several fields. */
    public function search(string $term): array
    {
        return $this->unwrap($this->client->get($this->endpoint(), ['SearchTerm' => $term]));
    }

    /**
     * Create a contact.
     *
     * Uses PUT, which is CREATE-ONLY: Xero errors if the ContactName or
     * ContactNumber already exists. POST would instead silently upsert, and
     * an accidental upsert overwrites a real customer record.
     *
     * @param  array<string, mixed>  $contact
     * @return array<string, mixed>
     */
    public function create(array $contact): array
    {
        $this->assertValidName($contact['Name'] ?? null);

        $claim = $this->claimWrite('contact.create');

        try {
            $body = $this->client->put($this->endpoint(), ['Contacts' => [$contact]]);
        } catch (Throwable $e) {
            $this->releaseWriteOnProvenFailure($claim, $e);

            throw $e;
        }

        $created = $this->unwrapFirst($body) ?? [];

        $this->confirmWrite($claim, $created);

        return $created;
    }

    /**
     * Update an existing contact by ContactID. Omitted elements are preserved.
     *
     * @param  array<string, mixed>  $contact
     * @return array<string, mixed>
     */
    public function update(string $contactId, array $contact): array
    {
        return $this->unwrapFirst($this->client->post(
            $this->endpoint().'/'.$this->pathSegment($contactId),
            ['Contacts' => [$contact]],
        )) ?? [];
    }

    /**
     * Find a contact by one lookup key, or create it.
     *
     * Deliberately NOT built on POST /Contacts' implicit upsert. Xero warns:
     * "'Contact Name' may no longer be a unique field... we recommend all
     * developers use ContactID to uniquely reference contacts". With a POST
     * the loser of a race silently overwrites the winner's record; with an
     * explicit lookup then a create-only PUT, the loser gets a clean conflict
     * that is resolved by looking the winner up.
     *
     * Typed loosely on purpose: the key is validated at runtime, because a
     * caller can pass anything and the error message needs to name what they
     * actually sent.
     *
     * @param  array<string, string>  $lookup  exactly one of EmailAddress or Name
     * @param  array<string, mixed>  $attributes  merged in when creating
     * @return array<string, mixed>
     */
    public function firstOrCreate(array $lookup, array $attributes = []): array
    {
        if (count($lookup) !== 1) {
            throw new InvalidArgumentException(
                'firstOrCreate() takes exactly one lookup key, either EmailAddress or Name.'
            );
        }

        $field = array_key_first($lookup);
        $value = (string) $lookup[$field];

        if (! in_array($field, ['EmailAddress', 'Name'], true)) {
            throw new InvalidArgumentException(
                "Contacts can only be looked up by EmailAddress or Name, got [{$field}]."
            );
        }

        if (($existing = $this->lookup($field, $value)) !== null) {
            return $existing;
        }

        try {
            return $this->create(array_merge($lookup, $attributes));
        } catch (XeroValidationException $e) {
            // Someone else created it between our lookup and our PUT. The
            // create-only PUT is what turns that into a clean error instead
            // of a silent overwrite.
            if (($winner = $this->lookup($field, $value)) !== null) {
                return $winner;
            }

            throw $e;
        }
    }

    /**
     * Marked impure because it performs a live lookup against Xero: calling
     * it twice with the same arguments can legitimately give different
     * answers, which is exactly the race firstOrCreate() resolves.
     *
     * @phpstan-impure
     *
     * @return array<string, mixed>|null
     */
    private function lookup(string $field, string $value): ?array
    {
        return $field === 'EmailAddress'
            ? $this->findByEmail($value)
            : ($this->findAllByName($value)[0] ?? null);
    }

    private function assertValidName(mixed $name): void
    {
        if (! is_string($name) || trim($name) === '') {
            throw new InvalidArgumentException('A contact needs a Name.');
        }

        // Xero's documented rules, checked here so the failure names the rule
        // rather than arriving as a generic validation error.
        if (mb_strlen($name) > 255) {
            throw new InvalidArgumentException('A contact Name cannot exceed 255 characters.');
        }

        if (str_contains($name, '<') || str_contains($name, '>')) {
            throw new InvalidArgumentException('A contact Name cannot contain angle brackets.');
        }

        if ($name !== trim($name)) {
            throw new InvalidArgumentException('A contact Name cannot start or end with whitespace.');
        }

        if (preg_match('/\s{2,}/', $name) === 1) {
            throw new InvalidArgumentException('A contact Name cannot contain repeated spaces.');
        }
    }

    private function assertNoQuote(string $value, string $label): void
    {
        if (str_contains($value, '"')) {
            throw new InvalidArgumentException(
                "A {$label} used in a Xero filter cannot contain a double quote."
            );
        }
    }
}
