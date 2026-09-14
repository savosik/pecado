import { createContext, useCallback, useContext, useMemo, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { Box, Flex, HStack, Stack, Text } from '@chakra-ui/react';
import { LuChevronLeft, LuCircleCheck, LuPercent } from 'react-icons/lu';
import { Button } from '@/components/ui/button';
import {
    DrawerBackdrop,
    DrawerBody,
    DrawerCloseTrigger,
    DrawerContent,
    DrawerFooter,
    DrawerHeader,
    DrawerRoot,
    DrawerTitle,
} from '@/components/ui/drawer';
import { toaster } from '@/components/ui/toaster';

/**
 * Опрос клиента о налогах и НДС.
 *
 * Три поверхности, от самой тихой к самой заметной:
 *  - TaxSurveySideTab — ярлычок у правого края на десктопе, пока есть о чём спросить;
 *  - TaxSurveyInvite — карточка в кабинете и на странице только что оформленного
 *    заказа; «Не сейчас» прячет её на две недели, после двух отказов — навсегда;
 *  - шторка с вопросами: по одному на экран, ответ — нажатием, у каждого вопроса
 *    есть «Уточню у бухгалтера».
 * Всплывающих окон при входе нет: клиент пришёл за заказом, а не за анкетой.
 *
 * В режиме просмотра от имени клиента всё то же видит менеджер: проходит опрос
 * во время звонка, ответ записывается от его имени. «Не сейчас» у менеджера
 * прячет карточку только у него — за клиента опрос не откладывается.
 */

const SNOOZE_URL = '/cabinet/tax-survey/snooze';

const TaxSurveyContext = createContext({ survey: null, open: () => {} });

export function useTaxSurvey() {
    return useContext(TaxSurveyContext);
}

export function TaxSurveyProvider({ children }) {
    const { taxSurvey = null } = usePage().props;
    // Снимок на момент открытия: после отправки пропс станет null, а шторка
    // должна успеть сказать «спасибо».
    const [snapshot, setSnapshot] = useState(null);

    const open = useCallback(() => {
        if (taxSurvey) {
            setSnapshot(taxSurvey);
        }
    }, [taxSurvey]);

    const value = useMemo(() => ({ survey: taxSurvey, open }), [taxSurvey, open]);

    return (
        <TaxSurveyContext.Provider value={value}>
            {children}
            {snapshot && <TaxSurveyDrawer survey={snapshot} onClose={() => setSnapshot(null)} />}
        </TaxSurveyContext.Provider>
    );
}

export function TaxSurveySideTab() {
    const { survey, open } = useTaxSurvey();

    if (!survey) {
        return null;
    }

    return (
        <Box
            as="button"
            type="button"
            onClick={open}
            aria-label="Пройти опрос о налогах и НДС"
            position="fixed"
            right="0"
            top="50%"
            transform="translateY(-50%)"
            zIndex="40"
            display={{ base: 'none', lg: 'flex' }}
            alignItems="center"
            gap="2"
            px="1.5"
            py="3"
            bg="bg"
            color="pecado.700"
            _dark={{ color: 'pecado.300' }}
            borderWidth="1px"
            borderRightWidth="0"
            borderColor="border.muted"
            borderLeftRadius="lg"
            shadow="sm"
            fontSize="xs"
            fontWeight="600"
            cursor="pointer"
            _hover={{ shadow: 'md', borderColor: 'pecado.200' }}
            style={{ writingMode: 'vertical-rl' }}
        >
            <LuPercent />
            Вопрос про НДС
        </Box>
    );
}

export function TaxSurveyInvite({ afterOrder = false, mb = '6' }) {
    const { survey, open } = useTaxSurvey();
    const { flash, impersonation } = usePage().props;
    const [hidden, setHidden] = useState(false);

    // На странице заказа — только сразу после оформления: при обычном заходе
    // в заказ просьба про НДС неуместна.
    if (!survey?.invite || hidden || (afterOrder && !flash?.order_placed)) {
        return null;
    }

    const snooze = () => {
        setHidden(true);

        if (!impersonation) {
            router.post(SNOOZE_URL, {}, { preserveScroll: true, preserveState: true });
        }
    };

    return (
        <Box
            bg="bg"
            borderRadius="xl"
            border="1px solid"
            borderColor="border.muted"
            p={{ base: '4', md: '5' }}
            mb={mb}
        >
            <Flex gap="4" align={{ base: 'start', md: 'center' }} direction={{ base: 'column', md: 'row' }}>
                <Flex
                    align="center"
                    justify="center"
                    w="10"
                    h="10"
                    borderRadius="xl"
                    bg="pecado.50"
                    color="pecado.600"
                    _dark={{ bg: 'pecado.900/20', color: 'pecado.300' }}
                    flexShrink="0"
                >
                    <LuPercent size={20} />
                </Flex>
                <Box flex="1">
                    <Text fontWeight="600" color="fg">
                        {afterOrder ? 'Пока заказ собирается — пара вопросов про НДС?' : 'Пара вопросов про НДС'}
                    </Text>
                    <Text fontSize="sm" color="fg.muted">
                        Хотим понимать, как вы работаете с НДС сейчас и что планируете на {survey.target_year} год, —
                        чтобы подстроить под вас документы и ассортимент. Меньше минуты.
                    </Text>
                </Box>
                <HStack gap="2" flexShrink="0">
                    <Button size="sm" colorPalette="pecado" onClick={open}>
                        Ответить
                    </Button>
                    <Button size="sm" variant="ghost" onClick={snooze}>
                        Не сейчас
                    </Button>
                </HStack>
            </Flex>
        </Box>
    );
}

function OptionButton({ selected, onClick, children }) {
    return (
        <Box
            as="button"
            type="button"
            onClick={onClick}
            w="full"
            textAlign="left"
            px="4"
            py="3"
            borderRadius="xl"
            borderWidth="2px"
            borderColor={selected ? 'pecado.600' : 'border.muted'}
            bg={selected ? 'pecado.50' : 'bg'}
            _dark={{ bg: selected ? 'pecado.900/20' : 'bg', borderColor: selected ? 'pecado.400' : 'border.muted' }}
            _hover={{ borderColor: selected ? 'pecado.600' : 'border.emphasized' }}
            transition="border-color 0.15s"
            fontSize="sm"
            fontWeight={selected ? '600' : '500'}
            color="fg"
            cursor="pointer"
        >
            {children}
        </Box>
    );
}

/**
 * Шаги: по два вопроса на каждое юрлицо и один общий — про важность НДС.
 */
function buildSteps(contractors) {
    return [
        ...contractors.flatMap((contractor, index) => [
            { kind: 'current', contractor, index },
            { kind: 'planned', contractor, index },
        ]),
        { kind: 'preference' },
    ];
}

function TaxSurveyDrawer({ survey, onClose }) {
    const { impersonation } = usePage().props;
    const contractors = survey.contractors;
    const steps = useMemo(() => buildSteps(contractors), [contractors]);
    const [step, setStep] = useState(0);
    const [answers, setAnswers] = useState(() => Object.fromEntries(contractors
        .filter((contractor) => contractor.current_regime)
        .map((contractor) => [contractor.id, {
            current_regime: contractor.current_regime,
            planned_regime: contractor.planned_regime,
        }])));
    const [preference, setPreference] = useState(
        () => contractors.find((contractor) => contractor.vat_preference)?.vat_preference ?? null,
    );
    const [busy, setBusy] = useState(false);
    const [done, setDone] = useState(false);

    const current = steps[step];
    const multiple = contractors.length > 1;
    const labelOf = (options, value) => options.find((option) => option.value === value)?.label;

    const next = () => setStep((value) => Math.min(value + 1, steps.length - 1));
    const back = () => setStep((value) => Math.max(value - 1, 0));

    const choose = (contractorId, field, value) => {
        setAnswers((prev) => ({ ...prev, [contractorId]: { ...prev[contractorId], [field]: value } }));
        next();
    };

    // «Уточню у бухгалтера» про режим сейчас — юрлицо пропускаем целиком:
    // спрашивать план без текущего режима бессмысленно.
    const skipContractor = (contractorId) => {
        setAnswers((prev) => {
            const copy = { ...prev };
            delete copy[contractorId];
            return copy;
        });
        setStep((value) => Math.min(value + 2, steps.length - 1));
    };

    const payload = Object.entries(answers)
        .filter(([, answer]) => answer.current_regime)
        .map(([companyId, answer]) => ({
            company_id: Number(companyId),
            current_regime: answer.current_regime,
            planned_regime: answer.planned_regime ?? null,
            vat_preference: preference,
        }));

    const submit = () => {
        if (payload.length === 0) {
            // Ни на что не ответили — не отправляем пустоту и не просим снова завтра.
            if (!impersonation) {
                router.post(SNOOZE_URL, {}, { preserveScroll: true, preserveState: true });
            }
            onClose();
            return;
        }

        setBusy(true);
        router.post('/cabinet/tax-survey', { answers: payload }, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => setDone(true),
            onError: (errors) => toaster.create({
                title: 'Не получилось отправить ответы',
                description: Object.values(errors)[0] || 'Попробуйте ещё раз чуть позже.',
                type: 'error',
            }),
            onFinish: () => setBusy(false),
        });
    };

    const renderStep = () => {
        if (done) {
            return (
                <Stack gap="3" align="center" textAlign="center" py="8">
                    <Box color="green.500" fontSize="4xl"><LuCircleCheck /></Box>
                    <Text fontWeight="600" fontSize="lg" color="fg">Спасибо!</Text>
                    <Text fontSize="sm" color="fg.muted">
                        {impersonation
                            ? 'Ответы сохранены в карточке партнёра от вашего имени.'
                            : 'Ответы увидит ваш менеджер. Если что-то поменяется — просто скажите ему.'}
                    </Text>
                </Stack>
            );
        }

        if (current.kind === 'preference') {
            return (
                <Stack gap="2">
                    <Text fontWeight="600" fontSize="lg" color="fg" mb="1">
                        Насколько вам важно, чтобы товар был с НДС?
                    </Text>
                    {survey.options.vat_preference.map((option) => (
                        <OptionButton
                            key={option.value}
                            selected={preference === option.value}
                            onClick={() => setPreference(option.value)}
                        >
                            {option.label}
                        </OptionButton>
                    ))}
                </Stack>
            );
        }

        const { contractor } = current;
        const answer = answers[contractor.id] ?? {};

        if (current.kind === 'current') {
            return (
                <Stack gap="2">
                    <Text fontWeight="600" fontSize="lg" color="fg" mb="1">
                        {multiple ? `Как ${contractor.name} работает сейчас?` : 'Как вы работаете сейчас?'}
                    </Text>
                    {survey.options.current.map((option) => (
                        <OptionButton
                            key={option.value}
                            selected={answer.current_regime === option.value}
                            onClick={() => choose(contractor.id, 'current_regime', option.value)}
                        >
                            {option.label}
                        </OptionButton>
                    ))}
                    <Button variant="plain" size="sm" color="fg.muted" alignSelf="start" px="0"
                        onClick={() => skipContractor(contractor.id)}>
                        Не знаю — уточню у бухгалтера
                    </Button>
                </Stack>
            );
        }

        // Вариант «как сейчас» — первым: чаще всего ничего не меняется.
        const planned = [
            ...survey.options.planned.filter((option) => option.value === answer.current_regime),
            ...survey.options.planned.filter((option) => option.value !== answer.current_regime),
        ];

        return (
            <Stack gap="2">
                <Text fontWeight="600" fontSize="lg" color="fg" mb="1">
                    А в {survey.target_year} году?
                </Text>
                {planned.map((option) => (
                    <OptionButton
                        key={option.value}
                        selected={answer.planned_regime === option.value}
                        onClick={() => choose(contractor.id, 'planned_regime', option.value)}
                    >
                        {option.value === answer.current_regime ? `Так же: ${option.label}` : option.label}
                    </OptionButton>
                ))}
                <Button variant="plain" size="sm" color="fg.muted" alignSelf="start" px="0"
                    onClick={() => choose(contractor.id, 'planned_regime', null)}>
                    Не знаю — уточню у бухгалтера
                </Button>
            </Stack>
        );
    };

    const contractorLine = !done && current.contractor && multiple
        ? `${current.contractor.name}${current.contractor.tax_id ? `, ИНН ${current.contractor.tax_id}` : ''}`
        : null;

    return (
        <DrawerRoot
            open
            onOpenChange={(event) => { if (!event.open) onClose(); }}
            placement={{ base: 'bottom', md: 'end' }}
            size={{ base: 'full', md: 'sm' }}
        >
            <DrawerBackdrop />
            <DrawerContent roundedTop={{ base: 'xl', md: 'none' }} maxH={{ base: '90vh', md: 'none' }}>
                <DrawerHeader>
                    <Stack gap="0.5">
                        <DrawerTitle>Налоги и НДС</DrawerTitle>
                        {!done && (
                            <Text fontSize="xs" color="fg.muted">
                                Вопрос {step + 1} из {steps.length}
                                {contractorLine && ` · ${contractorLine}`}
                            </Text>
                        )}
                        {!done && impersonation && (
                            <Text fontSize="xs" color="orange.600">
                                Вы отвечаете за партнёра — ответ запишется от вашего имени.
                            </Text>
                        )}
                    </Stack>
                </DrawerHeader>
                <DrawerBody>{renderStep()}</DrawerBody>
                <DrawerFooter>
                    {done ? (
                        <Button w="full" variant="outline" onClick={onClose}>Закрыть</Button>
                    ) : (
                        <HStack w="full" justify="space-between">
                            <Button variant="ghost" size="sm" onClick={back} disabled={step === 0}>
                                <LuChevronLeft /> Назад
                            </Button>
                            {current.kind === 'preference' && (
                                <Button size="sm" colorPalette="pecado" loading={busy} onClick={submit}>
                                    Отправить
                                </Button>
                            )}
                            {current.kind !== 'preference' && labelOf(survey.options.current, answers[current.contractor.id]?.current_regime) && (
                                <Button size="sm" variant="outline" onClick={next}>Дальше</Button>
                            )}
                        </HStack>
                    )}
                </DrawerFooter>
                <DrawerCloseTrigger />
            </DrawerContent>
        </DrawerRoot>
    );
}
