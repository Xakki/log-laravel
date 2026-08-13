<?php declare(strict_types=1);

namespace Xakki\LaraLogTests\Unit;

use Monolog\Processor\LoadAverageProcessor;
use Monolog\Processor\WebProcessor;
use Xakki\LaraLog\LogManager;
use Xakki\LaraLog\Processor\ExtraProcessor;
use Xakki\LaraLog\Redactor;
use Xakki\LaraLog\TraitFileTrace;
use Xakki\LaraLogTests\AbstractTestCase;

class LogManagerTest extends AbstractTestCase
{
    /** @var array<string, array<string, string>> */
    private array $contextTypeRulesBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        // Static caches leak across tests in one process — reset before each.
        Redactor::flush();
        TraitFileTrace::flushTraceConfig();
        // addContextTypeRules() mutates a static table; snapshot it so a project-rule test
        // can't leak its entries into the next one.
        $this->contextTypeRulesBackup = LogManager::$contextTypeRules;
        LogManager::flushContextTypeRules();
    }

    protected function tearDown(): void
    {
        LogManager::$contextTypeRules = $this->contextTypeRulesBackup;
        LogManager::flushContextTypeRules();
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $extraConfig applied AFTER the app is built (createApplication
     *                                           rebinds the global container, wiping prior config)
     */
    private function logToFile(string $level, string $message, array $context = [], string $minLevel = 'debug', array $extraConfig = []): string
    {
        $logManager = new LogManager($this->createApplication());
        config(array_merge([
            'logging.default' => 'single',
            'logging.channels.single.path' => $this->getLogPath(),
            'logging.channels.single.level' => $minLevel,
        ], $extraConfig));
        $this->clearLog();
        $logManager->{$level}($message, $context);
        return $this->getLog();
    }

    public function testInit(): void
    {
        // appendContext now always adds log_type + request_id, and (after the B2 fix) a trace
        // at >= warning — so assert on stable substrings instead of an exact context match.
        $log = $this->logToFile('warning', 'Test message', ['test' => 'context message'], 'notice');

        $this->assertStringContainsString('testing.WARNING: Test message', $log);
        $this->assertStringContainsString('"test":"context message"', $log);
        $this->assertStringContainsString('"message_len":12', $log);
        $this->assertStringContainsString('"log_type":"logger"', $log);
        $this->assertStringContainsString('"request_id":', $log);
        $this->assertStringContainsString('LogManagerTest.php:', $log);
    }

    public function testLogTypeExceptionOnExplicitException(): void
    {
        $log = $this->logToFile('error', 'boom', ['exception' => new \RuntimeException('x')]);

        $this->assertStringContainsString('"log_type":"exception"', $log);
        $this->assertStringContainsString('"exception":"RuntimeException"', $log);
    }

    public function testCredentialRedactionByKey(): void
    {
        $log = $this->logToFile('warning', 'login', [
            'password' => 'hunter2',
            'api_key'  => 'sk-live-123',
            'order_id' => '42',
        ]);

        $this->assertStringContainsString('"password":"***"', $log);
        $this->assertStringContainsString('"api_key":"***"', $log);
        $this->assertStringNotContainsString('hunter2', $log);
        $this->assertStringNotContainsString('sk-live-123', $log);
        // Non-secret fields still pass through. '*_id' is an INT by convention (auto-increment
        // MariaDB identifiers, wanted for range queries and aggregations).
        $this->assertStringContainsString('"order_id":42', $log);
    }

    public function testSnakeCaseOptIn(): void
    {
        $log = $this->logToFile('warning', 'msg', ['orderId' => '7'], 'debug', ['logger.snake_case' => true]);

        $this->assertStringContainsString('"order_id":7', $log);
        $this->assertStringNotContainsString('orderId', $log);
    }

    public function testTraceAttachedByLevel(): void
    {
        $notice = $this->logToFile('notice', 'just a note');
        $this->assertStringNotContainsString('"trace":', $notice);

        $error = $this->logToFile('error', 'a problem');
        $this->assertStringContainsString('"trace":', $error);
    }

    public function testTraceDepthIsConfigurable(): void
    {
        // depth 1 -> traceToString appends '***' after the first rendered frame. If B2
        // regressed (depth hardcoded to 20) this shallow stack would NOT be truncated.
        $log = $this->logToFile('error', 'deep', [], 'debug', [
            'logger.trace.depth' => ['warning' => 5, 'error' => 1, 'critical' => 20],
        ]);
        $this->assertStringContainsString('***', $log);
    }

    public function testSkipTraceMarkerSuppressesCallSiteTrace(): void
    {
        // Baseline: without the marker, an error-level log gets a trace (same fixture as
        // testTraceAttachedByLevel above).
        $withTrace = $this->logToFile('error', 'a problem');
        $this->assertStringContainsString('"trace":', $withTrace);

        $skipped = $this->logToFile('error', 'a problem', [\LOGGER_SKIP_TRACE => true]);
        $this->assertStringNotContainsString('"trace":', $skipped);
    }

    public function testSkipTraceMarkerSuppressesExceptionTrace(): void
    {
        $log = $this->logToFile('error', 'boom', [
            'exception' => new \RuntimeException('x'),
            \LOGGER_SKIP_TRACE => true,
        ]);

        $this->assertStringContainsString('"exception":"RuntimeException"', $log);
        $this->assertStringNotContainsString('"trace":', $log);
    }

    public function testSkipTraceMarkerKeyDoesNotLeakIntoContext(): void
    {
        $log = $this->logToFile('warning', 'msg', [\LOGGER_SKIP_TRACE => true]);

        $this->assertStringNotContainsString('skipTrace', $log);
        $this->assertStringNotContainsString('skip_trace', $log);
    }

    public function testSkipTraceWinsOverConfiguredTraceDepth(): void
    {
        // Even with an explicit, generous configured depth, a truthy skip-trace marker must
        // still suppress the trace entirely.
        $log = $this->logToFile('critical', 'deep', [\LOGGER_SKIP_TRACE => true], 'debug', [
            'logger.trace.depth' => ['warning' => 5, 'error' => 10, 'critical' => 20],
        ]);
        $this->assertStringNotContainsString('"trace":', $log);
    }

    public function testSkipTraceFalsyStringDoesNotSuppressTrace(): void
    {
        // Truthiness is decided via contextCastBool()/CONTEXT_FALSE_VALUES, matching how
        // every other bool-shaped context field in this library is interpreted — a string
        // 'false' opts back IN to tracing rather than opting out.
        $log = $this->logToFile('error', 'a problem', [\LOGGER_SKIP_TRACE => 'false']);
        $this->assertStringContainsString('"trace":', $log);
    }

    public function testExtraProcessorDumpsConfigExtra(): void
    {
        // ExtraProcessor copies config('logger.extra') verbatim; empty values are dropped.
        $this->createApplication();
        config(['logger.extra' => [
            'tier'        => 'prod',
            'release_tag' => 'v1.2.3',
            'log_ver'     => '0.3',
        ]]);

        $record = new \Monolog\LogRecord(
            new \DateTimeImmutable(),
            'test',
            \Monolog\Level::Info,
            'hello',
        );
        $out = (new ExtraProcessor())($record);

        $this->assertSame('prod', $out->extra['tier']);
        $this->assertSame('v1.2.3', $out->extra['release_tag']);
        $this->assertSame('0.3', $out->extra['log_ver']);
    }


    public function testSyslog(): void
    {
        $logManager = new LogManager($this->createApplication());
        $this->clearLog();

        config([
            'logging.default' => 'syslog',
            'logging.channels.syslog' => [
                'driver' => 'monolog',
                'handler'    => \Monolog\Handler\SyslogUdpHandler::class,
                'handler_with' => [
                    'host' => '127.0.0.1',
                    'port' => 15140,
                    'facility' => LOG_LOCAL0,
                    'level' => env('LOG_LEVEL', 'info'),
                ],
                'formatter' => \Xakki\LaraLog\Formatters\CustomFormatter::class,
                'formatter_with' => [
                    'dateFormat' => 'Y-m-d\TH:i:s.uP',
                ],
                'processors' => [
                    \Xakki\LaraLog\Processor\ExtraProcessor::class,
                    LoadAverageProcessor::class,
                    WebProcessor::class,
                ],
            ],
        ]);

        $mess = 'Test message';
        $context = ['test'  => 'context message'];
        $logManager->warning($mess, $context);

        $this->assertTrue(true);
    }

    /**
     * The convention (docs/ContextFieldNaming.ru.md), shared verbatim with the sibling
     * logger xakki/phperrorcatcher. String is the default; only the listed segments cast.
     *
     * @return array<string, array{0: string, 1: mixed, 2: mixed}>
     */
    public static function contextTypeProvider(): array
    {
        return [
            // --- 'id' is an int WORD: the bare key and every '*_id' ---
            'bare id is int' => ['id', '42', 42],
            'user_id is int' => ['user_id', '17', 17],
            'order_id is int' => ['order_id', '42', 42],
            'smq_id from a string call site' => ['smq_id', '2921172', 2921172],
            'smq_id from an int call site' => ['smq_id', 2921172, 2921172],
            'smq_id zero' => ['smq_id', 0, 0],
            'smq_id null stays null' => ['smq_id', null, null],
            // request_id is pinned to string in 'reserved' — the library writes a UUID there.
            // It must not rely on the string FALLBACK: an all-digit value would flip the field.
            'request_id uuid stays string' => ['request_id', '3f2a1c8e-5b6d-4e7f-9a01-2b3c4d5e6f70', '3f2a1c8e-5b6d-4e7f-9a01-2b3c4d5e6f70'],
            'request_id all digits still string' => ['request_id', '2921172', '2921172'],
            'request_id int still string' => ['request_id', 2921172, '2921172'],
            // TRAP 1: leading zeros are destroyed. Remedy — pin the key to string via config.
            'id loses leading zeros' => ['order_id', '00123', 123],
            'id loses leading zeros (007)' => ['order_id', '007', 7],
            // TRAP 2: an id past PHP_INT_MAX falls back to string, so THAT key is int for
            // short values and string for long ones. Remedy — the same config pin.
            'oversized id falls back to string' => ['order_id', '99999999999999999999', '99999999999999999999'],
            // Only the whole last SEGMENT counts: these merely end in the letters 'id'.
            'uuid is not id' => ['req_uuid', '5', '5'],
            'android is not id' => ['android', '5', '5'],
            'camelCase orderid is not id' => ['orderid', '5', '5'],

            // --- string is the DEFAULT: codes, hashes, names keep leading zeros ---
            'code stays string' => ['error_code', '0500', '0500'],
            'unknown key stays string' => ['whatever', '42', '42'],
            'int under no rule stringifies' => ['whatever', 42, '42'],
            'bool under no rule stringifies' => ['whatever', true, 'true'],

            // --- int words ---
            'count' => ['retry_count', '7', 7],
            'cnt' => ['row_cnt', '7', 7],
            'total' => ['item_total', '3', 3],
            'sum is int' => ['cart_sum', '42', 42],
            'bare sum is int' => ['sum', '42', 42],
            'sum truncates a money value' => ['cart_sum', '19.99', 19],
            'seconds' => ['wait_seconds', '2', 2],
            'time' => ['time', '1754300000', 1754300000],
            'time truncates microtime' => ['exec_time', 1.75, 1],
            'status' => ['status', '500', 500],
            'ttl' => ['cache_ttl', '600', 600],
            'size' => ['file_size', '1024', 1024],

            // --- float words ---
            'money' => ['order_money', '99.50', 99.50],
            'bare money' => ['money', '5', 5.0],
            'amount' => ['pay_amount', '100.10', 100.10],
            'price' => ['item_price', '19.99', 19.99],
            'duration' => ['job_duration', 3, 3.0],
            'rate' => ['hit_rate', '0.75', 0.75],
            'percent' => ['cpu_percent', '12.5', 12.5],
            'mctime is exact float' => ['mctime', '1.000001', 1.000001],

            // --- bool: last segment and first segment ---
            'success' => ['success', 1, true],
            'flag' => ['debug_flag', '', false],
            'enabled' => ['cache_enabled', 'true', true],
            'is prefix' => ['is_admin', 1, true],
            'has prefix' => ['has_access', 0, false],
            // 'word' outranks 'prefix', so a typed last segment BEATS a bool prefix:
            // 'is_bot_id' is an int, not a bool. Counter-intuitive but deliberate.
            'id word beats the is prefix' => ['is_bot_id', '5', 5],
            'legacy F is false' => ['is_bot', 'F', false],

            // --- escape hatch: '_int' / '_float' / '_bool' win over every other bucket ---
            'int suffix' => ['anything_int', '42', 42],
            'float suffix' => ['anything_float', '42', 42.0],
            'int suffix beats price' => ['price_int', '19.99', 19],
            'bool suffix beats count' => ['count_bool', '1', true],
            'float suffix beats seconds' => ['seconds_float', '2', 2.0],
            'suffix beats the is prefix' => ['is_ready_int', '1', 1],
            'bool suffix beats the id word' => ['some_id_bool', '1', true],
            'bool suffix beats the id word (off)' => ['some_id_bool', 'off', false],
            'float suffix beats the id word' => ['some_id_float', '42', 42.0],

            // --- whole SEGMENTS only, never substrings ---
            'recount is not count' => ['recount', '3', '3'],
            'report is not port' => ['report', '3', '3'],
            'datetime is not time' => ['datetime', '3', '3'],

            // --- uncastable falls back to string, null is preserved ---
            'uncastable int' => ['retry_count', 'many', 'many'],
            'hex is not decimal' => ['retry_count', '0x1A', '0x1A'],
            'null stays null' => ['retry_count', null, null],

            // --- exact pins for keys whose segments carry no type ---
            'memory_usage' => ['memory_usage', '2097152', 2097152],
            'memory_peak' => ['memory_peak', '4194304', 4194304],
            'legacy millisecond pinned int' => ['millisecond', '250', 250],
            'other ms spellings stay string' => ['exec_ms', '250', '250'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('contextTypeProvider')]
    public function testContextTypeConvention(string $key, mixed $input, mixed $expected): void
    {
        $this->createApplication();
        $context = [$key => $input];
        LogManager::contextTypeCorrector($context);

        $this->assertSame($expected, $context[$key]);
    }

    /**
     * The escape hatch is the TOP of the chain: it beats 'reserved' (library-owned fields)
     * and 'exact' (the host override level), so a '*_int' name always wins.
     */
    public function testTypeSuffixBeatsReservedAndExact(): void
    {
        $this->createApplication();
        LogManager::$contextTypeRules['reserved']['owned_int'] = LogManager::CONTEXT_TYPE_STRING;
        LogManager::$contextTypeRules['reserved']['skipped_bool'] = LogManager::CONTEXT_TYPE_SKIP;
        LogManager::$contextTypeRules['exact']['pinned_float'] = LogManager::CONTEXT_TYPE_STRING;

        $context = ['owned_int' => '42', 'skipped_bool' => '1', 'pinned_float' => '1.5'];
        LogManager::contextTypeCorrector($context);

        $this->assertSame(42, $context['owned_int']);
        $this->assertTrue($context['skipped_bool']);
        $this->assertSame(1.5, $context['pinned_float']);
    }

    /**
     * Resolution order end to end: suffix > reserved > exact > word > prefix > string.
     */
    public function testResolutionOrder(): void
    {
        $this->createApplication();
        LogManager::$contextTypeRules['exact']['is_count'] = LogManager::CONTEXT_TYPE_STRING;

        $context = [
            'price_int' => '19.99',   // 1. suffix beats the 'price' word
            'exception_code' => '7',  // 2. reserved
            'is_count' => '5',        // 3. exact beats the 'count' word and the 'is' prefix
            'has_total' => '3',       // 4. word beats the 'has' prefix
            'is_admin' => '1',        // 5. prefix, nothing else matched
            'whatever' => '9',        // 6. no rule -> string
        ];
        LogManager::contextTypeCorrector($context);

        $this->assertSame(19, $context['price_int']);
        $this->assertSame(7, $context['exception_code']);
        $this->assertSame('5', $context['is_count']);
        $this->assertSame(3, $context['has_total']);
        $this->assertTrue($context['is_admin']);
        $this->assertSame('9', $context['whatever']);
    }

    /**
     * camelCase never matches a segment — it needs a lowercase 'exact' entry (or snake_case).
     */
    public function testCamelCaseNeedsAnExactRule(): void
    {
        $this->createApplication();
        $context = ['orderQty' => '5'];
        LogManager::contextTypeCorrector($context);
        $this->assertSame('5', $context['orderQty']);

        LogManager::addContextTypeRules(['exact' => ['orderqty' => LogManager::CONTEXT_TYPE_INT]]);
        $context = ['orderQty' => '5'];
        LogManager::contextTypeCorrector($context);
        $this->assertSame(5, $context['orderQty']);
    }

    /**
     * The documented remedy for BOTH named traps of the int 'id' rule, proven executable:
     * there is no '_string' escape hatch, so a key carrying zero-padded or oversized ids can
     * only be pinned back to string through config 'exact'.
     */
    public function testIdTrapsAreFixedByPinningTheKeyToString(): void
    {
        $this->createApplication();

        // Unpinned: the traps themselves, asserted so they can never regress silently.
        $context = ['order_id' => '00123', 'other_id' => '99999999999999999999'];
        LogManager::contextTypeCorrector($context);
        $this->assertSame(123, $context['order_id']);
        $this->assertSame('99999999999999999999', $context['other_id']);

        LogManager::addContextTypeRules([
            'exact' => [
                'legacy_id' => LogManager::CONTEXT_TYPE_STRING,
                'legacy_id_long' => LogManager::CONTEXT_TYPE_STRING,
            ],
        ]);
        $context = ['legacy_id' => '00123', 'legacy_id_long' => '99999999999999999999'];
        LogManager::contextTypeCorrector($context);

        // Pinned: leading zeros survive, and the key is a string for EVERY value length.
        $this->assertSame('00123', $context['legacy_id']);
        $this->assertSame('99999999999999999999', $context['legacy_id_long']);
    }

    /**
     * An unruled array/object is NOT flattened (would break nested-path queries); under a
     * scalar rule it is json-encoded, because (int) [1,2] === 1 loses the data silently.
     */
    public function testStructuresAreEncodedOnlyUnderAScalarRule(): void
    {
        $this->createApplication();
        $context = ['payload' => [1, 2], 'retry_count' => [1, 2]];
        LogManager::contextTypeCorrector($context);

        $this->assertSame([1, 2], $context['payload']);
        $this->assertSame('[1,2]', $context['retry_count']);
    }

    public function testProjectRulesFromConfigAreMerged(): void
    {
        $this->createApplication();
        config(['logger.context_type_rules' => ['word' => ['rub' => LogManager::CONTEXT_TYPE_FLOAT]]]);

        $context = ['order_rub' => '99.50'];
        LogManager::contextTypeCorrector($context);

        $this->assertSame(99.50, $context['order_rub']);
    }

    public function testCorrectorIsIdempotent(): void
    {
        $this->createApplication();
        $context = [
            'order_id' => '42',
            'retry_count' => '42',
            'is_bot' => 'F',
            'cart_money' => '1.5',
            'payload' => [1],
            'nothing' => null,
        ];
        LogManager::contextTypeCorrector($context);
        $once = $context;
        LogManager::contextTypeCorrector($context);

        $this->assertSame($once, $context);
    }
}
