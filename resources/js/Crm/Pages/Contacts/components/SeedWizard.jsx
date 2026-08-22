import { useEffect, useState } from 'react';
import axios from 'axios';
import { Badge, Box, HStack, Text, VStack } from '@chakra-ui/react';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Alert } from '@/components/ui/alert';
import { toastError, toastSuccess } from '@/utils/toast';

/**
 * Мастер наполнения: кандидаты из данных, которые в базе уже есть.
 *
 * Подтверждает менеджер, а не автоматика: часть почт контрагентов — общие
 * ящики (info@, zakaz@), и человека за ними нет. Отличить может только тот,
 * кто с этой компанией работает.
 */
export default function SeedWizard({ onDone, onCancel }) {
    const [candidates, setCandidates] = useState([]);
    const [chosen, setChosen] = useState({});
    const [loading, setLoading] = useState(true);
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        axios.get(route('crm.contacts.candidates'))
            .then((res) => setCandidates(res.data.data || []))
            .catch(() => {})
            .finally(() => setLoading(false));
    }, []);

    const toggle = (email) => setChosen((prev) => ({ ...prev, [email]: !prev[email] }));

    const chooseAll = (impersonalToo) => {
        const next = {};
        candidates.forEach((item) => {
            if (impersonalToo || !item.impersonal) {
                next[item.email] = true;
            }
        });
        setChosen(next);
    };

    const accept = async () => {
        const emails = Object.keys(chosen).filter((email) => chosen[email]);

        if (emails.length === 0) {
            return;
        }

        setBusy(true);
        try {
            const res = await axios.post(route('crm.contacts.candidates.accept'), { emails });
            toastSuccess('Готово', res.data?.message);
            onDone?.();
        } catch (e) {
            toastError('Не получилось', e?.response?.data?.message || 'Попробуйте ещё раз.');
        } finally {
            setBusy(false);
        }
    };

    const chosenCount = Object.values(chosen).filter(Boolean).length;

    return (
        <Box borderWidth="1px" borderRadius="lg" p={4}>
            <VStack align="stretch" gap={3}>
                <Text fontSize="sm" fontWeight="600">Собрать контакты из данных сайта</Text>

                <Alert status="info" title="Что это">
                    Адреса и телефоны в системе уже есть — они просто лежат не карточками.
                    Отметьте тех, за кем стоит живой человек. Общие ящики вроде info@ помечены:
                    за ними обычно никого нет.
                </Alert>

                {loading && <Text fontSize="sm" color="fg.muted">Ищем кандидатов…</Text>}

                {!loading && candidates.length === 0 && (
                    <Text fontSize="sm" color="fg.muted">
                        Кандидатов нет: всё, что есть в базе, уже в справочнике.
                    </Text>
                )}

                {candidates.length > 0 && (
                    <>
                        <HStack gap={2} flexWrap="wrap">
                            <Button size="xs" variant="outline" onClick={() => chooseAll(false)}>
                                Отметить личные
                            </Button>
                            <Button size="xs" variant="ghost" onClick={() => chooseAll(true)}>
                                Отметить все
                            </Button>
                            <Button size="xs" variant="ghost" onClick={() => setChosen({})}>
                                Снять отметки
                            </Button>
                        </HStack>

                        <VStack align="stretch" gap={1} maxH="440px" overflowY="auto">
                            {candidates.map((item) => (
                                <HStack key={item.email} borderWidth="1px" borderRadius="md" p={2} gap={3}>
                                    <Checkbox
                                        checked={!!chosen[item.email]}
                                        onCheckedChange={() => toggle(item.email)}
                                    />
                                    <VStack align="start" gap={0} flex="1">
                                        <HStack gap={2} flexWrap="wrap">
                                            <Text fontSize="sm" fontWeight="600">{item.full_name}</Text>
                                            <Badge size="sm" variant="subtle">{item.source_label}</Badge>
                                            {item.impersonal && (
                                                <Badge size="sm" colorPalette="orange" variant="subtle">
                                                    общий ящик
                                                </Badge>
                                            )}
                                        </HStack>
                                        <Text fontSize="xs" color="fg.muted">
                                            {[item.email, item.phone, item.hint].filter(Boolean).join(' · ')}
                                        </Text>
                                    </VStack>
                                </HStack>
                            ))}
                        </VStack>

                        <HStack gap={2}>
                            <Button size="sm" onClick={accept} loading={busy} disabled={chosenCount === 0}>
                                Завести отмеченные ({chosenCount})
                            </Button>
                            <Button size="sm" variant="ghost" onClick={onCancel}>Закрыть</Button>
                        </HStack>
                    </>
                )}

                {!loading && candidates.length === 0 && (
                    <Button size="sm" variant="ghost" onClick={onCancel}>Закрыть</Button>
                )}
            </VStack>
        </Box>
    );
}
