<?php

declare(strict_types=1);

namespace BNT\Tests;

/** Tiny dependency-free test base class (no PHPUnit required). */
abstract class TestCase
{
    public int $assertions = 0;

    /** Override to skip the whole class with a reason. */
    public function skipReason(): ?string
    {
        return null;
    }

    public function setUp(): void {}

    public function tearDown(): void {}

    public static function setUpBeforeClass(): void {}

    public static function tearDownAfterClass(): void {}

    protected function assertTrue(mixed $v, string $msg = ''): void
    {
        $this->assertions++;
        if ($v !== true) {
            throw new AssertionFailed($msg ?: 'Expected true, got ' . var_export($v, true));
        }
    }

    protected function assertFalse(mixed $v, string $msg = ''): void
    {
        $this->assertions++;
        if ($v !== false) {
            throw new AssertionFailed($msg ?: 'Expected false, got ' . var_export($v, true));
        }
    }

    protected function assertSame(mixed $expected, mixed $actual, string $msg = ''): void
    {
        $this->assertions++;
        if ($expected !== $actual) {
            throw new AssertionFailed(($msg ? "$msg: " : '') . 'Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
        }
    }

    protected function assertEquals(mixed $expected, mixed $actual, string $msg = ''): void
    {
        $this->assertions++;
        if ($expected != $actual) {
            throw new AssertionFailed(($msg ? "$msg: " : '') . 'Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
        }
    }

    protected function assertContains(string $needle, string $haystack, string $msg = ''): void
    {
        $this->assertions++;
        if (!str_contains($haystack, $needle)) {
            throw new AssertionFailed(($msg ? "$msg: " : '') . "Expected to find '$needle' in '" . mb_substr($haystack, 0, 400) . "'");
        }
    }

    protected function assertNotContains(string $needle, string $haystack, string $msg = ''): void
    {
        $this->assertions++;
        if (str_contains($haystack, $needle)) {
            throw new AssertionFailed(($msg ? "$msg: " : '') . "Did not expect '$needle' in '" . mb_substr($haystack, 0, 400) . "'");
        }
    }

    protected function assertGreaterThan(float|int $min, float|int $actual, string $msg = ''): void
    {
        $this->assertions++;
        if (!($actual > $min)) {
            throw new AssertionFailed(($msg ? "$msg: " : '') . "Expected $actual > $min");
        }
    }

    protected function assertLessThan(float|int $max, float|int $actual, string $msg = ''): void
    {
        $this->assertions++;
        if (!($actual < $max)) {
            throw new AssertionFailed(($msg ? "$msg: " : '') . "Expected $actual < $max");
        }
    }

    protected function assertCount(int $n, array $a, string $msg = ''): void
    {
        $this->assertSame($n, count($a), $msg ?: 'count');
    }
}

class AssertionFailed extends \Exception {}
