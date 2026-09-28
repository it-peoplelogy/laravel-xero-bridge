<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\Resources;

/**
 * Read-only access to the organisation's own configuration.
 *
 * This is the resource you need BEFORE invoices, not after:
 *
 *  - You cannot build a line item without an account code, and account codes
 *    differ per organisation -- a '200' sales account in one org may be
 *    '4000' in another. Nothing should ever be hardcoded.
 *  - You cannot apply a payment without an account Xero will accept, and the
 *    only way to discover those is GET /Accounts.
 *  - Malaysian SST is per line (training 8%, education and rental 6%), so a
 *    caller needs the org's actual TaxType codes rather than a guess.
 *
 * Everything here needs the `accounting.settings` scope.
 *
 * Results are deliberately NOT cached in this version: a chart of accounts
 * changes rarely but the right cache key, TTL and invalidation depend
 * entirely on the consuming application. Cache it there.
 */
class Settings extends Resource
{
    protected function endpoint(): string
    {
        return 'Accounts';
    }

    /**
     * The chart of accounts.
     *
     * @param  array<string, mixed>  $query  e.g. ['where' => 'Type=="BANK"']
     * @return list<array<string, mixed>>
     */
    public function accounts(array $query = []): array
    {
        return $this->unwrap($this->client->get('Accounts', $query));
    }

    /**
     * Only the accounts a payment can actually be applied to.
     *
     * Xero's rule: the account must be of type BANK, or have "enable
     * payments to this account" switched on. Filtered client-side because
     * that is an OR across two different fields.
     *
     * @return list<array<string, mixed>>
     */
    public function paymentAccounts(): array
    {
        return array_values(array_filter(
            $this->accounts(),
            static function (array $account): bool {
                $isBank = strcasecmp((string) ($account['Type'] ?? ''), 'BANK') === 0;
                $enabled = filter_var(
                    $account['EnablePaymentsToAccount'] ?? false,
                    FILTER_VALIDATE_BOOLEAN,
                );

                return $isBank || $enabled;
            },
        ));
    }

    /**
     * The organisation's tax rates.
     *
     * TaxType is the CODE sent on a line item; this also returns the
     * human-readable Name and the effective rate.
     *
     * @param  array<string, mixed>  $query
     * @return list<array<string, mixed>>
     */
    public function taxRates(array $query = []): array
    {
        $body = $this->client->get('TaxRates', $query);

        $rates = $body['TaxRates'] ?? [];

        return is_array($rates) ? array_values($rates) : [];
    }

    /**
     * The connected organisation. Useful for a sanity check: base currency,
     * country and whether it is a demo company.
     *
     * @return array<string, mixed>
     */
    public function organisation(): array
    {
        $body = $this->client->get('Organisation');

        $organisations = $body['Organisations'] ?? [];

        return (is_array($organisations) && $organisations !== [])
            ? (array) $organisations[0]
            : [];
    }

    /**
     * Branding themes, for the BrandingThemeID on an invoice.
     *
     * @return list<array<string, mixed>>
     */
    public function brandingThemes(): array
    {
        $body = $this->client->get('BrandingThemes');

        $themes = $body['BrandingThemes'] ?? [];

        return is_array($themes) ? array_values($themes) : [];
    }
}
