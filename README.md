# ErrorFormatter

`phpsoftbox/error-formatter` — общий компонент для единообразного форматирования `Throwable`
в CLI и HTTP-обработчиках, а также для отправки ошибок во внешние системы (ErrorHub и т.п.).

Основной класс — `PhpSoftBox\ErrorFormatter\ThrowableFormatter` (только статические методы).

## Установка

```bash
composer require phpsoftbox/error-formatter
```

## Безопасный режим по умолчанию

Stack-trace строится самим formatter, а не через `Throwable::__toString()` / `getTraceAsString()`:

- **аргументы вызовов по умолчанию не выводятся** — даже если в PHP выключен
  `zend.exception_ignore_args`. В аргументах часто оказываются пароли, токены, персональные данные
  и SQL-параметры, поэтому режим без аргументов безопасен для production-логов и ответов;
- аргументы включаются только явно флагом `withArgs: true` (например, в dev-окружении).
  Строки обрезаются до 15 символов, массивы выводятся как `Array`, объекты — как `Object(Class)`;
- **цепочка `getPrevious()` всегда включается**: после основного исключения идут previous-исключения
  в порядке вложенности с заголовком `Caused by: Class: message in file:line`.

Сообщения исключений (`getMessage()`) выводятся как есть: решение показывать ли их пользователю
принимает вызывающий код (например, `includeDetails` в обработчиках ошибок Application).

## API

### `toLines(Throwable $exception, ?string $headline = null, bool $withArgs = false): list<string>`

Построчное описание для вывода в CLI/лог: необязательный заголовок, затем для каждого исключения
цепочки — строка `Class: message in file:line`, `Stack trace:` и frames. Пустые строки пропускаются.

```php
foreach (ThrowableFormatter::toLines($exception, 'Ошибка миграции:') as $line) {
    $io->writeln($line);
}
```

Пример вывода:

```text
Ошибка миграции:
LogicException: outer in /app/src/Service.php:42
Stack trace:
#0 /app/src/Handler.php(15): App\Service->run()
#1 {main}
Caused by: RuntimeException: boom in /app/src/Repository.php:10
Stack trace:
#0 /app/src/Service.php(40): App\Repository->load()
#1 /app/src/Handler.php(15): App\Service->run()
#2 {main}
```

### `toTrace(Throwable $exception, bool $withArgs = false): string`

Stack-trace в формате, близком к `getTraceAsString()`, для всей цепочки исключений. Trace каждого
previous-исключения отделяется пустой строкой и заголовком `Caused by: ...`.

```php
$trace = ThrowableFormatter::toTrace($exception);                 // без аргументов
$trace = ThrowableFormatter::toTrace($exception, withArgs: true); // только для dev
```

### `toLocation(Throwable $exception): string`

Место выброса исключения: `file:line`.

### `toFrames(Throwable $exception): list<array<string, mixed>>`

Структурированные frames (`file`, `line`, `function`, `class`) — аргументы вызовов **никогда** не
включаются. После frames основного исключения идут frames previous-исключений: первым идёт frame с
местом создания previous-исключения (`file`, `line`), и все frames previous-исключения помечены ключами
`previous` (глубина в цепочке, начиная с `1`) и `exception` (класс исключения).

```php
[
    ['file' => '/app/src/Handler.php', 'line' => 15, 'function' => 'run', 'class' => 'App\Service'],
    ['file' => '/app/src/Repository.php', 'line' => 10, 'previous' => 1, 'exception' => 'RuntimeException'],
    ['file' => '/app/src/Service.php', 'line' => 40, 'function' => 'load', 'class' => 'App\Repository', 'previous' => 1, 'exception' => 'RuntimeException'],
]
```

## Ограничения

- Глубина цепочки previous-исключений ограничена 50 уровнями; циклические ссылки обрываются.
