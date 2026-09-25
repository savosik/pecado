<?php

namespace App\Services\Client\Api\Operations;

use App\Enums\ContactRole;
use App\Enums\Crm\PreferredChannel;
use App\Models\Contact;
use App\Models\User;
use App\Services\Client\Api\Envelope;
use App\Services\Client\Api\Operation;
use App\Services\Client\Api\OperationProvider;
use App\Services\Contacts\PartnerContactService;
use App\Support\OperationApi\OperationInput;
use App\Support\OperationApi\Param;

/**
 * Люди на стороне клиента: кто директор, бухгалтер, закупщик. Удаления нет — деактивация.
 */
class PartnerContactOperations implements OperationProvider
{
    public function __construct(private readonly PartnerContactService $contacts) {}

    public static function section(): array
    {
        return ['contacts', 'Контакты'];
    }

    /**
     * @return list<Param>
     */
    private static function fields(bool $create): array
    {
        return [
            Param::string('full_name', 'ФИО', $create, ['max:191']),
            Param::string('greeting_name', 'Как обращаться', rules: ['max:100'], nullable: true),
            Param::string('position', 'Должность', rules: ['max:191'], nullable: true),
            Param::string('email', 'E-mail', rules: ['max:191'], nullable: true),
            Param::string('phone', 'Телефон', rules: ['max:50'], nullable: true),
            Param::string('phone_extra', 'Дополнительный телефон', rules: ['max:50'], nullable: true),
            Param::string('telegram', 'Telegram', rules: ['max:100'], nullable: true),
            Param::string('whatsapp', 'WhatsApp', rules: ['max:50'], nullable: true),
            Param::string('birthday', 'Дата рождения (YYYY-MM-DD)', rules: ['date'], nullable: true),
            Param::string('preferred_channel', 'Предпочитаемый канал связи', enum: array_column(PreferredChannel::cases(), 'value'), nullable: true),
            Param::list('links', 'Привязки к юрлицам {company_id, role}; роли: '.implode(', ', array_column(ContactRole::cases(), 'value')), 'object', rules: ['max:50']),
        ];
    }

    public static function operations(): array
    {
        $contact = Param::integer('contact', 'id контакта', true);

        return [
            new Operation(
                id: 'contacts.list', section: 'contacts', method: 'GET', uri: 'contacts',
                summary: 'Контакты клиента с привязками к юрлицам и ролями',
                description: 'is_mine — заведён клиентом; иначе завёл менеджер (такой контакт не удаляется, только деактивируется).',
                params: [],
                handler: [self::class, 'list'],
            ),
            new Operation(
                id: 'contacts.create', section: 'contacts', method: 'POST', uri: 'contacts',
                summary: 'Добавить контакт',
                description: 'Нужен телефон или e-mail. До '.PartnerContactService::MAX_CONTACTS.' контактов. Принимает Idempotency-Key.',
                params: self::fields(true),
                handler: [self::class, 'create'],
                mutating: true, idempotent: true,
            ),
            new Operation(
                id: 'contacts.update', section: 'contacts', method: 'PATCH', uri: 'contacts/{contact}',
                summary: 'Изменить контакт',
                description: 'Передаются все поля карточки (ФИО обязательно); привязки заменяются переданными.',
                params: [$contact, ...self::fields(false)],
                handler: [self::class, 'update'],
                mutating: true,
            ),
            new Operation(
                id: 'contacts.deactivate', section: 'contacts', method: 'POST', uri: 'contacts/{contact}/deactivate',
                summary: 'Пометить «больше не работает»',
                description: 'Контакт остаётся в истории, но исчезает из рассылок и адресных книг.',
                params: [$contact],
                handler: [self::class, 'deactivate'],
                mutating: true,
            ),
        ];
    }

    /** @return array<string, mixed> */
    public function list(User $actor, OperationInput $input): array
    {
        return Envelope::data($this->contacts->query($actor)->with('links.subject')->orderBy('full_name')->get()
            ->map(fn (Contact $c) => $this->contacts->payload($c))->values()->all());
    }

    /** @return array<string, mixed> */
    public function create(User $actor, OperationInput $input): array
    {
        $contact = $this->contacts->create($actor, $this->attributes($input));

        return Envelope::data($this->contacts->payload($contact), ['created' => true]);
    }

    /** @return array<string, mixed> */
    public function update(User $actor, OperationInput $input): array
    {
        $contact = $this->contact($actor, $input);
        $data = array_merge($contact->only(['full_name', 'greeting_name', 'position', 'email', 'phone', 'phone_extra', 'telegram', 'whatsapp', 'birthday', 'preferred_channel']), $this->attributes($input));

        if (! $input->has('links')) {
            $data['links'] = $contact->links()->where('subject_type', \App\Models\Company::class)->get()
                ->map(fn ($l) => ['company_id' => (int) $l->subject_id, 'role' => $l->role->value])->all();
        }

        return Envelope::data($this->contacts->payload($this->contacts->update($actor, $contact, $data)));
    }

    /** @return array<string, mixed> */
    public function deactivate(User $actor, OperationInput $input): array
    {
        return Envelope::data($this->contacts->payload($this->contacts->deactivate($actor, $this->contact($actor, $input))));
    }

    private function contact(User $actor, OperationInput $input): Contact
    {
        return $this->contacts->query($actor)->whereKey((int) $input->int('contact'))->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function attributes(OperationInput $input): array
    {
        $data = $input->only(array_map(fn (Param $p) => $p->name, self::fields(false)));

        if (isset($data['birthday'])) {
            $data['birthday_has_year'] = true;
        }

        return $data;
    }
}
