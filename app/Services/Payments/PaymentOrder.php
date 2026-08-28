<?php

namespace App\Services\Payments;

use Carbon\CarbonImmutable;

/**
 * Готовое платёжное поручение: всё, что нужно бухгалтеру клиента, чтобы
 * заплатить не думая (карточка pay-01). Собирается сервисом, отдаётся в трёх
 * видах — PDF, файл клиент-банка и QR — из одних данных.
 *
 * @param  list<array{id: int, number: string, date: ?string, due: ?string, amount: float, overdue: bool}>  $documents
 */
final class PaymentOrder
{
    public function __construct(
        public readonly string $number,
        public readonly CarbonImmutable $date,
        public readonly string $scenario,
        public readonly string $scenarioLabel,
        public readonly float $amount,
        public readonly string $purpose,
        public readonly array $payer,
        public readonly array $payee,
        public readonly array $documents,
        public readonly int $companyId,
        public readonly int $organizationId,
    ) {}

    public function fileStem(): string
    {
        return 'platezhka-'.$this->date->format('Y-m-d').'-'.$this->number;
    }

    public function amountKopecks(): int
    {
        return (int) round($this->amount * 100);
    }

    public function amountFormatted(): string
    {
        return number_format($this->amount, 2, ',', ' ');
    }

    /**
     * Строка для QR по ГОСТ Р 56042-2014 (ST00012 — UTF-8).
     * Обязательные поля идут первыми в фиксированном порядке.
     */
    public function qrPayload(): string
    {
        $clean = static fn (?string $value): string => str_replace('|', ' ', trim((string) $value));

        $parts = [
            'ST00012',
            'Name='.$clean($this->payee['legal_name'] ?: $this->payee['name']),
            'PersonalAcc='.$clean($this->payee['account_number']),
            'BankName='.$clean($this->payee['bank_name']),
            'BIC='.$clean($this->payee['bank_bik']),
            'CorrespAcc='.$clean($this->payee['correspondent_account']),
        ];

        if (filled($this->payee['tax_id'])) {
            $parts[] = 'PayeeINN='.$clean($this->payee['tax_id']);
        }

        if (filled($this->payee['tax_code'])) {
            $parts[] = 'KPP='.$clean($this->payee['tax_code']);
        }

        $parts[] = 'Sum='.$this->amountKopecks();
        $parts[] = 'Purpose='.$clean($this->purpose);

        if (filled($this->payer['name'])) {
            $parts[] = 'PayerName='.$clean($this->payer['legal_name'] ?: $this->payer['name']);
        }

        return implode('|', $parts);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'number' => $this->number,
            'date' => $this->date->format('d.m.Y'),
            'scenario' => $this->scenario,
            'scenario_label' => $this->scenarioLabel,
            'amount' => $this->amount,
            'amount_formatted' => $this->amountFormatted(),
            'purpose' => $this->purpose,
            'payer' => $this->payer,
            'payee' => $this->payee,
            'documents' => $this->documents,
            'company_id' => $this->companyId,
            'organization_id' => $this->organizationId,
            'qr_payload' => $this->qrPayload(),
        ];
    }
}
