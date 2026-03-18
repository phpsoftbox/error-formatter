<?php

declare(strict_types=1);

namespace PhpSoftBox\ErrorFormatter\Tests;

use PhpSoftBox\ErrorFormatter\ThrowableFormatter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function array_filter;
use function str_contains;

#[CoversClass(ThrowableFormatter::class)]
final class ThrowableFormatterTest extends TestCase
{
    /**
     * Проверяет, что formatter возвращает headline и stack-trace в строках.
     */
    #[Test]
    public function toLinesReturnsHeadlineAndTrace(): void
    {
        $exception = $this->makeExceptionWithTrace();

        $lines = ThrowableFormatter::toLines($exception, 'Ошибка миграции:');

        self::assertNotSame([], $lines);
        self::assertSame('Ошибка миграции:', $lines[0]);
        self::assertNotSame(
            [],
            array_filter($lines, static fn (string $line): bool => str_contains($line, 'RuntimeException: boom')),
        );
        self::assertNotSame(
            [],
            array_filter($lines, static fn (string $line): bool => str_contains($line, '#0 ')),
        );
    }

    /**
     * Проверяет, что formatter возвращает location/trace/frames в согласованном виде.
     */
    #[Test]
    public function returnsTraceLocationAndFrames(): void
    {
        $exception = $this->makeExceptionWithTrace();

        self::assertNotSame('', ThrowableFormatter::toTrace($exception));
        self::assertStringContainsString('.php:', ThrowableFormatter::toLocation($exception));
        self::assertNotSame([], ThrowableFormatter::toFrames($exception));
    }

    private function makeExceptionWithTrace(): RuntimeException
    {
        try {
            $this->throwRuntimeException();
        } catch (RuntimeException $exception) {
            return $exception;
        }

        return new RuntimeException('unreachable');
    }

    private function throwRuntimeException(): void
    {
        throw new RuntimeException('boom');
    }
}
