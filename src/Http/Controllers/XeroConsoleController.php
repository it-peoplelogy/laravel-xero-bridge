<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\Http\Controllers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use InvalidArgumentException;
use Peoplelogy\XeroBridge\Exceptions\XeroBridgeException;
use Peoplelogy\XeroBridge\Exceptions\XeroConfigurationException;
use Peoplelogy\XeroBridge\Exceptions\XeroConnectionNotFoundException;
use Peoplelogy\XeroBridge\Exceptions\XeroRateLimitException;
use Peoplelogy\XeroBridge\Exceptions\XeroReauthorizationRequiredException;
use Peoplelogy\XeroBridge\Exceptions\XeroScopeException;
use Peoplelogy\XeroBridge\Exceptions\XeroServiceUnavailableException;
use Peoplelogy\XeroBridge\Exceptions\XeroValidationException;
use Peoplelogy\XeroBridge\Facades\XeroBridge;
use Peoplelogy\XeroBridge\Filters\InvoiceFilter;
use Peoplelogy\XeroBridge\MyInvois\IdType;
use Peoplelogy\XeroBridge\MyInvois\MyInvoisClient;
use Peoplelogy\XeroBridge\MyInvois\MyInvoisConfig;
use Peoplelogy\XeroBridge\MyInvois\MyInvoisException;
use Peoplelogy\XeroBridge\OAuth\Actor;
use Peoplelogy\XeroBridge\Support\Diagnostics;
use Peoplelogy\XeroBridge\Support\RedactionPolicy;
use Peoplelogy\XeroBridge\Support\XeroConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * The test console: a hand-driven harness for the whole bridge.
 *
 * Every action is on an explicit allow-list, so a value posted from the browser
 * can never reach an arbitrary method. Writes into Xero are refused unless the
 * connected organisation is either a Xero Demo Company or named in
 * `xero-bridge.console.writable_organisations`. That gate is
 * assertWritableOrganisation(), applied once in dispatch() from the
 * `xero_writes` flag on ACTIONS, so a new write action cannot forget it.
 *
 * Reads are not gated, and neither are the two connection-lifecycle actions:
 * whoever gets past the middleware can read the connected organisation's
 * invoices and contacts, and forgetting a connection takes the integration
 * offline until someone reconnects. Credentials and bank details are masked
 * out of whatever an action returns -- see mask().
 *
 * The route is registered only when the console is enabled, and re-checks that
 * on every request -- see EnsureConsoleEnabled.
 */
class XeroConsoleController extends Controller
{
    /** Xero's hard limit on ContactPersons, which is the only CC mechanism. */
    private const MAX_CONTACT_PERSONS = 5;

    /** Xero's documented max length for both CompanyNumber and TaxNumber. */
    private const MAX_TAX_FIELD = 50;

    /**
     * Every action the console may run, as an explicit allow-list.
     *
     * `xero_writes` marks one that creates or changes a record inside the
     * connected Xero organisation; `writes` marks one that changes local state
     * only.
     *
     * @var array<string, array{label: string, writes?: bool, xero_writes?: bool}>
     */
    private const ACTIONS = [
        'status' => ['label' => 'Connection status (local only, no Xero call)'],

        // Reference lookups. An invoice cannot be built without an account code
        // and a tax type from THIS organisation.
        'settings.organisation' => ['label' => 'GET /Organisation'],
        'settings.accounts' => ['label' => 'GET /Accounts'],
        'settings.tax_rates' => ['label' => 'GET /TaxRates'],
        'settings.payment_accounts' => ['label' => 'GET /Accounts, payment-capable only'],

        // 1
        'contacts.first_or_create' => ['label' => '1. First or create contact', 'xero_writes' => true],
        // 2.1 / 2.2
        'invoices.create_draft' => ['label' => '2.1 Create invoice, DRAFT', 'xero_writes' => true],
        'invoices.create_paid' => ['label' => '2.2 Create invoice, PAID', 'xero_writes' => true],
        // 3
        'invoices.send_to_contact' => ['label' => '3. Set tax numbers, set CC list, email invoice', 'xero_writes' => true],
        // 4
        'invoices.find' => ['label' => '4. Find one invoice'],
        // 5.1 / 5.2
        'contacts.find_by_email' => ['label' => '5.1 Find contact by email'],
        'contacts.find' => ['label' => '5.2 Find contact by ContactID'],
        // 6
        'invoices.list' => ['label' => '6. List invoices'],

        'tokens.refresh' => ['label' => 'Force a token refresh', 'writes' => true],
        'connection.forget' => ['label' => 'Delete the stored connection row', 'writes' => true],
    ];

