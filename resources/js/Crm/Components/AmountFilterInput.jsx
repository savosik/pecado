import { useEffect, useState } from 'react';
import { Input } from '@chakra-ui/react';

/**
 * Числовая граница отбора (сумма от/до).
 *
 * Значение уходит на сервер по потере фокуса и по Enter, а не на каждое
 * нажатие: иначе набор «100000» превратился бы в шесть запросов, а медленный
 * ответ откатывал бы уже набранные символы (поле контролируется серверным
 * снимком фильтров).
 */
export default function AmountFilterInput({ value, onCommit, ...props }) {
    const [draft, setDraft] = useState(value ?? '');

    // Значение могло измениться извне — применили сохранённый отбор или сбросили.
    useEffect(() => setDraft(value ?? ''), [value]);

    const commit = () => {
        const next = draft === '' ? undefined : draft;

        if (String(next ?? '') !== String(value ?? '')) {
            onCommit(next);
        }
    };

    return (
        <Input
            size="sm"
            type="number"
            value={draft}
            onChange={(event) => setDraft(event.target.value)}
            onBlur={commit}
            onKeyDown={(event) => event.key === 'Enter' && commit()}
            {...props}
        />
    );
}
