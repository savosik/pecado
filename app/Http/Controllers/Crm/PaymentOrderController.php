<?php

namespace App\Http\Controllers\Crm;

use App\Models\User;
use App\Services\Payments\PaymentOrder;
use App\Services\Payments\PaymentOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Платёжка из карточки партнёра: менеджер собирает и шлёт бухгалтеру сам
 * (карточка pay-01). Те же данные и форматы, что в кабинете.
 */
class PaymentOrderController extends CrmController
{
    public function __construct(private readonly PaymentOrderService $orders) {}

    public function options(Request $request, int $client): JsonResponse
    {
        return response()->json($this->orders->options($this->partner($request, $client)));
    }

    public function preview(Request $request, int $client): JsonResponse
    {
        $order = $this->buildFromRequest($request, $this->partner($request, $client));

        return response()->json([...$order->toArray(), 'qr' => $this->orders->qrDataUri($order)]);
    }

    public function download(Request $request, int $client): Response
    {
        $order = $this->buildFromRequest($request, $this->partner($request, $client));

        if ((string) $request->input('format', 'pdf') === 'txt') {
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

    public function send(Request $request, int $client): JsonResponse
    {
        $partner = $this->partner($request, $client);
        $data = $request->validate([
            'email' => ['required', 'email:rfc'],
            'save_contact' => ['nullable', 'boolean'],
        ], [
            'email.required' => 'Укажите адрес бухгалтера.',
            'email.email' => 'Похоже, адрес написан с ошибкой.',
        ]);

        $order = $this->buildFromRequest($request, $partner);

        try {
            $letter = $this->orders->send($partner, $order, $data['email'], (bool) ($data['save_contact'] ?? false), $this->crmActor($request));
        } catch (\RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'email_id' => $letter->getKey(), 'message' => 'Платёжка отправлена на '.$data['email'].'.']);
    }

    private function partner(Request $request, int $client): User
    {
        return User::query()->visibleInCrm($this->crmActor($request))->findOrFail($client);
    }

    private function buildFromRequest(Request $request, User $partner): PaymentOrder
    {
        $data = $request->validate([
            'company_id' => ['required', 'integer'],
            'organization_id' => ['required', 'integer'],
            'scenario' => ['required', Rule::in(array_keys(PaymentOrderService::SCENARIOS))],
            'entry_id' => ['nullable', 'integer'],
            'amount' => ['nullable', 'numeric', 'min:1'],
        ]);

        try {
            return $this->orders->build(
                $partner,
                (int) $data['company_id'],
                (int) $data['organization_id'],
                (string) $data['scenario'],
                isset($data['entry_id']) ? (int) $data['entry_id'] : null,
                isset($data['amount']) ? (float) $data['amount'] : null,
            );
        } catch (\InvalidArgumentException|\RuntimeException $exception) {
            abort(422, $exception->getMessage());
        }
    }
}
