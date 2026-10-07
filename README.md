# Магазин заказов: Symfony и Angular

## Требования

- Docker Engine и Docker Compose v2 (или Docker Desktop).
- Свободный порт `8081`. Другой порт можно задать через `HTTP_PORT`.
- PHP, Composer, Node.js и npm на компьютере не требуются: установка и сборка выполняются в Docker.

## Пакеты

- Symfony 8.1, Serializer, Validator — API.
- Doctrine ORM, DoctrineBundle, Doctrine Migrations — работа с базой и миграции.
- Angular 22, TypeScript 6, RxJS 7 — интерфейс магазина.
- PHP 8.4 FPM, MySQL 8.4, Memcached 1.6, Nginx — серверное окружение.
- Node.js 24 — сборка Angular; PHPUnit 13 и Vitest — тесты.

Версии зависимостей закреплены в `composer.lock` и `frontend/package-lock.json`.

## Установка

Выполните из корня проекта. На macOS и Linux сначала задайте владельца файлов:

Соберите образы, установите зависимости PHP, запустите сервисы и примените миграции:

```sh
docker compose build
docker compose run --rm --no-deps php composer install --no-interaction
docker compose up -d --wait
docker compose exec php bin/console doctrine:migrations:migrate --no-interaction
```

Откройте [http://localhost:8081](http://localhost:8081). Angular и API доступны через один Nginx; запросы `/api/` передаются в Symfony.

Данные MySQL сохраняются в `docker/mysql/data`. По умолчанию база, пользователь и пароль — `app`. Локальные настройки можно задать в `.env.local`.

После изменений Angular обновите интерфейс:

```sh
docker compose up -d --build --wait nginx
```

Остановка окружения:

```sh
docker compose down
```
