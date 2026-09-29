<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\Support;

/**
 * What must never reach the capture table, decided once and frozen.
 *
 * The capture feature stores the request and response of every Xero and LHDN
 * call so a consuming project can render them on its own dashboard. That makes
 * this class the only thing standing between "a useful audit trail" and "a
 * table full of bearer tokens and other people's bank accounts".
 *
 * THREE TIERS, and the difference between them is who may switch them off:
 *
 *   ALWAYS      Credentials and bank details. `capture.redact.keep` CANNOT
 *               remove these. Two exceptions' docblocks promise in writing that
 *               nothing this package produces carries a token or the client
 *               secret; a config key able to break that promise would make the
 *               promise false. Bank details sit here by the package owner's
 *               explicit decision -- "save everything except bank account" is a
 *               requirement, so it may not be undone by one line of config.
 *
 *   DEFAULTS    Tax identifiers and identity data. Removable through
 *               `capture.redact.keep` by a project that has decided it needs
 *               them and has somewhere lawful to put them.
 *
 *   OAUTH_ONLY  Names that are credentials in one place and ordinary data in
 *               another. `code` is an authorisation grant in a token request
 *               and a Xero ACCOUNT CODE everywhere else -- redacting it
 *               globally would destroy the single most useful field in the
 *               capture. So these match only on a token channel, and only at
 *               the top level, where the OAuth form bodies are flat.
 *
 * MATCHING IS EXACT AND CASE-INSENSITIVE. Never substring, never prefix. The
 * bank fields share a stem and the temptation to match on it is strong; doing
 * so eats `BankAccountType` (the BANK/CREDITCARD enum that explains a rejected
 * payment), `AccountNumber` (which is NOT a bank account -- Xero defines it as
 * a user-defined number, in practice the consuming project's own customer code)
 * and `AccountCode` (which Payments::create() actually sends). Case-insensitivity
 * is what lets one list cover four naming conventions at once: Xero is
 * PascalCase, MyInvois query parameters are camelCase, OAuth bodies are
 * snake_case, and header casing is whatever the client sent.
 */
final class RedactionPolicy
{
    /** Channels whose payloads are token exchanges rather than business data. */
    private const OAUTH_CHANNELS = ['xero.identity', 'myinvois.token'];

    /**
     * Tier 1. Not removable by configuration.
     *
     * @var array<string, true>
     */
    public const ALWAYS = [
        // -- credentials ------------------------------------------------
        // On every single call. XeroHttpClient sets it via withToken().
        'authorization' => true,
        'proxy-authorization' => true,

        // The token endpoints' response bodies. `refresh_token` is the worst
        // leak available here: the package stores it ENCRYPTED on the
        // connection row, so capturing the raw exchange would write a
        // plaintext copy of the very thing that is encrypted elsewhere. It is
        // also rotating, so the table would accumulate every one ever held.
        'access_token' => true,
        'refresh_token' => true,

        // Deliberately never exposed or stored today -- see TokenResponse.
        // Capturing it would quietly reverse that decision.
        'id_token' => true,

        'client_secret' => true,

        // The HMAC over an inbound webhook body. Storing signature and body
        // side by side hands an attacker material against the signing key.
        'x-xero-signature' => true,

        // Organisation.APIKey. A live Xero-to-Xero credential sitting on an
        // endpoint everyone thinks of as harmless settings data.
        'apikey' => true,

        // -- bank -------------------------------------------------------
        // One name, two meanings, two depths: Account.BankAccountNumber is our
        // own organisation's, and Payment.BankAccountNumber is the supplier's.
        // An exact-name rule covers both; a path-based one would have to list
        // each and would miss the next.
        'bankaccountnumber' => true,

        // The CUSTOMER's account number, echoed back on every contact read.
        // The package never sets or reads it -- it arrives purely because Xero
        // returns the whole contact.
        'bankaccountdetails' => true,

        // The whole object, not just its number: it also carries
        // BankAccountName, Details, Code and Reference. Redacting the key takes
        // the entire subtree, which is the only way the siblings go with it.
        // Both spellings: Contact carries `BatchPayments`, a Payment read
        // carries a nested `BatchPayment`.
        'batchpayments' => true,
        'batchpayment' => true,

        // Listed separately because a rule keyed on "BankAccountNumber" leaves
        // this sibling behind wherever it appears outside the object above.
        'bankaccountname' => true,
    ];

