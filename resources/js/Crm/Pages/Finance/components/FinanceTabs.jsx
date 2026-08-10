import { Link } from '@inertiajs/react';
import { HStack } from '@chakra-ui/react';
import { LuCalendarClock, LuGauge, LuScale, LuTriangleAlert, LuWallet } from 'react-icons/lu';
import { Button } from '@/components/ui/button';

const TABS = [
    { key: 'index', label: 'Пульт', icon: LuGauge, href: '/crm/finance' },
    { key: 'plan', label: 'План поступлений', icon: LuWallet, href: '/crm/finance/plan' },
    { key: 'overdue', label: 'Просрочка', icon: LuTriangleAlert, href: '/crm/finance/overdue' },
    { key: 'balances', label: 'Балансы партнёров', icon: LuScale, href: '/crm/finance/balances' },
    { key: 'calendar', label: 'Календарь', icon: LuCalendarClock, href: '/crm/payments/calendar' },
];

/**
 * Переключение между страницами раздела.
 *
 * Ссылками, а не вкладками с локальным состоянием: у каждой страницы свой набор
 * данных и своя пагинация, и держать их все в одном ответе было бы дороже,
 * чем перейти.
 */
export default function FinanceTabs({ active }) {
    return (
        <HStack gap={2} wrap="wrap" mb={4}>
            {TABS.map((tab) => (
                <Button
                    key={tab.key}
                    asChild
                    size="sm"
                    variant={active === tab.key ? 'solid' : 'outline'}
                >
                    <Link href={tab.href}>
                        <tab.icon /> {tab.label}
                    </Link>
                </Button>
            ))}
        </HStack>
    );
}
