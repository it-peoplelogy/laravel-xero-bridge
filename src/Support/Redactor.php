<?php

declare(strict_types=1);

namespace Peoplelogy\XeroBridge\Support;

/**
 * One call's worth of redaction: bodies, headers and the URL.
 *
 * Deliberately STATEFUL and short-lived -- one instance per HTTP call, not a
 * singleton. The state is what makes echo suppression possible: values removed
 * from the request are remembered, so when the response quotes one back inside
 * a message string it is caught there too.
 *
 * That last part is not a nicety. Xero's validation errors echo the submitted
 * value into free text:
 *
 *     {"Elements":[{"ValidationErrors":[
 *         {"Message":"TaxNumber C25845632020 is not valid"}]}]}
 *
 * No key-based rule can see inside a string, so without this the careful
 * masking three keys earlier in the same row would be pointless.
 *
 * NOTHING HERE MAY THROW. Recording must never take down the call it was
 * recording -- the same contract TableGuard and the recorders already hold. So
 * every entry point accepts `mixed` and answers for whatever it is given: a
 * plain-text 503 body, a `null` from a non-JSON response, an integer where a
 * string was expected, a payload the host built with a reference cycle in it.
 */
final class Redactor
{
    private const TRUNCATED_DEPTH = '[truncated: depth]';

    private const TRUNCATED_BUDGET = '[truncated: budget]';

    /**
     * Below this length a removed value is not worth hunting for in later
     * strings: short tokens collide with ordinary words and the replacement
     * would mangle readable text rather than protect anything.
     */
    private const MIN_ECHO_LENGTH = 6;

    /** @var list<string> raw values removed from this call, for echo suppression */
    private array $secrets = [];

    /** @var array<string, true> the key names actually hit, for the row */
    private array $hits = [];

    private int $nodes = 0;

    private int $bytes = 0;

    public function __construct(
        private readonly RedactionPolicy $policy,
        private readonly string $channel,
    ) {}

    /**
     * Redact a decoded body.
     *
     * Answers null for anything that is not an array -- a plain-text 503, an
     * XML error page from a proxy, the `null` that Response::json() returns for
     * a non-JSON body. Those are real, documented Xero responses, and a
     * recorder that fataled on them would break precisely the failures someone
     * turned capture on to investigate.
     */
    public function body(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $out = $this->walk($value, 0);

        return is_array($out) ? $out : null;
    }

    /**
     * Redact a header map.
     *
     * Header values arrive as strings from one client and as arrays of strings
     * from another, which is why this goes through the same walk rather than a
     * simpler loop. Depth starts at 0 so a header name is matched at the same
     * level an OAuth form field would be.
     *
     * @param  array<string, mixed>  $headers
     * @return array<string, mixed>
     */
    public function headers(array $headers): array
    {
        $out = $this->walk($headers, 0);

        return is_array($out) ? $out : [];
    }

    /**
     * Redact a URL: its path through the policy's patterns, its query string
     * through the same key rules as a body.
     *
     * The path patterns exist because the MyInvois TIN is a path SEGMENT --
     * invisible to any rule that walks keys, and the single most important item
     * in the whole exclusion list for exactly that reason.
     */
    public function url(string $url): string
    {
        foreach ($this->policy->urlPatterns() as $pattern => $replacement) {
            $masked = @preg_replace($pattern, $replacement, $url);

            if (is_string($masked)) {
                $url = $masked;
            }
        }

        $mark = strpos($url, '?');

        if ($mark === false) {
            return $this->echoes($url);
        }

        $base = substr($url, 0, $mark);
        $query = [];
        parse_str(substr($url, $mark + 1), $query);

        $scrubbed = $this->walk($query, 0);

        if (! is_array($scrubbed) || $scrubbed === []) {
            return $this->echoes($base);
        }

        return $this->echoes($base.'?'.http_build_query($scrubbed));
    }

    /**
     * Rebuild a URL from a base plus a query array the call site still holds.
     *
     * Preferred over url() wherever the caller has the array, because
     * parse_str() mangles keys containing dots and brackets and Xero's `where`
     * clauses contain both.
     *
     * @param  array<string, mixed>  $query
     */
    public function urlWithQuery(string $base, array $query): string
    {
        $url = $this->url($base);

        if ($query === []) {
            return $url;
        }

        $scrubbed = $this->walk($query, 0);

        if (! is_array($scrubbed) || $scrubbed === []) {
            return $url;
        }

        return $url.(str_contains($url, '?') ? '&' : '?').http_build_query($scrubbed);
    }

