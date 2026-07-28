# Развертывание на сервере

## Требования

- Docker + Docker Compose plugin
- Git
- домен или tunnel до сервера
- reverse proxy перед Laravel-контейнером: Nginx, Caddy или Traefik

## 1. Клонирование

```bash
cd /opt
git clone <REPO_URL> sheetcollab
cd /opt/sheetcollab
```

## 2. Подготовка `vendor` перед первым запуском

`compose.yaml` собирает контейнер из `./vendor/laravel/sail/runtimes/8.5`, поэтому сначала нужно получить PHP-зависимости.

Если на сервере нет локального Composer, используйте контейнер:

```bash
docker run --rm \
  -u "$(id -u):$(id -g)" \
  -v /opt/sheetcollab:/app \
  -w /app \
  composer:2 \
  composer install --no-dev --optimize-autoloader
```

## 3. Production `.env`

```bash
cp .env.example .env
```

Заполнить минимум:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.example.com
APP_PORT=8080

DB_CONNECTION=mariadb
DB_HOST=mariadb
DB_PORT=3306
DB_DATABASE=sheetcollab
DB_USERNAME=sheetcollab
DB_PASSWORD=strong-password

SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database

ACTIVECOLLAB_BASE_URL=https://your-activecollab.example.com/api/v1
ACTIVECOLLAB_PROJECT_ID=10
ACTIVECOLLAB_API_TOKEN=...
GOOGLE_SHEETS_INTEGRATION_TOKEN=...
```

Рекомендуется не публиковать MariaDB наружу. Если нужны host ports, привязать к localhost:

```env
FORWARD_DB_PORT=127.0.0.1:3306
```

## 4. Первый запуск

```bash
docker compose up -d --build
docker compose exec laravel.test php artisan key:generate --force
docker compose exec laravel.test php artisan migrate --force
docker compose exec laravel.test npm install --ignore-scripts
docker compose exec laravel.test npm run build
docker compose exec laravel.test php artisan config:cache
docker compose exec laravel.test php artisan route:cache
docker compose exec laravel.test php artisan view:cache
```

Если нужен администратор Orchid:

```bash
docker compose exec laravel.test php artisan orchid:admin
```

## 5. Reverse proxy

Reverse proxy должен проксировать публичный HTTPS-домен на `127.0.0.1:${APP_PORT}`.

В `INTEGRATION_SERVICE_URL` для Google Apps Script нужно указывать **публичный HTTPS URL сервиса**, без `/admin` и без `/api/...`.

## 6. Smoke check после деплоя

```bash
docker compose ps
docker compose logs --tail=200
docker compose exec laravel.test php artisan about
curl -I http://127.0.0.1:${APP_PORT}
```

В браузере:

- `/admin/login` открывается
- можно войти в Orchid
- раздел `Apps Script` доступен
- раздел `Интеграции` доступен

## 7. Обновление уже развернутого сервиса

```bash
cd /opt/sheetcollab
git pull
docker run --rm \
  -u "$(id -u):$(id -g)" \
  -v /opt/sheetcollab:/app \
  -w /app \
  composer:2 \
  composer install --no-dev --optimize-autoloader
docker compose up -d --build
docker compose exec laravel.test php artisan migrate --force
docker compose exec laravel.test npm install --ignore-scripts
docker compose exec laravel.test npm run build
docker compose exec laravel.test php artisan config:cache
docker compose exec laravel.test php artisan route:cache
docker compose exec laravel.test php artisan view:cache
```

## 8. Что проверить, если не работает

- `APP_URL` совпадает с реальным публичным доменом
- `GOOGLE_SHEETS_INTEGRATION_TOKEN` задан и совпадает со значением в Apps Script
- `ACTIVECOLLAB_*` заполнены корректно
- migrations выполнены
- reverse proxy ведет на правильный `APP_PORT`
- контейнеры `laravel.test`, `mariadb` в статусе `healthy/running`

## 9. Не делать

- не оставлять `APP_DEBUG=true` на сервере
- не публиковать MariaDB в интернет
- не указывать `http://localhost` в `INTEGRATION_SERVICE_URL`
