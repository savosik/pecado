<?php

namespace Tests\Feature\Helpers;

use App\Helpers\TagHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Spatie\Tags\Tag;
use Tests\TestCase;

class TagHelperTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_current_locale_names(): void
    {
        app()->setLocale('ru');
        $tag = Tag::findOrCreate('вибратор');

        $this->assertSame(['вибратор'], TagHelper::names(collect([$tag])));
    }

    public function test_falls_back_to_fallback_locale_when_current_is_empty(): void
    {
        config()->set('app.fallback_locale', 'en');
        app()->setLocale('en');
        $tag = Tag::findOrCreate('lubricant');

        app()->setLocale('ru');

        $this->assertSame(['lubricant'], TagHelper::names(collect([$tag])));
    }

    public function test_filters_out_tags_with_no_translation(): void
    {
        config()->set('app.fallback_locale', 'en');
        app()->setLocale('en');
        $hasEn = Tag::findOrCreate('condom');
        $emptyTag = Tag::findOrCreate('temp');
        $emptyTag->setTranslation('name', 'en', '');
        $emptyTag->save();

        app()->setLocale('ru');

        $this->assertSame(['condom'], TagHelper::names(collect([$hasEn, $emptyTag])));
    }

    public function test_accepts_plain_iterable_and_preserves_order(): void
    {
        app()->setLocale('ru');
        $a = Tag::findOrCreate('a');
        $b = Tag::findOrCreate('b');
        $c = Tag::findOrCreate('c');

        $this->assertSame(['a', 'b', 'c'], TagHelper::names([$a, $b, $c]));
        $this->assertSame(['c', 'a'], TagHelper::names(Collection::make([$c, $a])));
    }

    public function test_explicit_locale_overrides_app_locale(): void
    {
        app()->setLocale('ru');
        $tag = new Tag;
        $tag->setTranslation('name', 'ru', 'русское');
        $tag->setTranslation('name', 'en', 'english');
        $tag->setTranslation('slug', 'ru', 'russkoe');
        $tag->setTranslation('slug', 'en', 'english');
        $tag->save();

        $this->assertSame(['english'], TagHelper::names(collect([$tag]), 'en'));
        $this->assertSame(['русское'], TagHelper::names(collect([$tag])));
    }
}