    /**
     * Organisation payloads already fetched this request, keyed by connection.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $organisations = [];

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly Diagnostics $diagnostics,
        private readonly XeroConfig $config,
        private readonly MyInvoisConfig $myInvois,
        private readonly MyInvoisClient $myInvoisClient,
        // Optional only because this class is not final: a host subclass that
        // calls parent::__construct() with the four arguments it has always
        // passed keeps working, and logs through the container's logger.
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? app(LoggerInterface::class);
    }

    /**
     * The console itself.
     */
    public function index(Request $request): View
    {
        return view('xero-bridge::console', [
            'actions' => $this->actions(),
            'myInvoisEnabled' => $this->myInvois->enabled(),
            'myInvoisProblems' => $this->myInvois->problems(),
            'myInvoisIdTypes' => IdType::cases(),
            'writableOrganisations' => $this->writableOrganisations(),
            'boot' => $this->diagnostics->snapshot(),
            'runUrl' => route($this->config->routeName('console.run')),
            'appName' => (string) config('app.name', 'Laravel'),
            'isProduction' => app()->environment('production'),

            // A host that narrows console.middleware to something session-less
            // (auth.basic, a token guard) has no session, and a bare
            // csrf_token() would throw on page load. Without a session there is
            // no VerifyCsrfToken in the stack either, so the POST still works.
            'csrfToken' => $request->hasSession() ? csrf_token() : null,
        ]);
    }

