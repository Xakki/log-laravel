<?php

declare(strict_types=1);

namespace Xakki\LaraLog;

/**
 * Credential / secret redaction at the process boundary (spec §2 / §2.1).
 *
 * Two modes:
 *  - byKey:   for keyed data (context fields) — match the FIELD NAME against a needle list.
 *  - byValue: for positional data (stack-trace arguments, where no name is available) —
 *             match the VALUE against sensitive patterns (Bearer/JWT/secret-ish).
 *
 * Built-in needles are ALWAYS on; `config('logger.redact', [])` is merged on top.
 *
 * Known, accepted limitation (verified identical in the Python port, pylog's
 * `redactor.py`): needle matching is a case-insensitive substring match via
 * `mb_strtolower()` + `str_contains()`. A needle can be defeated by a Unicode
 * homoglyph or a zero-width character spliced inside it — e.g. a Turkish dotted
 * İ / dotless ı, a fullwidth Latin lookalike, or a zero-width joiner inside
 * "password" — none of which lowercase to the plain-ASCII needle. This is a
 * known gap, not a bug to rediscover: closing it needs a normalization design
 * decision (NFKC folding, ZW-char stripping) that risks over-redaction of
 * legitimate non-Latin field names, so it is deliberately left unchanged here.
 */
final class Redactor
{
    public const MASK = '***';

    /**
     * High-signal needles only — substring match, so overly-broad tokens (`auth`, `card`,
     * `pin`, `session`) are deliberately excluded to avoid masking legit fields like
     * `cardinality` / `shipping` / `author`. Add narrow project needles via logger.redact.
     *
     * @var string[]
     */
    private const BUILTIN = [
        'password', 'passwd', 'secret', 'token', 'authorization',
        'api_key', 'apikey', 'access_key', 'private_key', 'credential',
        'cookie', 'cvv', 'card_number', 'cardnumber',
    ];

    /** @var string[]|null */
    private static ?array $needles = null;

    /** @return string[] */
    private static function needles(): array
    {
        if (self::$needles === null) {
            $extra = (array) config('logger.redact', []);
            $extra = array_map(static fn ($v): string => mb_strtolower((string) $v), $extra);
            self::$needles = array_values(array_unique(array_merge(self::BUILTIN, $extra)));
        }
        return self::$needles;
    }

    /** Test seam: drop the cached needle list. */
    public static function flush(): void
    {
        self::$needles = null;
    }

    public static function shouldRedactKey(string $key): bool
    {
        $key = mb_strtolower($key);
        foreach (self::needles() as $needle) {
            if ($needle !== '' && str_contains($key, $needle)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Best-effort value redaction for positional values (no key). Masks the whole value
     * when it looks like a bearer token, a JWT; otherwise masks the password component of
     * any `scheme://user:password@host` URL and the value part of any
     * `needle=...`/`needle: ...`/`needle=>...` assignment (optionally quoted) found inside
     * it, leaving the rest of the string untouched.
     */
    public static function redactValue(string $value): string
    {
        // Bearer / Basic auth header values.
        if (preg_match('/\b(bearer|basic)\s+[A-Za-z0-9._\-\/+=]{8,}/i', $value)) {
            return self::MASK;
        }
        // JWT (three base64url segments).
        if (preg_match('/\beyJ[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+/', $value)) {
            return self::MASK;
        }
        // URL-embedded credentials: scheme://user:password@host — mask ONLY the password,
        // scheme/user/host stay intact (diagnostically useful, not secret). Kept in sync
        // with pylog's `_URL_CREDENTIALS_RE`.
        $value = (string) preg_replace(
            '~([A-Za-z][A-Za-z0-9+.-]*://)([^:/?#\s@]+):([^@/?#\s]+)@~',
            '$1$2:' . self::MASK . '@',
            $value
        );
        // key=value / key: value / key=>value where key is sensitive. Tolerates an optional
        // quote right after the key (closing quote of a JSON/PHP-array key, e.g.
        // `"password":` / `'token' =>`) and matches a quoted value — together with its
        // closing quote — as a single unit, so no dangling quote is ever left behind
        // (previously `secret=...` matched inside `{"password": "hunter2"}` never fired,
        // because the closing `"` sat between the needle and the `:`). Kept in sync with
        // pylog's `_kv_sub_re`. preg_replace() is a no-op when nothing matches, so a single
        // call covers the old match-then-replace two-step.
        $needles = implode('|', array_map('preg_quote', self::needles()));
        if ($needles !== '') {
            $value = (string) preg_replace(
                '/((?:' . $needles . ')["\']?\s*(?:=>|[=:])\s*)(?:"[^"]*"|\'[^\']*\'|\S+)/i',
                '$1' . self::MASK,
                $value
            );
        }
        return $value;
    }
}
