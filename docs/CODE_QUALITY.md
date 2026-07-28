# Качество кода

Проект использует три инструмента контроля качества, которые не конфликтуют друг с другом:

| Инструмент | Роль | Изменяет файлы |
|-----------|------|---------------|
| **Rector** | Автоматический рефакторинг (структура, типы) | Да |
| **Pint** | Форматирование кода (стиль) | Да |
| **PHPStan** | Статический анализ (типы, ошибки) | Нет |

**Правильный порядок запуска:**

```
Rector → Pint → PHPStan
```

Rector может нарушить форматирование → Pint исправляет. PHPStan проверяет итоговое состояние.

---

## Laravel Pint

**Пакет:** `laravel/pint ^1.27`
**Конфиг:** `pint.json`
**Основа:** php-cs-fixer с Laravel preset

### Команды

```bash
# Только проверка (без изменений) — используется в CI
make lint
composer lint
php vendor/bin/pint --test

# Автоматическое исправление
make pint
php vendor/bin/pint

# Один файл
php vendor/bin/pint app/Services/ActiveCollab/ActiveCollabTaskService.php

# Verbose: показывает каждое изменение
php vendor/bin/pint --test --verbose
```

### Конфигурация (pint.json)

```json
{
    "preset": "laravel",
    "rules": {
        "declare_strict_types": true,
        "no_unused_imports": true,
        "ordered_imports": {
            "sort_algorithm": "alpha",
            "imports_order": ["class", "function", "const"]
        },
        "global_namespace_import": {
            "import_classes": false,
            "import_constants": false,
            "import_functions": false
        },
        "phpdoc_to_comment": false,
        "single_line_empty_body": true,
        "new_with_parentheses": {
            "named_class": true,
            "anonymous_class": false
        }
    }
}
```

### Что делает каждое правило

| Правило | Действие |
|---------|---------|
| `preset: laravel` | PSR-12 + Laravel-специфичные соглашения |
| `declare_strict_types` | Добавляет `declare(strict_types=1);` в начало каждого PHP-файла |
| `no_unused_imports` | Удаляет неиспользуемые `use` операторы |
| `ordered_imports` | Сортирует `use` операторы: классы → функции → константы, по алфавиту |
| `global_namespace_import` | Запрещает `use function strlen; use const PHP_EOL;` в пользу FQDN |
| `phpdoc_to_comment: false` | Не конвертирует PHPDoc `/** */` в обычные комментарии `/* */` |
| `single_line_empty_body` | Пустые тела методов в одну строку: `{}` |
| `new_with_parentheses` | Требует скобки при `new ClassName()` для именованных классов |

### Область применения

Pint по умолчанию форматирует всё в `app/`, `tests/`, `config/`, `database/`, `routes/`.
Vendor-директория исключена автоматически.

---

## Larastan / PHPStan

**Пакет:** `larastan/larastan ^3.0` (обёртка над `phpstan/phpstan`)
**Конфиг:** `phpstan.neon.dist`
**Уровень:** 6 (из 10)

### Команды

```bash
# Анализ (только чтение — не изменяет файлы)
make analyse
composer analyse
php vendor/bin/phpstan analyse --memory-limit=1G

# С явным конфигом
php vendor/bin/phpstan analyse -c phpstan.neon.dist --memory-limit=1G

# Один файл/директория
php vendor/bin/phpstan analyse app/Services/ActiveCollab/ --memory-limit=1G

# Сбросить кеш анализа
php vendor/bin/phpstan clear-result-cache
```

### Конфигурация (phpstan.neon.dist)

```yaml
includes:
    - vendor/larastan/larastan/extension.neon   # Laravel-специфичные расширения

parameters:
    paths:
        - app
        - routes
        - tests

    level: 6

    tmpDir: storage/phpstan                      # кеш результатов

    treatPhpDocTypesAsCertain: false             # не считать PHPDoc-типы абсолютной истиной

    excludePaths:
        - app/Orchid/Screens/Examples/*          # примеры Orchid — не наш код
        - app/Orchid/Layouts/Examples/*
```

### Что проверяет уровень 6

PHPStan уровень 6 включает все проверки уровней 0–5 и добавляет:

- Проверку типов возвращаемых значений на соответствие PHPDoc `@return`
- Проверку типов параметров на соответствие PHPDoc `@param`
- Анализ `mixed` типов — требует явного сужения перед использованием
- Проверку generic-типов (`Collection<K, V>`, `Builder<Model>`, `BelongsTo<TRelated, TDeclaring>`)
- Запрет вызова методов на потенциально `null` объектах без проверки

### Что делает расширение Larastan

Larastan добавляет понимание Laravel-специфики:

- Возвращаемые типы Eloquent (`Model::find()`, `Model::where()`, `Builder`)
- Маги Eloquent (`$model->attribute`)
- `config()`, `app()`, `route()` и другие хелперы
- Коллекции (`Collection<TKey, TValue>`)
- Правило `noModelMake`: запрещает `Model::make()`, рекомендует `new Model()`

### Требования к PHPDoc в проекте

Из-за уровня 6, все публичные методы, работающие с массивами, коллекциями или generics, должны иметь аннотации:

