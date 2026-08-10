<?php

namespace App\Services\Crm;

use App\Enums\Crm\BusinessType;
use App\Enums\Crm\ClientLifecycleStatus;
use App\Support\Crm\SalesSheet;
use App\Support\Crm\SalesSheetRow;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;

/**
 * Разбор управленческой таблицы продаж (XLSX) в строки партнёров.
 *
 * Колонки ищутся по подписям, а не по буквам: в таблице между месяцами уже стоят
 * «факт» и «% выполнения», и любая вставленная колонка сдвинула бы жёсткие
 * индексы, молча перекосив планы на месяц вбок. Подпись переживает вставку.
 */
class SalesSheetReader
{
    /** Строки, в которых ищем подписи колонок: часть подписей живёт во второй. */
    private const HEADER_ROWS = 2;

    /** Сокращения месяцев по первым трём буквам — так их пишут в таблице. */
    private const MONTHS = [
        'янв' => 1, 'фев' => 2, 'мар' => 3, 'апр' => 4, 'май' => 5, 'июн' => 6,
        'июл' => 7, 'авг' => 8, 'сен' => 9, 'окт' => 10, 'ноя' => 11, 'дек' => 12,
    ];

    private const STATUSES = [
        'активный' => ClientLifecycleStatus::ACTIVE,
        'спящий' => ClientLifecycleStatus::SLEEPING,
        'лид' => ClientLifecycleStatus::LEAD,
        'в работе' => ClientLifecycleStatus::IN_WORK,
        'закрывается' => ClientLifecycleStatus::CLOSING,
        'закрылся' => ClientLifecycleStatus::CHURNED,
        'непреодолимо' => ClientLifecycleStatus::HOPELESS,
    ];

    private const BUSINESS_TYPES = [
        'офлайн' => BusinessType::OFFLINE,
        'оффлайн' => BusinessType::OFFLINE,
        'онлайн' => BusinessType::ONLINE,
        'селлер' => BusinessType::SELLER,
        'массмаркет' => BusinessType::MASS_MARKET,
        'масс маркет' => BusinessType::MASS_MARKET,
        'сеть' => BusinessType::CHAIN,
        'опт' => BusinessType::WHOLESALE,
    ];

    /** Подытоги: партнёрами не являются и в базе их искать незачем. */
    private const TOTAL_ROWS = ['/^итого/u', '/^всего/u'];

    // Ниже — тексты чужой Google-таблицы: заголовки колонок и служебные строки
    // отдела продаж. В интерфейсе покупатель называется партнёром, но здесь
    // сравнение идёт с тем, что реально написано в файле, и переименование
    // сломало бы импорт.

    /** «Корзина» плана на ещё не привлечённых партнёров — уходит в план менеджера. */
    private const NEW_CLIENTS_ROW = '/^новые клиенты/u';

    /** Значения, которыми в таблице отмечают «нет» — всё прочее непустое читается как «да». */
    private const NEGATIVE = ['нет', 'no', '-', '—', '0', 'false'];

    /**
     * Предупреждения последнего разбора: колонки, которые не удалось опознать.
     *
     * @var list<string>
     */
    private array $warnings = [];

