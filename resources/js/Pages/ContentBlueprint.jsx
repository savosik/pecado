import { Head } from '@inertiajs/react';
import { Box, Text } from '@chakra-ui/react';
import UserLayout from './User/UserLayout';
import { Prose } from '@/components/ui/prose';

/**
 * Blueprint-страница контентной типографики.
 * Вдохновлено современными online-изданиями (citydog, kaktutzhit и др.)
 */
export default function ContentBlueprint() {
    return (
        <UserLayout>
            <Head title="Типографика: Журнальный стиль" />

            <Box mb="12" textAlign="center">
                <Text as="h1" fontSize={{ base: '3xl', md: '5xl' }} fontWeight="900" color="fg" letterSpacing="-0.03em" textTransform="uppercase" fontFamily="sans-serif">
                    Editorial Blueprint
                </Text>
                <Text mt="4" fontSize="lg" color="fg.muted" maxW="2xl" mx="auto">
                    Эталонная страница оформления текстовых материалов в журнальном стиле.
                    Отражает то, как будет выглядеть статья на сайте для читателей.
                </Text>
            </Box>

            <Box
                bg={{ base: 'white', _dark: 'gray.900' }}
                borderRadius="2xl"
                overflow="hidden"
                boxShadow="0 25px 50px -12px rgba(0, 0, 0, 0.05)"
                border="1px solid"
                borderColor={{ base: 'gray.100', _dark: 'gray.800' }}
                position="relative"
            >
                <Box position="relative" w="full" h={{ base: '300px', md: '500px' }} overflow="hidden">
                    <img 
                        src="https://placehold.co/1600x900/111827/f87171?text=Журнальная+Обложка&font=playfair-display" 
                        alt="Обложка" 
                        style={{ width: '100%', height: '100%', objectFit: 'cover' }}
                    />
                    <Box position="absolute" inset="0" bgGradient="linear(to-t, blackAlpha.800, transparent)"></Box>
                    <Box position="absolute" bottom="0" left="0" w="full" p={{ base: '6', md: '12' }}>
                        <div className="max-w-4xl mx-auto">
                            <span className="inline-block py-1 px-3 rounded-full bg-pink-600 text-white text-xs font-bold uppercase tracking-wider mb-4">Жизнь в городе</span>
                            <h1 className="text-4xl md:text-6xl font-extrabold text-white leading-tight tracking-tight mb-4">
                                Как правильная типографика меняет восприятие текста навсегда
                            </h1>
                            <p className="text-xl md:text-2xl text-gray-300 font-light max-w-2xl">
                                От огромных заголовков до изящных буквиц — разбираем анатомию красивой статьи.
                            </p>
                        </div>
                    </Box>
                </Box>

                <Box py={{ base: '10', md: '20' }} px={{ base: '4', md: '8' }}>
                    <div className="max-w-3xl mx-auto">
                        <Prose 
                            className="
                                prose prose-lg md:prose-xl prose-stone max-w-none
                                dark:prose-invert 
                                prose-p:font-serif prose-p:leading-loose prose-p:text-gray-800 dark:prose-p:text-gray-200
                                prose-p.drop-cap:first-letter:text-7xl prose-p.drop-cap:first-letter:font-black prose-p.drop-cap:first-letter:text-pink-600 prose-p.drop-cap:first-letter:float-left prose-p.drop-cap:first-letter:mr-3 prose-p.drop-cap:first-letter:mt-1 prose-p.drop-cap:first-letter:leading-none
                                prose-headings:font-sans prose-headings:font-extrabold prose-headings:tracking-tighter prose-headings:text-gray-900 dark:prose-headings:text-white
                                prose-blockquote:border-none prose-blockquote:pl-0 prose-blockquote:font-sans prose-blockquote:font-medium prose-blockquote:text-3xl prose-blockquote:leading-snug prose-blockquote:text-pink-600 dark:prose-blockquote:text-pink-400 prose-blockquote:italic
                                prose-blockquote:border-l-4 prose-blockquote:border-pink-500 prose-blockquote:pl-8
                                prose-a:text-pink-600 prose-a:font-semibold prose-a:no-underline prose-a:border-b-2 prose-a:border-pink-200 hover:prose-a:border-pink-600 transition-colors
                                prose-img:rounded-xl prose-img:w-full prose-img:my-12
                                prose-figcaption:text-center prose-figcaption:font-sans prose-figcaption:text-sm prose-figcaption:text-gray-500 prose-figcaption:uppercase prose-figcaption:tracking-widest
                                prose-hr:border-gray-200 prose-hr:my-16 prose-hr:border-2 prose-hr:w-16 prose-hr:mx-auto prose-hr:border-pink-500
                            "
                            dangerouslySetInnerHTML={{ __html: MAGAZINE_HTML }} 
                        />
                    </div>
                </Box>
            </Box>

            <div className="hidden">
                <div className="drop-cap first-letter:text-7xl first-letter:font-black first-letter:text-pink-600 first-letter:float-left first-letter:mr-3 first-letter:mt-1 first-letter:leading-none"></div>
                <div className="prose prose-lg md:prose-xl prose-stone max-w-none dark:prose-invert"></div>
            </div>
        </UserLayout>
    );
}

