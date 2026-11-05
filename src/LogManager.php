<?php

namespace Xakki\Log\Laravel;

use Illuminate\Support\Str;
use Monolog\Level;

class LogManager extends \Illuminate\Log\LogManager
{
    use TraitFileTrace;

    public const CONTEXT_TYPE_INT = 'int';
    public const CONTEXT_TYPE_FLOAT = 'float';
    public const CONTEXT_TYPE_BOOL = 'bool';
    public const CONTEXT_TYPE_STRING = 'string';
    /** "log() consumes this itself, do not touch". */
    public const CONTEXT_TYPE_SKIP = 'skip';

    /** Lucene refuses to index a term longer than 32766 bytes; cap below that. */
    public const CONTEXT_MAX_STRING_BYTES = 32000;

    /** Depth bound for json_encode of nested context values (guards recursion / huge blobs). */
    private const CONTEXT_JSON_MAX_DEPTH = 16;

    /**
     * Lowercased strings meaning boolean false. Legacy data really does ship 'F',
     * and a blind (bool) 'F' is true.
     *
     * @var string[]
     */
    public const CONTEXT_FALSE_VALUES = ['', '0', 'f', 'false', 'n', 'no', 'off'];

    /**
     * Key => type rules for contextTypeCorrector(). Shared convention with the sibling
     * logger xakki/phperrorcatcher (src/PhpErrorCatcher.php `$contextTypeRules`) — both
     * ship into the same OpenSearch cluster, so the two tables MUST agree.
     * Full reference: docs/ContextFieldNaming.ru.md.
     *
     * Extend per project via config('logger.context_type_rules') or at runtime:
     *   LogManager::addContextTypeRules(['word' => ['rub' => LogManager::CONTEXT_TYPE_FLOAT]]);
     * Entries are matched lowercased, so must be supplied lowercase.
     *
     * @var array<string, array<string, string>>
     */
    public static array $contextTypeRules = [
        // ESCAPE HATCH — TOP of the resolution chain, beats every other bucket including
        // 'reserved'. LAST segment only. Name a key '*_int'/'*_float'/'*_bool' when you
        // must force a type: it is the one rule nothing else can override.
        'suffix' => [
            'int' => self::CONTEXT_TYPE_INT,
            'float' => self::CONTEXT_TYPE_FLOAT,
            'bool' => self::CONTEXT_TYPE_BOOL,
        ],
        // Fields this library writes itself — pinned so a string default can't regress them.
        'reserved' => [
            'log_type' => self::CONTEXT_TYPE_STRING,
            'file' => self::CONTEXT_TYPE_STRING,
            'tag' => self::CONTEXT_TYPE_STRING,
            'trace' => self::CONTEXT_TYPE_SKIP,
            \LOGGER_SKIP_TRACE => self::CONTEXT_TYPE_SKIP,
            'exception' => self::CONTEXT_TYPE_STRING,
            'exception_code' => self::CONTEXT_TYPE_INT,
            'exception_prev' => self::CONTEXT_TYPE_STRING,
            'exception_prev_message' => self::CONTEXT_TYPE_STRING,
            'exception_prev_file' => self::CONTEXT_TYPE_STRING,
            'exception_prev_code' => self::CONTEXT_TYPE_INT,
            'exception_prev_trace' => self::CONTEXT_TYPE_STRING,
            // The one id that is NOT an auto-increment integer: getOrCreateRequestId() writes
            // a UUID (or the X-Request-ID header) here on every record. The int 'id' word
            // below would leave it on the string FALLBACK, which is stable only until some
            // caller passes an all-digit request id — then the field diverges and OpenSearch
            // drops documents. Pinned, not left to the fallback. Keep in sync with the
            // sibling logger's 'exact' entry (phperrorcatcher src/PhpErrorCatcher.php).
            'request_id' => self::CONTEXT_TYPE_STRING,
        ],
        // Whole-key overrides — the only way to type a key whose segments carry no type.
        'exact' => [
            // Last segment 'mctime' is not a word (would collide with the int 'time' rule).
            \LOGGER_MCTIME => self::CONTEXT_TYPE_FLOAT,
            // memory_get_usage()/memory_get_peak_usage() — both int; 'usage'/'peak' are
            // too generic to become words.
            \LOGGER_MEMORY => self::CONTEXT_TYPE_INT,
            \LOGGER_MEMORY_PEAK => self::CONTEXT_TYPE_INT,
            // Legacy key from the sibling logger (tamaranga bff/db/database.php:498).
            // 'millisecond' is deliberately NOT a word — 'seconds' is the canonical unit.
            'millisecond' => self::CONTEXT_TYPE_INT,
        ],
        // LAST segment => type. The type words themselves live in 'suffix' above, which
        // wins over this bucket: 'price_int' is an int, not a float.
        'word' => [
            // Covers the bare key 'id' and every '*_id' (user_id, order_id, smq_id). In these
            // projects an identifier is an auto-increment integer out of MariaDB, and the
            // owner wants range queries and aggregations on it — so int, not string.
            // TRAPS (both silent, both fixed by pinning the key to string via config
            // 'logger.context_type_rules' → 'exact'):
            //   * leading zeros — '00123' becomes 123;
            //   * an id past PHP_INT_MAX falls back to string, so THAT key is int for short
            //     values and string for long ones — the exact divergence this method prevents.
            // A non-integer id (UUID, hash, hex) must be pinned too, never left to the
            // fallback — see 'request_id' in 'reserved' above.
            'id' => self::CONTEXT_TYPE_INT,
            'count' => self::CONTEXT_TYPE_INT,
            'cnt' => self::CONTEXT_TYPE_INT,
            'num' => self::CONTEXT_TYPE_INT,
            'qty' => self::CONTEXT_TYPE_INT,
            'total' => self::CONTEXT_TYPE_INT,
            // A counted sum, not money — money is 'money'/'amount'/'price' (float).
            'sum' => self::CONTEXT_TYPE_INT,
            'size' => self::CONTEXT_TYPE_INT,
            'bytes' => self::CONTEXT_TYPE_INT,
            'len' => self::CONTEXT_TYPE_INT,
            'length' => self::CONTEXT_TYPE_INT,
            'limit' => self::CONTEXT_TYPE_INT,
            'offset' => self::CONTEXT_TYPE_INT,
            'page' => self::CONTEXT_TYPE_INT,
            'depth' => self::CONTEXT_TYPE_INT,
            'attempt' => self::CONTEXT_TYPE_INT,
            'retries' => self::CONTEXT_TYPE_INT,
            'level' => self::CONTEXT_TYPE_INT,
            'port' => self::CONTEXT_TYPE_INT,
            'status' => self::CONTEXT_TYPE_INT,
            // TRAP: '*_time' TRUNCATES a microtime(true) value — name it '*_duration'/'*_seconds'.
            'time' => self::CONTEXT_TYPE_INT,
            // Sole time-unit word on purpose: ms/millisecond(s)/sec/secs are plain strings.
            'seconds' => self::CONTEXT_TYPE_INT,
            'ttl' => self::CONTEXT_TYPE_INT,
            // The money words. 'total'/'sum' are int — money must use one of these.
            'money' => self::CONTEXT_TYPE_FLOAT,
            'amount' => self::CONTEXT_TYPE_FLOAT,
            'price' => self::CONTEXT_TYPE_FLOAT,
            'duration' => self::CONTEXT_TYPE_FLOAT,
            'rate' => self::CONTEXT_TYPE_FLOAT,
            'ratio' => self::CONTEXT_TYPE_FLOAT,
            'percent' => self::CONTEXT_TYPE_FLOAT,
            'avg' => self::CONTEXT_TYPE_FLOAT,
            'score' => self::CONTEXT_TYPE_FLOAT,
            'flag' => self::CONTEXT_TYPE_BOOL,
            'enabled' => self::CONTEXT_TYPE_BOOL,
            'success' => self::CONTEXT_TYPE_BOOL,
            // No 'force string' list: string IS the default, so 'code', 'at', 'uuid',
            // 'hash', 'ip', 'name', 'key', 'version' need no entry. NOTE: 'word' outranks
            // 'prefix', so a bool prefix loses to a typed last segment — 'is_bot_id' is an
            // INT, not a bool.
        ],
        // FIRST segment => type, only for keys with more than one segment.
        'prefix' => [
            'is' => self::CONTEXT_TYPE_BOOL,
            'has' => self::CONTEXT_TYPE_BOOL,
            'can' => self::CONTEXT_TYPE_BOOL,
            'should' => self::CONTEXT_TYPE_BOOL,
            'allow' => self::CONTEXT_TYPE_BOOL,
            'enable' => self::CONTEXT_TYPE_BOOL,
            'use' => self::CONTEXT_TYPE_BOOL,
        ],
    ];

