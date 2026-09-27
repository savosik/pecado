<?php

namespace App\Services\Client\Api\Operations;

use App\Models\User;
use App\Services\Client\Api\Envelope;
use App\Services\Client\Api\Operation;
use App\Services\Client\Api\OperationProvider;
use App\Services\Notifications\NotificationCatalog;
use App\Services\Notifications\NotificationMatrix;
use App\Services\Notifications\NotificationSettings;
use App\Support\Notifications\Destination;
use App\Support\OperationApi\OperationInput;
use App\Support\OperationApi\Param;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Матрица уведомлений клиента: какие письма и куда приходят.
 *
 * Клиентские уведомления выключены умолчанием — без этой секции агент не узнает
 * о смене статуса заказа. Тот же `NotificationSettings`, что у кабинета: строка
 * хранит только отклонение от умолчания, возврат к нему удаляет строку.
 */
class NotificationOperations implements OperationProvider
{
    public function __construct(
        private readonly NotificationMatrix $matrix,
        private readonly NotificationSettings $settings,
        private readonly NotificationCatalog $catalog,
    ) {}

    public static function section(): array
    {
        return ['notifications', 'Уведомления'];
    }

    public static function operations(): array
    {
        // marketing регистрируется раньше {occasion}: иначе слово «marketing» прошло бы как ключ типа.
        return [
            new Operation(
                id: 'notifications.list', section: 'notifications', method: 'GET', uri: 'notifications',
                summary: 'Матрица уведомлений: типы, включено ли, адресаты',
                description: 'rows — типы уведомлений (key, enabled, destinations, options, subtype); extras — рассылки '
                    .'и письма менеджера. Адресаты: login — почта аккаунта, email — другой адрес.',
                params: [],
                handler: [self::class, 'list'],
            ),
            new Operation(
                id: 'notifications.marketing', section: 'notifications', method: 'PUT', uri: 'notifications/marketing',
                summary: 'Рассылки и акции: включить или отказаться',
                description: 'Единственный переключатель вне типов уведомлений. К заказам и документам отношения не имеет.',
                params: [Param::boolean('enabled', 'Получать рассылки', true)],
                handler: [self::class, 'marketing'],
                mutating: true,
            ),
            new Operation(
                id: 'notifications.update', section: 'notifications', method: 'PUT', uri: 'notifications/{occasion}',
                summary: 'Включить или выключить тип уведомления и задать адресатов',
                description: 'occasion — key из notifications.list. destinations — список {type: login|email, email?}; '
                    .'до десяти адресов. options.subtypes — подтипы, если у типа они есть. Возврат к умолчанию '
                    .'удаляет персональную настройку.',
                params: [
                    Param::string('occasion', 'Ключ типа уведомления', true, ['max:100']),
                    Param::boolean('is_enabled', 'Включить', true),
                    Param::list('destinations', 'Адресаты {type, email?}', 'object', rules: ['max:10']),
                    Param::list('subtypes', 'Подтипы (если есть у типа)'),
                ],
                handler: [self::class, 'update'],
                mutating: true,
            ),
        ];
    }

    /** @return array<string, mixed> */
    public function list(User $actor, OperationInput $input): array
    {
        return Envelope::data($this->matrix->forClient($actor));
    }

    /** @return array<string, mixed> */
    public function update(User $actor, OperationInput $input): array
    {
        $validated = Validator::make([
            'occasion_key' => $input->string('occasion'),
            'is_enabled' => $input->get('is_enabled'),
            'destinations' => $input->array('destinations'),
            'subtypes' => $input->array('subtypes'),
        ], [
            'occasion_key' => ['required', 'string', Rule::in($this->catalog->clientVisibleKeys())],
            'is_enabled' => ['required', 'boolean'],
            'destinations' => ['present', 'array', 'max:10'],
            'destinations.*.type' => ['required', 'string', Rule::in([Destination::LOGIN, Destination::EMAIL])],
            'destinations.*.email' => ['nullable', 'email', 'max:255'],
            'subtypes' => ['nullable', 'array'],
            'subtypes.*' => ['string'],
        ], [
            'occasion_key.in' => 'Такое уведомление настроить нельзя.',
            'destinations.*.type.in' => 'Выберите почту аккаунта или укажите другой адрес.',
            'destinations.*.email.email' => 'Проверьте адрес: он не похож на электронную почту.',
            'destinations.max' => 'Больше десяти адресов на одно уведомление — это уже рассылка.',
        ])->validate();

        $options = ($validated['subtypes'] ?? []) !== [] ? ['subtypes' => array_values($validated['subtypes'])] : [];

        $this->settings->save(
            $actor,
            $validated['occasion_key'],
            (bool) $validated['is_enabled'],
            (array) $validated['destinations'],
            $options,
            $actor,
            byClient: true,
        );

        return Envelope::data($this->matrix->forClient($actor->refresh()));
    }

    /** @return array<string, mixed> */
    public function marketing(User $actor, OperationInput $input): array
    {
        $this->matrix->setMarketing($actor, $input->bool('enabled'));

        return Envelope::data($this->matrix->forClient($actor));
    }
}
