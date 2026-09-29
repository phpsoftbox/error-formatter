<?php

declare(strict_types=1);

namespace PhpSoftBox\ErrorFormatter;

use Throwable;

use function array_filter;
use function array_merge;
use function count;
use function explode;
use function get_debug_type;
use function get_resource_id;
use function implode;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_object;
use function is_resource;
use function is_string;
use function spl_object_id;
use function sprintf;
use function str_replace;
use function strlen;
use function substr;
use function trim;
use function var_export;

use const PHP_EOL;

/**
 * Единообразное форматирование Throwable для CLI, HTTP-обработчиков и отправки во внешние системы.
 *
 * По умолчанию аргументы вызовов в stack-trace не выводятся (безопасный режим для production):
 * в них могут оказаться пароли, токены и персональные данные. Аргументы включаются явно
 * флагом `$withArgs`. Цепочка `getPrevious()` всегда включается в вывод.
 */
final class ThrowableFormatter
{
    /**
     * Максимальная глубина цепочки previous-исключений.
     */
    private const int MAX_CHAIN_DEPTH = 50;

    /**
     * Максимальная длина строкового аргумента при выводе с аргументами.
     */
    private const int MAX_STRING_ARG_LENGTH = 15;

    /**
     * Возвращает построчное описание исключения: заголовок, сообщение, место, stack-trace
     * и такие же блоки для каждого previous-исключения (с префиксом «Caused by:»).
     *
     * @param bool $withArgs Выводить аргументы вызовов в stack-trace (по умолчанию — нет).
     *
     * @return list<string>
     */
    public static function toLines(Throwable $exception, ?string $headline = null, bool $withArgs = false): array
    {
        $lines = [];
        if ($headline !== null && trim($headline) !== '') {
            $lines[] = $headline;
        }

        foreach (self::chain($exception) as $index => $current) {
            $block = self::describe($current) . PHP_EOL
                . 'Stack trace:' . PHP_EOL
                . self::traceOf($current, $withArgs);

            if ($index > 0) {
                $block = 'Caused by: ' . $block;
            }

            foreach (explode(PHP_EOL, $block) as $line) {
                if ($line === '') {
                    continue;
                }
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * Возвращает stack-trace исключения и всех previous-исключений.
     *
     * Trace previous-исключения отделяется пустой строкой и заголовком
     * «Caused by: Class: message in file:line».
     *
     * @param bool $withArgs Выводить аргументы вызовов (по умолчанию — нет).
     */
    public static function toTrace(Throwable $exception, bool $withArgs = false): string
    {
        $blocks = [];
        foreach (self::chain($exception) as $index => $current) {
            $trace = self::traceOf($current, $withArgs);

            $blocks[] = $index === 0
                ? $trace
                : 'Caused by: ' . self::describe($current) . PHP_EOL . $trace;
        }

        return implode(PHP_EOL . PHP_EOL, $blocks);
    }

    public static function toLocation(Throwable $exception): string
    {
        return $exception->getFile() . ':' . $exception->getLine();
    }

    /**
     * Возвращает frames исключения без аргументов вызовов.
     *
     * После frames основного исключения идут frames previous-исключений: первый frame каждого
     * previous-исключения — место его создания; все его frames помечены ключами
     * `previous` (глубина в цепочке, начиная с 1) и `exception` (класс исключения).
     *
     * @return list<array<string, mixed>>
     */
    public static function toFrames(Throwable $exception): array
    {
        $frames = [];
        foreach (self::chain($exception) as $depth => $current) {
            $marker = $depth === 0 ? [] : ['previous' => $depth, 'exception' => $current::class];

            if ($depth > 0) {
                $frames[] = array_merge([
                    'file' => $current->getFile(),
                    'line' => $current->getLine(),
                ], $marker);
            }

            foreach ($current->getTrace() as $frame) {
                if (!is_array($frame)) {
                    continue;
                }

                $frames[] = array_merge(array_filter([
                    'file'     => $frame['file'] ?? null,
                    'line'     => $frame['line'] ?? null,
                    'function' => $frame['function'] ?? null,
                    'class'    => $frame['class'] ?? null,
                ], static fn (mixed $value): bool => $value !== null), $marker);
            }
        }

        return $frames;
    }

    /**
     * @return list<Throwable>
     */
    private static function chain(Throwable $exception): array
    {
        $chain   = [];
        $visited = [];
        $current = $exception;

        while ($current !== null && count($chain) < self::MAX_CHAIN_DEPTH) {
            $id = spl_object_id($current);
            if (isset($visited[$id])) {
                break;
            }

            $visited[$id] = true;
            $chain[]      = $current;
            $current      = $current->getPrevious();
        }

        return $chain;
    }

    private static function describe(Throwable $exception): string
    {
        $message = $exception->getMessage();

        return $exception::class
            . ($message !== '' ? ': ' . $message : '')
            . ' in ' . self::toLocation($exception);
    }

    private static function traceOf(Throwable $exception, bool $withArgs): string
    {
        $lines = [];
        $index = 0;

        foreach ($exception->getTrace() as $frame) {
            if (!is_array($frame)) {
                continue;
            }

            $location = isset($frame['file'])
                ? $frame['file'] . '(' . ($frame['line'] ?? '?') . ')'
                : '[internal function]';

            $call = ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? '');

            $args = '';
            if ($withArgs && isset($frame['args']) && is_array($frame['args'])) {
                $formatted = [];
                foreach ($frame['args'] as $arg) {
                    $formatted[] = self::formatArg($arg);
                }
                $args = implode(', ', $formatted);
            }

            $lines[] = sprintf('#%d %s: %s(%s)', $index, $location, $call, $args);
            $index++;
        }

        $lines[] = sprintf('#%d {main}', $index);

        return implode(PHP_EOL, $lines);
    }

    private static function formatArg(mixed $arg): string
    {
        return match (true) {
            $arg === null     => 'NULL',
            is_bool($arg)     => $arg ? 'true' : 'false',
            is_int($arg)      => (string) $arg,
            is_float($arg)    => var_export($arg, true),
            is_string($arg)   => self::formatStringArg($arg),
            is_array($arg)    => 'Array',
            is_object($arg)   => 'Object(' . $arg::class . ')',
            is_resource($arg) => 'Resource id #' . get_resource_id($arg),
            default           => get_debug_type($arg),
        };
    }

    private static function formatStringArg(string $arg): string
    {
        $short = strlen($arg) > self::MAX_STRING_ARG_LENGTH
            ? substr($arg, 0, self::MAX_STRING_ARG_LENGTH) . '...'
            : $arg;

        return "'" . str_replace(["\r", "\n"], ['\\r', '\\n'], $short) . "'";
    }
}
