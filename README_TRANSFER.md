# Инструкция по переносу проекта

Этот архив содержит исходный код проекта и конфигурацию для Docker.

## Инструкция по развертыванию

1.  **Распакуйте архив**:
    ```bash
    tar -xzvf pecado_project.tar.gz
    cd pecado
    ```

2.  **Запустите Docker окружение**:
    Убедитесь, что у вас установлены Docker и Docker Compose.
    ```bash
    docker compose up -d --build
    ```

3.  **Установите зависимости PHP**:
    ```bash
    docker compose exec app composer install
    ```

4.  **Установите зависимости Node.js и соберите ассеты**:
    ```bash
    docker compose run --rm node npm install
    docker compose run --rm node npm run build
    ```

5.  **База данных**:
    Если нужно запустить миграции:
    ```bash
    docker compose exec app php artisan migrate
    ```

Файл `.env` включен в архив, поэтому конфигурация должна работать сразу, при условии использования того же `docker-compose.yml`.
