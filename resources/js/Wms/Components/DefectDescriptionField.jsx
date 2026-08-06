import { Box, HStack, Text, Textarea, Wrap } from '@chakra-ui/react';
import { LuCheck, LuPlus } from 'react-icons/lu';
import { Button } from '@/components/ui/button';

/**
 * Поле описания дефекта: свободный текст + чипы быстрого выбора из справочника.
 *
 * Чип работает переключателем: первый клик добавляет формулировку к тексту
 * (через «; »), повторный — убирает её. Выбранный чип залит цветом и помечен
 * галочкой, чтобы на складе было видно, что уже нажато. Текст можно дописывать
 * руками — уточнения чипы не трогают.
 *
 * @param {string} value - текущий текст описания
 * @param {Function} onChange - (string) новый текст
 * @param {Array<{id: number, name: string}>} types - активные типы дефектов из справочника
 * @param {string} error - текст ошибки валидации
 */

/** Разбор текста на пункты: чипы пишутся через «; ». */
const splitParts = (text) => String(text || '')
    .split(';')
    .map((part) => part.trim())
    .filter(Boolean);

export function DefectDescriptionField({ value = '', onChange, types = [], error = null }) {
    const parts = splitParts(value);
    const lowerParts = parts.map((part) => part.toLowerCase());

    const isSelected = (name) => lowerParts.includes(name.trim().toLowerCase());

    const toggleChip = (name) => {
        const clean = name.trim();

        if (isSelected(clean)) {
            // Снимаем ровно этот пункт, остальное (в том числе ручные уточнения) остаётся.
            const kept = parts.filter((part) => part.toLowerCase() !== clean.toLowerCase());
            onChange(kept.join('; '));
            return;
        }

        onChange(parts.length > 0 ? `${parts.join('; ')}; ${clean}` : clean);
    };

    return (
        <Box>
            {types.length > 0 && (
                <Wrap gap={2} mb={2}>
                    {types.map((type) => {
                        const selected = isSelected(type.name);

                        return (
                            <Button
                                key={type.id}
                                size="xs"
                                type="button"
                                variant={selected ? 'solid' : 'outline'}
                                colorPalette={selected ? 'green' : undefined}
                                aria-pressed={selected}
                                title={selected ? 'Нажмите, чтобы убрать дефект' : 'Нажмите, чтобы добавить дефект'}
                                onClick={() => toggleChip(type.name)}
                            >
                                {selected ? <LuCheck /> : <LuPlus />}
                                <Text
                                    as="span"
                                    color={selected ? 'inherit' : 'fg.muted'}
                                    opacity={selected ? 0.8 : 1}
                                    fontVariantNumeric="tabular-nums"
                                >
                                    #{type.id}
                                </Text>
                                {type.name}
                            </Button>
                        );
                    })}
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
                        Нажмите на тип дефекта, чтобы добавить его, ещё раз — чтобы убрать.
                        Можно выбрать несколько и дописать уточнение.
                    </Text>
                </HStack>
            )}
        </Box>
    );
}
