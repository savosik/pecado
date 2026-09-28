import { useEffect, useState } from 'react';
import { Text } from '@chakra-ui/react';

/**
 * Живой обратный отсчёт до снятия резерва (v16.9.0, режим «Заказы в резерве»).
 *
 * Тикает на клиенте раз в секунду без запросов к серверу. Порог «истекает
 * скоро» (< 6 часов) подсвечивается красным; истёкший резерв показывает
 * «резерв истёк» — заказ не исчезает молча, авто-снятие доедет с сервера.
 */
// Срок в рабочих днях (res-12): пятничный резерв живёт до понедельника — «71:59:59»
// не читается, больше суток показываем днями, секунды тогда не нужны.
function dayWord(n) {
    const d = n % 10;
    const dd = n % 100;
    if (d === 1 && dd !== 11) return 'день';
    if (d >= 2 && d <= 4 && (dd < 12 || dd > 14)) return 'дня';
    return 'дней';
}

export default function ReserveCountdown({ until, fontSize = 'sm', fontWeight = '600' }) {
    const [now, setNow] = useState(() => Date.now());

    useEffect(() => {
        const id = setInterval(() => setNow(Date.now()), 1000);
        return () => clearInterval(id);
    }, []);

    if (!until) return null;

    const diffMs = new Date(until).getTime() - now;

    if (diffMs <= 0) {
        return (
            <Text as="span" fontSize={fontSize} fontWeight={fontWeight} color="red.500">
                резерв истёк
            </Text>
        );
    }

    const totalSec = Math.floor(diffMs / 1000);
    const days = Math.floor(totalSec / 86400);
    const hours = Math.floor((totalSec % 86400) / 3600);
    const minutes = Math.floor((totalSec % 3600) / 60);
    const seconds = totalSec % 60;
    const pad = (n) => String(n).padStart(2, '0');

    const expiringSoon = diffMs < 6 * 3600 * 1000;

    return (
        <Text
            as="span"
            fontSize={fontSize}
            fontWeight={fontWeight}
            fontVariantNumeric="tabular-nums"
            color={expiringSoon ? 'red.500' : 'fg'}
        >
            {days > 0
                ? `${days} ${dayWord(days)} ${hours}:${pad(minutes)}`
                : hours > 0 ? `${hours}:${pad(minutes)}:${pad(seconds)}` : `${minutes}:${pad(seconds)}`}
        </Text>
    );
}