    /** Guards the one-time merge of config('logger.context_type_rules'). */
    private static bool $contextTypeRulesLoaded = false;

    protected int $messageLimit = 3024;

    public function __construct($app)
    {
        parent::__construct($app);
        if ($limit = config('logger.message_limit')) {
            $this->messageLimit = (int) $limit;
        }
    }

    /**
     * @param string|\Stringable $message
     * @param array<string, string|int|float|bool> $context
     * @return void
     */
    public function emergency($message, array $context = []): void
    {
        $message = $this->appendContext(Level::Emergency, $message, $context);
        parent::emergency($message, $context);
    }

    /**
     * @param string|\Stringable $message
     * @param array<string, string|int|float|bool> $context
     * @return void
     */
    public function alert($message, array $context = []): void
    {
        $message = $this->appendContext(Level::Alert, $message, $context);
        parent::alert($message, $context);
    }

    /**
     * @param string|\Stringable $message
     * @param array<string, string|int|float|bool> $context
     * @return void
     */
    public function critical($message, array $context = []): void
    {
        $message = $this->appendContext(Level::Critical, $message, $context);
        parent::critical($message, $context);
    }

    /**
     * @param string|\Stringable $message
     * @param array<string, string|int|float|bool> $context
     * @return void
     */
    public function error($message, array $context = []): void
    {
        $message = $this->appendContext(Level::Error, $message, $context);
        parent::error($message, $context);
    }

