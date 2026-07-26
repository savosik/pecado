import { Box, HStack, Text, Textarea, Wrap } from '@chakra-ui/react';
import { LuPlus } from 'react-icons/lu';
import { Button } from '@/components/ui/button';

/**
 * Поле описания дефекта: свободный текст + чипы быстрого выбора из справочника.
 *
 * Чип добавляет свою формулировку к тексту (через «; »), не затирая уже
 * написанное — кладовщик может набрать несколько типовых дефектов и/или
 * дописать уточнение вручную. Повторный клик по уже добавленному чипу ничего
 * не дублирует.
 *
 * @param {string} value - текущий текст описания
 * @param {Function} onChange - (string) новый текст
 * @param {Array<{id: number, name: string}>} types - активные типы дефектов из справочника
 * @param {string} error - текст ошибки валидации
 */
export function DefectDescriptionField({ value = '', onChange, types = [], error = null }) {
    const addChip = (name) => {
        const current = value.trim();
        // Уже присутствует как отдельный пункт — не дублируем.
        const parts = current ? current.split(';').map((p) => p.trim().toLowerCase()) : [];
        if (parts.includes(name.toLowerCase())) {
            return;
        }
        onChange(current ? `${current}; ${name}` : name);
    };

    return (
        <Box>
            {types.length > 0 && (
                <Wrap gap={2} mb={2}>
                    {types.map((type) => (
                        <Button
                            key={type.id}
                            size="xs"
                            variant="outline"
                            type="button"
                            onClick={() => addChip(type.name)}
                        >
                            <LuPlus />
                            <Text as="span" color="fg.muted" fontVariantNumeric="tabular-nums">#{type.id}</Text>
                            {type.name}
                        </Button>
                    ))}
                </Wrap>
            )}

            <Textarea
                value={value}
                onChange={(event) => onChange(event.target.value)}
                rows={3}
                placeholder="Опишите дефект или выберите из списка выше"
                borderColor={error ? 'red.500' : undefined}
            />

            {types.length > 0 && (
                <HStack gap={1} mt={1}>
                    <Text fontSize="xs" color="fg.muted">
                        Нажмите на тип дефекта, чтобы добавить его. Можно выбрать несколько и дописать уточнение.
                    </Text>
                </HStack>
            )}
        </Box>
    );
}