    /**
     * Tier 2. Removable through `capture.redact.keep`.
     *
     * @var array<string, true>
     */
    public const DEFAULTS = [
        // The Malaysian TIN. Masked to its last four rather than blanked --
        // see MASK_LAST4 for why that is the right disclosure level here.
        'taxnumber' => true,

        // A US federal EIN, on GET /Organisation. Never read by this package.
        'employeridentificationnumber' => true,

        // The MyInvois query parameter: an NRIC, passport, army number or BRN.
        // An NRIC encodes date of birth, birth state and gender.
        'idvalue' => true,

        // The MyInvois header an intermediary sends: a TIN, or TIN:BRN.
        'onbehalfof' => true,

        // LHDN's error envelope names the offending item here, and on a
        // BadArgument from taxpayer-validate that item is the submitted
        // identifier itself. This is the one channel that puts the TIN back in
        // the table after the path and the query string have been scrubbed.
        'target' => true,

        // A stable key letting any Xero org address this contact's org.
        'xeronetworkkey' => true,

        // A public, unauthenticated capability link: whoever holds it can view
        // the invoice and, with a payment service configured, pay it. Also
        // excluded by URL, because on its own endpoint it is the only field
        // there is and a redacted row would store nothing at all.
        'onlineinvoiceurl' => true,

        // Free text a human typed to find a customer -- in practice a name or
        // an email address. Unlike `where` it has no structure to preserve, so
        // there is nothing to keep once the value is gone.
        'searchterm' => true,
    ];

    /**
     * Credentials whose names are also ordinary words. Token channels only,
     * and only at depth <= 1, where the OAuth form bodies are flat.
     *
     * @var array<string, true>
     */
    public const OAUTH_ONLY = [
        // grant_type=authorization_code. A Xero ACCOUNT code is also `Code`.
        'code' => true,

        // The revocation body. The key is the bland word `token`; the value is
        // the refresh token. A generic credential list would not contain it.
        'token' => true,

        // The single-use CSRF nonce keying the state store, which also names
        // the connection slot being filled.
        'state' => true,
    ];

    /**
     * Masked to their last four characters instead of blanked.
     *
     * Blanking the TIN is the one place over-redaction really bites: "which
     * TIN did we actually send?" is the first question on a rejected e-invoice.
     * Last-4 is already this package's accepted disclosure level for a TIN --
     * it is exactly what myinvois_validations.tin_last4 holds -- so a captured
     * row still joins to the verdict row for the same check.
     *
     * @var array<string, true>
     */
    public const MASK_LAST4 = [
        'taxnumber' => true,
    ];

    /**
     * Values whose quoted operands are masked while the structure survives.
     *
     * This package BUILDS these clauses itself: Contacts::findByEmail() sends
     * where=EmailAddress=="someone@example.com". Storing the URL verbatim would
     * put every customer address anyone ever looked up into the table in
     * plaintext, twice per row, whatever the body rules say. Masking only the
     * quoted operand leaves EmailAddress=="[redacted]", which still answers the
     * question the clause is kept for: which field did we filter on.
     *
     * @var array<string, true>
     */
    public const MASK_OPERANDS = [
        'where' => true,
    ];

    /**
     * Applied to every captured URL, on every channel.
     *
     * The MyInvois TIN is a PATH SEGMENT. No amount of key walking reaches it,
     * and storing it in full would undo the design that the myinvois_validations
     * migration spends seventeen lines of comment justifying. MyInvoisClient
     * also masks it at the call site; this is the net for the call site nobody
     * has written yet.
     *
     * @var array<string, string>
     */
    public const URL_PATTERNS = [
        '#(/taxpayer/validate/)[^/?]{4,}(?=($|[/?]))#i' => '$1[redacted]',
    ];

