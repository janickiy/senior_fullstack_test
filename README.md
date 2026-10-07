# Symfony API в Docker

Минимальный API на Symfony 8.1.8 и Doctrine ORM. Окружение включает PHP 8.4 FPM,
MySQL 8.4, Memcached 1.6 и Nginx. Версии PHP-пакетов закреплены в `composer.lock`.

## Первый запуск

Нужны установленный Docker с Compose v2 или новее и запущенный Docker Engine
(например, Docker Desktop). Все команды выполняются из корня проекта.

На macOS и Linux перед сборкой задайте владельца файлов, которые создаёт PHP:

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

