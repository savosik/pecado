import { Head, Link, router } from '@inertiajs/react';
import axios from 'axios';
import { Badge, Box, HStack, Image, Text, VStack } from '@chakra-ui/react';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { Button } from '@/components/ui/button';
import { Pagination } from '@/Admin/Components/Pagination';
import { toastError, toastSuccess } from '@/utils/toast';
import { LuArrowLeft, LuArrowRight, LuCheck, LuX } from 'react-icons/lu';

const money = (value) =>
    new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 0 }).format(value || 0);

/**
 * Очередь подтверждений связей замен: еженедельная 15-минутная рутина.
 *
 * learned — клиент согласовал такую замену, ai — предразметка агентом.
 * Обе не используются автоподбором, пока человек не подтвердит.
 * Отклонённая пара не воскресает.
 */
export default function Links({ links, awaitingCount }) {
    const act = async (link, action) => {
        try {
            await axios.post(`/crm/shortages/links/${link.id}/${action}`);
            toastSuccess(action === 'approve' ? 'Связь подтверждена — автоподбор начнёт её предлагать' : 'Связь отклонена и больше не появится');
            router.reload({ only: ['links', 'awaitingCount'] });
        } catch (e) {
            toastError(e.response?.data?.message || 'Не удалось обработать связь');
        }
    };

    const ProductCell = ({ product }) => product ? (
        <HStack gap={2} flex="1" minW="240px">
            {product.image_url && (
                <Image src={product.image_url} alt="" boxSize="44px" objectFit="cover" borderRadius="md" />
            )}
            <Box>
                <Text fontSize="sm" fontWeight="medium">{product.name}</Text>
                <Text fontSize="xs" color="fg.muted">
                    {money(product.price)} ₽ · остаток {product.available}
                    {product.sku ? ` · ${product.sku}` : ''}
                </Text>
            </Box>
        </HStack>
    ) : <Text color="fg.muted">товар удалён</Text>;

    return (
        <>
            <Head title="Связи замен — CRM" />

            <HStack mb={2}>
                <Link href="/crm/shortages">
                    <Button size="xs" variant="ghost">
                        <LuArrowLeft /> К очереди недоборов
                    </Button>
                </Link>
            </HStack>

            <PageHeader
                title="Связи замен: очередь подтверждений"
                description={`Неподтверждённых связей: ${awaitingCount}. Подтверждённые начинает предлагать автоподбор; отклонённые не воскресают.`}
            />

            <VStack align="stretch" gap={3}>
                {(links.data || []).length === 0 && (
                    <Text color="fg.muted" py={8} textAlign="center">
                        Очередь пуста — все связи разобраны.
                    </Text>
                )}
                {(links.data || []).map((link) => (
                    <Box key={link.id} borderWidth="1px" borderRadius="lg" p={4}>
                        <HStack gap={4} flexWrap="wrap" align="center">
                            <ProductCell product={link.from} />
                            <LuArrowRight />
                            <ProductCell product={link.to} />
                            <VStack align="end" gap={1} ml="auto">
                                <HStack gap={2}>
                                    <Badge variant="outline">{link.kind_label}</Badge>
                                    <Badge colorPalette={link.source === 'ai' ? 'purple' : 'blue'} variant="subtle">
                                        {link.source_label}
                                    </Badge>
                                    <Badge variant="subtle">уверенность {link.score}</Badge>
                                </HStack>
                                {link.negative_signals > 0 && (
                                    <Badge colorPalette="red" variant="subtle">
                                        {link.negative_signals} негативных сигнала(ов)
                                    </Badge>
                                )}
                            </VStack>
                        </HStack>
                        {link.note && (
                            <Text mt={2} fontSize="sm" color="fg.muted">
                                Причина для клиента: {link.note}
                            </Text>
                        )}
                        <HStack mt={3} gap={2}>
                            <Button size="xs" colorPalette="green" onClick={() => act(link, 'approve')}>
                                <LuCheck /> Подтвердить
                            </Button>
                            <Button size="xs" variant="outline" colorPalette="red" onClick={() => act(link, 'reject')}>
                                <LuX /> Отклонить
                            </Button>
                        </HStack>
                    </Box>
                ))}
            </VStack>

            {links.total > links.per_page && (
                <Box mt={4}>
                    <Pagination pagination={links} />
                </Box>
            )}
        </>
    );
}

Links.layout = (page) => <CrmLayout>{page}</CrmLayout>;
