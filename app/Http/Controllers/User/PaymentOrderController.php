<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Services\Payments\PaymentOrder;
use App\Services\Payments\PaymentOrderService;
use App\Support\Cabinet\CabinetFinance;
use App\Support\Debt\DebtControl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Платёжка «бери и плати» в кабинете (карточка pay-01).
 *
 * Раздел открыт там же, где клиент видит свой долг: при включённом разделе
 * «Оплаты» или боевой лестнице долга — иначе показывать суммы нечего.
 */
class PaymentOrderController extends Controller
{
    public function __construct(private readonly PaymentOrderService $orders) {}

    public function index(Request $request): InertiaResponse
    {
        $this->gate($request);

        return Inertia::render('User/Cabinet/PaymentOrders/Index', [
            'options' => $this->orders->options($request->user()),
        ]);
    }

    public function download(Request $request): Response
    {
        $this->gate($request);
        $order = $this->buildFromRequest($request);
        $format = (string) $request->input('format', 'pdf');

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
     * Предпросмотр: назначение, сумма, QR — без файла.
     */
    public function preview(Request $request): JsonResponse
    {
        $this->gate($request);
        $order = $this->buildFromRequest($request);

        return response()->json([
            ...$order->toArray(),
            'qr' => $this->orders->qrDataUri($order),
        ]);
    }

    public function send(Request $request): RedirectResponse
    {
        $this->gate($request);
        $data = $request->validate([
            'email' => ['required', 'email:rfc'],
            'save_contact' => ['nullable', 'boolean'],
        ], [
            'email.required' => 'Укажите адрес, куда отправить платёжку.',
            'email.email' => 'Похоже, адрес написан с ошибкой.',
        ]);

        $order = $this->buildFromRequest($request);

        try {
            $this->orders->send($request->user(), $order, $data['email'], (bool) ($data['save_contact'] ?? false));
        } catch (\RuntimeException $exception) {
            return back()->withErrors(['email' => $exception->getMessage()]);
        }

        return back()->with('success', 'Платёжка отправлена на '.$data['email'].'.');
    }

    private function gate(Request $request): void
    {
        abort_unless(
            CabinetFinance::enabledFor($request->user()) || DebtControl::live(DebtControl::ACTION_CABINET),
            404,
        );
    }

    private function buildFromRequest(Request $request): PaymentOrder
    {
        $data = $request->validate([
            'company_id' => ['required', 'integer'],
            'organization_id' => ['required', 'integer'],
            'scenario' => ['required', Rule::in(array_keys(PaymentOrderService::SCENARIOS))],
            'entry_id' => ['nullable', 'integer'],
            'amount' => ['nullable', 'numeric', 'min:1'],
        ], [
            'scenario.in' => 'Выберите, что оплатить.',
            'amount.min' => 'Сумма должна быть больше нуля.',
        ]);

        try {
            return $this->orders->build(
                $request->user(),
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
