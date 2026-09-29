<?php

declare(strict_types=1);

namespace PhpSoftBox\ErrorFormatter\Tests;

use LogicException;
use PhpSoftBox\ErrorFormatter\ThrowableFormatter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function array_filter;
use function array_values;
use function ini_get;
use function ini_set;
use function str_contains;

#[CoversClass(ThrowableFormatter::class)]
#[CoversMethod(ThrowableFormatter::class, 'toLines')]
#[CoversMethod(ThrowableFormatter::class, 'toTrace')]
#[CoversMethod(ThrowableFormatter::class, 'toLocation')]
#[CoversMethod(ThrowableFormatter::class, 'toFrames')]
final class ThrowableFormatterTest extends TestCase
{
    private string|false $ignoreArgs = false;

    protected function setUp(): void
    {
        // Включаем сбор аргументов в trace, чтобы проверять именно поведение formatter.
        $this->ignoreArgs = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');
    }

    protected function tearDown(): void
    {
        if ($this->ignoreArgs !== false) {
            ini_set('zend.exception_ignore_args', $this->ignoreArgs);
        }
    }

    /**
     * Проверим, что formatter возвращает headline, сообщение и stack-trace в строках.
     *
     * @see ThrowableFormatter::toLines()
     */
    #[Test]
    public function toLinesReturnsHeadlineAndTrace(): void
    {
        $exception = $this->makeException('secret-password');

        $lines = ThrowableFormatter::toLines($exception, 'Ошибка миграции:');

        self::assertSame('Ошибка миграции:', $lines[0]);
        self::assertNotSame([], $this->linesContaining($lines, 'RuntimeException: boom'));
        self::assertNotSame([], $this->linesContaining($lines, '#0 '));
    }

    /**
     * Проверим, что по умолчанию toLines() не выводит аргументы вызовов.
     *
     * @see ThrowableFormatter::toLines()
     */
    #[Test]
    public function toLinesHidesArgsByDefault(): void
    {
        $exception = $this->makeException('secret-password');

        $lines = ThrowableFormatter::toLines($exception);

        self::assertSame([], $this->linesContaining($lines, 'secret-password'));
    }

    /**
     * Проверим, что toLines() выводит аргументы вызовов только при явном флаге.
     *
     * @see ThrowableFormatter::toLines()
     */
    #[Test]
    public function toLinesShowsArgsWhenRequested(): void
    {
        $exception = $this->makeException('secret');

        $lines = ThrowableFormatter::toLines($exception, withArgs: true);

        self::assertNotSame([], $this->linesContaining($lines, "throwRuntimeException('secret')"));
    }

    /**
     * Проверим, что toLines() включает previous-исключения с префиксом «Caused by:».
     *
     * @see ThrowableFormatter::toLines()
     */
    #[Test]
    public function toLinesIncludesPreviousExceptions(): void
    {
        $exception = new LogicException('outer', 0, $this->makeException('x'));

        $lines = ThrowableFormatter::toLines($exception);

        self::assertStringStartsWith('LogicException: outer in ', $lines[0]);
        self::assertNotSame([], $this->linesContaining($lines, 'Caused by: RuntimeException: boom in '));
    }

    /**
     * Проверим, что по умолчанию toTrace() не выводит аргументы вызовов.
     *
     * @see ThrowableFormatter::toTrace()
     */
    #[Test]
    public function toTraceHidesArgsByDefault(): void
    {
        $exception = $this->makeException('secret-password');

        $trace = ThrowableFormatter::toTrace($exception);

        self::assertStringContainsString('throwRuntimeException()', $trace);
        self::assertStringNotContainsString('secret-password', $trace);
        self::assertStringContainsString('{main}', $trace);
    }

    /**
     * Проверим, что toTrace() с флагом withArgs выводит аргументы, обрезая длинные строки.
     *
     * @see ThrowableFormatter::toTrace()
     */
    #[Test]
    public function toTraceShowsShortenedArgsWhenRequested(): void
    {
        $exception = $this->makeException('0123456789abcdefXYZ');

        $trace = ThrowableFormatter::toTrace($exception, true);

        self::assertStringContainsString("throwRuntimeException('0123456789abcde...')", $trace);
        self::assertStringNotContainsString('XYZ', $trace);
    }

    /**
     * Проверим, что toTrace() добавляет trace previous-исключения с заголовком «Caused by:».
     *
     * @see ThrowableFormatter::toTrace()
     */
    #[Test]
    public function toTraceIncludesPreviousExceptions(): void
    {
        $exception = new LogicException('outer', 0, $this->makeException('x'));

        $trace = ThrowableFormatter::toTrace($exception);

        self::assertStringContainsString('Caused by: RuntimeException: boom in ', $trace);
        self::assertStringContainsString('throwRuntimeException()', $trace);
    }

    /**
     * Проверим, что toLocation() возвращает файл и строку исключения.
     *
     * @see ThrowableFormatter::toLocation()
     */
    #[Test]
    public function toLocationReturnsFileAndLine(): void
    {
        $exception = $this->makeException('x');

        self::assertSame(
            $exception->getFile() . ':' . $exception->getLine(),
            ThrowableFormatter::toLocation($exception),
        );
    }

    /**
     * Проверим, что toFrames() возвращает frames без аргументов вызовов.
     *
     * @see ThrowableFormatter::toFrames()
     */
    #[Test]
    public function toFramesExcludesArgs(): void
    {
        $exception = $this->makeException('secret-password');

        $frames = ThrowableFormatter::toFrames($exception);

        self::assertNotSame([], $frames);
        self::assertSame('throwRuntimeException', $frames[0]['function']);
        self::assertArrayNotHasKey('args', $frames[0]);
        self::assertArrayNotHasKey('previous', $frames[0]);
    }

    /**
     * Проверим, что toFrames() добавляет frames previous-исключения с пометкой глубины и класса.
     *
     * @see ThrowableFormatter::toFrames()
     */
    #[Test]
    public function toFramesIncludesPreviousExceptions(): void
    {
        $previous  = $this->makeException('x');
        $exception = new LogicException('outer', 0, $previous);

        $frames = ThrowableFormatter::toFrames($exception);

        // Первый frame previous-исключения — место его создания.
        $marked = array_values(array_filter($frames, static fn (array $frame): bool => isset($frame['previous'])));
        self::assertNotSame([], $marked);
        self::assertSame([
            'file'      => $previous->getFile(),
            'line'      => $previous->getLine(),
            'previous'  => 1,
            'exception' => RuntimeException::class,
        ], $marked[0]);
    }

    /**
     * @param list<string> $lines
     *
     * @return array<int, string>
     */
    private function linesContaining(array $lines, string $needle): array
    {
        return array_filter($lines, static fn (string $line): bool => str_contains($line, $needle));
    }

    private function makeException(string $argument): RuntimeException
    {
        try {
            $this->throwRuntimeException($argument);
        } catch (RuntimeException $exception) {
            return $exception;
        }

        return new RuntimeException('unreachable');
    }

    private function throwRuntimeException(string $argument): void
    {
        // Аргумент нужен только для попадания в trace.
        throw new RuntimeException('boom');
    }
}
