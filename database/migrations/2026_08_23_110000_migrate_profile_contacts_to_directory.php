<?php

use App\Enums\ContactRole;
use App\Enums\ContactSource;
use App\Models\Contact;
use App\Models\ContactLink;
use App\Models\CrmClientProfile;
use App\Models\User;
use App\Services\Contacts\ProfileContactParser;
use Illuminate\Database\Migrations\Migration;

/**
 * Перенос контактных лиц из анкет CRM в справочник.
 *
 * В анкете партнёра люди лежали свободным текстом — восемь колонок на бухгалтера,
 * собственника и ЛПР. Из «Афонина Мария, +7 912 …, buh@romashka.ru» нельзя ни
 * собрать .vcf, ни подшить письмо, ни вспомнить про день рождения.
 *
 * Сами колонки здесь **не удаляются**: между переносом и дропом должен пройти
 * релиз, чтобы перенос проверили на живых данных. Дроп — карточка contact-15.
 *
 * Идемпотентна: повторный прогон не плодит записи. Объём на проде измерен —
 * заполнена одна анкета из 185, так что риск близок к нулю.
 */
return new class extends Migration
{
    /** Какое поле анкеты какой ролью становится. */
    private const MAPPING = [
        ['owner_name', 'owner_contact', null, ContactRole::OWNER],
        ['accountant_name', 'accountant_contact', null, ContactRole::ACCOUNTANT],
        ['decision_maker_name', 'decision_maker_contact', 'decision_maker_role', ContactRole::MANAGER],
    ];

    public function up(): void
    {
        $parser = new ProfileContactParser;

        CrmClientProfile::query()->chunkById(200, function ($profiles) use ($parser): void {
            foreach ($profiles as $profile) {
                $this->migrateProfile($profile, $parser);
            }
        });
    }

    private function migrateProfile(CrmClientProfile $profile, ProfileContactParser $parser): void
    {
        $partner = User::query()->find($profile->user_id);

        if ($partner === null) {
            return;
        }

        foreach (self::MAPPING as [$nameField, $contactField, $positionField, $role]) {
            $parsed = $parser->parse(
                $profile->getAttribute($nameField),
                $profile->getAttribute($contactField),
                $positionField === null ? null : $profile->getAttribute($positionField),
            );

            if ($parsed === null) {
                continue;
            }

            if ($this->alreadyThere($partner->id, $parsed)) {
                continue;
            }

            $contact = new Contact([
                'full_name' => $parsed['full_name'],
                'position' => $parsed['position'],
                'email' => $parsed['email'],
                'phone' => $parsed['phone'],
                'is_active' => true,
            ]);

            $contact->client_user_id = $partner->id;
            $contact->source = ContactSource::PROFILE_IMPORT;
            $contact->notes = 'Перенесено из анкеты CRM — проверьте данные';

            // День рождения был только у ЛПР, и он же — единственное поле анкеты,
            // которому в справочнике есть точное место.
            if ($role === ContactRole::MANAGER && filled($profile->decision_maker_birthday)) {
                $contact->birthday = $profile->decision_maker_birthday;
            }

            $contact->save();

            ContactLink::query()->create([
                'contact_id' => $contact->getKey(),
                'subject_type' => User::class,
                'subject_id' => $partner->id,
                'role' => $role->value,
                'client_user_id' => $partner->id,
                'source' => ContactSource::PROFILE_IMPORT,
            ]);
        }
    }

    /**
     * Такой человек уже перенесён: сверяем по почте и по цифрам телефона,
     * с учётом мягко удалённых — иначе повторный прогон вернёт удалённое.
     *
     * @param  array{full_name: string, email: ?string, phone: ?string, position: ?string}  $parsed
     */
    private function alreadyThere(int $partnerId, array $parsed): bool
    {
        $query = Contact::withTrashed()->where('client_user_id', $partnerId);

        if ($parsed['email'] !== null) {
            return (clone $query)->where('email', $parsed['email'])->exists();
        }

        $digits = Contact::digitsOf($parsed['phone']);

        if ($digits !== null) {
            return (clone $query)->where('phone_digits', $digits)->exists();
        }

        return (clone $query)->where('full_name', $parsed['full_name'])->exists();
    }

    public function down(): void
    {
        // Перенесённое удаляем: данные остались в анкете, дублировать их
        // после отката незачем.
        Contact::query()->where('source', ContactSource::PROFILE_IMPORT->value)->forceDelete();
    }
};
