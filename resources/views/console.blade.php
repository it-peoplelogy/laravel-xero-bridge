{{--
    The Xero Bridge test console, shipped with peoplelogy/laravel-xero-bridge.

    Deliberately standalone: no layout, no Vite, no Blade components, no CDN.
    It is one HTML document with inline CSS and vanilla JS, so it renders the
    same in a Tailwind app, a Bootstrap app and an Inertia app, needs no build
    step and no published asset, and cannot disturb the host's own styling.

    Publish it with `--tag=xero-bridge-views` if you want to adapt it.

    Off unless XERO_CONSOLE_ENABLED=true in the environment serving it,
    whatever APP_ENV says. Writes into Xero are gated on the connected
    organisation being a Demo Company or named in
    xero-bridge.console.writable_organisations. Nothing else is: whoever gets
    past the console's middleware can read that organisation's invoices and
    contacts, and the two actions under "Connection lifecycle" change this
    app's stored connection -- "forget" deletes it, which takes the
    integration offline until someone reconnects.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @if ($csrfToken !== null)
        <meta name="csrf-token" content="{{ $csrfToken }}">
    @endif
    <title>Xero Bridge Test Console &mdash; {{ $appName }}</title>
    <style>
        :root {
            --bg: #f4f5f7;
            --panel: #ffffff;
            --ink: #16191d;
            --muted: #6b7280;
            --line: #e2e5ea;
            --accent: #13b5ea;      /* Xero blue */
            --accent-ink: #06485c;
            --ok: #197d4b;
            --ok-bg: #e7f6ee;
            --bad: #b3261e;
            --bad-bg: #fdecea;
            --warn: #8a5b00;
            --warn-bg: #fdf3e0;
            --code-bg: #12161c;
            --code-ink: #dfe6ee;
            --radius: 10px;
        }

        @media (prefers-color-scheme: dark) {
            :root:not([data-theme="light"]) {
                --bg: #0e1116;
                --panel: #171b22;
                --ink: #e6eaf0;
                --muted: #9aa4b2;
                --line: #272d38;
                --accent: #13b5ea;
                --accent-ink: #9fe3fa;
                --ok: #6ee7a8;
                --ok-bg: #10291d;
                --bad: #ff9c94;
                --bad-bg: #2d1413;
                --warn: #f3c46a;
                --warn-bg: #2b2210;
                --code-bg: #0a0d12;
                --code-ink: #dfe6ee;
            }
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            background: var(--bg);
            color: var(--ink);
            font: 14px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        }

        code, pre, .mono {
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace;
        }

        .wrap { max-width: 1400px; margin: 0 auto; padding: 24px 16px 64px; }

        /* ---------- header ---------- */

        header.top {
            background: linear-gradient(135deg, #0b2d3a, #124a5e);
            color: #eaf7fc;
            padding: 20px 16px;
        }
        header.top .inner { max-width: 1400px; margin: 0 auto; }
        header.top h1 { margin: 0 0 4px; font-size: 20px; letter-spacing: .2px; }
        header.top p { margin: 0; color: #a9d6e6; font-size: 13px; }

        .ribbon {
            background: var(--warn-bg);
            color: var(--warn);
            border-bottom: 1px solid var(--line);
            padding: 10px 16px;
            font-size: 13px;
        }
        .ribbon .inner { max-width: 1400px; margin: 0 auto; }

        /* ---------- layout ---------- */

        .cols { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 20px; }
        @media (max-width: 1080px) { .cols { grid-template-columns: minmax(0, 1fr); } }

        .panel {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: var(--radius);
            margin-bottom: 16px;
            overflow: hidden;
        }
        .panel > h2 {
            margin: 0;
            padding: 12px 16px;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: .6px;
            color: var(--muted);
            border-bottom: 1px solid var(--line);
        }
        .panel .body { padding: 16px; }
        .panel .body + .body { border-top: 1px solid var(--line); }

        /* ---------- action rows ---------- */

        .act { padding: 14px 16px; border-bottom: 1px solid var(--line); }
        .act:last-child { border-bottom: 0; }
        .act .hd { display: flex; align-items: baseline; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
        .act .name { font-weight: 600; }
        .act .endpoint { color: var(--muted); font-size: 12px; }
        .act .fields { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 10px; }
        .act .fields label { display: flex; flex-direction: column; gap: 3px; font-size: 11px; color: var(--muted); }
        .act .note { margin-top: 8px; font-size: 12px; color: var(--muted); }

        input[type="text"], input[type="number"], select, textarea {
            font: inherit;
            padding: 6px 9px;
            border: 1px solid var(--line);
            border-radius: 7px;
            background: var(--bg);
            color: var(--ink);
            min-width: 0;
        }
        input[type="text"], input[type="number"], select { width: 170px; }
        input.wide { width: 320px; }
        textarea { width: 100%; min-height: 64px; resize: vertical; }
        input:focus, select:focus, textarea:focus { outline: 2px solid var(--accent); outline-offset: 1px; }

        .checks { display: flex; gap: 14px; flex-wrap: wrap; margin-top: 10px; font-size: 12px; color: var(--muted); }
        .checks label { display: flex; align-items: center; gap: 5px; flex-direction: row; }

        button {
            font: inherit;
            font-weight: 600;
            padding: 7px 14px;
            border-radius: 7px;
            border: 1px solid transparent;
            background: var(--accent);
            color: #04303d;
            cursor: pointer;
        }
        button:hover { filter: brightness(1.06); }
        button:disabled { opacity: .5; cursor: progress; }
        button.ghost { background: transparent; border-color: var(--line); color: var(--ink); }
        button.danger { background: var(--bad-bg); color: var(--bad); border-color: var(--bad); }
        button.warn { background: var(--warn-bg); color: var(--warn); border-color: var(--warn); }

        /* Anything that writes into Xero is visually separated from the reads. */
        .panel.write { border-color: var(--warn); }
        .panel.write > h2 { color: var(--warn); background: var(--warn-bg); }

        a.btn {
            display: inline-block; text-decoration: none; font-weight: 600;
            padding: 7px 14px; border-radius: 7px; background: var(--accent); color: #04303d;
        }

        .row { display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end; }

        /* ---------- status readouts ---------- */

        table.kv { width: 100%; border-collapse: collapse; }
        table.kv th, table.kv td { text-align: left; padding: 6px 8px; border-bottom: 1px solid var(--line); vertical-align: top; }
        table.kv th { color: var(--muted); font-weight: 500; width: 38%; white-space: nowrap; }
        table.kv tr:last-child th, table.kv tr:last-child td { border-bottom: 0; }

        .pill {
            display: inline-block; padding: 1px 8px; border-radius: 999px;
            font-size: 11px; font-weight: 700; letter-spacing: .3px;
        }
        .pill.ok { background: var(--ok-bg); color: var(--ok); }
        .pill.bad { background: var(--bad-bg); color: var(--bad); }
        .pill.warn { background: var(--warn-bg); color: var(--warn); }
        .pill.mute { background: var(--bg); color: var(--muted); border: 1px solid var(--line); }

        .empty { color: var(--muted); font-style: normal; padding: 6px 0; }

        /* ---------- result pane ---------- */

        .sticky { position: sticky; top: 16px; }

        .result-bar {
            display: flex; gap: 8px; flex-wrap: wrap; align-items: center;
            padding: 10px 16px; border-bottom: 1px solid var(--line);
            font-size: 12px; color: var(--muted);
        }
        .result-bar .spacer { flex: 1; }

        pre.json {
            margin: 0;
            padding: 14px 16px;
            background: var(--code-bg);
            color: var(--code-ink);
            font-size: 12.5px;
            line-height: 1.55;
            max-height: 62vh;
            overflow: auto;
            white-space: pre;
            tab-size: 2;
        }
        pre.json .k { color: #7fd6ff; }
        pre.json .s { color: #c3e88d; }
        pre.json .n { color: #f78c6c; }
        pre.json .b { color: #c792ea; }
        pre.json .z { color: #7a869a; }

        .hint {
            padding: 10px 16px; font-size: 12.5px;
            background: var(--warn-bg); color: var(--warn);
            border-bottom: 1px solid var(--line);
        }

        details.ref summary { cursor: pointer; color: var(--muted); font-size: 12px; }
        details.ref ul { margin: 10px 0 0; padding-left: 18px; font-size: 12px; color: var(--muted); }
        details.ref li { margin-bottom: 3px; }
    </style>
</head>
<body>

<header class="top">
    <div class="inner">
        <h1>Xero Bridge Test Console</h1>
        <p>peoplelogy/laravel-xero-bridge &mdash; manual exercise harness for {{ $appName }}</p>
    </div>
</header>

@if ($isProduction)
    <div class="ribbon" style="background:var(--bad-bg); color:var(--bad);">
        <div class="inner">
            <strong>This is a production host.</strong>
            The console is reachable here only because <code class="mono">XERO_CONSOLE_ENABLED=true</code>.
            Anything you run touches the live Xero organisation.
        </div>
    </div>
@endif

<div class="ribbon">
    <div class="inner">
        <strong>Developer tool.</strong>
        This page is off unless <code class="mono">XERO_CONSOLE_ENABLED=true</code> in this environment.
        Reads run against whatever is connected: anyone who gets past its middleware can read that organisation's
        invoices and contacts. Everything that writes into Xero is refused unless the organisation is an approved
        <strong>sandbox</strong>; the two actions under <em>Connection lifecycle</em> are not gated, and
        <em>Forget connection</em> takes the integration offline until someone reconnects.
    </div>
</div>

<div class="wrap">

    {{-- ---------------------------------------------------------------- --}}
    {{-- Environment + connections                                         --}}
    {{-- ---------------------------------------------------------------- --}}

    <div class="cols">
        <div>
            <section class="panel">
                <h2>Pre-flight</h2>
                <div class="body" id="preflight-body"></div>
            </section>

            <section class="panel">
                <h2>Environment</h2>
                <div class="body" id="env-body"></div>
            </section>
        </div>

        <div>
            <section class="panel">
                <h2>Connections</h2>
                <div class="body" id="conn-body"></div>
                <div class="body">
                    <div class="row">
                        <a class="btn" id="connect-link" href="#" target="_blank" rel="noopener">Connect to Xero &rarr;</a>
                        <button class="ghost" type="button" data-run="status">Re-read status</button>
                    </div>
                    <p class="note" style="margin:10px 0 0; font-size:12px; color:var(--muted);">
                        Connect opens Xero's consent screen in a new tab. It needs the redirect URI below to be
                        registered on the Xero app <em>exactly</em>, trailing slash and all.
                    </p>
                </div>
            </section>
        </div>
    </div>

    {{-- ---------------------------------------------------------------- --}}
    {{-- Runner                                                            --}}
    {{-- ---------------------------------------------------------------- --}}

    <div class="cols">

        {{-- left: the actions --}}
        <div>
            <section class="panel">
                <h2>Connection key</h2>
                <div class="body">
                    <div class="row">
                        <label style="display:flex; flex-direction:column; gap:3px; font-size:11px; color:var(--muted);">
                            Every call below runs against this key
                            <input type="text" id="connection-key"
                                   value="{{ $boot['config']['default_connection'] }}"
                                   placeholder="default">
                        </label>
                    </div>
                </div>
            </section>

            <section class="panel">
                <h2>Reference lookups &mdash; run these first</h2>
                <div class="body" style="font-size:13px; color:var(--muted);">
                    The invoice forms below cannot be filled without them: account codes, tax types and
                    payment accounts all differ per organisation, so a value copied from another Xero org
                    will be rejected.
                </div>

                <div class="act">
                    <div class="hd">
                        <span class="name">Organisation</span>
                        <span class="endpoint mono">GET /Organisation</span>
                    </div>
                    <p class="note">Shows <code class="mono">Name</code>, base currency and <code class="mono">IsDemoCompany</code> &mdash; which organisation you are about to write into.</p>
                    <div class="fields"><button type="button" data-run="settings.organisation">Run</button></div>
                </div>

                <div class="act">
                    <div class="hd">
                        <span class="name">Chart of accounts</span>
                        <span class="endpoint mono">GET /Accounts</span>
                    </div>
                    <p class="note">Gives the <code class="mono">Code</code> for a line item's <code class="mono">AccountCode</code>.</p>
                    <div class="fields">
                        <label>where (optional)
                            <input type="text" class="wide" data-param="where" placeholder='Type=="REVENUE"'>
                        </label>
                        <button type="button" data-run="settings.accounts">Run</button>
                    </div>
                </div>

                <div class="act">
                    <div class="hd">
                        <span class="name">Tax rates</span>
                        <span class="endpoint mono">GET /TaxRates</span>
                    </div>
                    <p class="note">
                        Gives the <code class="mono">TaxType</code> code. Tax is per LINE, not per invoice, which
                        is why the package ships no default: in Malaysia, for instance, training is 8% while
                        education and rental are 6%, so one invoice can legitimately carry two rates.
                    </p>
                    <div class="fields"><button type="button" data-run="settings.tax_rates">Run</button></div>
                </div>

                <div class="act">
                    <div class="hd">
                        <span class="name">Payment-capable accounts</span>
                        <span class="endpoint mono">GET /Accounts &rarr; filtered</span>
                    </div>
                    <p class="note">The only accounts Xero accepts a payment against. Needed by <strong>2.2</strong>.</p>
                    <div class="fields"><button type="button" data-run="settings.payment_accounts">Run</button></div>
                </div>
            </section>

            <section class="panel write">
                <h2>Write guard</h2>
                <div class="body" style="font-size:13px;">
                    <p style="margin:0;">
                        The amber sections below write into Xero, and are refused unless the connected
                        organisation is either a Xero <strong>Demo Company</strong>
                        (<code class="mono">IsDemoCompany</code> true) or named in the allow-list.
                        @if ($writableOrganisations === [])
                            Nothing is currently allowed by name, so <strong>only a Demo Company</strong> is writable.
                        @else
                            Currently allowed by name:
                            @foreach ($writableOrganisations as $organisation)
                                <code class="mono">{{ $organisation }}</code>@if (! $loop->last), @endif
                            @endforeach
                        @endif
                    </p>
                    <p style="margin:10px 0 0; color:var(--muted);">
                        Xero sets <code class="mono">IsDemoCompany</code> only on the Demo Company it creates
                        for you; a sandbox you set up yourself is indistinguishable from a real ledger over
                        the API, which is why it must be named. Set
                        <code class="mono">XERO_CONSOLE_WRITABLE_ORGANISATIONS</code> to a comma-separated list
                        to add one.
                    </p>
                </div>
            </section>

            <section class="panel write">
                <h2>1 &mdash; First or create contact</h2>
                <div class="act">
                    <div class="hd">
                        <span class="name">firstOrCreate</span>
                        <span class="endpoint mono">GET /Contacts, then PUT if absent</span>
                    </div>
                    <p class="note">
                        Exactly one lookup key. If the contact exists it is returned <em>untouched</em> &mdash;
                        the other fields are merged in only when creating, so this can never overwrite a
                        real customer record. Safe against a race: the loser of a concurrent create gets a
                        clean conflict and re-reads rather than clobbering the winner.
                    </p>
                    <div class="fields">
                        <label>look up by
                            <select data-param="lookup_by">
                                <option value="EmailAddress">EmailAddress</option>
                                <option value="Name">Name</option>
                            </select>
                        </label>
                        <label>email
                            <input type="text" data-param="email" placeholder="finance@acme.com">
                        </label>
                        <label>name
                            <input type="text" data-param="name" placeholder="Acme Ltd">
                        </label>
                    </div>
                    <div class="fields">
                        <label>first name
                            <input type="text" data-param="first_name">
                        </label>
                        <label>last name
                            <input type="text" data-param="last_name">
                        </label>
                        <label>CompanyNumber
                            <input type="text" data-param="brn" placeholder="registration no.">
                        </label>
                        <label>TaxNumber
                            <input type="text" data-param="tin" placeholder="tax id">
                        </label>
                    </div>
                    <div class="fields"><button type="button" class="warn" data-run="contacts.first_or_create">Run</button></div>
                </div>
            </section>

            <section class="panel write">
                <h2>2.1 &mdash; Create invoice, DRAFT</h2>
                <div class="act">
                    <div class="hd">
                        <span class="name">create</span>
                        <span class="endpoint mono">POST /Invoices</span>
                    </div>
                    <p class="note">
                        Give <em>either</em> contact_id or contact name. ContactID is sent alone on purpose:
                        ContactID plus any other field makes Xero rewrite the contact record as a side
                        effect, deleting ContactPersons you left out. Leave account code and tax type blank
                        to fall back to the connection defaults.
                    </p>
                    <div class="fields">
                        <label>contact_id
                            <input type="text" data-param="contact_id" placeholder="GUID">
                        </label>
                        <label>or contact name
                            <input type="text" data-param="contact_name">
                        </label>
                        <label>type
                            <select data-param="type">
                                <option value="ACCREC">ACCREC — sales</option>
                                <option value="ACCPAY">ACCPAY — bill</option>
                            </select>
                        </label>
                    </div>
                    <div class="fields">
                        <label>description (required)
                            <input type="text" class="wide" data-param="description" placeholder="Training — 2 days">
                        </label>
                        <label>quantity
                            <input type="text" data-param="quantity" placeholder="1">
                        </label>
                        <label>unit amount
                            <input type="text" data-param="unit_amount" placeholder="1500.00">
                        </label>
                    </div>
                    <div class="fields">
                        <label>account code
                            <input type="text" data-param="account_code" placeholder="from /Accounts">
                        </label>
                        <label>tax type
                            <input type="text" data-param="tax_type" placeholder="from /TaxRates">
                        </label>
                        <label>date
                            <input type="text" data-param="date" placeholder="2026-09-28">
                        </label>
                        <label>due date
                            <input type="text" data-param="due_date" placeholder="2026-10-28">
                        </label>
                        <label>reference
                            <input type="text" data-param="reference">
                        </label>
                    </div>
                    <div class="fields"><button type="button" class="warn" data-run="invoices.create_draft">Create DRAFT</button></div>
                </div>
            </section>

            <section class="panel write">
                <h2>2.2 &mdash; Create invoice, PAID</h2>
                <div class="act">
                    <div class="hd">
                        <span class="name">create + pay in full</span>
                        <span class="endpoint mono">POST /Invoices, then PUT /Payments</span>
                    </div>
                    <p class="note">
                        <strong>PAID is not a status you can set.</strong> Xero rejects
                        <code class="mono">Status: "PAID"</code> on create; PAID is what it moves an invoice
                        to once payments cover it. So this creates the invoice <strong>AUTHORISED</strong>
                        (a payment cannot attach to a DRAFT), pays Xero's own
                        <code class="mono">AmountDue</code> against the account below, then reads the
                        invoice back so you see the status Xero actually settled on.
                    </p>
                    <p class="note">
                        A PAID invoice can never be edited or voided again &mdash; the payment has to be
                        reversed first.
                    </p>
                    <div class="fields">
                        <label>contact_id
                            <input type="text" data-param="contact_id" placeholder="GUID">
                        </label>
                        <label>or contact name
                            <input type="text" data-param="contact_name">
                        </label>
                        <label>type
                            <select data-param="type">
                                <option value="ACCREC">ACCREC — sales</option>
                                <option value="ACCPAY">ACCPAY — bill</option>
                            </select>
                        </label>
                    </div>
                    <div class="fields">
                        <label>description (required)
                            <input type="text" class="wide" data-param="description" placeholder="Training — 2 days">
                        </label>
                        <label>quantity
                            <input type="text" data-param="quantity" placeholder="1">
                        </label>
                        <label>unit amount
                            <input type="text" data-param="unit_amount" placeholder="1500.00">
                        </label>
                    </div>
                    <div class="fields">
                        <label>account code
                            <input type="text" data-param="account_code" placeholder="from /Accounts">
                        </label>
                        <label>tax type
                            <input type="text" data-param="tax_type" placeholder="from /TaxRates">
                        </label>
                        <label>date
                            <input type="text" data-param="date">
                        </label>
                        <label>due date
                            <input type="text" data-param="due_date">
                        </label>
                        <label>reference
                            <input type="text" data-param="reference">
                        </label>
                    </div>
                    <div class="fields">
                        <label>payment account code (required)
                            <input type="text" data-param="payment_account_code" placeholder="from Payment-capable accounts">
                        </label>
                        <label>paid on
                            <input type="text" data-param="paid_on" placeholder="today if blank">
                        </label>
                    </div>
                    <div class="fields">
                        <button type="button" class="danger" data-run="invoices.create_paid"
                                data-confirm="Create an AUTHORISED invoice and immediately pay it in full? Neither step can be undone without reversing the payment first.">Create and pay</button>
                    </div>
                </div>
            </section>

            <section class="panel write">
                <h2>3 &mdash; Tax numbers, CC list, then send</h2>
                <div class="act">
                    <div class="hd">
                        <span class="name">collect and email</span>
                        <span class="endpoint mono">POST /Contacts/{id}, then /Invoices/{id}/Email</span>
                    </div>
                    <p class="note">
                        Tax numbers live on the <strong>contact</strong>, not the invoice: the tax
                        identification number goes to <code class="mono">TaxNumber</code>, the business
                        registration number to <code class="mono">CompanyNumber</code>, both capped at 50
                        characters. The contact is found from the invoice, so only the invoice ID is needed.
                    </p>
                    <p class="note">
                        <strong>On CC:</strong> Xero's email endpoint takes a completely empty body &mdash; no
                        to, cc, bcc, subject or reply-to. The only way to copy anyone is
                        <code class="mono">ContactPersons</code> with <code class="mono">IncludeInEmails</code>
                        on the contact record, five maximum. That list <em>replaces</em> the stored one and is
                        <em>per contact, not per send</em>: everyone below will be copied on every future
                        invoice for this customer until you change it again.
                    </p>
                    <p class="note">
                        A DRAFT is authorised first, because Xero only emails a SUBMITTED, AUTHORISED or
                        PAID invoice. Subject and body come from the organisation's own template, and the
                        sender is whoever authorised the connection.
                    </p>
                    <div class="fields">
                        <label>invoice id or number (required)
                            <input type="text" class="wide" data-param="id" placeholder="INV-0042 or GUID">
                        </label>
                        <label>CompanyNumber
                            <input type="text" data-param="brn" placeholder="registration no.">
                        </label>
                        <label>TaxNumber
                            <input type="text" data-param="tin" placeholder="tax id">
                        </label>
                    </div>
                    <div class="fields" style="flex-direction:column; align-items:stretch;">
                        <label style="width:100%;">CC &mdash; one per line, max 5, as an address or "Name &lt;email&gt;"
                            <textarea data-param="cc" placeholder="Finance Team &lt;finance@acme.com&gt;&#10;audit@acme.com"></textarea>
                        </label>
                    </div>
                    <div class="fields">
                        <button type="button" class="danger" data-run="invoices.send_to_contact"
                                data-confirm="Xero will really send this invoice, and the CC list will replace the one stored on the contact. Continue?">Collect and send</button>
                    </div>
                </div>
            </section>

            <section class="panel">
                <h2>4 &mdash; Find one invoice</h2>
                <div class="act">
                    <div class="hd">
                        <span class="name">find</span>
                        <span class="endpoint mono">GET /Invoices/{id}</span>
                    </div>
                    <p class="note">Takes an InvoiceID GUID or a human invoice number. Returns <code class="mono">null</code> if there is no match, rather than throwing.</p>
                    <div class="fields">
                        <label>id or number (required)
                            <input type="text" class="wide" data-param="id" placeholder="INV-0042 or GUID">
                        </label>
                        <button type="button" data-run="invoices.find">Run</button>
                    </div>
                </div>
            </section>

            <section class="panel">
                <h2>5 &mdash; Find a contact</h2>

                <div class="act">
                    <div class="hd">
                        <span class="name">5.1 By email</span>
                        <span class="endpoint mono">GET /Contacts?where=EmailAddress</span>
                    </div>
                    <p class="note">Returns the first match or <code class="mono">null</code>. Xero permits more than one contact per address, so treat this as "a" match, not "the" match.</p>
                    <div class="fields">
                        <label>email (required)
                            <input type="text" class="wide" data-param="email" placeholder="finance@acme.com">
                        </label>
                        <button type="button" data-run="contacts.find_by_email">Run</button>
                    </div>
                </div>

                <div class="act">
                    <div class="hd">
                        <span class="name">5.2 By ContactID</span>
                        <span class="endpoint mono">GET /Contacts/{ContactID}</span>
                    </div>
                    <p class="note">The only unique way to reference a contact &mdash; Xero warns that names are not unique.</p>
                    <div class="fields">
                        <label>contact_id (required)
                            <input type="text" class="wide" data-param="contact_id" placeholder="GUID">
                        </label>
                        <button type="button" data-run="contacts.find">Run</button>
                    </div>
                </div>
            </section>

            <section class="panel">
                <h2>6 &mdash; List invoices</h2>
                <div class="act">
                    <div class="hd">
                        <span class="name">list</span>
                        <span class="endpoint mono">GET /Invoices</span>
                    </div>
                    <p class="note">
                        Always paged. An unbounded <code class="mono">/Invoices</code> on a live organisation
                        is the quickest way to spend the daily rate limit. Leave everything blank for the
                        most recent 25.
                    </p>
                    <div class="fields">
                        <label>statuses (csv)
                            <input type="text" data-param="statuses" placeholder="AUTHORISED,PAID">
                        </label>
                        <label>type
                            <select data-param="type">
                                <option value="">(any)</option>
                                <option value="ACCREC">ACCREC — sales</option>
                                <option value="ACCPAY">ACCPAY — bills</option>
                            </select>
                        </label>
                        <label>contact name
                            <input type="text" data-param="contact_name">
                        </label>
                        <label>reference
                            <input type="text" data-param="reference">
                        </label>
                    </div>
                    <div class="fields">
                        <label>invoice numbers (csv)
                            <input type="text" data-param="invoice_numbers" placeholder="INV-0001,INV-0002">
                        </label>
                        <label>date from
                            <input type="text" data-param="date_from" placeholder="2026-01-01">
                        </label>
                        <label>date to
                            <input type="text" data-param="date_to" placeholder="2026-12-31">
                        </label>
                        <label>modified since
                            <input type="text" data-param="modified_since" placeholder="2026-09-01">
                        </label>
                    </div>
                    <div class="fields">
                        <label>order by
                            <input type="text" data-param="order_by" placeholder="Date">
                        </label>
                        <label>direction
                            <select data-param="order_dir">
                                <option value="">ASC</option>
                                <option value="DESC">DESC</option>
                            </select>
                        </label>
                        <label>page
                            <input type="number" data-param="page" value="1" min="1">
                        </label>
                        <label>page size
                            <input type="number" data-param="page_size" value="25" min="1" max="1000">
                        </label>
                    </div>
                    <div class="checks">
                        <label><input type="checkbox" data-param="summary_only" value="1"> summary only</label>
                    </div>
                    <div class="fields"><button type="button" data-run="invoices.list">Run</button></div>
                </div>
            </section>

            <section class="panel">
                <h2>Connection lifecycle &mdash; changes stored state</h2>
                <div class="act">
                    <div class="hd">
                        <span class="name">Force token refresh</span>
                        <span class="endpoint mono">POST identity/connect/token</span>
                    </div>
                    <p class="note">
                        The only action that reaches Xero and changes something: refresh tokens <em>rotate</em>, so the
                        stored one is replaced. This is the behaviour most worth testing before go-live. If it fails
                        terminally the connection is marked invalidated and must be reconnected.
                    </p>
                    <div class="fields"><button type="button" class="danger" data-run="tokens.refresh" data-confirm="Force a token refresh? The stored refresh token will be rotated.">Refresh now</button></div>
                </div>

                <div class="act">
                    <div class="hd">
                        <span class="name">Delete stored connection</span>
                        <span class="endpoint mono">local row only</span>
                    </div>
                    <p class="note">
                        Removes the row from <code class="mono">{{ $boot['config']['table'] }}</code> so the consent flow can be
                        replayed. Until someone reconnects, every call through this connection fails: the integration is
                        offline. Nothing is revoked at Xero, so the authorisation stays live there until it is removed in Xero.
                    </p>
                    <div class="fields"><button type="button" class="danger" data-run="connection.forget" data-confirm="Delete the stored connection row? The integration stays offline until someone reconnects.">Forget connection</button></div>
                </div>
            </section>

            {{-- A different API and a different authority. Rendered only when the
                 module is switched on, so this console looks exactly as it did
                 for anyone outside Malaysia. --}}
            @if ($myInvoisEnabled)
                @include('xero-bridge::console.myinvois')
            @endif
        </div>

        {{-- right: the result --}}
        <div>
            <div class="sticky">
                <section class="panel">
                    <h2>Response</h2>
                    <div class="result-bar" id="result-bar">
                        <span class="pill mute">idle</span>
                        <span class="spacer"></span>
                        <button class="ghost" type="button" id="copy-btn" style="padding:3px 10px; font-size:12px;">Copy JSON</button>
                    </div>
                    <div id="result-hint"></div>
                    <pre class="json" id="result">Pick an action on the left.

Suggested order for a first run:
  1. Connect to Xero, if Connections is empty
  2. Settings &rarr; Organisation      (proves the token works)
  3. Settings &rarr; Chart of accounts (gives you real account codes)
  4. Settings &rarr; Tax rates         (gives you real TaxType codes)
  5. Invoices &rarr; List              (proves paging and filters)
  6. Connection lifecycle &rarr; Force token refresh</pre>
                </section>

                <section class="panel">
                    <h2>Reference</h2>
                    <div class="body">
                        <details class="ref">
                            <summary>Actions this console is allowed to run ({{ count($actions) }})</summary>
                            <ul>
                                @foreach ($actions as $name => $meta)
                                    <li>
                                        <code class="mono">{{ $name }}</code> &mdash; {{ $meta['label'] }}
                                        @if (! empty($meta['xero_writes']))
                                            <span class="pill warn">writes to Xero</span>
                                        @elseif (! empty($meta['writes']))
                                            <span class="pill warn">local state</span>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        </details>
                    </div>
                </section>
            </div>
        </div>
    </div>
</div>

{{-- HEX flags so a value echoed back from Xero can never close this tag. --}}
<script type="application/json" id="boot-data">@json($boot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)</script>
<script type="application/json" id="urls-data">@json([
    'run' => $runUrl,
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)</script>

<script>
(function () {
    'use strict';

    var boot  = JSON.parse(document.getElementById('boot-data').textContent);
    var urls  = JSON.parse(document.getElementById('urls-data').textContent);
    /* Absent when the console runs on a session-less middleware stack; there
       is no VerifyCsrfToken in that stack either, so the POST still works. */
    var csrfMeta = document.querySelector('meta[name="csrf-token"]');
    var csrf = csrfMeta ? csrfMeta.getAttribute('content') : null;

    var resultEl  = document.getElementById('result');
    var barEl     = document.getElementById('result-bar');
    var hintEl    = document.getElementById('result-hint');
    var lastJson  = '';

    /* ---------------------------------------------------------------- */
    /* rendering helpers                                                 */
    /* ---------------------------------------------------------------- */

    function esc(v) {
        return String(v).replace(/[&<>]/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c];
        });
    }

    function pill(text, kind) {
        return '<span class="pill ' + kind + '">' + esc(text) + '</span>';
    }

    function yesNo(value, goodIsTrue) {
        var good = goodIsTrue === false ? !value : !!value;
        return pill(value ? 'yes' : 'no', good ? 'ok' : 'bad');
    }

    /* A URL is null when the host has that route group switched off. */
    function url(value) {
        return value
            ? '<code class="mono">' + esc(value) + '</code>'
            : pill('route disabled', 'warn');
    }

    /* How far the lock store's locks reach, as xero-bridge:status judges
       it: by the store's class, never its name. Amber wherever status
       warns. A file store XERO_LOCK_STORE names was chosen, and status
       only notes it -- --strict ignores it -- so its pill is grey. Nothing
       for a shared store, nor for one that cannot be resolved: the
       Pre-flight panel reports that. */
    function lockScope(c) {
        var label = {
            'one process': 'one process only',
            'one server': 'one server only',
            'none': 'no locking'
        }[c.lock_scope];

        if (!label) {
            return '';
        }

        var chosen = c.lock_scope === 'one server' && c.lock_store_set;

        return ' ' + pill(label, chosen ? 'mute' : 'warn');
    }

    /* Minimal JSON syntax highlighting; the payloads are Xero's own. */
    function highlight(jsonText) {
        return esc(jsonText).replace(
            /("(\\u[a-zA-Z0-9]{4}|\\[^u]|[^\\"])*"(\s*:)?|\b(true|false)\b|\bnull\b|-?\d+(?:\.\d*)?(?:[eE][+\-]?\d+)?)/g,
            function (match) {
                var cls = 'n';
                if (/^"/.test(match)) {
                    cls = /:$/.test(match) ? 'k' : 's';
                } else if (/true|false/.test(match)) {
                    cls = 'b';
                } else if (/null/.test(match)) {
                    cls = 'z';
                }
                return '<span class="' + cls + '">' + match + '</span>';
            }
        );
    }

    function show(payload) {
        lastJson = JSON.stringify(payload, null, 2);
        resultEl.innerHTML = highlight(lastJson);
    }

    /* ---------------------------------------------------------------- */
    /* environment + connections panels                                  */
    /* ---------------------------------------------------------------- */

    function renderPreflight(state) {
        var checks = state.preflight || {};

        var html = Object.keys(checks).map(function (name) {
            var c = checks[name];

            if (c.ok) {
                return '<div style="padding:6px 0;">' + pill('pass', 'ok') + ' ' + esc(c.label) + '</div>';
            }

            var warn = c.severity === 'warn';
            var detail = c.type ? '<code class="mono">' + esc(c.type) + '</code> &mdash; ' : '';

            return '<div style="padding:6px 0;">' +
                pill(warn ? 'warn' : 'fail', warn ? 'warn' : 'bad') +
                ' <strong>' + esc(c.label) + '</strong>' +
                '<div style="margin:5px 0 0 4px; color:var(--muted); font-size:12.5px;">' +
                detail + esc(c.message) +
                '</div></div>';
        }).join('');

        /* Advisories that are not yet failures, from the same source the
           xero-bridge:status command reports them from. */
        html += (state.warnings || []).map(function (warning) {
            return '<div style="padding:6px 0;">' + pill('warn', 'warn') +
                '<div style="margin:5px 0 0 4px; color:var(--muted); font-size:12.5px;">' +
                esc(warning) + '</div></div>';
        }).join('');

        document.getElementById('preflight-body').innerHTML = html ||
            '<p class="empty">No checks reported.</p>';
    }

    function renderEnv(state) {
        var c = state.config, u = state.urls;
        var rows = [
            ['XERO_CLIENT_ID',     yesNo(c.client_id_set)],
            ['XERO_CLIENT_SECRET', yesNo(c.client_secret_set)],
            ['XERO_WEBHOOK_KEY',   c.webhook_key_set
                ? pill('set', 'ok')
                : pill('not set', 'warn') + ' <span style="color:var(--muted)">webhook handling is off</span>'],
            ['offline_access',     c.has_offline_access
                ? pill('granted', 'ok')
                : pill('MISSING', 'bad') + ' <span style="color:var(--muted)">the connection would die after 30 minutes</span>'],
            ['Redirect URI',       '<code class="mono">' + esc(c.redirect_uri) + '</code>'],
            ['Connect URL',        url(u.connect)],
            ['Callback URL',       url(u.callback)],
            ['Webhook URL',        url(u.webhook)],
            ['Table',              '<code class="mono">' + esc(c.table) + '</code>'],
            ['Default connection', '<code class="mono">' + esc(c.default_connection) + '</code>'],
            ['Lock store',         '<code class="mono">' + esc(c.lock_store) + '</code>' + lockScope(c)],
            ['Idempotency keys',   yesNo(c.idempotency)],
            ['Scopes',             '<code class="mono" style="font-size:11.5px">' + esc(c.scopes.join(' ')) + '</code>']
        ];

        document.getElementById('env-body').innerHTML =
            '<table class="kv"><tbody>' +
            rows.map(function (r) { return '<tr><th>' + r[0] + '</th><td>' + r[1] + '</td></tr>'; }).join('') +
            '</tbody></table>';
    }

    function renderConnections(state) {
        var el = document.getElementById('conn-body');

        if (!state.connections.length) {
            el.innerHTML =
                '<p class="empty">No organisation is connected yet. ' +
                'The table exists but holds no rows &mdash; use <strong>Connect to Xero</strong> below.</p>';
            return;
        }

        el.innerHTML = state.connections.map(function (c) {
            /* Tokens this APP_KEY cannot decrypt fail every call, however
               far off their expiry: red, as xero-bridge:status shows them. */
            var health = c.needs_reauthorisation
                ? pill('needs reauthorisation', 'bad')
                : (c.tokens_readable === false
                    ? pill('tokens unreadable', 'bad')
                    : (c.expired ? pill('access token expired', 'warn') : pill('healthy', 'ok')));

            var expiry = c.expires_at
                ? esc(c.expires_at) + ' <span style="color:var(--muted)">(' + c.expires_in + 's)</span>'
                : pill('unknown', 'bad');

            var rows = [
                ['Key',           '<code class="mono">' + esc(c.key) + '</code> ' + health],
                ['Organisation',  esc(c.organisation)],
                ['Tenant ID',     '<code class="mono" style="font-size:11.5px">' + esc(c.tenant_id) + '</code>'],
                ['Access token',  expiry],
                ['Last refreshed', c.last_refreshed_at ? esc(c.last_refreshed_at) : '<span style="color:var(--muted)">never</span>'],
                ['Failures',      c.failure_count > 0
                                    ? pill(c.failure_count, 'warn') + (c.last_failure_at ? ' ' + esc(c.last_failure_at) : '')
                                    : pill('0', 'ok')],
                ['Scopes',        '<code class="mono" style="font-size:11.5px">' + esc(c.scopes.join(' ')) + '</code>']
            ];

            if (c.invalidated_reason) {
                rows.push(['Invalidated because', esc(c.invalidated_reason)]);
            }

            return '<table class="kv"><tbody>' +
                rows.map(function (r) { return '<tr><th>' + r[0] + '</th><td>' + r[1] + '</td></tr>'; }).join('') +
                '</tbody></table>';
        }).join('<hr style="border:0;border-top:1px solid var(--line);margin:14px 0">');
    }

    function renderState(state) {
        renderPreflight(state);
        renderEnv(state);
        renderConnections(state);

        var link = document.getElementById('connect-link');

        if (state.urls.connect) {
            link.href = state.urls.connect;
        } else {
            link.removeAttribute('href');
            link.textContent = 'Connect route is disabled';
        }
    }

    /* ---------------------------------------------------------------- */
    /* running an action                                                 */
    /* ---------------------------------------------------------------- */

    function collectParams(button) {
        /* Fields belong to the nearest .act block, so two actions in the
           same block share one set of inputs. */
        var scope = button.closest('.act');
        var params = {};

        if (!scope) {
            return params;
        }

        scope.querySelectorAll('[data-param]').forEach(function (input) {
            var name = input.getAttribute('data-param');

            if (input.type === 'checkbox') {
                if (input.checked) { params[name] = 1; }
                return;
            }

            var value = input.value.trim();
            if (value !== '') { params[name] = value; }
        });

        return params;
    }

    function setBar(response) {
        var bits = [];

        if (response.ok) {
            bits.push(pill('ok', 'ok'));
        } else {
            bits.push(pill(response.error ? response.error.type : 'failed', 'bad'));
        }

        bits.push('<code class="mono">' + esc(response.action) + '</code>');
        bits.push(response.duration_ms + ' ms');

        if (response.count !== null && response.count !== undefined) {
            bits.push(response.count + ' item' + (response.count === 1 ? '' : 's'));
        }

        var rl = response.rate_limit;
        if (rl) {
            var left = [];
            if (rl.minute_remaining !== null && rl.minute_remaining !== undefined) { left.push(rl.minute_remaining + '/min'); }
            if (rl.day_remaining !== null && rl.day_remaining !== undefined) { left.push(rl.day_remaining + '/day'); }
            if (left.length) { bits.push('limit left: ' + left.join(', ')); }
            if (rl.problem) { bits.push(pill(rl.problem, 'warn')); }
        }

        barEl.innerHTML = bits.join(' &nbsp; ') +
            '<span class="spacer"></span>' +
            '<button class="ghost" type="button" id="copy-btn" style="padding:3px 10px; font-size:12px;">Copy JSON</button>';

        wireCopy();
    }

    function setHint(response) {
        if (!response.ok && response.error && response.error.hint) {
            hintEl.innerHTML = '<div class="hint"><strong>' + esc(response.error.type) + '</strong> &mdash; ' +
                esc(response.error.hint) + '</div>';
        } else {
            hintEl.innerHTML = '';
        }
    }

    function post(body) {
        var headers = {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        };

        if (csrf) { headers['X-CSRF-TOKEN'] = csrf; }

        return fetch(urls.run, {
            method: 'POST',
            headers: headers,
            credentials: 'same-origin',
            body: JSON.stringify(body)
        });
    }

    function connectionKey() {
        return document.getElementById('connection-key').value.trim();
    }

    function run(button) {
        var action  = button.getAttribute('data-run');
        var confirm_ = button.getAttribute('data-confirm');

        if (confirm_ && !window.confirm(confirm_)) {
            return;
        }

        button.disabled = true;
        barEl.innerHTML = pill('running…', 'mute') + ' &nbsp; <code class="mono">' + esc(action) + '</code>';
        hintEl.innerHTML = '';

        post({ action: action, connection: connectionKey(), params: collectParams(button) })
            .then(function (r) {
                return r.json().catch(function () {
                    throw new Error('The server answered ' + r.status + ' with something that was not JSON. ' +
                        'If that is a login page, the session expired — reload and sign in again.');
                });
            })
            .then(function (response) {
                setBar(response);
                setHint(response);
                show(response);

                /* status, refresh and forget all change what the panels show. */
                if (response.ok && action === 'status') {
                    renderState(response.data);
                } else if (response.ok && (action === 'tokens.refresh' || action === 'connection.forget')) {
                    refreshState();
                }
            })
            .catch(function (e) {
                barEl.innerHTML = pill('transport error', 'bad');
                hintEl.innerHTML = '';
                show({ ok: false, error: { type: 'FetchError', message: e.message } });
            })
            .finally(function () {
                button.disabled = false;
            });
    }

    function refreshState() {
        post({ action: 'status', connection: connectionKey() })
            .then(function (r) { return r.json(); })
            .then(function (response) { if (response.ok) { renderState(response.data); } })
            .catch(function () { /* the panels simply stay as they were */ });
    }

    /* ---------------------------------------------------------------- */
    /* wiring                                                            */
    /* ---------------------------------------------------------------- */

    function wireCopy() {
        var btn = document.getElementById('copy-btn');
        if (!btn) { return; }

        btn.addEventListener('click', function () {
            if (!lastJson) { return; }
            navigator.clipboard.writeText(lastJson).then(function () {
                var original = btn.textContent;
                btn.textContent = 'Copied';
                setTimeout(function () { btn.textContent = original; }, 1200);
            });
        });
    }

    document.querySelectorAll('[data-run]').forEach(function (button) {
        button.addEventListener('click', function () { run(button); });
    });

    wireCopy();
    renderState(boot);
}());
</script>

</body>
</html>