    /**
     * @param string|\Stringable $message
     * @param array<string, string|int|float|bool> $context
     * @return void
     */
    public function warning($message, array $context = []): void
    {
        $message = $this->appendContext(Level::Warning, $message, $context);
        parent::warning($message, $context);
    }

    /**
     * @param string|\Stringable $message
     * @param array<string, string|int|float|bool> $context
     * @return void
     */
    public function notice($message, array $context = []): void
    {
        $message = $this->appendContext(Level::Notice, $message, $context);
        parent::notice($message, $context);
    }

    /**
     * @param string|\Stringable $message
     * @param array<string, string|int|float|bool> $context
     * @return void
     */
    public function info($message, array $context = []): void
    {
        $message = $this->appendContext(Level::Info, $message, $context);
        parent::info($message, $context);
    }

    /**
     * @param string|\Stringable $message
     * @param array<string, string|int|float|bool> $context
     * @return void
     */
    public function debug($message, array $context = []): void
    {
        $message = $this->appendContext(Level::Debug, $message, $context);
        parent::debug($message, $context);
    }

    /**
     * @param mixed $level
     * @param string|\Stringable $message
     * @param array<string, string|int|float|bool> $context
     * @return void
     */
    public function log($level, $message, array $context = []): void
    {
        $message = $this->appendContext(Level::fromName($level), $message, $context);
        parent::log($level, $message, $context);
    }