    /**
     * DELIBERATELY ABSENT, each for a reason worth writing down, because the
     * instinct to add them is strong and every one costs more than it saves:
     *
     *   BankAccountType   An enum, not an identifier. It is how you tell a
     *                     credit card from a bank account on a rejected payment.
     *   AccountNumber     NOT a bank account. Normally the consuming project's
     *                     own customer code, and the join key support reaches
     *                     for first.
     *   Code / AccountID  Payments::create() SENDS Account.Code and refuses a
     *                     payment without Code or AccountID.
     *   CompanyNumber     The BRN is public SSM register data, and when LHDN
     *                     rejects a TIN/BRN pair it is almost always the BRN.
     *                     With TaxNumber masked this is the most diagnostic
     *                     value left.
     *   correlationId     LHDN's own request id, unrecoverable after the fact,
     *                     and the one id their support asks for.
     *   Idempotency-Key   The join to xero_write_records; the only way to tell
     *                     a retry from a genuine duplicate.
     *   Xero-tenant-id    Without it a row cannot be attributed to an
     *                     organisation at all.
     *   client_id         Not secret, and it is how you tell a sandbox
     *                     credential from a production one. Its partner secret
     *                     IS redacted.
     *   idType            With idValue gone, the only clue what was checked.
     *   EmailAddress      "Who did Xero email this to?" has no API to read back.
     *   Particulars       NZ bank references, and reconciliation evidence. A
     *   Details           project whose organisation types account numbers into
     *                     them should add them via capture.redact.add.
     */
    /** @var array<string, true> */
    private array $keys;

    /** @var array<string, true> */
    private array $maskLast4;

    /** @var array<string, true> */
    private array $maskOperands;

    /** @var array<string, string> */
    private array $urlPatterns;

    /** @var list<string> */
    private array $skipUrls;

    /**
     * @param  list<string>  $add  names added to DEFAULTS
     * @param  list<string>  $keep  names removed from DEFAULTS; ALWAYS ignores this
     * @param  array<string, string>  $urlPatterns
     * @param  list<string>  $skipUrls
     */
    public function __construct(
        array $add = [],
        array $keep = [],
        private readonly string $placeholder = '[redacted]',
        array $urlPatterns = self::URL_PATTERNS,
        array $skipUrls = [],
        public readonly int $maxDepth = 24,
        public readonly int $maxNodes = 20000,
    ) {
        $kept = self::normalise($keep);

        $keys = self::DEFAULTS + self::normalise($add);

        foreach ($kept as $name => $_) {
            unset($keys[$name]);
        }

        // Tier 1 last, so nothing named in `keep` can reach through and remove
        // a credential or a bank field.
        $this->keys = $keys;

        $this->maskLast4 = array_diff_key(self::MASK_LAST4, $kept);
        $this->maskOperands = array_diff_key(self::MASK_OPERANDS, $kept);
        $this->urlPatterns = $urlPatterns;
        $this->skipUrls = $skipUrls;
    }

    /**
     * @param  list<string>  $names
     * @return array<string, true>
     */
    private static function normalise(array $names): array
    {
        $out = [];

        foreach ($names as $name) {
            $key = strtolower(trim($name));

            if ($key !== '') {
                $out[$key] = true;
            }
        }

        return $out;
    }

    public function placeholder(): string
    {
        return $this->placeholder;
    }

    /** Whether this key's value is replaced wholesale, subtree and all. */
    public function redacts(string $lowerKey, string $channel, int $depth): bool
    {
        if (isset(self::ALWAYS[$lowerKey])) {
            return true;
        }

        if (isset(self::OAUTH_ONLY[$lowerKey])) {
            return $depth <= 1 && in_array($channel, self::OAUTH_CHANNELS, true);
        }

        return isset($this->keys[$lowerKey]);
    }

    /** Whether this key keeps its last four characters. Checked BEFORE redacts(). */
    public function masksLast4(string $lowerKey): bool
    {
        return isset($this->maskLast4[$lowerKey]) && ! isset(self::ALWAYS[$lowerKey]);
    }

    /** Whether this key's quoted operands are masked in place. */
    public function masksOperands(string $lowerKey): bool
    {
        return isset($this->maskOperands[$lowerKey]) && ! isset(self::ALWAYS[$lowerKey]);
    }

    /** @return array<string, string> */
    public function urlPatterns(): array
    {
        return $this->urlPatterns;
    }

    /** Whether this URL is excluded from capture entirely. */
    public function skips(string $url): bool
    {
        foreach ($this->skipUrls as $pattern) {
            if (@preg_match($pattern, $url) === 1) {
                return true;
            }
        }

        return false;
    }
}