```php
/** @return array<string, mixed> */
public function toArray(): array { ... }

/** @param array<string, mixed> $data */
public static function fromRequest(array $data): self { ... }

/** @return iterable<string, mixed> */
public function query(): iterable { ... }

/** @return BelongsTo<ActiveCollabIntegration, $this> */
public function integration(): BelongsTo { ... }

/** @return Builder<User> */
public function searchQuery(?string $query = null): Builder { ... }
```

**Важно для `BelongsTo`:** используется `$this`, а не `self` — PHPStan не поддерживает ковариантность `TDeclaringModel`.

### Типичные ошибки и решения

| Ошибка | Решение |
|--------|---------|
| `Parameter $data of method X expects array<string, mixed>, array<mixed, mixed> given` | Добавить `/** @param array<string, mixed> $data */` |
| `Method X should return BelongsTo<A, B> but returns BelongsTo<A, $this(C)>` | Использовать `$this` в PHPDoc: `@return BelongsTo<A, $this>` |
| `Cannot call method X on mixed` | Явно проверить тип или добавить assert |
| `Call to static method Model::make() is not allowed` | Заменить на `new Model([...])` |
| `Anonymous function never returns null so it can be removed from the return type` | Убрать `|null` из return type closure |
| `Template type TKey is not specified in method collect()` | Привести к явному типу: `collect((array) $value)` |

---

## Rector

**Пакет:** `rector/rector ^2.5`
**Конфиг:** `rector.php`

### Команды

```bash
# Применить рефакторинг (изменяет файлы)
make refactor
composer refactor
php vendor/bin/rector process

# Только просмотр — что изменится (dry run, без изменений)
composer refactor:dry
php vendor/bin/rector process --dry-run

# Один файл
php vendor/bin/rector process app/Services/ActiveCollab/ActiveCollabTaskService.php

# Конкретный файл, dry run
php vendor/bin/rector process app/Services/ --dry-run
```

### Конфигурация (rector.php)

```php
return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/app',
        __DIR__.'/tests',
    ])
    ->withSkip([
        __DIR__.'/app/Orchid/Screens/Examples',
        __DIR__.'/app/Orchid/Layouts/Examples',
    ])
    ->withSets([
        SetList::DEAD_CODE,        // удаляет мёртвый код
        SetList::CODE_QUALITY,     // улучшения качества
        SetList::TYPE_DECLARATION, // добавляет явные типы
        LevelSetList::UP_TO_PHP_84,// современный PHP синтаксис
    ])
    ->withImportNames(importShortClasses: false)  // не укорачивает namespace в use
    ->withPhpVersion(PhpVersion::PHP_84);
```

**Важно:** `SetList::CODING_STYLE` намеренно не включён — он дублирует работу Pint и может конфликтовать с его правилами форматирования.

### Что делает каждый набор правил

**`SetList::DEAD_CODE`:**
- Удаляет неиспользуемые параметры, переменные, условия
- Удаляет код, который никогда не выполняется
- Удаляет лишние `return` операторы

**`SetList::CODE_QUALITY`:**
- `ExplicitBoolCompareRector`: `if ($string)` → `if ($string !== '' && $string !== '0')`
- Улучшение условий, упрощение логики
- Инвертирование условий для снижения вложенности

**`SetList::TYPE_DECLARATION`:**
- Добавляет return types там, где их нет
- Добавляет типы параметров
- Добавляет `#[\Override]` к переопределённым методам

**`LevelSetList::UP_TO_PHP_84`:**
- Современный синтаксис: `match`, `readonly`, `enum`, `fibers`
- Именованные аргументы где применимо
- Null-safe оператор `?->`
- Intersection types

### Рефакторинги, применённые в проекте

| Правило | До | После |
|---------|-----|------|
| `ExplicitBoolCompareRector` | `if ($data->environment)` | `if ($data->environment !== '' && $data->environment !== '0')` |
| `#[\Override]` | `public function query()` | `#[\Override] public function query()` |
| Return type inference | `fn() => __('Regular User')` | `fn(): string\|array => __('Regular User')` |

---

## Интеграция в CI через make check

Команда `make check` (или `composer check`) запускает полную цепочку проверок:

```bash
make check
# = composer lint + composer analyse + composer test + npm run build
```

Отдельные шаги:

```bash
make lint      # pint --test (не изменяет файлы, только проверяет)
make analyse   # phpstan analyse --memory-limit=1G
make test      # php artisan test
make npm-build # npm run build (проверяет сборку Vite)
```

**Порядок выполнения имеет значение:**

```
make lint     → должен пройти до analyse (форматирование)
make analyse  → последняя проверка (типы и ошибки)
make test     → после analyse (тесты верифицируют логику)
```

### Workflow разработки

При внесении изменений в код:

```bash
# 1. Авторефакторинг структурных изменений
composer refactor

# 2. Исправление форматирования после Rector
composer pint   # или: php vendor/bin/pint (без --test)

# 3. Проверка типов
composer analyse

# 4. Запуск тестов
composer test

# 5. Полная проверка перед коммитом
make check
```

### Текущее состояние

Все три инструмента проходят без ошибок:

```
Pint:    95 files checked — no formatting issues
PHPStan: 0 errors (level 6)
Tests:   92 passed, 236 assertions
```
