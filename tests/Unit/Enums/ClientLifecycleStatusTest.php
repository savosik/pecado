<?php

namespace Tests\Unit\Enums;

use App\Enums\Crm\ClientLifecycleStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Стадии партнёра — словарь отдела продаж, а не техническая деталь.
 *
 * Тест держит две вещи, которые ломаются молча: порядок вариантов (он же
 * порядок в интерфейсе) и разбиение на группы.
 */
class ClientLifecycleStatusTest extends TestCase
{
    #[Test]
    #[TestDox('Порядок стадий — лестница работы, потом причины ухода')]
    public function stages_go_from_work_to_reasons_of_leaving(): void
    {
        $this->assertSame(
            ['lead', 'in_work', 'active', 'sleeping', 'at_risk', 'competitor', 'closed', 'bankrupt', 'churned'],
            array_column(ClientLifecycleStatus::cases(), 'value'),
        );
    }

    #[Test]
    #[TestDox('Терминальны только причины ухода')]
    public function only_reasons_of_leaving_are_terminal(): void
    {
        $terminal = array_values(array_filter(
            ClientLifecycleStatus::cases(),
            fn (ClientLifecycleStatus $case) => $case->isTerminal(),
        ));

        $this->assertSame(
            [
                ClientLifecycleStatus::COMPETITOR,
                ClientLifecycleStatus::CLOSED,
                ClientLifecycleStatus::BANKRUPT,
                ClientLifecycleStatus::CHURNED,
            ],
            $terminal,
        );

        foreach ($terminal as $case) {
            $this->assertSame(ClientLifecycleStatus::GROUP_LOST, $case->group());
        }
    }

    #[Test]
    #[TestDox('У каждой стадии есть русская подпись и пояснение')]
    public function every_stage_speaks_russian(): void
    {
        foreach (ClientLifecycleStatus::cases() as $case) {
            $this->assertNotSame('', $case->label());
            $this->assertNotSame('', $case->description());
            $this->assertMatchesRegularExpression('/^[А-ЯЁ]/u', $case->label());
        }
    }

    #[Test]
    #[TestDox('Снятые стадии подписаны и знают свою замену')]
    public function retired_stages_keep_their_labels(): void
    {
        $this->assertSame('Непреодолимо (устар.)', ClientLifecycleStatus::retiredLabel('hopeless'));
        $this->assertSame(ClientLifecycleStatus::CHURNED, ClientLifecycleStatus::replacementFor('hopeless'));

        $this->assertSame('Закрывается (устар.)', ClientLifecycleStatus::retiredLabel('closing'));
        $this->assertSame(ClientLifecycleStatus::AT_RISK, ClientLifecycleStatus::replacementFor('closing'));

        // Действующая стадия снятой не считается — иначе история подписала бы её дважды.
        $this->assertNull(ClientLifecycleStatus::retiredLabel('active'));
    }

    #[Test]
    #[TestDox('Варианты для фронта несут цвет, пояснение и группу')]
    public function options_carry_everything_the_front_needs(): void
    {
        $options = ClientLifecycleStatus::optionsWithColor();

        $this->assertCount(count(ClientLifecycleStatus::cases()), $options);
        $this->assertSame(
            ['value', 'label', 'color', 'description', 'group', 'group_label'],
            array_keys($options[0]),
        );
        $this->assertSame('Работаем с партнёром', $options[0]['group_label']);
        $this->assertSame('Больше не покупает', $options[count($options) - 1]['group_label']);
    }
}
