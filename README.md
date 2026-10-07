# Symfony API в Docker

Минимальный API на Symfony 8.1.8 и Doctrine ORM. Окружение включает PHP 8.4 FPM,
MySQL 8.4, Memcached 1.6 и Nginx. Версии PHP-пакетов закреплены в `composer.lock`.

## Первый запуск

Нужны установленный Docker с Compose v2 или новее и запущенный Docker Engine
(например, Docker Desktop). Все команды выполняются из корня проекта.

На macOS и Linux перед сборкой задайте владельца файлов, которые создаёт PHP:

```sh
export LOCAL_UID="$(id -u)"
export LOCAL_GID="$(id -g)"
```

Затем соберите образ, установите зависимости и запустите сервисы:

```sh
docker compose build
docker compose run --rm --no-deps php composer install --no-interaction
docker compose up -d --wait
```

API доступен по адресу [http://localhost:8081](http://localhost:8081).
`GET /` возвращает JSON со статусом и версией Symfony. Это стартовая проверка
окружения; бизнес-методы API ещё не добавлены.

## Настройки

По умолчанию база называется `app`, пользователь — `app`, пароль — `app`.
PHP подключается к сервисам `mysql:3306` и `memcached:11211` внутри сети Docker.
MySQL и Memcached не публикуют порты на компьютере; Nginx доступен только локально.

Для другого HTTP-порта перед запуском задайте:

```sh
export HTTP_PORT=8082
docker compose up -d --wait
```

Параметры Docker можно задать переменными оболочки перед сборкой и запуском:
`MYSQL_DATABASE`, `MYSQL_USER`, `MYSQL_PASSWORD`, `MYSQL_ROOT_PASSWORD`,
`DATABASE_URL`, `MEMCACHED_DSN`, `APP_ENV` и `APP_DEBUG`.
Если меняете имя базы, пользователя или пароль, задайте и полный `DATABASE_URL`
с совпадающими значениями, например:

```sh
export MYSQL_DATABASE=project
export MYSQL_USER=project
export MYSQL_PASSWORD=project_password
export DATABASE_URL='mysql://project:project_password@mysql:3306/project?serverVersion=8.4.0&charset=utf8mb4'
```

Спецсимволы в имени пользователя и пароле URL должны быть закодированы.
`.env.local` предназначен для Symfony; Docker Compose не читает его для настройки
контейнеров. Переменные `MYSQL_*` создают базу и пользователя при первой
инициализации пустой папки данных; изменение переменных не меняет пароль уже
созданного пользователя.

## Данные и кеш

Файлы MySQL находятся непосредственно в `docker/mysql/data/` на компьютере:
эта папка подключена в контейнер как `/var/lib/mysql`. Данные исключены из Git,
кроме пустого файла `.gitkeep`.

Остановка сохраняет базу:

```sh
docker compose down
```

Для повторного запуска достаточно `docker compose up -d --wait`.
Не удаляйте `docker/mysql/data/`, если хотите сохранить данные.

Symfony использует Memcached для `cache.app`. Этот кеш временный и очищается
при перезапуске Memcached; постоянные данные храните в MySQL.
Системный кеш Symfony остаётся файловым.

## Команды и проверка

```sh
# Статус сервисов
docker compose ps

# Информация о Symfony
docker compose exec php php bin/console about

# Проверка соединения с MySQL
docker compose exec php php bin/console dbal:run-sql 'SELECT VERSION() AS version, DATABASE() AS database_name'

# Функциональные тесты
docker compose exec php php bin/phpunit

# Проверка конфигурации
docker compose exec php php bin/console lint:container
docker compose exec php php bin/console lint:yaml config/

# Логи
docker compose logs --tail=100 nginx php mysql memcached
```

Для следующих изменений схемы используйте миграции Doctrine; подготовленные
миграции применяются командой:

```sh
docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction
```

Исходный код подключён в контейнеры из текущей папки. Изменения PHP-файлов
доступны без пересборки; после изменения Dockerfile пересоберите образ.
