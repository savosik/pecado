SHELL := /bin/bash

# Локальный dev-workflow для Pecado.
# Подробнее: docs/LOCAL_DEV.md

DC := docker compose
DB ?= all
S  ?=

.PHONY: help setup up down restart dev logs sh tinker bash-app db-pull restart-vite ps

help:
	@echo "Доступные команды:"
	@echo "  make setup        — первичная настройка (.env, /etc/hosts hint, docker up, key:generate)"
	@echo "  make up           — поднять весь стек (Docker + Vite + Caddy)"
	@echo "  make down         — остановить стек"
	@echo "  make restart      — перезапустить стек"
	@echo "  make dev          — поднять и привязаться к логам node/nginx/caddy (HMR live)"
	@echo "  make logs S=app   — tail логов сервиса (S=имя сервиса; пусто = все)"
	@echo "  make ps           — статус контейнеров"
	@echo "  make sh           — войти в pecado-app (bash)"
	@echo "  make tinker       — php artisan tinker"
	@echo "  make restart-vite — перезапустить контейнер node (если HMR завис)"
	@echo "  make db-pull DB=all|main|prices — скачать БД с dev-сервера и импортировать локально"

setup:
	bash scripts/setup-local-dev.sh

up:
	$(DC) up -d

down:
	$(DC) down

restart: down up

dev: up
	$(DC) logs -f --tail=50 node nginx caddy

ps:
	$(DC) ps

logs:
	$(DC) logs -f --tail=100 $(S)

sh:
	$(DC) exec app bash

bash-app: sh

tinker:
	$(DC) exec app php artisan tinker

restart-vite:
	$(DC) restart node

db-pull:
	bash scripts/db-pull.sh "$(DB)"
