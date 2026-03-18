<?php

declare(strict_types=1);

namespace PhpSoftBox\ErrorFormatter;

use Throwable;

use function array_filter;
use function explode;
use function is_array;
use function trim;

use const PHP_EOL;

final class ThrowableFormatter
{
    /**
     * @return list<string>
     */
    public static function toLines(Throwable $exception, ?string $headline = null): array
    {
        $lines = [];
        if ($headline !== null && trim($headline) !== '') {
            $lines[] = $headline;
        }

        foreach (explode(PHP_EOL, (string) $exception) as $line) {
            if ($line === '') {
                continue;
            }
            $lines[] = $line;
        }

        return $lines;
    }

    public static function toTrace(Throwable $exception): string
    {
        return $exception->getTraceAsString();
    }

    public static function toLocation(Throwable $exception): string
    {
        return $exception->getFile() . ':' . $exception->getLine();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function toFrames(Throwable $exception): array
    {
        $frames = [];
        foreach ($exception->getTrace() as $frame) {
            if (!is_array($frame)) {
                continue;
            }

            $frames[] = array_filter([
                'file'     => $frame['file'] ?? null,
                'line'     => $frame['line'] ?? null,
                'function' => $frame['function'] ?? null,
                'class'    => $frame['class'] ?? null,
            ], static fn (mixed $value): bool => $value !== null);
        }

        return $frames;
    }
}