    /**
     * Run one action and answer with a uniform envelope, so the page renders a
     * success and a failure through the same code path.
     */
    public function run(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'action' => ['required', 'string', 'in:'.implode(',', array_keys($this->actions()))],
            'connection' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/'],
            'params' => ['array'],
        ]);

        // validate() omits a nullable key that was not sent at all, so every
        // optional field is read with ?? rather than by subscript.
        $action = $validated['action'];
        $key = ($validated['connection'] ?? null) ?: $this->config->defaultConnection();
        $params = $validated['params'] ?? [];

        $startedAt = microtime(true);

        try {
            $data = $this->mask(
                $this->dispatch($request, $action, $key, $params),
                (string) $this->config->get('capture.redact.placeholder', '[redacted]'),
            );

            return response()->json([
                'ok' => true,
                'action' => $action,
                'connection' => $key,
                'duration_ms' => $this->elapsed($startedAt),
                'rate_limit' => $this->rateLimit($action, $key),
                'count' => (is_array($data) && array_is_list($data)) ? count($data) : null,
                'data' => $data,
            ]);
        } catch (Throwable $e) {
            // 200 on purpose: the console renders the diagnosis itself, and a
            // non-2xx would be swallowed by the browser's own error handling.
            return response()->json([
                'ok' => false,
                'action' => $action,
                'connection' => $key,
                'duration_ms' => $this->elapsed($startedAt),
                'rate_limit' => $this->rateLimit($action, $key),
                'error' => $this->describeError($e),
            ]);
        }
    }

    /* ------------------------------------------------------------------ */
    /* Dispatch */
    /* ------------------------------------------------------------------ */

    /**
     * @param  array<string, mixed>  $p
     */
    private function dispatch(Request $request, string $action, string $key, array $p): mixed
    {
        // FIRST, before XeroBridge::connection() is touched. MyInvois has no
        // Xero connection and must work on a host that has never connected one.
        if (str_starts_with($action, 'myinvois.')) {
            return $this->dispatchMyInvois($action, $p);
        }

        $bridge = XeroBridge::connection($key);

        // One gate for every Xero-mutating action, rather than a check repeated
        // in each branch where one could be forgotten.
        if (! empty(self::ACTIONS[$action]['xero_writes'])) {
            $this->assertWritableOrganisation($key);
        }

        return match ($action) {
            'status' => $this->diagnostics->snapshot(),

            'settings.organisation' => $bridge->settings()->organisation(),
            'settings.accounts' => $bridge->settings()->accounts(
                ($where = $this->str($p, 'where')) ? ['where' => $where] : []
            ),
            'settings.tax_rates' => $bridge->settings()->taxRates(),
            'settings.payment_accounts' => $bridge->settings()->paymentAccounts(),

            'contacts.first_or_create' => $bridge->contacts()->firstOrCreate(
                $this->buildContactLookup($p),
                $this->buildContactAttributes($p),
            ),

            'invoices.create_draft' => $bridge->invoices()->create(
                $this->buildInvoice($p, 'DRAFT')
            ),
            'invoices.create_paid' => $this->createPaidInvoice($key, $p),

            'invoices.send_to_contact' => $this->sendInvoiceToContact($key, $p),

            'invoices.find' => $bridge->invoices()->find($this->need($p, 'id')),

            'contacts.find_by_email' => $bridge->contacts()->findByEmail($this->need($p, 'email')),
            'contacts.find' => $bridge->contacts()->find($this->need($p, 'contact_id')),

            'invoices.list' => $bridge->invoices()->list($this->buildInvoiceFilter($p)),

            'tokens.refresh' => $this->forceRefresh($key),
            'connection.forget' => $this->forget($request, $key),

            // Reachable only if someone adds to ACTIONS without adding a branch
            // here. Better a readable message than an UnhandledMatchError.
            default => throw new InvalidArgumentException(
                "Action [{$action}] is allow-listed but has no branch in dispatch()."
            ),
        };
    }

    /* ------------------------------------------------------------------ */
    /* 2.2 -- an invoice that ends up PAID */
    /* ------------------------------------------------------------------ */

    /**
     * Xero has no "create it paid" call.
     *
     * PAID is not a status you may set: it is what Xero moves an invoice to once
     * payments cover it in full. POSTing Status=PAID is rejected, and a PAID
     * invoice can never be edited afterwards -- InvoiceTransitions lists no
     * legal transition out of it.
     *
     * So this is three calls: create the invoice AUTHORISED (a payment cannot
     * attach to a DRAFT), apply a payment for exactly the total, then read the
     * invoice back so the caller sees the status Xero actually settled on rather
     * than the one we assumed.
     *
     * @param  array<string, mixed>  $p
     * @return array<string, mixed>
     */
    private function createPaidInvoice(string $key, array $p): array
    {
        $bridge = XeroBridge::connection($key);

        $paymentAccount = $this->need($p, 'payment_account_code');

        $invoice = $bridge->invoices()->create($this->buildInvoice($p, 'AUTHORISED'));

        $invoiceId = (string) ($invoice['InvoiceID'] ?? '');

        if ($invoiceId === '') {
            throw new RuntimeException(
                'Xero accepted the invoice but returned no InvoiceID, so no payment could be '
                .'applied. The invoice exists and is AUTHORISED -- pay it by hand.'
            );
        }

        // Pay exactly what Xero says is outstanding, never the figure we sent:
        // rounding and tax are applied server-side, so AmountDue is the only
        // number that can settle the invoice to the cent.
        $due = (float) ($invoice['AmountDue'] ?? $invoice['Total'] ?? 0);

        if ($due <= 0) {
            throw new RuntimeException(
                'Xero reports nothing outstanding on the new invoice, so there is nothing to pay. '
                ."Invoice [{$invoiceId}] was created and left AUTHORISED."
            );
        }

        $payment = $bridge->payments()->createForInvoice(
            $invoiceId,
            $due,
            $paymentAccount,
            $this->str($p, 'paid_on'),
        );

        return [
            'invoice' => $bridge->invoices()->find($invoiceId),
            'payment' => $payment,
            'note' => 'Created AUTHORISED, then paid '.number_format($due, 2).' against account ['
                .$paymentAccount.']. Xero sets PAID itself once AmountDue reaches zero; if the '
                .'invoice below still reads AUTHORISED, the payment was partial.',
        ];
    }

    /* ------------------------------------------------------------------ */
    /* 3 -- tax numbers, CC list, then send */
    /* ------------------------------------------------------------------ */

    /**
     * Capture the buyer's tax details, set who gets copied, and have Xero send
     * the invoice.
     *
     * Three facts drive the shape of this:
     *
     *  - Tax numbers live on the CONTACT, not the invoice. The tax
     *    identification number goes in `TaxNumber`; the business registration
     *    number is `CompanyNumber`. Both are capped at 50 characters by Xero.
     *
     *  - POST /Invoices/{id}/Email takes an EMPTY body. There is no to, cc, bcc,
     *    subject or body. The only way to copy anyone is ContactPersons with
     *    IncludeInEmails on the contact, max five, and the list you send
     *    REPLACES the stored one wholesale -- so it is rebuilt in full here. It
     *    is per contact, not per send: everyone listed is copied on every later
     *    invoice for this customer too.
     *
     *  - Xero only emails an invoice that is SUBMITTED, AUTHORISED or PAID, so a
     *    DRAFT is authorised first.
     *
     * @param  array<string, mixed>  $p
     * @return array<string, mixed>
     */
    private function sendInvoiceToContact(string $key, array $p): array
    {
        $bridge = XeroBridge::connection($key);

        $invoiceId = $this->need($p, 'id');
        $invoice = $bridge->invoices()->find($invoiceId);

        if ($invoice === null) {
            throw new RuntimeException("Invoice [{$invoiceId}] is not in Xero.");
        }

        $contactId = (string) ($invoice['Contact']['ContactID'] ?? '');

        if ($contactId === '') {
            throw new RuntimeException(
                "Invoice [{$invoiceId}] carries no ContactID, so there is no contact to update."
            );
        }

        // (a) the buyer's details, onto the contact record
        $contactUpdate = [];

        if (($brn = $this->str($p, 'brn')) !== null) {
            $contactUpdate['CompanyNumber'] = $this->assertMaxLength($brn, 'brn');
        }

        if (($tin = $this->str($p, 'tin')) !== null) {
            $contactUpdate['TaxNumber'] = $this->assertMaxLength($tin, 'tin');
        }

        $copyTo = $this->parseCopyList($this->str($p, 'cc'));

        if ($copyTo !== []) {
            $contactUpdate['ContactPersons'] = $copyTo;
        }

        $contact = $contactUpdate === []
            ? $bridge->contacts()->find($contactId)
            : $bridge->contacts()->update($contactId, $contactUpdate);

        // (b) Xero refuses to email a DRAFT
        $status = (string) ($invoice['Status'] ?? '');
        $authorised = false;

        if ($status === 'DRAFT' || $status === 'SUBMITTED') {
            $invoice = $bridge->invoices()->authorise($invoiceId);
            $authorised = true;
        }

        // (c) send it. 204 No Content, so there is nothing to return.
        $bridge->invoices()->email($invoiceId);

        return [
            'invoice_id' => $invoiceId,
            'status_before' => $status,
            'authorised_now' => $authorised,
            'status_now' => $invoice['Status'] ?? null,
            'emailed' => true,
            'sent_to' => [
                'primary' => $contact['EmailAddress'] ?? null,
                'copied' => array_map(
                    static fn (array $person) => $person['EmailAddress'] ?? null,
                    $copyTo,
                ),
            ],
            'contact' => $contact,
            'note' => 'Xero sends from the organisation\'s own template, as the user who authorised '
                .'the connection, to the contact\'s EmailAddress plus every ContactPerson with '
                .'IncludeInEmails. There is no API to read back what was sent. The CC list is '
                .'stored on the contact and applies to every future invoice for it.',
        ];
    }

    /**
     * Parse the CC box into ContactPersons.
     *
     * Accepts one recipient per line, as either "someone@example.com" or
     * "Their Name <someone@example.com>".
     *
     * @return list<array<string, mixed>>
     */
    private function parseCopyList(?string $raw): array
    {
        if ($raw === null) {
            return [];
        }

        $people = [];

        foreach (preg_split('/[\r\n,]+/', $raw) ?: [] as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $name = '';
            $email = $line;

            if (preg_match('/^(.*?)\s*<([^>]+)>$/', $line, $m)) {
                $name = trim($m[1]);
                $email = trim($m[2]);
            }

            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException(
                    "[{$line}] is not a usable CC entry. Use one per line, as an email address or "
                    .'as "Name <email@example.com>".'
                );
            }

            [$first, $last] = array_pad(array_values(array_filter(explode(' ', $name, 2))), 2, '');

            $people[] = [
                'FirstName' => $first,
                'LastName' => $last,
                'EmailAddress' => $email,
                'IncludeInEmails' => true,
            ];
        }

        if (count($people) > self::MAX_CONTACT_PERSONS) {
            throw new InvalidArgumentException(sprintf(
                'Xero allows at most %d ContactPersons and %d were given. Everyone beyond the '
                .'limit would be dropped silently, so this is refused instead.',
                self::MAX_CONTACT_PERSONS,
                count($people),
            ));
        }

        return $people;
    }

    /* ------------------------------------------------------------------ */
    /* Payload builders */
    /* ------------------------------------------------------------------ */

    /**
     * firstOrCreate() takes exactly one lookup key, so this refuses to guess
     * which one was meant rather than silently preferring email.
     *
     * @param  array<string, mixed>  $p
     * @return array<string, string>
     */
    private function buildContactLookup(array $p): array
    {
        $by = $this->str($p, 'lookup_by') ?: 'EmailAddress';

        return match ($by) {
            'EmailAddress' => ['EmailAddress' => $this->need($p, 'email')],
            'Name' => ['Name' => $this->need($p, 'name')],
            default => throw new InvalidArgumentException(
                "Contacts can only be looked up by EmailAddress or Name, got [{$by}]."
            ),
        };
    }

    /**
     * Merged in only when firstOrCreate actually creates. An existing contact is
     * returned untouched, which is the point: this never overwrites one.
     *
     * @param  array<string, mixed>  $p
     * @return array<string, mixed>
     */
    private function buildContactAttributes(array $p): array
    {
        $attributes = [];

        $map = [
            'Name' => 'name',
            'EmailAddress' => 'email',
            'FirstName' => 'first_name',
            'LastName' => 'last_name',
        ];

        foreach ($map as $field => $param) {
            if (($value = $this->str($p, $param)) !== null) {
                $attributes[$field] = $value;
            }
        }

        foreach (['CompanyNumber' => 'brn', 'TaxNumber' => 'tin'] as $field => $param) {
            if (($value = $this->str($p, $param)) !== null) {
                $attributes[$field] = $this->assertMaxLength($value, $param);
            }
        }

        return $attributes;
    }

    /**
     * One line item, which is all these flows need.
     *
     * @param  array<string, mixed>  $p
     * @return array<string, mixed>
     */
    private function buildInvoice(array $p, string $status): array
    {
        $line = ['Description' => $this->need($p, 'description')];

        foreach (['Quantity' => 'quantity', 'UnitAmount' => 'unit_amount'] as $field => $param) {
            if (($value = $this->str($p, $param)) !== null) {
                $line[$field] = (float) $value;
            }
        }

        // Left out entirely when blank, so the connection defaults apply rather
        // than being overwritten with an empty string.
        foreach (['AccountCode' => 'account_code', 'TaxType' => 'tax_type'] as $field => $param) {
            if (($value = $this->str($p, $param)) !== null) {
                $line[$field] = $value;
            }
        }

        $invoice = [
            'Type' => $this->str($p, 'type') ?: 'ACCREC',
            'Status' => $status,
            'LineItems' => [$line],
        ];

        // ContactID alone, never ContactID plus other fields -- the package
        // refuses that outright, because Xero would rewrite the contact record
        // as a side effect of writing the invoice, deleting any ContactPersons
        // left out.
        if (($contactId = $this->str($p, 'contact_id')) !== null) {
            $invoice['Contact'] = ['ContactID' => $contactId];
        } elseif (($contactName = $this->str($p, 'contact_name')) !== null) {
            $invoice['Contact'] = ['Name' => $contactName];
        } else {
            throw new InvalidArgumentException(
                'An invoice needs a contact: give either contact_id or contact_name.'
            );
        }

        foreach (['Date' => 'date', 'DueDate' => 'due_date', 'Reference' => 'reference'] as $field => $param) {
            if (($value = $this->str($p, $param)) !== null) {
                $invoice[$field] = $value;
            }
        }

        return $invoice;
    }

    /**
     * @param  array<string, mixed>  $p
     */
    private function buildInvoiceFilter(array $p): InvoiceFilter
    {
        $filter = InvoiceFilter::make();

        if ($statuses = $this->str($p, 'statuses')) {
            $filter = $filter->statuses($this->csv($statuses));
        }

        if ($type = $this->str($p, 'type')) {
            $filter = $filter->type($type);
        }

        if ($contactName = $this->str($p, 'contact_name')) {
            $filter = $filter->contactName($contactName);
        }

        if ($reference = $this->str($p, 'reference')) {
            $filter = $filter->reference($reference);
        }

        if ($numbers = $this->str($p, 'invoice_numbers')) {
            $filter = $filter->invoiceNumbers($this->csv($numbers));
        }

        $from = $this->str($p, 'date_from');
        $to = $this->str($p, 'date_to');

        if ($from !== null || $to !== null) {
            $filter = $filter->dateBetween($from, $to);
        }

        if ($since = $this->str($p, 'modified_since')) {
            $filter = $filter->modifiedSince($since);
        }

        if ($orderBy = $this->str($p, 'order_by')) {
            $filter = $filter->orderBy($orderBy, $this->str($p, 'order_dir') ?: 'ASC');
        }

        if (! empty($p['summary_only'])) {
            $filter = $filter->summaryOnly();
        }

        // Always paged: an unbounded /Invoices on a live organisation is the
        // fastest way to spend the daily rate limit from a browser tab.
        return $filter->page(
            max(1, (int) ($p['page'] ?? 1)),
            max(1, min(1000, (int) ($p['page_size'] ?? 25))),
        );
    }

    /* ------------------------------------------------------------------ */
    /* Guards and connection state */
    /* ------------------------------------------------------------------ */

    /**
     * Organisations this console may write into, besides a Demo Company.
     *
     * @return list<string>
     */
    private function writableOrganisations(): array
    {
        /** @var list<string> $names */
        $names = (array) $this->config->get('console.writable_organisations', []);

        return array_values(array_filter(array_map(
            static fn ($name): string => trim((string) $name),
            $names,
        )));
    }

    /**
     * The single gate on every action that writes into Xero.
     *
     * Xero gives each account a free Demo Company whose data is disposable.
     * Anything else is somebody's real ledger: a DRAFT can be deleted, but an
     * AUTHORISED invoice can only ever be voided, and it stays visible in Xero
     * for good.
     *
     * The answer is memoised per request so a single action costs at most one
     * extra call, and deliberately NOT cached beyond that -- a cached "yes"
     * would outlive a reconnection to a different organisation, which is exactly
     * the mistake this guard exists to prevent.
     */
    private function assertWritableOrganisation(string $key): void
    {
        $this->organisations[$key] ??= XeroBridge::connection($key)->settings()->organisation();

        $organisation = $this->organisations[$key];

        // Xero's own Demo Company: disposable by definition.
        if (filter_var($organisation['IsDemoCompany'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return;
        }

        $name = trim((string) ($organisation['Name'] ?? $organisation['LegalName'] ?? ''));

        $allowed = $this->writableOrganisations();

        foreach ($allowed as $candidate) {
            if ($name !== '' && strcasecmp($name, $candidate) === 0) {
                return;
            }
        }

        $allowList = $allowed === []
            ? 'The allow-list is empty, so only a Demo Company is writable.'
            : 'Writable organisations are '
                .implode(', ', array_map(static fn (string $o): string => '"'.$o.'"', $allowed)).'.';

        throw new RuntimeException(
            'Refused: ['.($name ?: '(unnamed)').'] is not a Xero Demo Company, and is not on this '
            .'console\'s write allow-list. Nothing was sent. '.$allowList
            .' If this organisation is genuinely a sandbox, add its exact name to '
            .'XERO_CONSOLE_WRITABLE_ORGANISATIONS.'
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function forceRefresh(string $key): array
    {
        $connection = XeroBridge::tokens()->find($key);

        if ($connection === null) {
            throw new XeroConnectionNotFoundException(
                "No stored Xero connection named [{$key}]. Connect one first."
            );
        }

        $before = $connection->expires_at?->toIso8601String();

        $refreshed = XeroBridge::tokens()->refresh($connection, force: true);
        $after = $refreshed->expires_at?->toIso8601String();

        return [
            'key' => $key,
            'expires_before' => $before,
            'expires_after' => $after,
            'rotated' => $before !== $after,
            'connection' => $this->diagnostics->describe($refreshed),
        ];
    }

    /**
     * Local only: drops the stored row so the connect flow can be replayed.
     * Nothing is revoked at Xero and no bookkeeping data is touched, but every
     * call through the key fails from here on: the integration is offline
     * until someone reconnects.
     *
     * The only connection deletion a person triggers by hand, so it leaves a
     * warning in the log naming who did it. Nothing else would record it: no
     * Xero call is made, so there is no capture row, and the connection_id
     * needed to finish the disconnect at Xero goes with the row.
     *
     * @return array<string, mixed>
     */
    private function forget(Request $request, string $key): array
    {
        $connection = XeroBridge::tokens()->find($key);

        if ($connection === null) {
            return ['key' => $key, 'deleted' => false, 'note' => 'No stored connection under that key.'];
        }

        $name = $connection->displayName();

        // delete() answers false when a `deleting` listener on the model
        // vetoed it -- a host's own guard, say. Then nothing was deleted, and
        // reporting or logging a deletion would be false.
        if ($connection->delete() === false) {
            return [
                'key' => $key,
                'deleted' => false,
                'note' => "The row for [{$name}] was not deleted: a listener on the model's deleting event cancelled it.",
            ];
        }

        $actor = $this->actor($request);

        $this->logger->warning(
            'xero-bridge: the test console deleted a stored Xero connection. Calls through it fail until '
            .'someone reconnects; the authorisation stays live in Xero until it is removed there.',
            [
                'connection' => $key,
                'tenant_id' => $connection->tenant_id,
                'connection_id' => $connection->connection_id,
                'actor_id' => $actor?->id,
                'actor_type' => $actor?->type,
                'actor_guard' => $actor?->guard,
            ],
        );

        return [
            'key' => $key,
            'deleted' => true,
            'note' => "Local row for [{$name}] deleted. Every call through [{$key}] now fails until someone "
                .'reconnects at '.$this->config->connectUrl($key).'. The authorisation still exists in Xero '
                .'until it is removed there.',
        ];
    }

    /**
     * Who is signed in, as three scalars -- never the user model, which a log
     * channel would serialise whole, password hash and all.
     *
     * Resolving the user must never decide whether a deletion that already
     * happened gets logged, so a guard or user provider that throws just
     * leaves the actor out.
     */
    private function actor(Request $request): ?Actor
    {
        try {
            $user = $request->user();

            return $user instanceof Authenticatable
                ? Actor::from($user, app('auth')->getDefaultDriver())
                : null;
        } catch (Throwable) {
            return null;
        }
    }

    /* ------------------------------------------------------------------ */
    /* Helpers */
    /* ------------------------------------------------------------------ */

    /**
     * Replace every value whose key RedactionPolicy::ALWAYS names --
     * credentials and bank details -- at any depth, before the response is
     * built.
     *
     * The reads hand Xero's payloads back whole: GET /Organisation carries
     * APIKey, a live Xero-to-Xero credential; every contact read echoes the
     * customer's BankAccountDetails and BatchPayments; GET /Accounts carries
     * the organisation's own BankAccountNumber. The package refuses to store
     * exactly these anywhere, and a page that any signed-in user may be able
     * to open is no place to show them.
     *
     * Deliberately not the capture Redactor. Its node budget would truncate a
     * 1000-invoice page, and its second tier would hide the TaxNumber this
     * console has just set -- the very thing being tested. The matching is the
     * policy's own: the whole key, case-insensitively, never a stem, so
     * BankAccountType and AccountNumber survive. A matched value goes whole,
     * subtree and all, which is what takes BatchPayments' siblings with it.
     */
    private function mask(mixed $data, string $placeholder): mixed
    {
        if (! is_array($data)) {
            return $data;
        }

        foreach ($data as $key => $value) {
            if (is_string($key) && isset(RedactionPolicy::ALWAYS[strtolower(trim($key))])) {
                $data[$key] = $placeholder;
            } elseif (is_array($value)) {
                $data[$key] = $this->mask($value, $placeholder);
            }
        }

        return $data;
    }

    /**
     * Turn any throwable into something a human can act on, including the
     * bridge's own typed exceptions and their Xero-side detail.
     *
     * @return array<string, mixed>
     */
    private function describeError(Throwable $e): array
    {
        $out = [
            'type' => class_basename($e),
            'class' => $e::class,
            // Never a QueryException's own message: Laravel writes the SQL
            // into it with every binding filled in, which for a token save
            // that failed is the ciphertext of the tokens just rotated. The
            // driver's message beneath it says what went wrong without them.
            'message' => $e instanceof QueryException
                ? ($e->getPrevious()?->getMessage() ?? 'A database query failed.')
                : $e->getMessage(),
            'hint' => $this->hintFor($e),
        ];

        if ($e instanceof XeroBridgeException) {
            $out['status'] = $e->statusCode();
            $out['connection_key'] = $e->connectionKey();
            $out['xero_type'] = $e->xeroType();
            $out['xero_error_number'] = $e->xeroErrorNumber();
            $out['retry_after'] = $e->retryAfter();
            $out['validation_errors'] = $e->validationErrors() ?: null;
            $out['context'] = $e->context() ?: null;
        }

        if ($e instanceof XeroScopeException) {
            $out['granted_scopes'] = $e->grantedScopes();
        }

        if ($e instanceof XeroRateLimitException) {
            $out['limit_problem'] = $e->limitProblem();
        }

        if ($e instanceof MyInvoisException) {
            $out['status'] = $e->statusCode();
            $out['correlation_id'] = $e->correlationId();
            $out['error_code'] = $e->errorCode();
            $out['error_ms'] = $e->errorMalay();
            $out['retry_after'] = $e->retryAfter();
            $out['context'] = $e->context() ?: null;
        }

        return array_filter($out, static fn ($v) => $v !== null && $v !== []);
    }

    private function hintFor(Throwable $e): ?string
    {
        return match (true) {
            $e instanceof XeroConfigurationException => 'Fix the XERO_* keys in .env, then run `php artisan config:clear`.',
            $e instanceof XeroConnectionNotFoundException => 'Nothing is connected under this key yet. Use "Connect to Xero" at the top of the page.',
            $e instanceof XeroReauthorizationRequiredException => 'The refresh token is dead. Reconnect; no code change will fix this.',
            $e instanceof XeroScopeException => 'The connection was authorised without a scope this call needs. Add it to XERO_SCOPES and reconnect: an existing connection never gains scopes on its own.',
            $e instanceof XeroRateLimitException => 'Back off and retry. Minute and daily limits carry a Retry-After; a concurrency limit carries none.',
            $e instanceof XeroValidationException => 'Xero rejected the request. See validation_errors for the element it objected to.',
            $e instanceof XeroServiceUnavailableException => 'Xero reports the organisation offline, or the API is down. Retry in a few minutes.',
            $e instanceof MyInvoisException && $e->isConfigurationProblem() => 'Fix the MYINVOIS_* keys in .env, then run `php artisan config:clear`. LHDN blocks a Client ID that repeatedly sends bad credentials, so do not simply retry.',
            $e instanceof MyInvoisException && $e->isRetryable() => 'LHDN is rate-limiting or unavailable. This endpoint allows 60 requests per minute per Client ID; back off and retry.',
            $e instanceof MyInvoisException => 'LHDN rejected the request itself. A 400 means the request was malformed -- check the idType and that neither value is blank.',
            $e instanceof InvalidArgumentException => 'Fill in the field this action needs, then run it again.',
            default => null,
        };
    }

    /**
     * The rate limit belonging to whichever API the action actually called.
     *
     * Reporting Xero's remaining quota after a MyInvois call would be worse
     * than reporting nothing: the number is real, just about the wrong API.
     *
     * @return array<string, mixed>|null
     */
    private function rateLimit(string $action, string $key): ?array
    {
        if (str_starts_with($action, 'myinvois.')) {
            return $this->myInvoisClient->lastRateLimit();
        }

        return XeroBridge::connection($key)->client()->lastRateLimit()?->toArray();
    }

    /* ------------------------------------------------------------------ */
    /* MyInvois */
    /* ------------------------------------------------------------------ */

    /**
     * The allow-list, plus the MyInvois entries ONLY while the module is on.
     *
     * Built rather than declared so a consumer who never enables MyInvois sees
     * no extra actions, no extra panel and no change to the "Actions this
     * console is allowed to run (n)" count. The `in:` validation rule reads the
     * same list, so a disabled action is rejected by validation rather than
     * reaching a branch that would have to explain itself.
     *
     * @return array<string, array{label: string, writes?: bool, xero_writes?: bool}>
     */
    private function actions(): array
    {
        $actions = self::ACTIONS;

        if ($this->myInvois->enabled()) {
            $actions += [
                'myinvois.validate' => ['label' => 'LHDN MyInvois: validate a taxpayer TIN'],
                'myinvois.forget_token' => ['label' => 'LHDN MyInvois: drop the cached access token', 'writes' => true],
            ];
        }

        return $actions;
    }

    /**
     * @param  array<string, mixed>  $p
     * @return array<string, mixed>
     */
    private function dispatchMyInvois(string $action, array $p): array
    {
        return match ($action) {
            'myinvois.validate' => $this->validateTin($p),

            'myinvois.forget_token' => $this->forgetMyInvoisToken(),

            default => throw new InvalidArgumentException(
                "Action [{$action}] is allow-listed but has no branch in dispatchMyInvois()."
            ),
        };
    }

    /**
     * @param  array<string, mixed>  $p
     * @return array<string, mixed>
     */
    private function validateTin(array $p): array
    {
        $tin = $this->need($p, 'tin');
        $idType = $this->str($p, 'id_type') ?: IdType::BRN->value;
        $idValue = $this->need($p, 'id_value');

        $matched = $this->myInvoisClient->validate($tin, $idType, $idValue);

        return [
            'tin' => $tin,
            'id_type' => IdType::coerce($idType)->value,
            'id_value' => $idValue,
            'matched' => $matched,
            'environment' => $this->myInvois->environment(),
            'note' => $matched
                ? 'HASiL holds this TIN paired with this identifier. That is ALL a 200 proves: the '
                    .'endpoint returns no name and no address, so this is not evidence that the pair '
                    .'belongs to the customer you are invoicing.'
                : 'HASiL has no record of this TIN paired with this identifier. Since 1 August 2026 '
                    .'the pair is validated together, so a valid TIN with a stale or mistyped '
                    .'registration number answers exactly like this.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function forgetMyInvoisToken(): array
    {
        $this->myInvois->assertUsable();
        $this->myInvoisClient->forgetToken();

        return [
            'forgotten' => true,
            'note' => 'The cached access token was dropped. The next validation acquires a new one. '
                .'Worth doing after rotating the client secret, which otherwise leaves a '
                .'valid-looking token in the cache until it expires on its own.',
        ];
    }

    private function elapsed(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    /**
     * Xero truncates silently past 50 characters on these, which is worse than
     * refusing: nobody notices the tax number is short until an audit.
     */
    private function assertMaxLength(string $value, string $field): string
    {
        if (mb_strlen($value) > self::MAX_TAX_FIELD) {
            throw new InvalidArgumentException(sprintf(
                '"%s" is %d characters; Xero allows at most %d.',
                $field,
                mb_strlen($value),
                self::MAX_TAX_FIELD,
            ));
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    private function csv(string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $value))));
    }

    /**
     * @param  array<string, mixed>  $p
     */
    private function str(array $p, string $field): ?string
    {
        $value = $p[$field] ?? null;

        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @param  array<string, mixed>  $p
     */
    private function need(array $p, string $field): string
    {
        $value = $this->str($p, $field);

        if ($value === null) {
            throw new InvalidArgumentException("This action needs a value for \"{$field}\".");
        }

        return $value;
    }
}
