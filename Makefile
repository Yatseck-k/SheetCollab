SHELL := /bin/bash

SAIL := bash vendor/laravel/sail/bin/sail
USER_ID := $(shell id -u)
GROUP_ID := $(shell id -g)

.PHONY: help env up down restart build ps logs shell root-shell artisan test lint analyse refactor check pint composer npm-install npm-build npm-dev migrate fresh seed stop setup

help:
	@printf "Доступные команды:\n"
	@printf "  make env         Создать .env из .env.example, если файла еще нет\n"
	@printf "  make up          Запустить стек Laravel Sail в фоне\n"
	@printf "  make down        Остановить стек Sail\n"
	@printf "  make restart     Перезапустить стек Sail\n"
	@printf "  make build       Собрать или пересобрать образы Sail\n"
	@printf "  make ps          Показать состояние контейнеров\n"
	@printf "  make logs        Показать и продолжать вывод логов контейнеров\n"
	@printf "  make shell       Открыть shell внутри контейнера приложения\n"
	@printf "  make root-shell  Открыть root-shell внутри контейнера приложения\n"
	@printf "  make artisan CMD='route:list'\n"
	@printf "  make composer CMD='install'\n"
	@printf "  make npm-install Установить Node-зависимости внутри контейнера\n"
	@printf "  make test        Запустить тесты через Sail\n"
	@printf "  make lint        Проверить форматирование через Pint\n"
	@printf "  make analyse     Запустить статический анализ через PHPStan / Larastan\n"
	@printf "  make check       Запустить lint, анализ, тесты и frontend build\n"
	@printf "  make pint        Запустить Laravel Pint\n"
	@printf "  make npm-build   Собрать frontend assets\n"
	@printf "  make npm-dev     Запустить Vite dev server внутри контейнера\n"
	@printf "  make migrate     Выполнить миграции базы данных\n"
	@printf "  make seed        Запустить DatabaseSeeder (создает admin@example.test)\n"
	@printf "  make fresh       Пересоздать базу через migrate:fresh\n"
	@printf "  make refactor    Запустить Rector (автоматический рефакторинг)\n"
	@printf "  make setup       Первый запуск: migrate + seed + npm-install + npm-build\n"

env:
	@if [ ! -f ".env" ]; then cp ".env.example" ".env"; fi

up: env
	WWWUSER=$(USER_ID) WWWGROUP=$(GROUP_ID) $(SAIL) up -d

down:
	WWWUSER=$(USER_ID) WWWGROUP=$(GROUP_ID) $(SAIL) down

stop: down

restart: down up

build:
	WWWUSER=$(USER_ID) WWWGROUP=$(GROUP_ID) $(SAIL) build --no-cache

ps:
	WWWUSER=$(USER_ID) WWWGROUP=$(GROUP_ID) $(SAIL) ps

logs:
	WWWUSER=$(USER_ID) WWWGROUP=$(GROUP_ID) $(SAIL) logs -f

shell:
	WWWUSER=$(USER_ID) WWWGROUP=$(GROUP_ID) $(SAIL) shell

root-shell:
	WWWUSER=$(USER_ID) WWWGROUP=$(GROUP_ID) $(SAIL) root-shell

artisan:
	@if [ -z "$(CMD)" ]; then echo "Использование: make artisan CMD='route:list'"; exit 1; fi
	WWWUSER=$(USER_ID) WWWGROUP=$(GROUP_ID) $(SAIL) artisan $(CMD)

composer:
	@if [ -z "$(CMD)" ]; then echo "Использование: make composer CMD='install'"; exit 1; fi
	WWWUSER=$(USER_ID) WWWGROUP=$(GROUP_ID) $(SAIL) composer $(CMD)

test:
	WWWUSER=$(USER_ID) WWWGROUP=$(GROUP_ID) $(SAIL) test

lint:
	WWWUSER=$(USER_ID) WWWGROUP=$(GROUP_ID) $(SAIL) composer lint

analyse:
	WWWUSER=$(USER_ID) WWWGROUP=$(GROUP_ID) $(SAIL) composer analyse

refactor:
	WWWUSER=$(USER_ID) WWWGROUP=$(GROUP_ID) $(SAIL) composer refactor

check: lint analyse test npm-build

pint:
	WWWUSER=$(USER_ID) WWWGROUP=$(GROUP_ID) $(SAIL) pint

npm-install:
	WWWUSER=$(USER_ID) WWWGROUP=$(GROUP_ID) $(SAIL) npm install --ignore-scripts

npm-build:
	WWWUSER=$(USER_ID) WWWGROUP=$(GROUP_ID) $(SAIL) npm run build

npm-dev:
	WWWUSER=$(USER_ID) WWWGROUP=$(GROUP_ID) $(SAIL) npm run dev

migrate:
	WWWUSER=$(USER_ID) WWWGROUP=$(GROUP_ID) $(SAIL) artisan migrate

fresh:
	WWWUSER=$(USER_ID) WWWGROUP=$(GROUP_ID) $(SAIL) artisan migrate:fresh

seed:
	WWWUSER=$(USER_ID) WWWGROUP=$(GROUP_ID) $(SAIL) artisan db:seed

setup: migrate seed npm-install npm-build