const MAGAZINE_HTML = `
<p class="drop-cap">Огромная буквица с первого взгляда даёт понять: перед вами не просто сухая новость, а полноценный журнальный материал. Чтение текста с экрана — это всегда вызов для глаз, поэтому типографика должна быть не просто читаемой, а эстетически безупречной. Широкие поля, крупный шрифт с засечками (serif), увеличенный интерлиньяж — всё это создаёт ощущение воздуха и лёгкости пространстве.</p>

<p>Журнальная верстка в интернете всё чаще отходит от стандартных паттернов «простыни текста». Современные издания активно комбинируют строгую классику с яркими диджитал-акцентами. В этом помогает игра шрифтовых пар: массивные, рубленые заголовки контрастируют с элегантным текстом для чтения.</p>

<h2>Ритм и структура — основа лонгрида</h2>

<p>Длинные тексты невозможно читать без перерывов. Глазу нужны визуальные паузы, "зацепки", которые помогут мозгу структурировать информацию. Идеальный ритм статьи создаётся за счёт чередования параграфов, подзаголовков, крупных фотографий и врезок.</p>

<p>Вот несколько правил, которые делают верстку "дорогой":</p>

<ul>
    <li><strong>Ограниченная ширина строки.</strong> Оптимально — 60-80 символов. Если строка длиннее, глаз устаёт прыгать.</li>
    <li><strong>Воздух вокруг заголовков.</strong> Отступ перед подзаголовком должен быть больше, чем после него.</li>
    <li><strong>Шрифтовые пары.</strong> Санс-сериф для акцентов и заголовков, Сериф для основного чтения.</li>
</ul>

<blockquote>
  «Верстка — это не то, как текст выглядит. Это то, как он звучит в голове читателя.»
</blockquote>

<h2>Внимание к деталям</h2>

<p>В тексте можно встретить <a href="#">инлайн-ссылки</a>, которые не должны перетягивать на себя слишком много внимания, но при этом обязаны быть кликабельными и понятными. Мы используем аккуратное подчёркивание.</p>

<figure>
    <img src="https://placehold.co/1200x800/1f2937/f472b6?text=Жить+в+городе&font=inter" alt="Городская жизнь">
    <figcaption>фото: Unsplash / Иллюстрация эстетики города</figcaption>
</figure>

<h3>Врезки и фактоиды</h3>

<p>Иногда нужно вынести важную мысль отдельно от повествования.</p>

<div style="background-color: #fce7f3; border-radius: 1rem; padding: 2.5rem; margin: 3rem 0; color: #831843;">
    <h4 style="margin-top:0; font-family: sans-serif; font-weight: 800; text-transform: uppercase; letter-spacing: 0.1em; color: #be185d;">Кстати о цифрах</h4>
    <p style="margin-bottom:0; font-size: 1.1em; font-family: sans-serif;">Люди читают лишь 20% текста на среднестатистической веб-странице. Грамотная структура вовлекает читателя куда сильнее сплошного полотна.</p>
</div>

<p>В итоге, хорошая типографика не кричит о себе. Она становится невидимым проводником между автором и читателем. Когда верстка идеальна, читатель даже не осознаёт, почему ему так легко и приятно поглощать материал.</p>

<hr>

<p style="text-align: center; color: #6b7280; font-style: italic;">Текст подготовлен отделом контента Pecado.</p>
`;
