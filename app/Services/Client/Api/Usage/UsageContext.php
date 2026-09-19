<?php

namespace App\Services\Client\Api\Usage;

use App\Models\Company;
use App\Services\Client\Api\Operation;

/**
 * Что произошло внутри одного вызова: какая операция реестра выполнялась и
 * от какого юрлица.
 *
 * Синглтон на запрос. Заполняет его {@see \App\Services\Client\Api\OperationRunner}
 * — единственная точка выполнения операций, — а читает тот, кто пишет строку
 * журнала: сервер MCP после инструмента или middleware REST после ответа.
 * Инструмент-ярлык (`client-prices`) выполняет несколько операций подряд;
 * в журнал идёт первая — она и есть «задача», ради которой инструмент вызван.
 */
final class UsageContext
{
    private ?string $operation = null;

    private ?int $companyId = null;

    private bool $mutating = false;

    private ?string $errorCode = null;

    /** Начать новый вызов: сбросить следы предыдущего (воркер и тесты живут долго). */
    public function begin(): void
    {
        $this->operation = null;
        $this->companyId = null;
        $this->mutating = false;
        $this->errorCode = null;
    }

    /**
     * Вызов отклонён с кодом: гейт, валидация, юрлицо, идемпотентность…
     * Первый код и остаётся — он и объясняет, почему агент не получил результата.
     */
    public function fail(string $code): void
    {
        $this->errorCode ??= mb_substr($code, 0, 64);
    }

    public function errorCode(): ?string
    {
        return $this->errorCode;
    }

    public function operation(Operation $operation, ?Company $company = null): void
    {
        if ($this->operation === null) {
            $this->operation = $operation->id;
        }

        if ($company !== null && $this->companyId === null) {
            $this->companyId = (int) $company->getKey();
        }

        $this->mutating = $this->mutating || $operation->mutating;
    }

    public function operationId(): ?string
    {
        return $this->operation;
    }

    public function companyId(): ?int
    {
        return $this->companyId;
    }

    public function isMutating(): bool
    {
        return $this->mutating;
    }
}
