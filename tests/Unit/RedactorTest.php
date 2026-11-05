<?php declare(strict_types=1);

namespace Xakki\Log\Laravel\Tests\Unit;

use Xakki\Log\Laravel\Redactor;
use Xakki\Log\Laravel\Tests\AbstractTestCase;

class RedactorTest extends AbstractTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Static needle cache leaks across tests in one process — reset before each, and
        // rebuild the app so config('logger.redact', []) is resolvable (needles() reads it).
        Redactor::flush();
        $this->createApplication();
    }

    protected function tearDown(): void
    {
        Redactor::flush();
        parent::tearDown();
    }

    // --- Fix 1: quoted/JSON-ish key=value values (HIGH) ---

    public function testJsonDoubleQuotedValueIsRedacted(): void
    {
        // The quoted value (incl. its own quotes) is matched and replaced as a single unit,
        // so no dangling quote is left behind around the mask.
        $out = Redactor::redactValue('body: {"user": "alice", "password": "hunter2"}');

        $this->assertStringNotContainsString('hunter2', $out);
        $this->assertSame('body: {"user": "alice", "password": ***}', $out);
    }

    public function testPhpArrowSingleQuotedValueIsRedacted(): void
    {
        $out = Redactor::redactValue("'token' => 'abc'");

        $this->assertStringNotContainsString('abc', $out);
        $this->assertSame("'token' => ***", $out);
    }

    public function testUnquotedKeyEqualsQuotedValueIsRedacted(): void
    {
        $out = Redactor::redactValue('password="hunter2"');

        $this->assertStringNotContainsString('hunter2', $out);
        $this->assertSame('password=***', $out);
    }

    public function testPlainUnquotedAssignmentStillWorks(): void
    {
        // Pre-existing behaviour — must not regress.
        $out = Redactor::redactValue('secret=abc');

        $this->assertSame('secret=***', $out);
    }

    public function testColonSeparatedAssignmentStillWorks(): void
    {
        $out = Redactor::redactValue('token: abc123');

        $this->assertSame('token: ***', $out);
    }

    // --- Fix 2: URL-embedded credentials (MED) ---

    public function testPostgresUrlPasswordIsMaskedHostAndUserSurvive(): void
    {
        $out = Redactor::redactValue('postgres://user:S3cr3t@host/db');

        $this->assertStringNotContainsString('S3cr3t', $out);
        $this->assertSame('postgres://user:***@host/db', $out);
    }

    public function testHttpsUrlPasswordIsMasked(): void
    {
        $out = Redactor::redactValue('connect to https://user:pass@host/path failed');

        $this->assertStringNotContainsString('pass@', $out);
        $this->assertStringContainsString('https://user:***@host/path', $out);
    }

    // --- Pre-existing behaviours (no regression) ---

    public function testBuiltinNeedleIsRedacted(): void
    {
        $out = Redactor::redactValue('api_key=sk-live-123');

        $this->assertSame('api_key=***', $out);
    }

    public function testBearerTokenIsFullyMasked(): void
    {
        $out = Redactor::redactValue('Authorization: Bearer abcDEF123.token-value');

        $this->assertSame(Redactor::MASK, $out);
    }

    public function testJwtIsFullyMasked(): void
    {
        $jwt = 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dozjgNryP4J3jVmNHl0w5N_XgL0n3I9PlFUP0THsR8U';
        $out = Redactor::redactValue($jwt);

        $this->assertSame(Redactor::MASK, $out);
    }

    public function testKeyMatchRedactsByField(): void
    {
        $this->assertTrue(Redactor::shouldRedactKey('password'));
        $this->assertTrue(Redactor::shouldRedactKey('User-Password'));
        $this->assertTrue(Redactor::shouldRedactKey('api_key'));
    }

    public function testCardinalityAndAuthorAreNotOverRedacted(): void
    {
        // 'card'/'auth' are deliberately NOT needles — only 'card_number'/'authorization' are.
        $this->assertFalse(Redactor::shouldRedactKey('cardinality'));
        $this->assertFalse(Redactor::shouldRedactKey('author'));

        $out = Redactor::redactValue('cardinality=5, author=jdoe');
        $this->assertSame('cardinality=5, author=jdoe', $out);
    }
}
