import { useState } from 'react';
import { Card, Button, Stack, Heading, Text, Box } from '@chakra-ui/react';
import { LuPlus } from 'react-icons/lu';
import {
    DndContext,
    closestCenter,
    KeyboardSensor,
    PointerSensor,
    useSensor,
    useSensors,
} from '@dnd-kit/core';
import {
    arrayMove,
    SortableContext,
    sortableKeyboardCoordinates,
    verticalListSortingStrategy,
} from '@dnd-kit/sortable';
import SlideCard from './SlideCard';
import axios from 'axios';
import { toaster } from '@/components/ui/toaster';

export default function SlidesEditor({ story, slides, onSlidesChange }) {
    const [isAdding, setIsAdding] = useState(false);

    const sensors = useSensors(
        useSensor(PointerSensor),
        useSensor(KeyboardSensor, {
            coordinateGetter: sortableKeyboardCoordinates,
        })
    );

    const handleDragEnd = (event) => {
        const { active, over } = event;

        if (!over || active.id === over.id) return;

        const oldIndex = slides.findIndex((slide) => slide.id === active.id);
        const newIndex = slides.findIndex((slide) => slide.id === over.id);

        const newSlides = arrayMove(slides, oldIndex, newIndex);

        // Обновить sort_order
        const updatedSlides = newSlides.map((slide, index) => ({
            ...slide,
            sort_order: index
        }));

        onSlidesChange(updatedSlides);

        // Отправить на сервер только сохранённые слайды (без isNew)
        const savedSlides = updatedSlides.filter(s => !s.isNew);
        if (savedSlides.length === 0) return;

        axios.put(route('admin.stories.slides.reorder', story.id), {
            slides: savedSlides.map(s => ({ id: s.id, sort_order: s.sort_order }))
        }).then(() => {
            toaster.create({
                title: 'Порядок слайдов обновлён',
                type: 'success',
            });
        }).catch(() => {
            toaster.create({
                title: 'Ошибка при обновлении порядка',
                type: 'error',
            });
        });
    };

    const handleAddSlide = () => {
        setIsAdding(true);

        // Добавить пустой слайд в начало списка
        const newSlide = {
            id: `new-${Date.now()}`,
            story_id: story.id,
            title: '',
            content: '',
            button_text: '',
            button_url: '',
            linkable_type: null,
            linkable_id: null,
            duration: 5,
            sort_order: slides.length,
            media_url: null,
            media_thumbnail: null,
            isNew: true,
        };

        onSlidesChange([...slides, newSlide]);
        setIsAdding(false);
    };

    const handleUpdateSlide = (updatedSlide) => {
        onSlidesChange(slides.map(slide =>
            slide.id === updatedSlide.id ? updatedSlide : slide
        ));
    };

    const handleDeleteSlide = (slideId) => {
        onSlidesChange(slides.filter(slide => slide.id !== slideId));
    };

    return (
        <Card.Root>
            <Card.Header>
                <Box display="flex" justifyContent="space-between" alignItems="center">
                    <Box>
                        <Heading size="md">Слайды</Heading>
                        <Text color="gray.600" fontSize="sm">
                            Перетаскивайте слайды для изменения порядка
                        </Text>
                    </Box>
                    <Button
                        colorPalette="blue"
                        onClick={handleAddSlide}
                        loading={isAdding}
                    >
                        <LuPlus />
                        Добавить слайд
                    </Button>
                </Box>
            </Card.Header>

            <Card.Body>
                {slides.length === 0 ? (
                    <Box textAlign="center" py={8} color="gray.500">
                        <Text>Нет слайдов. Добавьте первый слайд.</Text>
                    </Box>
                ) : (
                    <DndContext
                        sensors={sensors}
                        collisionDetection={closestCenter}
                        onDragEnd={handleDragEnd}
                    >
                        <SortableContext
                            items={slides.map(s => s.id)}
                            strategy={verticalListSortingStrategy}
                        >
                            <Stack gap={4}>
                                {slides.map((slide) => (
                                    <SlideCard
                                        key={slide.id}
                                        slide={slide}
                                        story={story}
                                        onUpdate={handleUpdateSlide}
                                        onDelete={handleDeleteSlide}
                                    />
                                ))}
                            </Stack>
                        </SortableContext>
                    </DndContext>
                )}
            </Card.Body>
        </Card.Root>
    );
}
