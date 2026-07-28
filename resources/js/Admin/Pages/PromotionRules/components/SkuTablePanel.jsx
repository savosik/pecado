import { useState } from 'react';
import axios from 'axios';
import { Box, Card, HStack, VStack, Text, Textarea, Button, Badge } from '@chakra-ui/react';
import { LuTableProperties, LuX } from 'react-icons/lu';
import { Alert } from '@/components/ui/alert';

/**
 * Вставка таблицы «артикул → кратность» из Excel.
 *
 * Пятнадцать позиций маркетолог наберёт руками, сотню — нет. Каждая строка
 * становится отдельным условием со своим шагом кратности. Нераспознанные
 * артикулы показываем явно: молча терять строки из вставки нельзя.
 */
export default function SkuTablePanel({ onAdd }) {
    const [open, setOpen] = useState(false);
    const [text, setText] = useState('');
    const [result, setResult] = useState(null);
    const [loading, setLoading] = useState(false);
    const [failed, setFailed] = useState(false);

    const parse = async () => {
        setLoading(true);
        setFailed(false);

        try {
            const { data } = await axios.post(route('admin.promotion-rules.parse-sku-table'), { text });
            setResult(data);
        } catch {
            setFailed(true);
            setResult(null);
        } finally {
            setLoading(false);
        }
    };

    const close = () => {
        setOpen(false);
        setText('');
        setResult(null);
        setFailed(false);
    };

    const add = () => {
        onAdd(result.matched);
        close();
    };

    if (!open) {
        return (
            <Button variant="outline" type="button" onClick={() => setOpen(true)}>
                <LuTableProperties /> Вставить список из Excel
            </Button>
        );
    }

    return (
        <Card.Root borderWidth="1px">
            <Card.Body>
                <VStack align="stretch" gap={3}>
                    <HStack justify="space-between">
                        <Text fontWeight="semibold">Список артикулов с кратностью</Text>
                        <Button size="xs" variant="ghost" type="button" onClick={close}>
                            <LuX /> Закрыть
                        </Button>
                    </HStack>

                    <Text fontSize="sm" color="fg.muted">
                        Две колонки: артикул и кратность. Скопируйте их из Excel и вставьте сюда —
                        каждая строка станет отдельным условием «за каждые N штук этого артикула».
                        Если кратность не указана, считается «за каждую штуку».
                    </Text>

                    <Textarea
                        rows={8}
                        fontFamily="mono"
                        placeholder={'LE-22\t1\nLE-60\t2\nLE-62\t4'}
                        value={text}
                        onChange={(e) => setText(e.target.value)}
                    />

                    <HStack>
                        <Button
                            type="button"
                            variant="outline"
                            loading={loading}
                            disabled={!text.trim()}
                            onClick={parse}
                        >
                            Разобрать
                        </Button>

                        {result && result.matched.length > 0 && (
                            <Button type="button" colorPalette="blue" onClick={add}>
                                Добавить условий: {result.matched.length}
                            </Button>
                        )}
                    </HStack>

                    {failed && <Alert status="error" title="Не удалось разобрать список. Попробуйте ещё раз." />}

                    {result && (
                        <VStack align="stretch" gap={2}>
                            {result.matched.length === 0 && (
                                <Alert status="warning" title="Ни один артикул не найден в каталоге" />
                            )}

                            {result.matched.length > 0 && (
                                <Box>
                                    <Text fontSize="sm" mb={1}>
                                        Найдено артикулов: {result.matched.length}
                                    </Text>
                                    <HStack wrap="wrap" gap={2}>
                                        {result.matched.map((row) => (
                                            <Badge key={row.product_id} colorPalette="blue" variant="subtle">
                                                {row.sku} — каждые {row.per_value} шт.
                                            </Badge>
                                        ))}
                                    </HStack>
                                </Box>
                            )}

                            {result.unknown.length > 0 && (
                                <Box>
                                    <Text fontSize="sm" mb={1} color="orange.fg">
                                        Не найдены в каталоге ({result.unknown.length}) — эти строки добавлены не будут:
                                    </Text>
                                    <HStack wrap="wrap" gap={2}>
                                        {result.unknown.map((sku) => (
                                            <Badge key={sku} colorPalette="orange" variant="subtle">{sku}</Badge>
                                        ))}
                                    </HStack>
                                </Box>
                            )}
                        </VStack>
                    )}
                </VStack>
            </Card.Body>
        </Card.Root>
    );
}
