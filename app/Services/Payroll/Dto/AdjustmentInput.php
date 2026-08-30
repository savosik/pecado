<?php

namespace App\Services\Payroll\Dto;

/**
 * Ручная строка дохода: позиция доп. дохода или корректировка РОПа.
 */
final class AdjustmentInput
{
    public function __construct(
        public readonly ?int $id,
        public readonly string $label,
        public readonly float $qty,
        public readonly float $price,
        public readonly float $amount,
        public readonly ?string $comment = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'qty' => $this->qty,
            'price' => $this->price,
            'amount' => $this->amount,
            'comment' => $this->comment,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $qty = (float) ($data['qty'] ?? 1);
        $price = (float) ($data['price'] ?? 0);

        return new self(
            id: isset($data['id']) ? (int) $data['id'] : null,
            label: (string) ($data['label'] ?? ''),
            qty: $qty,
            price: $price,
            amount: isset($data['amount']) ? (float) $data['amount'] : round($qty * $price, 2),
            comment: isset($data['comment']) ? (string) $data['comment'] : null,
        );
    }
}