    /**
     * @param Level $level
     * @param string|\Stringable $message
     * @param array<string, mixed> $context
     * @return string
     */
    public function appendContext(Level $level, string|\Stringable $message, array &$context): string
    {
        // LOGGER_SKIP_TRACE ('skipTrace') opt-out (mirrors the Python port's
        // trace.py::should_skip_trace()): a truthy marker suppresses BOTH the call-site
        // and the exception trace below, wins over any configured trace depth, and the
        // marker key itself is stripped here so it never leaks onto the wire — same
        // treatment as 'exception' just below. Truthiness goes through contextCastBool()
        // (this library's own bool convention, CONTEXT_FALSE_VALUES) rather than a bare
        // PHP truthy check, so a string 'false'/'0'/'off' opts back IN, consistent with
        // every other bool-shaped context field.
        $skipTrace = false;
        if (array_key_exists(\LOGGER_SKIP_TRACE, $context)) {
            $skipTraceRaw = $context[\LOGGER_SKIP_TRACE];
            if (is_scalar($skipTraceRaw)) {
                $skipTrace = self::contextCastBool($skipTraceRaw);
            }
            unset($context[\LOGGER_SKIP_TRACE]);
        }

        $e = null;
        if (isset($context['exception']) && $context['exception'] instanceof \Throwable) {
            $e = $context['exception'];
            unset($context['exception']);
        }

        // §4.3.1 log_type: a handler-set origin (trigger/fatal/exception) wins; an explicit
        // log carrying an exception is 'exception'; everything else is 'logger'.
        if (! isset($context['log_type'])) {
            $logType = LogType::current();
            if ($e !== null && $logType === LogType::LOGGER) {
                $logType = LogType::EXCEPTION;
            }
            $context['log_type'] = $logType;
        }

        self::contextTypeCorrector($context);

        if ($e) {
            $context['exception'] = get_class($e);
            $context['exception_code'] = (int) $e->getCode();
            $context['file'] = self::getRelativeFilePath($e->getFile()) . ':' . $e->getLine();
            if (! $skipTrace) {
                $context['trace'] = self::traceToString($e->getTrace(), 30, false);
            }
            if ($e->getPrevious()) {
                $context['exception_prev'] = get_class($e->getPrevious());
                $context['exception_prev_message'] = $e->getPrevious()->getMessage();
                $context['exception_prev_file'] = self::getRelativeFilePath($e->getPrevious()->getFile()) . ':' . $e->getPrevious()->getLine();
                $context['exception_prev_code'] = (int) $e->getPrevious()->getCode();
                if (! $skipTrace) {
                    $context['exception_prev_trace'] = self::traceToString($e->getPrevious()->getTrace(), 30, false);
                }
            }
        }

        $context['message_len'] = mb_strlen($message);

        if (empty($context['file'])) {
            $trace = array_slice(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 40), 1);
            $context['file'] = self::getFileLine($trace);
            if (! $skipTrace && empty($context['trace']) && $level->value >= Level::Warning->value) {
                $context['trace'] = self::traceToString($trace, self::traceDepthForLevel($level));
            }
        }
        if (config('logger.allow_memory', false)) {
            $context[\LOGGER_MEMORY_PEAK] = memory_get_peak_usage();
            $context[\LOGGER_MEMORY] = memory_get_usage();
        }

        $context['request_id'] = self::getOrCreateRequestId();

