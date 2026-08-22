<?php

namespace App\Services\Contacts;

use App\Models\Contact;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Выгрузка контактов файлом .vcf.
 *
 * То, что делает справочник полезным до его наполнения: менеджер заводит
 * человека не «для системы», а чтобы номер оказался в трубке.
 *
 * Кодировка — UTF-8 **без BOM**. В CSV для Excel BOM обязателен, в vCard
 * запрещён: это разные экспортёры, и перепутать их легко.
 */
class VCardExporter
{
    /** Больше телефон импортирует мучительно долго, а часть прошивок отказывается. */
    public const MAX_CONTACTS = 2000;

    public function __construct(private readonly VCardBuilder $builder) {}

    /**
     * Одна карточка — с фото: файл маленький, а лицо в телефоне полезно.
     */
    public function one(Contact $contact): StreamedResponse
    {
        return $this->stream(
            $this->fileName($contact->full_name),
            collect([$contact]),
            withPhotos: true,
        );
    }

    /**
     * Пачка карточек.
     *
     * Фото по умолчанию не вкладываем: пятьсот контактов по 15 КБ дадут
     * восьмимегабайтный файл, который телефон импортирует минутами.
     *
     * @param  Collection<int, Contact>  $contacts
     */
    public function many(Collection $contacts, bool $withPhotos = false): StreamedResponse
    {
        return $this->stream('kontakty-pecado.vcf', $contacts->take(self::MAX_CONTACTS), $withPhotos);
    }

    /**
     * @param  Collection<int, Contact>  $contacts
     */
    private function stream(string $fileName, Collection $contacts, bool $withPhotos): StreamedResponse
    {
        return response()->streamDownload(function () use ($contacts, $withPhotos): void {
            foreach ($contacts as $contact) {
                echo $this->builder->build($contact, $withPhotos);
            }
        }, $fileName, [
            'Content-Type' => 'text/vcard; charset=utf-8',
        ]);
    }

    /**
     * Имя файла латиницей: часть почтовых клиентов и файловых менеджеров
     * на телефоне спотыкается о кириллицу в Content-Disposition.
     */
    private function fileName(string $name): string
    {
        $slug = \Illuminate\Support\Str::slug($name);

        return ($slug === '' ? 'kontakt' : $slug).'.vcf';
    }
}
