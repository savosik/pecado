import { useState } from 'react';
import { useForm, router } from '@inertiajs/react';
import { Box, Card, Stack, HStack, VStack, Text, Input, Button, SimpleGrid, Tabs, Badge } from '@chakra-ui/react';
import { LuPlus, LuRefreshCw } from 'react-icons/lu';
import { PageHeader, FormField, FormActions, RegionSelector, MultiEntitySelector } from '@/Admin/Components';
import { Switch } from '@/components/ui/switch';
import { Checkbox } from '@/components/ui/checkbox';
import { Radio, RadioGroup } from '@/components/ui/radio';
import { NativeSelectRoot, NativeSelectField } from '@/components/ui/native-select';
import { SegmentedControl } from '@/components/ui/segmented-control';
import { Alert } from '@/components/ui/alert';
import { toaster } from '@/components/ui/toaster';
import ConditionCard from './ConditionCard';
import RewardCard from './RewardCard';
import PreviewPanel from './PreviewPanel';
import SkuTablePanel from './SkuTablePanel';
import { toFormState, toPayload, emptyCondition, emptyReward, conditionFromSkuRow } from './ruleState';

/**
 * Конструктор правила акции. Один и тот же компонент обслуживает создание и
 * редактирование — разница только в маршруте отправки.
 */
