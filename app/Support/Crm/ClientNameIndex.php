<?php

namespace App\Support\Crm;

/**
 * Поиск клиента по имени, записанному в управленческой таблице.
 *
 * Таблица и 1С называют одного и того же клиента по-разному: «Ракович Владимир
 * Сергеевич ИП, г.Москва» против «Ракович Владимир Сергеевич», «Гевея, г. Москва»
 * против «ООО Гевея». Сравнение строк как есть находит пять клиентов из двухсот —
 * то есть не работает вовсе.
 *
 * Поэтому сравнивается «ядро» имени: без города и без организационно-правовой
 * формы, которые в таблице приписаны для удобства чтения, а не для опознания.
 * Второй заход — фамилия с инициалами: в 1С встречается «ИП Железняк Д.И.» там,
 * где таблица пишет имя полностью.
 *
 * Совпадение засчитывается, только когда кандидат ровно один. Похожесть здесь
 * опаснее пропуска: неверно угаданный однофамилец получит чужой план на
 * миллионы, и вскроется это к концу месяца, тогда как ненайденный клиент честно
 * попадает в отчёт и доводится руками.
 */
final class ClientNameIndex
{
    /** Организационно-правовые формы: в таблице их пишут где угодно, в 1С — обычно спереди. */
    private const LEGAL_FORMS = 'ип|ооо|оао|зао|пао|ао|чп|тоо|одо';

    /** @var array<string, list<int>> */
    private array $byCore = [];

    /** @var array<string, list<int>> */
    private array $byInitials = [];

    /**
     * Клиент под всеми известными именами: рабочим наименованием из 1С и именем на сайте.
     */
    public function add(int $id, string ...$names): void
    {
        foreach ($names as $name) {
            $core = self::core($name);

            if ($core === '') {
                continue;
            }

            $this->push($this->byCore, $core, $id);

            $initials = self::initials($core);

            if ($initials !== null) {
                $this->push($this->byInitials, $initials, $id);
            }
        }
    }

    /**
     * Кандидаты на имя из таблицы. Пустой список — не нашли, больше одного — не уверены.
     *
     * @return list<int>
     */
    public function find(string $name): array
    {
        $core = self::core($name);

        if ($core === '') {
            return [];
        }

        if (isset($this->byCore[$core])) {
            return $this->byCore[$core];
        }

        $initials = self::initials($core);

        return $initials === null ? [] : ($this->byInitials[$initials] ?? []);
    }

    /**
     * Ядро имени: без города, без правовой формы и без пунктуации.
     */
    public static function core(string $name): string
    {
        $text = mb_strtolower(trim($name));
        $text = str_replace(["\u{00A0}", 'ё', '«', '»', '"', '(', ')'], [' ', 'е', '', '', '', ' ', ' '], $text);

        // Всё после запятой — почти всегда город: «Гевея, г. Москва», «Затулина Ирина Андреевна ИП, МО».
        $text = (string) preg_replace('/,.*$/u', ' ', $text);

        // Город без запятой: «Авента ООО г.Новосибирск». Точка или пробел после «г»
        // обязательны — иначе правило съедало бы само название («Гевея» как «г.евея»).
        $text = (string) preg_replace('/(?:\bг\.\s*|\bг\s+)[а-я\-]+.*$/u', ' ', $text);

        $text = (string) preg_replace('/\b(?:'.self::LEGAL_FORMS.')\b/u', ' ', $text);
        $text = (string) preg_replace('/[.]/u', ' ', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Фамилия и инициалы: «железняк денис имранович» и «железняк д и» дают «железняк ди».
     */
    public static function initials(string $core): ?string
    {
        $parts = array_values(array_filter(explode(' ', $core), fn (string $part): bool => $part !== ''));

        if (count($parts) < 2) {
            return null;
        }

        $letters = '';

        foreach (array_slice($parts, 1, 2) as $part) {
            $letters .= mb_substr($part, 0, 1);
        }

        return $parts[0].' '.$letters;
    }

    /**
     * Имя для точного сравнения — им сличаются строки самой таблицы между собой.
     */
    public static function normalize(string $name): string
    {
        $text = mb_strtolower(trim($name));
        $text = str_replace(["\u{00A0}", 'ё', '«', '»', '"'], [' ', 'е', '', '', ''], $text);
        $text = (string) preg_replace('/\s*([,.])\s*/u', '$1', $text);
        $text = (string) preg_replace('/\s+/u', ' ', $text);

        return trim($text, " \t\n\r\0\x0B.,");
    }

    /**
     * @param  array<string, list<int>>  $bucket
     */
    private function push(array &$bucket, string $key, int $id): void
    {
        if (! isset($bucket[$key])) {
            $bucket[$key] = [];
        }

        if (! in_array($id, $bucket[$key], true)) {
            $bucket[$key][] = $id;
        }
    }
}
