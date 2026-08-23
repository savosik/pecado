<?php

namespace App\Models;

use App\Enums\ContactSource;
use App\Enums\Crm\CrmScope;
use App\Enums\Crm\PreferredChannel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Человек: контактное лицо партнёра, контрагента, документа.
 *
 * Карточка одна на человека, а ролей у него сколько угодно — они живут
 * в `contact_links`. Бухгалтер двух юрлиц одного партнёра — это один `Contact`
 * и две привязки, поэтому смена телефона правится в одном месте.
 *
 * @property int $id
 * @property string $full_name
 * @property string|null $greeting_name
 * @property string|null $position
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $phone_digits
 * @property \Illuminate\Support\Carbon|null $birthday
 * @property bool $birthday_has_year
 * @property int|null $client_user_id
 * @property bool $is_active
 * @property ContactSource $source
 * @property \Illuminate\Support\Carbon|null $partner_touched_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, ContactLink> $links
 */
class Contact extends Model implements HasMedia
{
    /** @use HasFactory<\Database\Factories\ContactFactory> */
    use HasFactory, InteractsWithMedia, SoftDeletes;

    /** Коллекция аватара: одно фото на человека. */
    public const AVATAR_COLLECTION = 'avatar';

    /**
     * Уменьшенная копия под vCard.
     *
     * Фото уезжает в телефон внутри .vcf в base64, и оригинал раздул бы файл
     * до неимпортируемого размера.
     */
    public const AVATAR_VCARD_CONVERSION = 'vcard';

    protected $fillable = [
        'full_name',
        'greeting_name',
        'position',
        'email',
        'phone',
        'phone_extra',
        'telegram',
        'whatsapp',
        'instagram',
        'website',
        'preferred_channel',
        'birthday',
        'birthday_has_year',
        'client_user_id',
        'is_active',
        'marketing_consent',
        'source',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'birthday' => 'date',
            'birthday_has_year' => 'boolean',
            'is_active' => 'boolean',
            'marketing_consent' => 'boolean',
            'marketing_consent_at' => 'datetime',
            'unsubscribed_at' => 'datetime',
            'partner_touched_at' => 'datetime',
            'preferred_channel' => PreferredChannel::class,
            'source' => ContactSource::class,
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $contact): void {
            if (blank($contact->unsubscribe_token)) {
                $contact->unsubscribe_token = Str::random(64);
            }
        });

        static::saving(function (self $contact): void {
            // Адрес в нижний регистр: по нему ищут «чей это ящик», и Buh@ с buh@
            // не должны оказаться двумя разными людьми.
            if (filled($contact->email)) {
                $contact->email = mb_strtolower(trim($contact->email));
            }

            // Телефон храним как ввели, но рядом кладём голые цифры: поиск идёт
            // по ним, потому что LIKE по отформатированной строке индекс не берёт.
            $contact->phone_digits = self::digitsOf($contact->phone);
        });
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::AVATAR_COLLECTION)
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp'])
            ->singleFile();
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        // performOnCollections() первым, а не последним в цепочке: после width()
        // статический анализ теряет тип Conversion — та же ошибка, что в Product.
        $this->addMediaConversion(self::AVATAR_VCARD_CONVERSION)
            ->performOnCollections(self::AVATAR_COLLECTION)
            ->width(200)
            ->height(200)
            ->format('jpg')
            ->quality(80);
    }

    /**
     * Только цифры номера — то, по чему ищут и сверяют дубли.
     *
     * Российская восьмёрка приводится к семёрке: «8 912 …» и «+7 912 …» — один
     * и тот же номер, и люди пишут то так, то так. Без этого один человек
     * оказался бы в базе дважды, и слияние его бы не нашло.
     */
    public static function digitsOf(?string $phone): ?string
    {
        if (blank($phone)) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if ($digits === '') {
            return null;
        }

        if (mb_strlen($digits) === 11 && str_starts_with($digits, '8')) {
            $digits = '7'.mb_substr($digits, 1);
        }

        return mb_substr($digits, -20);
    }

    public function links(): HasMany
    {
        return $this->hasMany(ContactLink::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_user_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_id');
    }

    public function avatarUrl(): ?string
    {
        $url = $this->getFirstMediaUrl(self::AVATAR_COLLECTION);

        return $url === '' ? null : $url;
    }

    /**
     * Готов ли контакт получать письма.
     *
     * Отдельный вопрос от «активен»: человек может работать, но отписаться.
     */
    public function isDeliverable(): bool
    {
        return $this->is_active
            && filled($this->email)
            && $this->unsubscribed_at === null
            && $this->merged_into_id === null;
    }

    /**
     * Контакты, доступные сотруднику: те, чей партнёр ему виден.
     *
     * Человек без партнёра (водитель перевозчика) виден тому, кто видит всю базу,
     * и своему автору. Второе — не поблажка: без него менеджер, заведший карточку
     * из справочника и не указавший партнёра, терял бы её в тот же миг.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeVisibleInCrm(Builder $query, User $actor): Builder
    {
        return $query->where(function (Builder $inner) use ($actor) {
            $inner->whereIn('client_user_id', User::query()->visibleInCrm($actor)->select('id'));

            $inner->orWhere(fn (Builder $orphan) => $orphan
                ->whereNull('client_user_id')
                ->where(fn (Builder $who) => $who
                    ->when(
                        $actor->can('crm-clients-all.view'),
                        fn (Builder $all) => $all->whereNotNull('id'),
                        fn (Builder $mine) => $mine->where('created_by_user_id', $actor->getKey()),
                    )));
        });
    }

    /**
     * Разрез «мои / отдел» поверх границы видимости.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeScopedInCrm(Builder $query, User $actor, CrmScope $scope): Builder
    {
        return $query->where(function (Builder $inner) use ($actor, $scope) {
            $inner->whereIn('client_user_id', User::query()->inCrmScope($actor, $scope)->select('id'));

            $inner->orWhere(fn (Builder $orphan) => $orphan
                ->whereNull('client_user_id')
                ->where(fn (Builder $who) => $who
                    ->when(
                        $actor->can('crm-clients-all.view'),
                        fn (Builder $all) => $all->whereNotNull('id'),
                        fn (Builder $mine) => $mine->where('created_by_user_id', $actor->getKey()),
                    )));
        });
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->whereNull('merged_into_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeDeliverable(Builder $query): Builder
    {
        return $query->active()
            ->whereNotNull('email')
            ->whereNull('unsubscribed_at');
    }
}