    public function read(string $path, string $sheetName): SalesSheet
    {
        if (! is_readable($path)) {
            throw new RuntimeException("Файл выгрузки не найден или недоступен для чтения: {$path}");
        }

        $this->warnings = [];

        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $reader->setLoadSheetsOnly([$sheetName]);

        $sheet = $reader->load($path)->getSheetByName($sheetName);

        if ($sheet === null) {
            throw new RuntimeException("В файле нет листа «{$sheetName}».");
        }

        $columns = $this->locateColumns($sheet);
        $rows = [];

        $lastRow = $sheet->getHighestDataRow();

        for ($line = self::HEADER_ROWS + 1; $line <= $lastRow; $line++) {
            $row = $this->readRow($sheet, $line, $columns);

            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return new SalesSheet(
            rows: $rows,
            // Итог отдела стоит в последней строке шапки — там же, где подписи
            // колонок: в таблице это одна и та же строка.
            departmentPlans: $this->plans($sheet, self::HEADER_ROWS, $columns['plans']),
            warnings: $this->warnings,
        );
    }

    /**
     * Найти колонки по подписям в шапке.
     *
     * @return array{status: ?int, business_type: ?int, offline: ?int, online: ?int, marketplace: ?int, points: ?int, manager: ?int, plans: array<string, int>}
     */
    private function locateColumns(Worksheet $sheet): array
    {
        /** @var array<string, int|null> $fields */
        $fields = [
            'status' => null,
            'business_type' => null,
            'offline' => null,
            'online' => null,
            'marketplace' => null,
            'points' => null,
            'manager' => null,
        ];

        /** @var array<string, int> $plans */
        $plans = [];

        $lastColumn = $sheet->getHighestDataColumn();
        $lastColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($lastColumn);

        for ($column = 1; $column <= $lastColumnIndex; $column++) {
            for ($headerRow = 1; $headerRow <= self::HEADER_ROWS; $headerRow++) {
                $title = $this->normalize($sheet->getCell([$column, $headerRow])->getValue());

                if ($title === '') {
                    continue;
                }

                $month = $this->planMonth($title);

                if ($month !== null) {
                    $plans[$month] = $column;

                    continue;
                }

                $key = match (true) {
                    str_contains($title, 'статус клиента') => 'status',
                    (bool) preg_match('/^ти[кп] клиента/u', $title) => 'business_type',
                    str_contains($title, 'офлайн-точки') || str_contains($title, 'офлайн точки') => 'offline',
                    str_contains($title, 'интернет-магазин') && str_starts_with($title, 'есть') => 'online',
                    str_contains($title, 'маркетплейс') => 'marketplace',
                    str_contains($title, 'количество магазин') => 'points',
                    $title === 'менеджер' => 'manager',
                    default => null,
                };

                // Первая подпись побеждает: ниже по шапке те же слова встречаются
                // в служебных колонках («Сайт интернет-магазина»).
                if ($key !== null && $fields[$key] === null) {
                    $fields[$key] = $column;
                }
            }
        }

        foreach (['status' => 'Статус клиента', 'business_type' => 'Тип клиента', 'points' => 'Количество магазинов'] as $key => $label) {
            if ($fields[$key] === null) {
                $this->warnings[] = "В шапке не найдена колонка «{$label}» — это поле импортировано не будет.";
            }
        }

        if ($plans === []) {
            throw new RuntimeException('В шапке не найдено ни одной колонки «корректировка» — проверьте лист и формат выгрузки.');
        }

        return [
            'status' => $fields['status'],
            'business_type' => $fields['business_type'],
            'offline' => $fields['offline'],
            'online' => $fields['online'],
            'marketplace' => $fields['marketplace'],
            'points' => $fields['points'],
            'manager' => $fields['manager'],
            'plans' => $plans,
        ];
    }

    /**
     * Месяц плана из подписи колонки: «фев.26 корректировка» → «2026-02».
     *
     * Требование месяца в начале подписи отсекает итоговую колонку
     * «Итого корректировка план 2026», которая планом месяца не является.
     */
    private function planMonth(string $title): ?string
    {
        if (! preg_match('/^([а-яё]+)\.?\s*(\d{2})\s+корректировк/u', $title, $matches)) {
            return null;
        }

        $month = self::MONTHS[mb_substr($matches[1], 0, 3)] ?? null;

        if ($month === null) {
            $this->warnings[] = "Не удалось определить месяц колонки «{$title}» — план по ней пропущен.";

            return null;
        }

        return sprintf('%d-%02d', 2000 + (int) $matches[2], $month);
    }

    /**
     * @param  array{status: ?int, business_type: ?int, offline: ?int, online: ?int, marketplace: ?int, points: ?int, manager: ?int, plans: array<string, int>}  $columns
     */
    private function readRow(Worksheet $sheet, int $line, array $columns): ?SalesSheetRow
    {
        $name = trim((string) $this->value($sheet, 1, $line));

        if ($name === '') {
            return null;
        }

        $normalizedName = $this->normalize($name);

        foreach (self::TOTAL_ROWS as $pattern) {
            if (preg_match($pattern, $normalizedName)) {
                return null;
            }
        }

        $manager = trim((string) $this->cell($sheet, $columns['manager'], $line));
        $plans = $this->plans($sheet, $line, $columns['plans']);

        if (preg_match(self::NEW_CLIENTS_ROW, $normalizedName)) {
            return new SalesSheetRow(
                line: $line,
                name: $name,
                kind: SalesSheetRow::KIND_NEW_CLIENTS,
                manager: $manager === '' ? null : $manager,
                plans: $plans,
            );
        }

        return new SalesSheetRow(
            line: $line,
            name: $name,
            manager: $manager === '' ? null : $manager,
            status: $this->status($this->cell($sheet, $columns['status'], $line)),
            businessType: $this->businessType($this->cell($sheet, $columns['business_type'], $line)),
            hasOfflinePoints: $this->flag($this->cell($sheet, $columns['offline'], $line)),
            hasOnlineStore: $this->flag($this->cell($sheet, $columns['online'], $line)),
            worksWithMarketplaces: $this->flag($this->cell($sheet, $columns['marketplace'], $line)),
            pointsCount: $this->points($this->cell($sheet, $columns['points'], $line)),
            plans: $plans,
        );
    }

    /**
     * Планы строки по месяцам.
     *
     * @param  array<string, int>  $planColumns
     * @return array<string, float>
     */
    private function plans(Worksheet $sheet, int $line, array $planColumns): array
    {
        $plans = [];

        foreach ($planColumns as $month => $column) {
            $amount = $this->value($sheet, $column, $line);

            // Ноль — это не «в этом месяце не продаём», а незаполненная формула
            // у партнёров, с которыми уже не работают. Планом он не становится.
            if (is_numeric($amount) && (float) $amount > 0) {
                $plans[$month] = round((float) $amount, 2);
            }
        }

        return $plans;
    }

    private function cell(Worksheet $sheet, ?int $column, int $line): mixed
    {
        return $column === null ? null : $this->value($sheet, $column, $line);
    }

    /**
     * Значение ячейки, а не текст формулы.
     *
     * Больше половины планов в таблице — формулы вида `=AS3*$AD$241`. Пересчитывать
     * их нельзя: часть ссылается на соседние книги и даёт `#REF!`, а нужное число
     * Excel уже сохранил рядом с формулой. Поэтому у формул берётся именно
     * закэшированное значение, и лишь при его отсутствии формула считается.
     */
    private function value(Worksheet $sheet, int $column, int $line): mixed
    {
        $cell = $sheet->getCell([$column, $line]);

        if (! $cell->isFormula()) {
            return $cell->getValue();
        }

        $cached = $cell->getOldCalculatedValue();

        if ($cached !== null) {
            return $cached;
        }

        try {
            return $cell->getCalculatedValue();
        } catch (\Throwable) {
            return null;
        }
    }

    private function status(mixed $value): ?ClientLifecycleStatus
    {
        $normalized = $this->normalize($value);

        if ($normalized === '') {
            return null;
        }

        $status = self::STATUSES[$normalized] ?? null;

        if ($status === null) {
            $this->warnings[] = "Неизвестный статус партнёра «{$normalized}» — статус пропущен.";
        }

        return $status;
    }

    private function businessType(mixed $value): ?BusinessType
    {
        $normalized = $this->normalize($value);

        if ($normalized === '') {
            return null;
        }

        $type = self::BUSINESS_TYPES[$normalized] ?? null;

        if ($type === null) {
            $this->warnings[] = "Неизвестный тип партнёра «{$normalized}» — тип пропущен.";
        }

        return $type;
    }

    /**
     * Колонки-признаки заполняются отметкой «да».
     *
     * Любая непустая отметка читается как «да»: в таблице встречаются и опечатки
     * не в той раскладке, и они означают ровно то же самое. Пустая ячейка — не
     * «нет», а «не выясняли», поэтому null.
     */
    private function flag(mixed $value): ?bool
    {
        $normalized = $this->normalize($value);

        if ($normalized === '') {
            return null;
        }

        return ! in_array($normalized, self::NEGATIVE, true);
    }

    private function points(mixed $value): ?int
    {
        return is_numeric($value) ? (int) round((float) $value) : null;
    }

    private function normalize(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        $text = mb_strtolower(trim((string) $value));
        $text = str_replace(["\u{00A0}", 'ё'], [' ', 'е'], $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
