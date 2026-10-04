{{--
    LHDN MyInvois taxpayer TIN validation.

    Included from console.blade.php only when the module is enabled, so a
    consumer who never switches it on sees nothing at all -- not even a note
    telling them it exists. The console is off unless XERO_CONSOLE_ENABLED=true,
    but wherever it is on, a "MyInvois is off" panel would appear in every
    non-Malaysian installation.

    Every field and the Run button sit inside ONE .act block on purpose:
    collectParams() scopes to button.closest('.act'), and the Xero contact
    panels above already carry their own data-param="tin". Splitting this across
    two .act blocks would send the wrong TIN.
--}}
<section class="panel">
    <h2>LHDN MyInvois &mdash; validate a taxpayer TIN</h2>

    @if ($myInvoisProblems !== [])
        <div class="body">
            @foreach ($myInvoisProblems as $problem)
                <div style="padding:6px 0;">
                    <span class="pill bad">config</span>
                    <div style="margin:5px 0 0 4px; color:var(--muted); font-size:12.5px;">{{ $problem }}</div>
                </div>
            @endforeach
        </div>
    @endif

    <div class="act">
        <div class="hd">
            <span class="name">Validate</span>
            <span class="endpoint mono">GET /api/v1.0/taxpayer/validate/{tin}</span>
        </div>

        <p class="note">
            Asks HASiL whether this TIN is genuinely paired with this identifier. Since
            <strong>1 August 2026</strong> the two are validated <em>together</em>, so a valid TIN with a
            stale or mistyped registration number answers exactly the same as a fake one.
        </p>

        <p class="note">
            A match proves the pair exists in HASiL's records and <strong>nothing more</strong> &mdash; the
            endpoint returns no name and no address, so it is not evidence that the pair belongs to the
            customer you are invoicing.
        </p>

        <div class="fields">
            <label>TIN (required)
                <input type="text" class="wide" data-param="tin" placeholder="C25845632020">
            </label>
            <label>ID type
                <select data-param="id_type">
                    @foreach ($myInvoisIdTypes as $idType)
                        <option value="{{ $idType->value }}" @selected($idType->value === 'BRN')>{{ $idType->value }}</option>
                    @endforeach
                </select>
            </label>
            <label>ID value (required)
                <input type="text" data-param="id_value" placeholder="201901234567">
            </label>
        </div>

        <p class="note">
            No format check is applied to the TIN, deliberately: LHDN publishes none, and Malaysian TINs
            carry letter prefixes that vary by taxpayer class. The ID type <em>is</em> a published closed
            list, so it is a dropdown.
        </p>

        <div class="fields"><button type="button" data-run="myinvois.validate">Validate</button></div>
    </div>

    <div class="act">
        <div class="hd">
            <span class="name">Drop the cached token</span>
            <span class="endpoint mono">local cache only</span>
        </div>
        <p class="note">
            Access tokens are cached because LHDN allows only <strong>12 token requests per minute</strong>
            and calls one-token-per-request an anti-pattern. Drop it after rotating the client secret,
            which otherwise leaves a valid-looking token cached until it expires on its own.
        </p>
        <div class="fields">
            <button type="button" class="danger" data-run="myinvois.forget_token"
                    data-confirm="Drop the cached MyInvois access token? The next validation will acquire a new one.">Forget token</button>
        </div>
    </div>
</section>
