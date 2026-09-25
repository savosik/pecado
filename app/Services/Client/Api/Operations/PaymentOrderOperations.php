<?php

namespace App\Services\Client\Api\Operations;

use App\Models\User;
use App\Services\Client\Api\Envelope;
use App\Services\Client\Api\FeatureGate;
use App\Services\Client\Api\FileLinks;
use App\Services\Client\Api\Operation;
use App\Services\Client\Api\OperationProvider;
use App\Services\Payments\PaymentOrder;
use App\Services\Payments\PaymentOrderService;
use App\Support\OperationApi\OperationInput;
use App\Support\OperationApi\Param;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * Платёжное поручение «бери и плати» (pay-01): назначение, сумма, QR по ГОСТ,
 * PDF и файл 1CClientBankExchange, отправка бухгалтеру.
 *
 * Открыто там же, где клиент видит долг (гейт PAYMENT_ORDERS).
 */
class PaymentOrderOperations implements OperationProvider
{
    public function __construct(
        private readonly PaymentOrderService $orders,
        private readonly FileLinks $links,
    ) {}

    public static function section(): array
    {
        return ['payment-orders', 'Платёжные поручения'];
    }

    /**
     * @return list<Param>
     */
    private static function orderParams(): array
    {
        return [
            Param::integer('company_id', 'Плательщик — контрагент клиента (из payment-orders.options)', true, ['min:1']),
            Param::integer('organization_id', 'Получатель — наша организация (из payment-orders.options)', true, ['min:1']),
            Param::string('scenario', 'Что оплатить', true, enum: array_keys(PaymentOrderService::SCENARIOS)),
            Param::integer('entry_id', 'Документ (строка регистра) для scenario=document', rules: ['min:1']),
            Param::string('amount', 'Сумма для scenario=custom', rules: ['numeric', 'min:1']),
        ];
    }

    public static function operations(): array
    {
        return [
            new Operation(
                id: 'payment-orders.options', section: 'payment-orders', method: 'GET', uri: 'payment-orders/options',
                summary: 'Пары плательщик/получатель, сценарии и адресная книга бухгалтеров',
                description: 'Сценарии: '.implode('; ', array_map(fn ($k, $v) => "$k — $v", array_keys(PaymentOrderService::SCENARIOS), PaymentOrderService::SCENARIOS)).'.',
                params: [],
                handler: [self::class, 'options'],
                gate: FeatureGate::PAYMENT_ORDERS,
            ),
            new Operation(
                id: 'payment-orders.preview', section: 'payment-orders', method: 'GET', uri: 'payment-orders/preview',
                summary: 'Предпросмотр платёжки: назначение, сумма, реквизиты, QR',
                description: 'Ничего не пишет. qr_data_uri — QR по ГОСТ Р 56042 для банковского приложения.',
                params: self::orderParams(),
                handler: [self::class, 'preview'],
                gate: FeatureGate::PAYMENT_ORDERS,
            ),
            new Operation(
                id: 'payment-orders.link', section: 'payment-orders', method: 'GET', uri: 'payment-orders/link',
                summary: 'Временная ссылка на файл платёжки (PDF или 1CClientBankExchange)',
                description: 'format=pdf — печатная форма с QR; format=txt — файл для банк-клиента (Windows-1251). '
                    .'Ссылка открывается без токена, живёт до 60 минут.',
                params: [...self::orderParams(), Param::string('format', 'pdf или txt', enum: ['pdf', 'txt']), Param::integer('ttl', 'Срок ссылки, минут (1–60)', rules: ['min:1', 'max:60'])],
                handler: [self::class, 'link'],
                gate: FeatureGate::PAYMENT_ORDERS,
            ),
            new Operation(
                id: 'payment-orders.send', section: 'payment-orders', method: 'POST', uri: 'payment-orders/send',
                summary: 'Отправить платёжку на e-mail (например, бухгалтеру)',
                description: 'Письмо уходит от имени менеджера партнёра. save_contact сохраняет адрес в адресную книгу '
                    .'с ролью бухгалтера. Принимает Idempotency-Key — повтор не отправит второе письмо.',
                params: [...self::orderParams(), Param::string('email', 'Куда отправить', true, ['email:rfc']), Param::boolean('save_contact', 'Сохранить адрес в контакты')],
                handler: [self::class, 'send'],
                mutating: true, idempotent: true,
                gate: FeatureGate::PAYMENT_ORDERS,
            ),
        ];
    }

    /** @return array<string, mixed> */
    public function options(User $actor, OperationInput $input): array
    {
        return Envelope::data($this->orders->options($actor));
    }

    /** @return array<string, mixed> */
    public function preview(User $actor, OperationInput $input): array
    {
        $order = $this->build($actor, $input->all());

        return Envelope::data($order->toArray() + ['qr_data_uri' => $this->orders->qrDataUri($order)]);
    }

    /** @return array<string, mixed> */
    public function link(User $actor, OperationInput $input): array
    {
        $order = $this->build($actor, $input->all());
        $format = $input->string('format') ?? 'pdf';

        $params = array_filter([
            'company_id' => $input->int('company_id'),
            'organization_id' => $input->int('organization_id'),
            'scenario' => $input->string('scenario'),
            'entry_id' => $input->int('entry_id'),
            'amount' => $input->string('amount'),
            'format' => $format,
        ], fn ($v) => $v !== null);

        return Envelope::data([
            'filename' => $order->fileStem().'.'.$format,
            'format' => $format,
            'amount' => $order->amount,
        ] + $this->links->paymentOrder($actor, $params, $input->int('ttl')));
    }

    /** @return array<string, mixed> */
    public function send(User $actor, OperationInput $input): array
    {
        $order = $this->build($actor, $input->all());
        $email = (string) $input->string('email');

        $this->orders->send($actor, $order, $email, $input->bool('save_contact'));

        return Envelope::data([
            'sent' => true,
            'email' => $email,
            'amount' => $order->amount,
            'purpose' => $order->purpose,
        ], ['created' => true]);
    }

    /**
     * Файл по подписанной ссылке — вызывается из ClientFileController.
     *
     * @param  array<string, mixed>  $params
     */
    public function file(User $user, array $params): Response
    {
        $order = $this->build($user, $params);
        $format = ($params['format'] ?? 'pdf') === 'txt' ? 'txt' : 'pdf';

        if ($format === 'txt') {
            return response($this->orders->clientBankExchange($order), 200, [
                'Content-Type' => 'text/plain; charset=windows-1251',
                'Content-Disposition' => 'attachment; filename="'.$order->fileStem().'.txt"',
            ]);
        }

        return response($this->orders->pdf($order), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$order->fileStem().'.pdf"',
        ]);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function build(User $user, array $params): PaymentOrder
    {
        $scenario = (string) ($params['scenario'] ?? '');

        if (! array_key_exists($scenario, PaymentOrderService::SCENARIOS)) {
            throw ValidationException::withMessages(['scenario' => 'Выберите, что оплатить.']);
        }

        // Отказы сервиса (чужая пара, нет реквизитов, нечего платить) — InvalidArgument/Runtime,
        // контроллер отдаст 422 business_rule.
        return $this->orders->build(
            $user,
            (int) ($params['company_id'] ?? 0),
            (int) ($params['organization_id'] ?? 0),
            $scenario,
            isset($params['entry_id']) && $params['entry_id'] !== '' ? (int) $params['entry_id'] : null,
            isset($params['amount']) && $params['amount'] !== '' ? (float) $params['amount'] : null,
        );
    }
}