        return mb_substr($message, 0, $this->messageLimit);
    }

    /**
     * Trace depth (frames) by level, config-driven (spec §3.7). Replaces the old
     * broken int-vs-enum comparison block.
     */
    protected static function traceDepthForLevel(Level $level): int
    {
        /** @var array<string,int> $depth */
        $depth = (array) config('logger.trace.depth', ['warning' => 5, 'error' => 10, 'critical' => 20]);
        return match (true) {
            $level->value >= Level::Critical->value => (int) ($depth['critical'] ?? 20),
            $level->value >= Level::Error->value => (int) ($depth['error'] ?? 10),
            default => (int) ($depth['warning'] ?? 5),
        };
    }

    /**
     * Reset the per-request id so the next entrypoint (e.g. the next queue job) gets a
     * fresh one. Call between units of work; LaraLogServiceProvider wires this to
     * JobProcessing (spec §5.1, fixes B7).
     */
    public static function resetRequestId(): void
    {
        unset($_SERVER['HTTP_REQUEST_ID']);
        putenv('HTTP_REQUEST_ID');
    }

    public static function getOrCreateRequestId(): string
    {
        if (empty($_SERVER['HTTP_REQUEST_ID'])) {
            if (! empty($_SERVER['HTTP_X_REQUEST_ID'])) {
                $id = $_SERVER['HTTP_X_REQUEST_ID'];
            } else {
                $id = Str::uuid();
            }
            $_SERVER['HTTP_REQUEST_ID'] = (string) $id;
        }

        if (! env('HTTP_REQUEST_ID')) {
            putenv('HTTP_REQUEST_ID=' . $_SERVER['HTTP_REQUEST_ID']);
        }

        return $_SERVER['HTTP_REQUEST_ID'];
    }

    /**
     * @param array<string, mixed> $context
     * @return void
     */
    public static function contextTypeCorrector(array &$context): void
    {
        // §4.7: optional snake_case of keys (default off). Run BEFORE the reserved-key
        // switch — the LOGGER_* constants are already lowercase, so they still match.
        if (config('logger.snake_case', false)) {
            $normalized = [];
            foreach ($context as $k => $v) {
                $normalized[Str::snake((string) $k)] = $v;
            }
            $context = $normalized;
        }

        $rules = self::contextTypeRules();

        foreach ($context as $k => &$r) {
            // §2: mask credential-ish fields by key name before anything else.
            if (Redactor::shouldRedactKey((string) $k)) {
                $r = Redactor::MASK;
                continue;
            }
            $type = self::contextResolveType((string) $k, $rules);
            if ($type === self::CONTEXT_TYPE_SKIP) {
                continue;
            }
            $r = self::contextCastValue($r, $type);
        }
        // Avoid leaving a live reference to the last element (trap for a future second loop).
        unset($r);
    }

    /**
     * Merge host-declared rules into the type table (entries win over library defaults for
     * the same pattern). Fed by config('logger.context_type_rules') at first use, or called
     * directly at runtime.
     *
     * A 'suffix'/'word'/'prefix' entry is a whole SEGMENT ('ttl', not '_ttl') — stray
     * underscores are trimmed. Unknown buckets and non-CONTEXT_TYPE_* values are skipped
     * silently: a config typo must not break logging.
     *
     * @param array<string, mixed> $rules Bucket => (pattern => type); anything else is ignored.
     */
    public static function addContextTypeRules(array $rules): void
    {
        $types = [
            self::CONTEXT_TYPE_INT,
            self::CONTEXT_TYPE_FLOAT,
            self::CONTEXT_TYPE_BOOL,
            self::CONTEXT_TYPE_STRING,
            self::CONTEXT_TYPE_SKIP,
        ];
        foreach ($rules as $bucket => $entries) {
            if (! isset(self::$contextTypeRules[$bucket]) || ! is_array($entries)) {
                continue;
            }
            foreach ($entries as $pattern => $type) {
                if (! in_array($type, $types, true)) {
                    continue;
                }
                $pattern = (string) $pattern;
                // 'reserved' is looked up by the raw key first, every other bucket only by
                // the lowercased one — so a host entry must be lowercase to ever match.
                if ($bucket !== 'reserved') {
                    $pattern = mb_strtolower($pattern);
                }
                if ($bucket === 'word' || $bucket === 'prefix' || $bucket === 'suffix') {
                    $pattern = trim($pattern, '_');
                    // A multi-segment 'word' entry can never match one segment; 'exact' is
                    // what types a whole key.
                    if ($pattern === '' || str_contains($pattern, '_')) {
                        continue;
                    }
                }
                self::$contextTypeRules[$bucket][$pattern] = $type;
            }
        }
    }

    /** Drop the config-merge guard so the next call re-reads config (tests / config reload). */
    public static function flushContextTypeRules(): void
    {
        self::$contextTypeRulesLoaded = false;
    }

    /**
     * @return array<string, array<string, string>>
     */
    private static function contextTypeRules(): array
    {
        if (! self::$contextTypeRulesLoaded) {
            self::$contextTypeRulesLoaded = true;
            /** @var array<string, array<string, string>> $extra */
            $extra = (array) config('logger.context_type_rules', []);
            self::addContextTypeRules($extra);
        }

        return self::$contextTypeRules
            + ['suffix' => [], 'reserved' => [], 'exact' => [], 'word' => [], 'prefix' => []];
    }

    /**
     * Resolution order, first match wins:
     *   1. suffix   — the ESCAPE HATCH: LAST segment 'int'/'float'/'bool'. Highest
     *                 priority, beats every bucket below including 'reserved' — naming a
     *                 key '*_int' is the one way to force a type nothing else overrides.
     *   2. reserved — whole key; library-owned fields.
     *   3. exact    — whole key, lowercased; host override level.
     *   4. word     — the LAST segment.
     *   5. prefix   — the FIRST segment, for keys with 2+ segments only.
     *   6. no rule  — the default string.
     *
     * @param array<string, array<string, string>> $rules
     * @return string|null Type constant, or null when no rule matches
     */
    private static function contextResolveType(string $key, array $rules): ?string
    {
        $low = mb_strtolower($key);
        // Whole segments only: 'report' splits into ['report'], never matches 'port'.
        $parts = explode('_', $low);
        $last = $parts[count($parts) - 1];

        return $rules['suffix'][$last]
            ?? $rules['reserved'][$key]
            ?? $rules['reserved'][$low]
            ?? $rules['exact'][$low]
            ?? $rules['word'][$last]
            // A single-segment key has no prefix: a lone 'is' is a word, not a flag.
            ?? (count($parts) > 1 ? ($rules['prefix'][$parts[0]] ?? null) : null);
    }

    /**
     * A value is cast only when information-preserving; otherwise it falls back to string
     * (array/object under a scalar rule is json-encoded instead). `null` always stays null.
     * Exception: a fractional value under an int rule is truncated rather than falling back
     * to string, so `retries => 1.5` and `retries => 3` don't diverge in type.
     */
    private static function contextCastValue(mixed $value, ?string $type): mixed
    {
        if ($value === null) {
            return null;
        }
        if ($value instanceof \Stringable && $type === self::CONTEXT_TYPE_STRING) {
            // A value object rendering itself beats json_encode()'ing it to '{}'.
            return self::contextCapString((string) $value);
        }
        if (is_array($value) || is_object($value)) {
            // No declared rule => do NOT flatten; would break existing nested-path queries.
            // Under a scalar rule (int) [1,2] === 1 would lose data — json-encode instead.
            return $type === null ? $value : self::contextJsonEncode($value);
        }
        if (is_resource($value)) {
            return '(resource:' . get_resource_type($value) . ')';
        }

        return match ($type) {
            self::CONTEXT_TYPE_INT => self::contextCastInt($value),
            self::CONTEXT_TYPE_FLOAT => self::contextCastFloat($value),
            self::CONTEXT_TYPE_BOOL => self::contextCastBool($value),
            // Default type for every key without an int/float/bool rule.
            default => self::contextCapString(self::contextScalarToString($value)),
        };
    }

    private static function contextCastInt(bool|int|float|string $value): int|string
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        $limit = (float) PHP_INT_MAX;
        if (is_float($value)) {
            if (! is_finite($value) || $value >= $limit || $value <= -$limit) {
                return self::contextScalarToString($value);
            }

            return (int) $value;
        }

        $str = trim($value);
        if (! self::contextIsDecimal($str)) {
            // (int) 'RU' === 0 would destroy the value — fall back to the default type.
            return self::contextCapString($value);
        }
        $int = filter_var($str, FILTER_VALIDATE_INT);
        if (is_int($int)) {
            // Exact for every integer literal in range — no float round-trip to lose bits.
            return $int;
        }
        // Fractional ('19.99'), exponent ('1e5'), leading zeros ('007') or out of int range.
        $num = (float) $str;
        if (! is_finite($num) || $num >= $limit || $num <= -$limit) {
            // Out of int range: (int) would wrap into garbage.
            return self::contextCapString($value);
        }

        return (int) $num;
    }

    private static function contextCastFloat(bool|int|float|string $value): float|string
    {
        if (is_float($value)) {
            return is_finite($value) ? $value : self::contextScalarToString($value);
        }
        if (is_int($value)) {
            return (float) $value;
        }
        if (is_bool($value)) {
            return $value ? 1.0 : 0.0;
        }
        $str = trim($value);
        if (! self::contextIsDecimal($str)) {
            return self::contextCapString($value);
        }
        $num = (float) $str;

        return is_finite($num) ? $num : self::contextCapString($value);
    }

    private static function contextCastBool(bool|int|float|string $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return $value != 0;
        }

        return ! in_array(mb_strtolower(trim($value)), self::CONTEXT_FALSE_VALUES, true);
    }

    /**
     * Strict decimal check — deliberately NOT is_numeric(): '0x1A' / '1_000' must not be
     * silently turned into a number.
     *
     * @param string $str Already trimmed
     */
    private static function contextIsDecimal(string $str): bool
    {
        return (bool) preg_match('/^[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?$/', $str);
    }

    private static function contextScalarToString(bool|int|float|string $value): string
    {
        if (is_bool($value)) {
            // (string) false === '' would look like a missing value.
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }

    private static function contextCapString(string $value): string
    {
        if (strlen($value) <= self::CONTEXT_MAX_STRING_BYTES) {
            return $value;
        }

        return mb_strcut($value, 0, self::CONTEXT_MAX_STRING_BYTES - 1) . '…';
    }

    private static function contextJsonEncode(mixed $value): string
    {
        // Flags kept identical to the sibling logger so the same structure renders to the
        // same string in both. JSON_PRESERVE_ZERO_FRACTION: float 5.0 must not emit as `5`.
        $flags = JSON_PRESERVE_ZERO_FRACTION;
        $json = json_encode($value, $flags, self::CONTEXT_JSON_MAX_DEPTH);
        if (! is_string($json)) {
            // Too deep, recursive, or invalid UTF-8 — keep whatever can be salvaged.
            $json = json_encode($value, $flags | JSON_PARTIAL_OUTPUT_ON_ERROR, self::CONTEXT_JSON_MAX_DEPTH);
        }
        if (! is_string($json)) {
            // Never return false: it would become the empty string downstream.
            $json = '{"_encode_error":"' . (is_object($value) ? get_class($value) : 'array') . '"}';
        }

        return self::contextCapString($json);
    }
}
