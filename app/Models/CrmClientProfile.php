<?php

namespace App\Models;

use App\Enums\Crm\BusinessType;
use App\Enums\Crm\ClientLifecycleStatus;
use App\Enums\Crm\ClientSentiment;
use App\Enums\Crm\ClientSpecialization;
use App\Enums\Crm\CreditRating;
use App\Enums\Crm\DeliveryMethod;
use App\Enums\Crm\NoveltyAttitude;
use App\Enums\Crm\PaymentBehavior;
use App\Enums\Crm\PaymentType;
use App\Enums\Crm\PointLocation;
use App\Enums\Crm\PreferredChannel;
use App\Enums\Crm\PriceSegment;
use App\Enums\Crm\Psychotype;
use App\Enums\Crm\SalesChannel;
use App\Enums\Crm\StaffLevel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Профиль партнёра в CRM — то, что менеджер знает о партнёре помимо данных 1С.
 *
 * @property int $id
 * @property int $user_id
 * @property string|null $decision_maker_name
 * @property string|null $decision_maker_role
 * @property string|null $decision_maker_contact
 * @property \Illuminate\Support\Carbon|null $decision_maker_birthday
 * @property string|null $decision_process
 * @property BusinessType|null $business_type
 * @property int|null $points_count
 * @property bool|null $has_offline_points
 * @property bool|null $has_online_store
 * @property bool|null $works_with_marketplaces
 * @property ClientSpecialization|null $specialization
 * @property SalesChannel|null $primary_channel
 * @property SalesChannel|null $secondary_channel
 * @property PointLocation|null $point_location
 * @property PriceSegment|null $price_segment
 * @property StaffLevel|null $staff_level
 * @property string|null $regions
 * @property DeliveryMethod|null $delivery_method
 * @property string|null $carrier
 * @property string|null $receiving_hours
 * @property string|null $packaging_notes
 * @property PaymentType|null $payment_type
 * @property int|null $deferral_days
 * @property CreditRating|null $credit_rating
 * @property string|null $commercial_terms
 * @property string|null $unique_terms
 * @property string|null $taboo_categories
 * @property string|null $taboo_brands
 * @property string|null $competitors
 * @property string|null $accountant_name
 * @property string|null $accountant_contact
 * @property string|null $owner_name
 * @property string|null $owner_contact
 * @property NoveltyAttitude|null $novelty_attitude
 * @property Psychotype|null $psychotype
 * @property string|null $marketing_needs
 * @property string|null $traffic_work
 * @property PaymentBehavior|null $payment_behavior
 * @property string|null $payment_terms
 * @property int|null $order_cycle_days
 * @property PreferredChannel|null $preferred_channel
 * @property ClientSentiment|null $sentiment
 * @property string|null $notes_md
 * @property \Illuminate\Support\Carbon|null $notes_updated_at
 * @property int|null $notes_updated_by
 * @property ClientLifecycleStatus $lifecycle_status
 * @property \Illuminate\Support\Carbon|null $lifecycle_changed_at
 * @property int|null $lifecycle_changed_by
 * @property ClientLifecycleStatus|null $lifecycle_hint
 * @property string|null $lifecycle_hint_reason
 * @property \Illuminate\Support\Carbon|null $lifecycle_hint_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read User $client
 * @property-read User|null $notesEditor
 * @property-read User|null $lifecycleEditor
 * @property-read \Illuminate\Database\Eloquent\Collection<int, CrmClientProfileRevision> $revisions
 */
class CrmClientProfile extends Model
{
    /** @use HasFactory<\Database\Factories\CrmClientProfileFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'decision_maker_name',
        'decision_maker_role',
        'decision_maker_contact',
        'decision_maker_birthday',
        'decision_process',
        'payment_behavior',
        'payment_terms',
        'order_cycle_days',
        'preferred_channel',
        'sentiment',
        // Паспорт партнёра: бизнес, логистика, условия, ограничения, контакты по ролям.
        'business_type',
        'points_count',
        'has_offline_points',
        'has_online_store',
        'works_with_marketplaces',
        'specialization',
        'primary_channel',
        'secondary_channel',
        'point_location',
        'price_segment',
        'staff_level',
        'regions',
        'delivery_method',
        'carrier',
        'receiving_hours',
        'packaging_notes',
        'payment_type',
        'deferral_days',
        'credit_rating',
        'commercial_terms',
        'unique_terms',
        'taboo_categories',
        'taboo_brands',
        'competitors',
        'accountant_name',
        'accountant_contact',
        'owner_name',
        'owner_contact',
        'novelty_attitude',
        'psychotype',
        'marketing_needs',
        'traffic_work',
        'notes_md',
        'notes_updated_at',
        'notes_updated_by',
        'lifecycle_status',
        'lifecycle_changed_at',
        'lifecycle_changed_by',
        'lifecycle_hint',
        'lifecycle_hint_reason',
        'lifecycle_hint_at',
    ];

    protected function casts(): array
    {
        return [
            'payment_behavior' => PaymentBehavior::class,
            'preferred_channel' => PreferredChannel::class,
            'sentiment' => ClientSentiment::class,
            'notes_updated_at' => 'datetime',
            'order_cycle_days' => 'integer',
            'lifecycle_status' => ClientLifecycleStatus::class,
            'lifecycle_hint' => ClientLifecycleStatus::class,
            'lifecycle_changed_at' => 'datetime',
            'lifecycle_hint_at' => 'datetime',
            'decision_maker_birthday' => 'date',
            'business_type' => BusinessType::class,
            'points_count' => 'integer',
            'has_offline_points' => 'boolean',
            'has_online_store' => 'boolean',
            'works_with_marketplaces' => 'boolean',
            'specialization' => ClientSpecialization::class,
            'primary_channel' => SalesChannel::class,
            'secondary_channel' => SalesChannel::class,
            'point_location' => PointLocation::class,
            'price_segment' => PriceSegment::class,
            'staff_level' => StaffLevel::class,
            'delivery_method' => DeliveryMethod::class,
            'payment_type' => PaymentType::class,
            'deferral_days' => 'integer',
            'credit_rating' => CreditRating::class,
            'novelty_attitude' => NoveltyAttitude::class,
            'psychotype' => Psychotype::class,
        ];
    }

    /**
     * Значения по умолчанию для незаписанного профиля.
     *
     * Карточка партнёра открывается и до первого сохранения, и статус там должен
     * читаться так же, как у сохранённого — иначе фронт получал бы null вместо enum.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'lifecycle_status' => 'active',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function notesEditor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'notes_updated_by');
    }

    public function lifecycleEditor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lifecycle_changed_by');
    }

    /**
     * @return HasMany<CrmClientProfileRevision, $this>
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(CrmClientProfileRevision::class);
    }
}
