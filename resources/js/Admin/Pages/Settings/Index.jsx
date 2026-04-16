import { useState } from 'react';
import { useForm } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, FormField, FormActions } from '@/Admin/Components';
import { Card, Input, Stack, SimpleGrid, Textarea, Button, Box, Text, HStack, Code, Alert } from '@chakra-ui/react';
import { Tabs } from '@chakra-ui/react';
import { toaster } from '@/components/ui/toaster';
import { Switch } from '@/components/ui/switch';
import axios from 'axios';

export default function Index({ settings }) {
    // Формируем объект для useForm из всех настроек
    const initialData = {};
    Object.keys(settings).forEach(group => {
        Object.keys(settings[group]).forEach(key => {
            initialData[key] = settings[group][key].value;
        });
    });

    const { data, setData, put, processing, errors } = useForm(initialData);

    // Состояние для генерации токена
    const [tokenGenerating, setTokenGenerating] = useState(false);
    const [generatedToken, setGeneratedToken] = useState(null);
    const [copied, setCopied] = useState(false);

    const handleSubmit = (e) => {
        e.preventDefault();
        put(route('admin.settings.update'), {
            preserveState: true,
            preserveScroll: true,
            onSuccess: () => {
                toaster.create({
                    title: 'Настройки успешно обновлены',
                    type: 'success',
                });
            },
            onError: () => {
                toaster.create({
                    title: 'Ошибка при обновлении настроек',
                    description: 'Проверьте правильность заполнения полей',
                    type: 'error',
                });
            },
        });
    };

    const handleGenerateToken = async () => {
        setTokenGenerating(true);
        setGeneratedToken(null);
        setCopied(false);

        try {
            const response = await axios.post(route('admin.settings.generate-content-token'));
            setGeneratedToken(response.data.token);
            toaster.create({
                title: 'Токен успешно сгенерирован',
                description: 'Скопируйте токен — он показывается только один раз',
                type: 'success',
            });
        } catch (error) {
            toaster.create({
                title: 'Ошибка при генерации токена',
                description: error.response?.data?.message || 'Не удалось сгенерировать токен',
                type: 'error',
            });
        } finally {
            setTokenGenerating(false);
        }
    };

    const handleCopyToken = async () => {
        if (!generatedToken) return;

        try {
            await navigator.clipboard.writeText(generatedToken);
            setCopied(true);
            toaster.create({
                title: 'Токен скопирован в буфер обмена',
                type: 'success',
            });
            setTimeout(() => setCopied(false), 3000);
        } catch {
            toaster.create({
                title: 'Не удалось скопировать',
                description: 'Скопируйте токен вручную',
                type: 'error',
            });
        }
    };

    const renderField = (key, settingData) => {
        const { type, description } = settingData;
        const label = key.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());

        switch (type) {
            case 'boolean':
                return (
                    <FormField key={key} label={label} description={description} error={errors[key]}>
                        <Switch
                            checked={!!data[key]}
                            onCheckedChange={(e) => setData(key, e.checked)}
                        />
                    </FormField>
                );

            case 'integer':
                return (
                    <FormField key={key} label={label} description={description} error={errors[key]}>
                        <Input
                            type="number"
                            value={data[key] || ''}
                            onChange={(e) => setData(key, parseInt(e.target.value) || 0)}
                        />
                    </FormField>
                );

            case 'json':
                return (
                    <FormField key={key} label={label} description={description} error={errors[key]}>
                        <Textarea
                            value={typeof data[key] === 'object' ? JSON.stringify(data[key], null, 2) : data[key]}
                            onChange={(e) => setData(key, e.target.value)}
                            rows={6}
                            fontFamily="mono"
                            fontSize="sm"
                        />
                    </FormField>
                );

            default: // string
                return (
                    <FormField key={key} label={label} description={description} error={errors[key]}>
                        <Input
                            value={data[key] || ''}
                            onChange={(e) => setData(key, e.target.value)}
                        />
                    </FormField>
                );
        }
    };

    const tabs = [
        { id: 'general', label: 'Общие' },
        { id: 'email', label: 'Email' },
        { id: 'limits', label: 'Лимиты' },
        { id: 'api', label: 'API' },
        { id: 'integrations', label: 'Интеграции' },
    ];

    return (
        <>
            <PageHeader title="Настройки" />

            <Card.Root>
                <Card.Body>
                    <form onSubmit={handleSubmit}>
                        <Tabs.Root defaultValue="general">
                            <Tabs.List>
                                {tabs.map(tab => (
                                    <Tabs.Trigger key={tab.id} value={tab.id}>
                                        {tab.label}
                                    </Tabs.Trigger>
                                ))}
                            </Tabs.List>

                            {tabs.filter(t => t.id !== 'integrations').map(tab => (
                                <Tabs.Content key={tab.id} value={tab.id}>
                                    <Stack gap={6} mt={6}>
                                        {settings[tab.id] && Object.keys(settings[tab.id]).map(key =>
                                            renderField(key, settings[tab.id][key])
                                        )}
                                    </Stack>
                                </Tabs.Content>
                            ))}

                            <Tabs.Content value="integrations">
                                <Stack gap={6} mt={6}>
                                    {/* Секция AI Content API Token */}
                                    <Box
                                        borderWidth="1px"
                                        borderRadius="lg"
                                        p={6}
                                    >
                                        <Text fontWeight="bold" fontSize="lg" mb={2}>
                                            AI Content API — Токен доступа
                                        </Text>
                                        <Text fontSize="sm" color="fg.muted" mb={4}>
                                            Sanctum-токен для сервисного пользователя <Code>ai-content-bot@pecado.ru</Code>.
                                            Используется внешним AI-агентом для управления контентом через Content API.
                                            При генерации нового токена все предыдущие токены будут автоматически отозваны.
                                        </Text>

                                        {generatedToken && (
                                            <Box
                                                mb={4}
                                                p={4}
                                                borderWidth="1px"
                                                borderRadius="md"
                                                borderColor="green.500"
                                                bg="green.50"
                                                _dark={{ bg: 'green.900/20' }}
                                            >
                                                <Text fontSize="sm" fontWeight="semibold" color="green.700" _dark={{ color: 'green.300' }} mb={2}>
                                                    ⚠ Сохраните токен — он показывается только один раз!
                                                </Text>
                                                <HStack gap={2}>
                                                    <Code
                                                        flex="1"
                                                        p={2}
                                                        fontSize="sm"
                                                        wordBreak="break-all"
                                                    >
                                                        {generatedToken}
                                                    </Code>
                                                    <Button
                                                        size="sm"
                                                        variant={copied ? 'solid' : 'outline'}
                                                        colorPalette={copied ? 'green' : 'gray'}
                                                        onClick={handleCopyToken}
                                                        minW="140px"
                                                    >
                                                        {copied ? '✓ Скопировано' : 'Копировать'}
                                                    </Button>
                                                </HStack>
                                            </Box>
                                        )}

                                        <Button
                                            colorPalette="blue"
                                            onClick={handleGenerateToken}
                                            loading={tokenGenerating}
                                            loadingText="Генерация..."
                                        >
                                            Сгенерировать токен
                                        </Button>
                                    </Box>
                                </Stack>
                            </Tabs.Content>
                        </Tabs.Root>

                        <FormActions
                            submitLabel="Сохранить настройки"
                            onCancel={() => window.history.back()}
                            processing={processing}
                        />
                    </form>
                </Card.Body>
            </Card.Root>
        </>
    );
}

Index.layout = (page) => <AdminLayout>{page}</AdminLayout>;
