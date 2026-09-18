import { useRef } from 'react';
import { useForm } from '@inertiajs/react';
import { Card, HStack, Input, SimpleGrid, Stack, Text, Textarea } from '@chakra-ui/react';
import { FormField, FormActions, EditorJsEditor, ImageUploader, FileUploader, VideoUploader } from '@/Admin/Components';
import { Checkbox } from '@/components/ui/checkbox';
import { SegmentedControl } from '@/components/ui/segmented-control';
import { Switch } from '@/components/ui/switch';
import { toaster } from '@/components/ui/toaster';

/**
 * Форма инструкции — одна на создание и правку.
 *
 * Формат переключает тело формы: текст (блоки как в новостях), PDF (файл)
 * или видео (ссылка на площадку либо файл до 50 МБ). Аудитории — галочки:
 * одна инструкция может быть нужна и складу, и менеджерам.
 *
 * @param {{instruction?: object, options: {types: Array, audiences: Array}}} props
 */
export default function InstructionForm({ instruction = null, options }) {
    const isEditing = instruction !== null;

    const { data, setData, post, processing, errors, transform } = useForm({
        title: instruction?.title || '',
        short_description: instruction?.short_description || '',
        type: instruction?.type || 'text',
        audiences: instruction?.audiences || [],
        is_published: instruction?.is_published ?? true,
        content: instruction?.content || '',
        video_url: instruction?.video_url || '',
        cover: null,
        pdf: null,
        video: null,
        remove_cover: false,
        remove_file: false,
        remove_video: false,
        ...(isEditing ? { _method: 'PUT' } : {}),
    });

    const closeAfterSaveRef = useRef(false);

    transform((form) => ({
        ...form,
        _close: closeAfterSaveRef.current ? 1 : 0,
        is_published: form.is_published ? 1 : 0,
        remove_cover: form.remove_cover ? 1 : 0,
        remove_file: form.remove_file ? 1 : 0,
        remove_video: form.remove_video ? 1 : 0,
    }));

    const submit = (e, shouldClose = false) => {
        e.preventDefault();
        closeAfterSaveRef.current = shouldClose;

        const url = isEditing
            ? route('admin.instructions.update', instruction.id)
            : route('admin.instructions.store');

        // Файлы уходят multipart: post с _method=PUT, как в новостях.
        post(url, {
            forceFormData: true,
            onSuccess: () => toaster.create({
                title: isEditing ? 'Инструкция обновлена' : 'Инструкция создана',
                type: 'success',
            }),
            onError: () => toaster.create({
                title: 'Проверьте поля формы',
                type: 'error',
            }),
        });
    };

    const toggleAudience = (value, checked) => {
        setData('audiences', checked
            ? [...new Set([...data.audiences, value])]
            : data.audiences.filter((a) => a !== value));
    };

    const existingCover = data.remove_cover ? null : (instruction?.cover || null);
    const existingFile = !data.remove_file && instruction?.file ? [instruction.file] : [];
    const existingVideo = data.remove_video ? null : (instruction?.video_file?.url || null);

    return (
        <Card.Root>
            <Card.Body>
                <form onSubmit={submit}>
                    <Stack gap={6}>
                        <FormField label="Заголовок" error={errors.title} required helperText="Коротко, о чём инструкция — читатель видит его в списке">
                            <Input value={data.title} onChange={(e) => setData('title', e.target.value)} />
                        </FormField>

                        <FormField label="Краткое описание" error={errors.short_description} helperText="Одно-два предложения под заголовком: кому и когда пригодится">
                            <Textarea
                                value={data.short_description}
                                onChange={(e) => setData('short_description', e.target.value)}
                                rows={3}
                                maxLength={1000}
                            />
                        </FormField>

                        <SimpleGrid columns={{ base: 1, md: 2 }} gap={6}>
                            <FormField label="Кому показывать" error={errors.audiences} required>
                                <HStack gap={5} flexWrap="wrap">
                                    {options.audiences.map((a) => (
                                        <Checkbox
                                            key={a.value}
                                            checked={data.audiences.includes(a.value)}
                                            onCheckedChange={(e) => toggleAudience(a.value, !!e.checked)}
                                        >
                                            {a.label}
                                        </Checkbox>
                                    ))}
                                </HStack>
                            </FormField>

                            <FormField label="Опубликована" error={errors.is_published} helperText="Скрытая инструкция видна только здесь">
                                <Switch checked={data.is_published} onCheckedChange={(e) => setData('is_published', e.checked)} />
                            </FormField>
                        </SimpleGrid>

                        <FormField label="Формат" error={errors.type} required>
                            <SegmentedControl
                                value={data.type}
                                onValueChange={(e) => setData('type', e.value)}
                                items={options.types.map((t) => ({ value: t.value, label: t.label }))}
                            />
                        </FormField>

                        {data.type === 'text' && (
                            <FormField label="Текст инструкции" error={errors.content} required>
                                <EditorJsEditor
                                    value={data.content}
                                    onChange={(value) => setData('content', value)}
                                    placeholder="Пишите инструкцию блоками: заголовки, списки, картинки, таблицы…"
                                />
                            </FormField>
                        )}

                        {data.type === 'pdf' && (
                            <FormField label="PDF-файл" error={errors.pdf} required helperText="До 50 МБ. Читатель откроет его прямо на странице и сможет скачать">
                                <FileUploader
                                    name="pdf"
                                    value={data.pdf ? [data.pdf] : []}
                                    onChange={(files) => setData('pdf', files[0] || null)}
                                    existingFiles={existingFile}
                                    onRemoveExisting={() => setData('remove_file', true)}
                                    error={errors.pdf}
                                    label=""
                                    maxFiles={1}
                                    maxSize={50}
                                    acceptedTypes={['application/pdf']}
                                />
                            </FormField>
                        )}

                        {data.type === 'video' && (
                            <Stack gap={4}>
                                <FormField
                                    label="Ссылка на видео"
                                    error={errors.video_url}
                                    helperText="YouTube, Rutube, VK Видео или Vimeo. Для длинных роликов — лучше ссылка, чем файл"
                                >
                                    <Input
                                        value={data.video_url}
                                        onChange={(e) => setData('video_url', e.target.value)}
                                        placeholder="https://rutube.ru/video/…"
                                    />
                                </FormField>
                                <Text fontSize="sm" color="fg.muted">или загрузите файл</Text>
                                <FormField label="Видеофайл" error={errors.video} helperText="MP4, WebM или MOV до 50 МБ. Если указана ссылка, показывается она">
                                    <VideoUploader
                                        name="video"
                                        value={data.video}
                                        onChange={(file) => setData('video', file)}
                                        existingVideo={existingVideo}
                                        onRemoveExisting={() => setData('remove_video', true)}
                                        error={errors.video}
                                        label=""
                                        maxSize={50}
                                    />
                                </FormField>
                            </Stack>
                        )}

                        <FormField label="Обложка" error={errors.cover} helperText="Необязательно. Картинка в списке и в шапке инструкции">
                            <ImageUploader
                                name="cover"
                                onChange={(file) => setData('cover', file)}
                                existingUrl={existingCover}
                                onRemoveExisting={() => setData('remove_cover', true)}
                                error={errors.cover}
                                maxSize={20}
                                placeholder="Обложка инструкции"
                            />
                        </FormField>

                        <FormActions
                            onSaveAndClose={(e) => submit(e, true)}
                            submitLabel={isEditing ? 'Сохранить' : 'Создать инструкцию'}
                            onCancel={() => window.history.back()}
                            isLoading={processing}
                        />
                    </Stack>
                </form>
            </Card.Body>
        </Card.Root>
    );
}
