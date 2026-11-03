# SheetCollab

**Интеграция Google Sheets и ActiveCollab — управление задачами из таблицы.**

[English](README.md) · [Руководство](docs/PROJECT_MANUAL.md) · [API](docs/API_REFERENCE.md) · [Развёртывание](docs/DEPLOY.md) · [Лицензия](LICENSE)

![PHP](https://img.shields.io/badge/PHP-8.5.12%2B-777BB4?logo=php&logoColor=white) ![Laravel](https://img.shields.io/badge/Laravel-13-FF2D20?logo=laravel&logoColor=white) ![Orchid](https://img.shields.io/badge/Admin-Orchid%2014-6366F1) ![License](https://img.shields.io/badge/License-MIT-blue)

## О проекте

**SheetCollab** связывает **Google Sheets ↔ ActiveCollab** через Google Apps Script и Laravel. Пользователь выбирает строку таблицы и запускает синхронизацию: приложение создаёт, обновляет или пропускает соответствующую задачу ActiveCollab. Каждая попытка фиксируется для проверки и восстановления.

Проект рассчитан на **несколько таблиц, интеграций и пользователей** с разграничением доступа и административным управлением.

## Возможности

| Раздел | Назначение |
| --- | --- |
| **Синхронизация задач** | Создание и обновление задач ActiveCollab по записям таблиц |
| **Несколько интеграций** | Настройка подключений и интеграций для разных таблиц |
| **Доступы** | Пользователи, роли, доступ к интеграциям и индивидуальные токены |
| **Администрирование** | Настройка подключений, экспортов и восстановления через Orchid |
| **Прозрачность операций** | История попыток синхронизации и административных действий |
| **Восстановление** | Обработка неопределённых результатов и конфликтующих привязок |
| **Развёртывание** | Docker Compose для локальной разработки и отдельная production-конфигурация |

## Принцип работы

```text
Google Sheets
    │  Выбрать строку → ActiveCollab → Sync task
    ▼
Google Apps Script
    │  Токен интеграции, HTTPS
    ▼
SheetCollab API (Laravel)
    │  Проверка → сервис → репозиторий
    ▼
Задачи ActiveCollab
```

Токен ActiveCollab хранится на сервере SheetCollab. Apps Script использует отдельный токен интеграции. Строки таблиц связаны с постоянными UUID — изменять их вручную нельзя.

**Безопасность:** неопределённый результат создания задачи требует сверки перед повторной попыткой. Автоматически повторять такое создание нельзя.

## Технологии

| Уровень | Технологии |
| --- | --- |
| **Backend** | PHP 8.5.12+, Laravel 13 |
| **Админ-панель** | Orchid 14 |
| **База данных** | MariaDB для локального окружения; SQLite для основных тестов |
| **Frontend-инструменты** | Node.js 26, Vite 8, Tailwind CSS 4 |
| **Интеграции** | Google Apps Script, ActiveCollab API |
| **Инфраструктура** | Docker Compose / Laravel Sail для разработки; отдельный production-образ |

Ограничения зависимостей указаны в `composer.json` и `package.json`, точные версии — в lock-файлах.

## Быстрый старт

Для локального запуска нужны Git, Docker Compose, PHP 8.5.12+ и Composer 2.

```bash
git clone https://github.com/Yatseck-k/SheetCollab.git
cd SheetCollab
composer install
cp .env.example .env
make up
make artisan CMD='key:generate'
make setup
```

Админ-панель: `http://localhost/admin` или адрес с настроенным `APP_PORT`. Учётные данные локального окружения нельзя использовать в production.

### Подключение Google Sheets

1. Создайте подключение ActiveCollab и интеграцию таблицы в Orchid.
2. Назначьте пользователей и выпустите индивидуальный токен интеграции.
3. Экспортируйте Apps Script из Orchid, укажите `INTEGRATION_SERVICE_URL` и `INTEGRATION_SERVICE_TOKEN` в Script Properties.
4. Выполните документированную миграцию UUID перед синхронизацией.
5. Выберите строку и нажмите **ActiveCollab → Sync task**.

Для работы Apps Script необходим доступный извне HTTPS-адрес — локальный `localhost` недоступен.

## Команды разработки

```bash
make up
make down
make test
make lint
make analyse
make check
make migrate
make artisan CMD='route:list'
make logs
```

`make check` запускает проверки проекта. Правила изоляции тестовых баз данных — в [руководстве разработчика](docs/DEVELOPMENT.md).

## Документация

- [Руководство пользователя и администратора (EN)](docs/PROJECT_MANUAL.md)
- [API (EN)](docs/API_REFERENCE.md)
- [Разработка и тестирование (EN)](docs/DEVELOPMENT.md)
- [Production, миграции и восстановление (EN)](docs/DEPLOY.md)

**Перед обновлением production необходимы резервные копии базы и контролируемая миграция.** Используйте инструкции по развёртыванию, а не локальную процедуру разработки.

## Лицензия

[MIT](LICENSE).
