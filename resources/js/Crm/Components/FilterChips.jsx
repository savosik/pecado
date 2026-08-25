import { HStack, Text, Wrap } from '@chakra-ui/react';
import { LuX } from 'react-icons/lu';
import { Button } from '@/components/ui/button';

/**
 * Активные фильтры строкой — что именно сейчас отобрано и как это снять.
 *
 * Без такой строки состав отбора виден только внутри выпадающих списков:
 * кнопка показывает «3 выбрано», а какие именно — нужно открывать каждый.
 * Чип называет значение и снимает его одним кликом, не открывая список.
 *
 * @param {{items: Array<{key: string, label: string, value: string, onRemove: Function}>, onReset?: Function}} props
 */
export default function FilterChips({ items = [], onReset }) {
    if (items.length === 0) {
        return null;
    }

    return (
        <Wrap gap={2} align="center" mb={3}>
            <Text fontSize="xs" color="fg.muted">Отбор:</Text>

            {items.map((item) => (
                <Button
                    key={item.key}
                    size="xs"
                    variant="subtle"
                    colorPalette="pecado"
                    onClick={item.onRemove}
                    // Крестик справа, а не иконка-кнопка внутри чипа: вложенная
                    // кнопка ломает клавиатурную навигацию, а весь чип и так
                    // означает единственное действие — снять этот фильтр.
                    aria-label={`Снять фильтр: ${item.label} — ${item.value}`}
                >
                    <Text as="span" color="fg.muted" fontWeight="400">{item.label}:</Text>
                    <Text as="span" lineClamp={1} maxW="220px">{item.value}</Text>
                    <LuX />
                </Button>
            ))}

            {/* Отмена — такая же видимая кнопка, как и сами чипы: ghost рядом
                с заливными чипами теряется и выглядит подписью, а не действием. */}
            {onReset && items.length > 1 && (
                <Button size="xs" variant="outline" colorPalette="red" onClick={onReset}>
                    <LuX /> Снять всё
                </Button>
            )}
        </Wrap>
    );
}
