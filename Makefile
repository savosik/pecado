SHELL := /bin/bash

# Локальный dev-workflow для Pecado.
# Подробнее: docs/LOCAL_DEV.md

DC := docker compose
DB ?= all
S  ?=

# Сколько процессов отдавать параллельным тестам и сборке.
# Шесть из двенадцати потоков: остальное остаётся рабочему столу, машина не
# уходит в троттлинг (Ryzen 4600H держит ~82 °C уже в простое).
JOBS ?= 6

# Обёртка для тяжёлых команд: на время прогона приглушает фоновые контейнеры,
# чтобы процессорное время досталось тому, кто реально работает.
#
# ВАЖНО: systemd-run вокруг `docker exec` бесполезен — он ограничил бы только
# docker-клиент, а работа идёт внутри контейнера, в своей cgroup под dockerd.
# Ограничить контейнер без root можно лишь через `docker update`, что и делает
# with-cap.sh. Общий потолок на ВСЕ контейнеры — docker.slice из local-tune.sh.
CAP := bash scripts/with-cap.sh

# Полному test suite не хватает 512M из docker/php/uploads.ini: примерно на
# 2150-м тесте из 3026 процесс падает с Fatal error. Поднять лимит в самом
# uploads.ini нельзя — файл монтируется и на dev, и на prod.
#
# Поэтому цели ниже зовут phpunit НАПРЯМУЮ: `artisan test` форкает дочерний
# процесс, и переданный ему `-d memory_limit` до phpunit не долетает.
PHP_TEST_MEM ?= 2G
PHP_TEST     := php -d memory_limit=$(PHP_TEST_MEM)

.PHONY: help setup up down restart dev logs sh tinker bash-app db-pull db-pull-prod \
        restart-vite ps verify test test-parallel lint build audit clean focus unfocus release

help:
	@echo "Стек:"
	@echo "  make setup        — первичная настройка (.env, docker up, key:generate)"
	@echo "  make up           — поднять весь стек (Docker + Vite)"
	@echo "  make down         — остановить стек"
	@echo "  make restart      — перезапустить стек"
	@echo "  make dev          — поднять и привязаться к логам node/nginx (HMR live)"
	@echo "  make logs S=app   — tail логов сервиса (S=имя сервиса; пусто = все)"
	@echo "  make ps           — статус контейнеров"
	@echo "  make sh           — войти в pecado-app (bash)"
	@echo "  make tinker       — php artisan tinker"
	@echo "  make restart-vite — перезапустить контейнер node (если HMR завис)"
	@echo ""
	@echo "Проверки (потолок $(JOBS) из 12 потоков — машина не уходит в троттлинг):"
	@echo "  make verify       — ПОЛНАЯ проверка перед релизом: lint + тесты + сборка"
	@echo "  make test         — весь test suite последовательно"
	@echo "  make test-parallel— весь test suite параллельно ($(JOBS) процессов)"
	@echo "  make lint         — Pint по изменённым + ESLint"
	@echo "  make build        — фронтенд-сборка Vite"
	@echo ""
	@echo "Ресурсы машины:"
	@echo "  make audit        — что зря занимает место и память в Docker"
	@echo "  make clean        — вычистить безопасный docker-мусор"
	@echo "  make focus        — погасить стеки других проектов"
	@echo "  make unfocus      — вернуть их обратно"
	@echo ""
	@echo "Данные и релиз:"
	@echo "  make db-pull DB=all|main|prices — скачать БД с dev-сервера"
	@echo "  make db-pull-prod               — скачать БОЕВУЮ БД (реальные перс. данные)"
	@echo "  make release                    — verify + push в dev + push в main (прод)"

# ─────────────────────────────────────────────────────────────
# Стек
# ─────────────────────────────────────────────────────────────
setup:
	bash scripts/setup-local-dev.sh

up:
	$(DC) up -d

down:
	$(DC) down

restart: down up

dev: up
	$(DC) logs -f --tail=50 node nginx

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

# ─────────────────────────────────────────────────────────────
# Проверки
# ─────────────────────────────────────────────────────────────
# Полная верификация перед релизом. Прод-CI гоняет тесты БЕЗ fast-lane, а
# упавший прод-деплой оставляет pecado.ru под maintenance до ручного
# `artisan up` — поэтому перед push в main это обязательный шаг.
verify:
	@echo "▶ Проверка перед релизом (потолок $(JOBS) потоков)"
	@start=$$(date +%s); \
	$(MAKE) --no-print-directory lint  && \
	$(MAKE) --no-print-directory test  && \
	$(MAKE) --no-print-directory build \
	|| { echo ""; echo "✗ Проверка НЕ прошла — смотри вывод выше"; exit 1; }; \
	end=$$(date +%s); \
	echo ""; \
	echo "✓ Всё прошло за $$(( (end-start)/60 ))м $$(( (end-start)%60 ))с"; \
	sensors 2>/dev/null | grep Tctl | sed 's/^/  температура после прогона: /' || true

lint:
	@echo "▶ Pint (изменённые файлы)"
	$(DC) exec -T app vendor/bin/pint --dirty
	@echo "▶ ESLint"
	$(DC) exec -T node npm run lint:js

test:
	@echo "▶ Тесты (последовательно, memory_limit=$(PHP_TEST_MEM))"
	$(CAP) $(DC) exec -T app $(PHP_TEST) vendor/bin/phpunit

# ⚠️ Параллельный прогон конфликтует с тестами, которые мигрируют реальную
# MySQL `pecado_prices`: процессы одновременно делают migrate:fresh на одной
# общей БД. Пока это не изолировано, `make verify` использует `make test`.
# Подробности: docs/LOCAL_DEV.md.
test-parallel:
	@echo "▶ Тесты (параллельно, $(JOBS) процессов)"
	@echo "  ⚠ часть тестов упадёт: prices-БД общая для всех процессов"
	$(CAP) $(DC) exec -T app $(PHP_TEST) vendor/bin/paratest --processes=$(JOBS)

build:
	@echo "▶ Сборка фронтенда"
	$(CAP) $(DC) exec -T node npm run build

# ─────────────────────────────────────────────────────────────
# Ресурсы машины
# ─────────────────────────────────────────────────────────────
audit:
	@bash scripts/docker-audit.sh

clean:
	@bash scripts/docker-audit.sh --clean

focus:
	@bash scripts/focus.sh pecado

unfocus:
	@bash scripts/focus.sh --restore

# ─────────────────────────────────────────────────────────────
# Данные и релиз
# ─────────────────────────────────────────────────────────────
db-pull:
	bash scripts/db-pull.sh "$(DB)"

db-pull-prod:
	bash scripts/db-pull.sh "$(DB)" --from=prod

release:
	@bash scripts/release.sh
