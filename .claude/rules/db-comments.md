# Комментарии в БД — обязательны для новых миграций

База данных полностью прокомментирована (таблицы и столбцы), чтобы ИИ-агент через
`SHOW FULL COLUMNS` / `information_schema` понимал назначение данных без чтения кода.
Это покрытие нужно поддерживать.

## Правило

При создании миграций **всегда** проставляй человекочитаемые комментарии на русском:

- **Новая таблица** — комментарий таблицы: `$table->comment('Назначение таблицы');`
- **Новый столбец** — комментарий столбца: `->comment('Что хранит поле');`
- Для FK-полей указывай ссылку: `->comment('Товар (products.id)')`.
- Для enum/строк-статусов перечисляй значения: `->comment("Тип: 'order' — обычный, 'preorder' — предзаказ")`.

```php
Schema::create('example', function (Blueprint $table) {
    $table->comment('Пример таблицы');
    $table->id()->comment('Первичный ключ');
    $table->foreignId('product_id')->comment('Товар (products.id)');
    $table->string('status')->comment("Статус: 'new' — новый, 'done' — завершён");
    $table->timestamps();
});
```

При изменении столбца через `->change()` **сохраняй** его `->comment(...)`, иначе комментарий сотрётся.
Генерируемым (GENERATED) столбцам комментарий не задаётся.

## Проверка

Страховочная сеть — команда аудита (только MySQL/MariaDB, в Docker):

```bash
docker exec pecado-app php artisan db:comments:audit          # отчёт о пробелах
docker exec pecado-app php artisan db:comments:audit --strict # ненулевой код для CI
```

Разовое сплошное покрытие сделано миграцией
`database/migrations/2026_07_07_120000_add_comments_to_database_schema.php`
(движок берёт точный DDL из `SHOW CREATE TABLE`). Её править не нужно —
новые комментарии добавляй прямо в новых миграциях через `->comment()`.
