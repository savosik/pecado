import { useState } from 'react';
import { useSortable } from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { Card, Box, HStack, VStack, Text, Image, IconButton, Button } from '@chakra-ui/react';
import { Collapsible } from '@chakra-ui/react';
import { LuGripVertical, LuChevronDown, LuChevronUp, LuTrash2, LuImage } from 'react-icons/lu';
import SlideForm from './SlideForm';
import { ConfirmDialog } from '@/Admin/Components';
import axios from 'axios';
import { toaster } from '@/components/ui/toaster';

export default function SlideCard({ slide, story, onUpdate, onDelete }) {
    const [expanded, setExpanded] = useState(slide.isNew || false);
    const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);

    const {
        attributes,
        listeners,
        setNodeRef,
        transform,
        transition,
        isDragging,
    } = useSortable({ id: slide.id });

    const style = {
        transform: CSS.Transform.toString(transform),
        transition,
        opacity: isDragging ? 0.5 : 1,
    };

    const handleDelete = () => {
        if (slide.isNew) {
            // Новый слайд - просто удалить из списка
            onDelete(slide.id);
            return;
        }

        // Существующий слайд - отправить запрос на сервер
        axios.delete(route('admin.stories.slides.destroy', { story: story.id, slide: slide.id }))
            .then(() => {
                toaster.create({
                    title: 'Слайд успешно удалён',
                    type: 'success',
                });
                onDelete(slide.id);
                setDeleteDialogOpen(false);
            })
            .catch((error) => {
                toaster.create({
                    title: 'Ошибка при удалении слайда',
                    description: error.response?.data?.message || 'Попробуйте снова',
                    type: 'error',
                });
            });
    };

    return (
        <>
            <Card.Root ref={setNodeRef} style={style} variant="outline">
                <Card.Body p={4}>
                    <HStack justify="space-between" mb={expanded ? 4 : 0}>
                        <HStack gap={3} flex={1}>
                            {/* Drag handle */}
                            <Box
                                {...attributes}
                                {...listeners}
                                cursor="grab"
                                _active={{ cursor: 'grabbing' }}
                                color="gray.400"
                                _hover={{ color: 'gray.600' }}
                            >
                                <LuGripVertical size={20} />
                            </Box>

                            {/* Превью медиа */}
                            <Box>
                                {slide.media_thumbnail ? (
                                    <Image
                                        src={slide.media_thumbnail}
                                        alt={slide.title || 'Слайд'}
                                        boxSize="60px"
                                        objectFit="cover"
                                        borderRadius="md"
                                    />
                                ) : (
                                    <Box
                                        boxSize="60px"
                                        bg="gray.100"
                                        _dark={{ bg: 'gray.800' }}
                                        borderRadius="md"
                                        display="flex"
                                        alignItems="center"
                                        justifyContent="center"
                                        color="gray.400"
                                    >
                                        <LuImage size={24} />
                                    </Box>
                                )}
                            </Box>

                            {/* Инфо */}
                            <VStack align="start" gap={0} flex={1}>
                                <Text fontWeight="semibold" fontSize="sm">
                                    {slide.title || 'Без названия'}
                                </Text>
                                <Text fontSize="xs" color="gray.600" _dark={{ color: 'gray.400' }}>
                                    Длительность: {slide.duration}с
                                </Text>
                            </VStack>
                        </HStack>

                        {/* Действия */}
                        <HStack gap={2}>
                            <IconButton
                                size="sm"
                                variant="ghost"
                                onClick={() => setExpanded(!expanded)}
                            >
                                {expanded ? <LuChevronUp /> : <LuChevronDown />}
                            </IconButton>
                            <IconButton
                                size="sm"
                                variant="ghost"
                                colorPalette="red"
                                onClick={() => setDeleteDialogOpen(true)}
                            >
                                <LuTrash2 />
                            </IconButton>
                        </HStack>
                    </HStack>

                    {/* Expandable форма редактирования */}
                    <Collapsible.Root open={expanded}>
                        <Collapsible.Content>
                            <Box pt={4} borderTop="1px" borderColor="gray.200" _dark={{ borderColor: 'gray.700' }}>
                                <SlideForm
                                    slide={slide}
                                    story={story}
                                    onSave={onUpdate}
                                    onCancel={() => {
                                        if (slide.isNew) {
                                            onDelete(slide.id);
                                        } else {
                                            setExpanded(false);
                                        }
                                    }}
                                />
                            </Box>
                        </Collapsible.Content>
                    </Collapsible.Root>
                </Card.Body>
            </Card.Root>

            <ConfirmDialog
                open={deleteDialogOpen}
                onClose={() => setDeleteDialogOpen(false)}
                onConfirm={handleDelete}
                title="Удалить слайд?"
                description={`Вы уверены, что хотите удалить этот слайд? Это действие нельзя отменить.`}
            />
        </>
    );
}