export default function RuleForm({
    rule,
    promotions = [],
    regions = [],
    warehouses = [],
    erpPromotionTypes = [],
    issueModeAvailable = false,
    isEdit = false,
}) {
    const [tab, setTab] = useState('general');
    // Какую карточку раскрыть сразу — только что добавленную; остальные свёрнуты
    const [openCondition, setOpenCondition] = useState(null);
    const [openReward, setOpenReward] = useState(null);
    const { data, setData, processing, errors, transform, post, put } = useForm(toFormState(rule));

    const submit = (e, shouldClose = false) => {
        e.preventDefault();

        const options = {
            preserveScroll: true,
            onSuccess: () => toaster.create({
                title: isEdit ? 'Правило сохранено' : 'Правило создано',
                description: 'Конфигурация проверена и записана',
                type: 'success',
            }),
            onError: () => toaster.create({
                title: 'Правило не сохранено',
                description: 'Проверьте вкладки с ошибками — они подсвечены красным',
                type: 'error',
            }),
        };

        // Состояние формы держит объекты {id, name} для селекторов — на бэкенд уходят
        // только идентификаторы. «Сохранить и закрыть» читается из _close (RedirectsAfterSave).
        transform((values) => ({
            ...toPayload(values),
            ...(shouldClose ? { _close: 1 } : {}),
        }));

        if (isEdit) {
            put(route('admin.promotion-rules.update', rule.id), options);
        } else {
            post(route('admin.promotion-rules.store'), options);
        }
    };

    const patchConditions = (patch) => setData('conditions', { ...data.conditions, ...patch });

    const updateCondition = (index, condition) => {
        const items = [...data.conditions.items];
        items[index] = condition;
        patchConditions({ items });
    };

    const updateReward = (index, reward) => {
        const rewards = [...data.rewards];
        rewards[index] = reward;
        setData('rewards', rewards);
    };

    const addCondition = () => {
        setOpenCondition(data.conditions.items.length);
        patchConditions({ items: [...data.conditions.items, emptyCondition()] });
    };

    const addReward = () => {
        setOpenReward(data.rewards.length);
        setData('rewards', [...data.rewards, emptyReward()]);
    };

    /**
     * Таблица «артикул → кратность» из Excel: строка = условие «за каждые N штук
     * этого артикула». Такие условия — альтернативы друг другу, поэтому режим
     * переводим в «достаточно любого»: иначе правило потребует все артикулы разом.
     */
    const addConditionsFromSkuTable = (rows) => {
        const items = [...data.conditions.items, ...rows.map(conditionFromSkuRow)];

        setOpenCondition(null);
        patchConditions({ items, mode: items.length > 1 ? 'any' : data.conditions.mode });

        toaster.create({
            title: `Добавлено условий: ${rows.length}`,
            description: items.length > 1
                ? 'Режим проверки переключён на «Достаточно любого» — артикулы считаются независимо'
                : undefined,
            type: 'success',
        });
    };

    const rebuildParticipants = () => {
        router.post(route('admin.promotion-rules.rebuild', rule.id), {}, {
            preserveScroll: true,
            onSuccess: () => toaster.create({ title: 'Список участников пересчитан', type: 'success' }),
            onError: () => toaster.create({ title: 'Не удалось пересчитать участников', type: 'error' }),
        });
    };

    const tabError = {
        general: errors.name || errors.mode || errors.promotion_id || errors.starts_at || errors.ends_at || errors.priority,
        conditions: errors.conditions,
        rewards: errors.rewards,
        audience: errors.audience || errors.limits,
    };

    const tabLabel = (key, label) => (
        <HStack gap={2}>
            <Text>{label}</Text>
            {tabError[key] && <Badge colorPalette="red" variant="solid" size="xs">!</Badge>}
        </HStack>
    );

    return (
        <>
            <PageHeader
                title={isEdit ? `Правило: ${rule.name}` : 'Новое правило акции'}
                description="Условие срабатывания и промо-позиция, которую получает клиент"
                actions={isEdit && (
                    <Button variant="outline" onClick={rebuildParticipants}>
                        <LuRefreshCw /> Пересчитать участников
                    </Button>
                )}
            />

            <form onSubmit={submit}>
                <Card.Root>
                    <Card.Body>
                        <Tabs.Root value={tab} onValueChange={(e) => setTab(e.value)} colorPalette="blue">
                            <Tabs.List>
                                <Tabs.Trigger value="general">{tabLabel('general', 'Основное')}</Tabs.Trigger>
                                <Tabs.Trigger value="conditions">{tabLabel('conditions', 'Условия')}</Tabs.Trigger>
                                <Tabs.Trigger value="rewards">{tabLabel('rewards', 'Награды')}</Tabs.Trigger>
                                <Tabs.Trigger value="audience">{tabLabel('audience', 'Аудитория и лимиты')}</Tabs.Trigger>
                                <Tabs.Trigger value="preview">Предпросмотр</Tabs.Trigger>
                            </Tabs.List>

                            {/* ── Основное ───────────────────────────────── */}
                            <Tabs.Content value="general">
                                <Stack gap={5} pt={4}>
                                    <SimpleGrid columns={{ base: 1, md: 2 }} gap={4}>
                                        <FormField label="Название правила" error={errors.name} required>
                                            <Input
                                                value={data.name}
                                                onChange={(e) => setData('name', e.target.value)}
                                                placeholder="Например: Lovense — Lush 4 за 0 ₽ от 150 000 ₽"
                                            />
                                        </FormField>

                                        <FormField
                                            label="Акция-лендинг"
                                            error={errors.promotion_id}
                                            helpText="Необязательно: правило может работать без страницы акции"
                                        >
                                            <NativeSelectRoot>
                                                <NativeSelectField
                                                    value={data.promotion_id || ''}
                                                    onChange={(e) => setData('promotion_id', e.target.value ? Number(e.target.value) : null)}
                                                >
                                                    <option value="">Без привязки</option>
                                                    {promotions.map((promotion) => (
                                                        <option key={promotion.id} value={promotion.id}>
                                                            {promotion.name}
                                                        </option>
                                                    ))}
                                                </NativeSelectField>
                                            </NativeSelectRoot>
                                        </FormField>
                                    </SimpleGrid>

                                    <FormField label="Режим работы" error={errors.mode}>
                                        <VStack align="stretch" gap={2}>
                                            <RadioGroup
                                                value={data.mode}
                                                onValueChange={(e) => setData('mode', e.value)}
                                            >
                                                <VStack align="start" gap={2}>
                                                    <Radio value="info">Только показывать</Radio>
                                                    <Radio value="issue" disabled={!issueModeAvailable}>
                                                        Выдавать промо-позиции
                                                    </Radio>
                                                </VStack>
                                            </RadioGroup>
                                            {!issueModeAvailable && (
                                                <Alert status="info" title="Выдача промо-позиций пока недоступна">
                                                    Появится после подключения складского учёта акций. Сейчас правило
                                                    срабатывает и показывается, но промо-позиция в корзину не попадает.
                                                </Alert>
                                            )}
                                        </VStack>
                                    </FormField>

                                    <SimpleGrid columns={{ base: 1, md: 2 }} gap={4}>
                                        <FormField label="Начало периода" error={errors.starts_at} helpText="Пусто — без ограничения">
                                            <Input
                                                type="datetime-local"
                                                value={data.starts_at || ''}
                                                onChange={(e) => setData('starts_at', e.target.value)}
                                            />
                                        </FormField>

                                        <FormField label="Конец периода" error={errors.ends_at} helpText="Пусто — без ограничения">
                                            <Input
                                                type="datetime-local"
                                                value={data.ends_at || ''}
                                                onChange={(e) => setData('ends_at', e.target.value)}
                                            />
                                        </FormField>
                                    </SimpleGrid>

                                    <SimpleGrid columns={{ base: 1, md: 3 }} gap={4}>
                                        <FormField
                                            label="Приоритет"
                                            error={errors.priority}
                                            helpText="Больше — важнее при конфликте правил"
                                        >
                                            <Input
                                                type="number"
                                                min={0}
                                                value={data.priority}
                                                onChange={(e) => setData('priority', e.target.value)}
                                            />
                                        </FormField>

                                        <FormField label="Правило включено" error={errors.is_active}>
                                            <Switch
                                                checked={data.is_active}
                                                onCheckedChange={(e) => setData('is_active', e.checked)}
                                            />
                                        </FormField>

                                        <FormField
                                            label="Можно совмещать с другими акциями"
                                            helpText="Выключено — правило работает в одиночку"
                                        >
                                            <Switch
                                                checked={data.stackable}
                                                onCheckedChange={(e) => setData('stackable', e.checked)}
                                            />
                                        </FormField>
                                    </SimpleGrid>
                                </Stack>
                            </Tabs.Content>

                            {/* ── Условия ────────────────────────────────── */}
                            <Tabs.Content value="conditions">
                                <Stack gap={4} pt={4}>
                                    {errors.conditions && <Alert status="error" title={errors.conditions} />}

                                    {data.conditions.items.length > 1 && (
                                        <FormField
                                            label="Как проверять условия"
                                            helpText={data.conditions.mode === 'any'
                                                ? 'Правило сработает, как только выполнено любое из условий. Кратность считается по сработавшим'
                                                : 'Правило сработает, только когда выполнены все условия сразу'}
                                        >
                                            <SegmentedControl
                                                value={data.conditions.mode}
                                                onValueChange={(e) => patchConditions({ mode: e.value })}
                                                items={[
                                                    { value: 'all', label: 'Выполнить все условия' },
                                                    { value: 'any', label: 'Достаточно любого' },
                                                ]}
                                            />
                                        </FormField>
                                    )}

                                    {data.conditions.items.length === 0 && (
                                        <Text color="fg.muted">
                                            Условий пока нет. Без условия правило не сработает никогда.
                                        </Text>
                                    )}

                                    {data.conditions.items.map((condition, index) => (
                                        <ConditionCard
                                            key={index}
                                            index={index}
                                            condition={condition}
                                            erpPromotionTypes={erpPromotionTypes}
                                            defaultOpen={index === openCondition}
                                            onChange={(updated) => updateCondition(index, updated)}
                                            onRemove={() => patchConditions({
                                                items: data.conditions.items.filter((_, i) => i !== index),
                                            })}
                                        />
                                    ))}

                                    <Box>
                                        <Button variant="outline" onClick={addCondition} type="button">
                                            <LuPlus /> Добавить условие
                                        </Button>
                                    </Box>

                                    <SkuTablePanel onAdd={addConditionsFromSkuTable} />
                                </Stack>
                            </Tabs.Content>

                            {/* ── Награды ────────────────────────────────── */}
                            <Tabs.Content value="rewards">
                                <Stack gap={4} pt={4}>
                                    {errors.rewards && <Alert status="error" title={errors.rewards} />}

                                    {data.rewards.length === 0 && (
                                        <Text color="fg.muted">
                                            Наград пока нет. Добавьте хотя бы одну промо-позицию.
                                        </Text>
                                    )}

                                    {data.rewards.map((reward, index) => (
                                        <RewardCard
                                            key={index}
                                            index={index}
                                            reward={reward}
                                            warehouses={warehouses}
                                            defaultOpen={index === openReward}
                                            onChange={(updated) => updateReward(index, updated)}
                                            onRemove={() => setData('rewards', data.rewards.filter((_, i) => i !== index))}
                                        />
                                    ))}

                                    <Box>
                                        <Button variant="outline" type="button" onClick={addReward}>
                                            <LuPlus /> Добавить награду
                                        </Button>
                                    </Box>
                                </Stack>
                            </Tabs.Content>

                            {/* ── Аудитория и лимиты ─────────────────────── */}
                            <Tabs.Content value="audience">
                                <Stack gap={5} pt={4}>
                                    {(errors.audience || errors.limits) && (
                                        <Alert status="error" title={errors.audience || errors.limits} />
                                    )}

                                    <FormField
                                        label="Регионы"
                                        helpText="Если не выбран ни один регион — правило работает во всех"
                                    >
                                        <RegionSelector
                                            regions={regions}
                                            value={data.audience.region_ids}
                                            onChange={(region_ids) => setData('audience', { ...data.audience, region_ids })}
                                        />
                                    </FormField>

                                    <SimpleGrid columns={{ base: 1, md: 2 }} gap={4}>
                                        <FormField label="Только эти клиенты" helpText="Пусто — все клиенты">
                                            <MultiEntitySelector
                                                value={data.audience.users}
                                                onChange={(users) => setData('audience', { ...data.audience, users })}
                                                searchUrl="admin.users.search"
                                                placeholder="Начните вводить имя клиента..."
                                            />
                                        </FormField>

                                        <FormField label="Только клиенты этих менеджеров" helpText="Пусто — все менеджеры">
                                            <MultiEntitySelector
                                                value={data.audience.managers}
                                                onChange={(managers) => setData('audience', { ...data.audience, managers })}
                                                searchUrl="admin.users.search"
                                                placeholder="Начните вводить имя менеджера..."
                                            />
                                        </FormField>
                                    </SimpleGrid>

                                    <FormField label="Каналы" helpText="Ничего не отмечено — правило работает везде">
                                        <HStack gap={5}>
                                            {[
                                                { value: 'site', label: 'Сайт' },
                                                { value: 'api', label: 'Клиентское API' },
                                            ].map((channel) => (
                                                <Checkbox
                                                    key={channel.value}
                                                    checked={data.audience.channels.includes(channel.value)}
                                                    onCheckedChange={() => setData('audience', {
                                                        ...data.audience,
                                                        channels: data.audience.channels.includes(channel.value)
                                                            ? data.audience.channels.filter((c) => c !== channel.value)
                                                            : [...data.audience.channels, channel.value],
                                                    })}
                                                >
                                                    {channel.label}
                                                </Checkbox>
                                            ))}
                                        </HStack>
                                    </FormField>

                                    <SimpleGrid columns={{ base: 1, md: 2 }} gap={4}>
                                        <FormField label="Лимит на клиента" helpText="Пусто — без ограничения">
                                            <Input
                                                type="number"
                                                min={1}
                                                value={data.limits.per_client_total ?? ''}
                                                onChange={(e) => setData('limits', {
                                                    ...data.limits,
                                                    per_client_total: e.target.value ? Number(e.target.value) : null,
                                                })}
                                            />
                                        </FormField>

                                        <FormField label="Общий лимит выдач" helpText="Пусто — без ограничения">
                                            <Input
                                                type="number"
                                                min={1}
                                                value={data.limits.total ?? ''}
                                                onChange={(e) => setData('limits', {
                                                    ...data.limits,
                                                    total: e.target.value ? Number(e.target.value) : null,
                                                })}
                                            />
                                        </FormField>
                                    </SimpleGrid>
                                </Stack>
                            </Tabs.Content>

                            {/* ── Предпросмотр ───────────────────────────── */}
                            <Tabs.Content value="preview">
                                <Box pt={4}>
                                    <PreviewPanel ruleId={isEdit ? rule.id : null} />
                                </Box>
                            </Tabs.Content>
                        </Tabs.Root>
                    </Card.Body>

                    <Card.Footer>
                        <FormActions
                            isLoading={processing}
                            onSaveAndClose={(e) => submit(e, true)}
                            onCancel={() => router.visit(route('admin.promotion-rules.index'))}
                            submitLabel={isEdit ? 'Сохранить изменения' : 'Создать правило'}
                        />
                    </Card.Footer>
                </Card.Root>
            </form>
        </>
    );
}
