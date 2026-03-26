import { useForm } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, FormField, FormActions } from '@/Admin/Components';
import { Card, Input, Stack, SimpleGrid, Textarea } from '@chakra-ui/react';
import { Tabs } from '@chakra-ui/react';
import { toaster } from '@/components/ui/toaster';
import { Switch } from '@/components/ui/switch';

export default function Index({ settings }) {
    // Формируем объект для useForm из всех настроек
    const initialData = {};
    Object.keys(settings).forEach(group => {
        Object.keys(settings[group]).forEach(key => {
            initialData[key] = settings[group][key].value;
        });
    });

    const { data, setData, put, processing, errors } = useForm(initialData);

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

                            {tabs.map(tab => (
                                <Tabs.Content key={tab.id} value={tab.id}>
                                    <Stack gap={6} mt={6}>
                                        {settings[tab.id] && Object.keys(settings[tab.id]).map(key =>
                                            renderField(key, settings[tab.id][key])
                                        )}
                                    </Stack>
                                </Tabs.Content>
                            ))}
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
