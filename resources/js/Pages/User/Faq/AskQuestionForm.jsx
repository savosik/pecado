import { useEffect, useRef, useState } from 'react';
import { Box, Heading, HStack, Input, Stack, Text, Textarea } from '@chakra-ui/react';
import { Link, useForm, usePage } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { toaster } from '@/components/ui/toaster';
import { LuCircleCheck, LuMessageSquare, LuPaperclip, LuX } from 'react-icons/lu';

const FILE_ACCEPT = '.jpg,.jpeg,.png,.webp,.gif,.pdf,.doc,.docx,.xls,.xlsx,.txt';
const MAX_FILE_SIZE = 10 * 1024 * 1024;

export default function AskQuestionForm() {
    const { auth } = usePage().props;
    const user = auth?.user ?? null;
    const fileInputRef = useRef(null);
    const [submitted, setSubmitted] = useState(false);

    const { data, setData, post, processing, errors, reset, clearErrors } = useForm({
        email: user?.email ?? '',
        name: user?.name ?? '',
        subject: '',
        body: '',
        file: null,
        website: '',
    });

    useEffect(() => {
        if (user) {
            setData((prev) => ({
                ...prev,
                email: user.email ?? '',
                name: user.name ?? '',
            }));
        }
    }, [user?.email, user?.name]);

    const handleSubmit = (e) => {
        e.preventDefault();
        clearErrors();
        post('/faq/questions', {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                reset('subject', 'body', 'file');
                if (fileInputRef.current) fileInputRef.current.value = '';
                setSubmitted(true);
            },
        });
    };

    const handleAskAnother = () => {
        setSubmitted(false);
        clearErrors();
    };

    const handleFileChange = (e) => {
        const file = e.target.files?.[0] ?? null;
        if (file && file.size > MAX_FILE_SIZE) {
            toaster.create({
                title: 'Файл слишком большой',
                description: 'Максимальный размер вложения — 10 МБ.',
                type: 'error',
            });
            e.target.value = '';
            setData('file', null);
            return;
        }
        setData('file', file);
    };

    const handleFileRemove = () => {
        setData('file', null);
        if (fileInputRef.current) fileInputRef.current.value = '';
    };

    if (submitted) {
        return (
            <Box
                bg="bg"
                border="1px solid"
                borderColor="green.200"
                _dark={{ borderColor: 'green.700', bg: 'gray.800' }}
                borderRadius="sm"
                p="6"
                textAlign="center"
            >
                <Stack gap="3" align="center">
                    <Box
                        w="56px"
                        h="56px"
                        borderRadius="full"
                        bg="green.50"
                        _dark={{ bg: 'green.900' }}
                        display="flex"
                        alignItems="center"
                        justifyContent="center"
                        color="green.500"
                    >
                        <LuCircleCheck size={32} />
                    </Box>
                    <Heading size="md">Спасибо за вопрос!</Heading>
                    <Text fontSize="sm" color="fg.muted">
                        {user ? (
                            <>
                                Мы ответим в течение 1 рабочего дня. Ответ появится в разделе{' '}
                                <Link
                                    href="/cabinet/questions"
                                    style={{ color: 'var(--chakra-colors-pecado-500)', textDecoration: 'underline' }}
                                >
                                    «Мои вопросы»
                                </Link>{' '}
                                и придёт на email.
                            </>
                        ) : (
                            'Мы ответим в течение 1 рабочего дня на указанный email.'
                        )}
                    </Text>
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={handleAskAnother}
                        mt="2"
                    >
                        Задать ещё вопрос
                    </Button>
                </Stack>
            </Box>
        );
    }

    return (
        <Box
            as="form"
            onSubmit={handleSubmit}
            bg="bg"
            _dark={{ bg: 'gray.800' }}
            border="1px solid"
            borderColor="border.muted"
            borderRadius="sm"
            p="5"
        >
            <HStack mb="1" gap="2">
                <Box color="pecado.500"><LuMessageSquare size={18} /></Box>
                <Heading size="md">Не нашли ответ?</Heading>
            </HStack>
            <Text fontSize="sm" color="fg.muted" mb="4">
                Задайте вопрос — ответим в течение 1 рабочего дня.
            </Text>

            <Stack gap="3">
                {!user && (
                    <>
                        <Field
                            label="Ваш email"
                            required
                            invalid={!!errors.email}
                            errorText={errors.email}
                        >
                            <Input
                                type="email"
                                value={data.email}
                                onChange={(e) => setData('email', e.target.value)}
                                placeholder="you@example.com"
                                autoComplete="email"
                            />
                        </Field>
                        <Field
                            label="Имя"
                            optionalText="необязательно"
                            invalid={!!errors.name}
                            errorText={errors.name}
                        >
                            <Input
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                placeholder="Как к вам обращаться"
                                autoComplete="name"
                                maxLength={100}
                            />
                        </Field>
                    </>
                )}

                <Field
                    label="Тема"
                    required
                    invalid={!!errors.subject}
                    errorText={errors.subject}
                >
                    <Input
                        value={data.subject}
                        onChange={(e) => setData('subject', e.target.value)}
                        placeholder="О чём вопрос?"
                        maxLength={200}
                    />
                </Field>

                <Field
                    label="Вопрос"
                    required
                    invalid={!!errors.body}
                    errorText={errors.body}
                >
                    <Textarea
                        value={data.body}
                        onChange={(e) => setData('body', e.target.value)}
                        placeholder="Опишите ваш вопрос подробно..."
                        rows={5}
                        resize="vertical"
                        maxLength={5000}
                    />
                </Field>

                <Field
                    label="Вложение"
                    optionalText="до 10 МБ"
                    invalid={!!errors.file}
                    errorText={errors.file}
                    helperText={!data.file ? 'jpg, png, webp, gif, pdf, doc, docx, xls, xlsx, txt' : undefined}
                >
                    <Input
                        ref={fileInputRef}
                        type="file"
                        accept={FILE_ACCEPT}
                        onChange={handleFileChange}
                        p="1"
                        css={{
                            '&::file-selector-button': {
                                marginRight: '8px',
                                padding: '6px 10px',
                                borderRadius: '4px',
                                border: 'none',
                                background: 'var(--chakra-colors-pecado-50, #fef2f2)',
                                color: 'var(--chakra-colors-pecado-700, #b91c1c)',
                                fontWeight: '500',
                                cursor: 'pointer',
                            },
                        }}
                    />
                    {data.file && (
                        <HStack mt="2" gap="2" fontSize="sm" color="fg.muted">
                            <LuPaperclip size={14} />
                            <Text flex="1" truncate>{data.file.name}</Text>
                            <Button
                                size="2xs"
                                variant="ghost"
                                colorPalette="red"
                                onClick={handleFileRemove}
                                type="button"
                            >
                                <LuX />
                            </Button>
                        </HStack>
                    )}
                </Field>

                <input
                    type="text"
                    name="website"
                    tabIndex={-1}
                    autoComplete="off"
                    value={data.website}
                    onChange={(e) => setData('website', e.target.value)}
                    style={{
                        position: 'absolute',
                        left: '-9999px',
                        height: 0,
                        width: 0,
                        opacity: 0,
                    }}
                    aria-hidden="true"
                />

                <Button
                    type="submit"
                    colorPalette="pecado"
                    w="full"
                    loading={processing}
                    loadingText="Отправляем..."
                >
                    Отправить вопрос
                </Button>

                <Text fontSize="xs" color="fg.muted" textAlign="center">
                    Ответ придёт {user ? 'в личный кабинет и на email' : 'на указанный email'}.
                </Text>
            </Stack>
        </Box>
    );
}
