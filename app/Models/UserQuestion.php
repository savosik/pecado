<?php

namespace App\Models;

use App\Enums\UserQuestionStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * @property int $id
 * @property int|null $user_id
 * @property string|null $name
 * @property string $email
 * @property string $subject
 * @property string $body
 * @property UserQuestionStatus $status
 * @property string|null $answer
 * @property \Illuminate\Support\Carbon|null $answered_at
 * @property int|null $answered_by_user_id
 * @property string|null $rejected_reason
 * @property string|null $ip
 * @property string|null $user_agent
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read User|null $user
 * @property-read User|null $answeredBy
 */
class UserQuestion extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia;

    protected $fillable = [
        'user_id',
        'name',
        'email',
        'subject',
        'body',
        'status',
        'answer',
        'answered_at',
        'answered_by_user_id',
        'rejected_reason',
        'ip',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'status' => UserQuestionStatus::class,
            'answered_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function answeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'answered_by_user_id');
    }

    public function scopeForUser(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->id);
    }

    public function scopeWithStatus(Builder $query, UserQuestionStatus|string $status): Builder
    {
        return $query->where('status', $status instanceof UserQuestionStatus ? $status->value : $status);
    }

    public function markInProgress(User $manager): void
    {
        if ($this->status !== UserQuestionStatus::NEW) {
            return;
        }

        $this->update([
            'status' => UserQuestionStatus::IN_PROGRESS,
            'answered_by_user_id' => $manager->id,
        ]);
    }

    public function markAnswered(User $manager, string $answer): void
    {
        $this->update([
            'status' => UserQuestionStatus::ANSWERED,
            'answer' => $answer,
            'answered_at' => now(),
            'answered_by_user_id' => $manager->id,
        ]);
    }

    public function markRejected(User $manager, ?string $reason): void
    {
        $this->update([
            'status' => UserQuestionStatus::REJECTED,
            'rejected_reason' => $reason,
            'answered_by_user_id' => $manager->id,
        ]);
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('attachment')
            ->acceptsMimeTypes([
                'image/jpeg',
                'image/png',
                'image/webp',
                'image/gif',
                'application/pdf',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'text/plain',
            ])
            ->singleFile();
    }
}