    /**
     * Scrub a free-text string: an exception message, a plain-text error page.
     *
     * A client exception quotes the URL it failed on, which on the MyInvois
     * side carries the TIN in its path -- so the URL patterns are applied here
     * too, not only to the stored URL column.
     */
    public function scrubText(string $text): string
    {
        foreach ($this->policy->urlPatterns() as $pattern => $replacement) {
            $masked = @preg_replace($pattern, $replacement, $text);

            if (is_string($masked)) {
                $text = $masked;
            }
        }

        return $this->echoes($text);
    }

    /** The key names removed from this call, for the row's own record. */
    public function hits(): array
    {
        return array_keys($this->hits);
    }

    /**
     * The last four characters, or nothing if there are too few to spare.
     *
     * Accepts mixed on purpose: TaxNumber is host-supplied and a consumer may
     * hand it an integer. A strict string signature here would turn a typed
     * argument into a TypeError thrown from inside the recorder, killing the
     * Xero call it was recording.
     */
    public static function last4(mixed $value, string $placeholder = '[redacted]'): string
    {
        if (! is_scalar($value)) {
            return $placeholder;
        }

        $text = trim((string) $value);

        // Under five characters the last four ARE the value.
        return strlen($text) < 5 ? $placeholder : '****'.substr($text, -4);
    }

    private function walk(mixed $node, int $depth): mixed
    {
        if ($this->nodes > $this->policy->maxNodes || $this->bytes > 262144) {
            return self::TRUNCATED_BUDGET;
        }

        if ($depth > $this->policy->maxDepth) {
            return self::TRUNCATED_DEPTH;
        }

        if (is_array($node)) {
            $out = [];

            foreach ($node as $key => $child) {
                $this->nodes++;

                // Integer keys are list indices, never field names. Skipping
                // the lowercase-and-match work matters: LineItems[] and
                // Invoices[] in a batch create are pure lists, and they are the
                // hottest thing this walk ever sees.
                if (is_int($key)) {
                    $out[$key] = $this->walk($child, $depth + 1);

                    continue;
                }

                $needle = strtolower(trim((string) $key));

                if ($this->policy->masksLast4($needle)) {
                    $this->remember($child);
                    $out[$key] = self::last4($child, $this->policy->placeholder());
                    $this->hits[$needle] = true;

                    continue;
                }

                if ($this->policy->masksOperands($needle)) {
                    $out[$key] = $this->operands($child);
                    $this->hits[$needle] = true;

                    continue;
                }

                if ($this->policy->redacts($needle, $this->channel, $depth)) {
                    // The value is replaced WHOLE and not recursed into.
                    // BatchPayments is an object carrying BankAccountNumber,
                    // BankAccountName, Details, Code and Reference; taking the
                    // key must take the subtree or the siblings survive.
                    $this->remember($child);
                    $out[$key] = $this->policy->placeholder();
                    $this->hits[$needle] = true;

                    continue;
                }

                $out[$key] = $this->walk($child, $depth + 1);
            }

            return $out;
        }

        // Objects are not recursed into. A host can put a Model or a
        // JsonSerializable into a request payload, and walking one would touch
        // lazy-loading accessors -- firing database queries from inside a
        // recorder that is contractually not allowed to have side effects.
        if (is_object($node)) {
            return '[object '.$node::class.']';
        }

        if (is_string($node)) {
            $this->bytes += strlen($node);

            return $this->echoes($node);
        }

        return $node;
    }

    /**
     * Mask the quoted operands of a filter clause, keeping its structure.
     *
     * where=EmailAddress=="jane@acme.com" becomes
     * where=EmailAddress=="[redacted]", which still answers the question the
     * clause is kept for -- which field did we filter on -- without storing
     * every customer address anyone has ever looked up.
     */
    private function operands(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $this->walk($value, 1);
        }

        $masked = @preg_replace('/"[^"]*"/', '"'.$this->policy->placeholder().'"', $value);

        return is_string($masked) ? $masked : $this->policy->placeholder();
    }

    /** Remember a removed value so the response cannot quote it back. */
    private function remember(mixed $value): void
    {
        if (! is_scalar($value)) {
            return;
        }

        $text = trim((string) $value);

        if (strlen($text) >= self::MIN_ECHO_LENGTH && ! in_array($text, $this->secrets, true)) {
            $this->secrets[] = $text;
        }
    }

    /** Replace any remembered value quoted back inside a free-text string. */
    private function echoes(string $text): string
    {
        if ($this->secrets === []) {
            return $text;
        }

        return str_replace($this->secrets, $this->policy->placeholder(), $text);
    }
}
