<?php

namespace App\Helpers;

use Illuminate\Support\Collection;
use Spatie\Tags\Tag;

class TagHelper
{
    /**
     * Вернуть локализованные имена тегов с fallback на `app.fallback_locale`.
     * Пустые значения отбрасываются, дубликаты сохраняются в исходном порядке.
     *
     * @param  iterable<Tag>  $tags
     * @return array<int, string>
     */
    public static function names(iterable $tags, ?string $locale = null): array
    {
        $locale = $locale ?: app()->getLocale();
        $fallback = config('app.fallback_locale', 'en');

        $collection = $tags instanceof Collection ? $tags : Collection::make($tags);

        return $collection
            ->map(function (Tag $tag) use ($locale, $fallback): string {
                $name = trim((string) ($tag->getTranslation('name', $locale) ?: ''));

                if ($name === '' && $fallback && $fallback !== $locale) {
                    $name = trim((string) ($tag->getTranslation('name', $fallback) ?: ''));
                }

                if ($name === '') {
                    foreach ($tag->getTranslations('name') as $value) {
                        $candidate = trim((string) $value);
                        if ($candidate !== '') {
                            $name = $candidate;
                            break;
                        }
                    }
                }

                return $name;
            })
            ->filter(fn (string $name): bool => $name !== '')
            ->values()
            ->all();
    }
}
