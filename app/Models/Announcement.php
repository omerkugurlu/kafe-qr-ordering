<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Announcement extends Model
{
    use HasFactory;

    public const TYPE_ANNOUNCEMENT = 'announcement';

    public const TYPE_EVENT = 'event';

    public const TYPE_CAMPAIGN = 'campaign';

    protected $fillable = [
        'cafe_id',
        'branch_id',
        'type',
        'title',
        'body',
        'starts_at',
        'ends_at',
        'is_active',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Announcement $announcement): void {
            if ($announcement->cafe_id === null && $announcement->branch_id !== null) {
                $announcement->cafe_id = Branch::query()->find($announcement->branch_id)?->cafe_id;
            }
        });
    }

    public function cafe(): BelongsTo
    {
        return $this->belongsTo(Cafe::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @param Builder<Announcement> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true)
            ->where(fn (Builder $query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn (Builder $query) => $query->whereNull('ends_at')->orWhere('ends_at', '>=', now()));
    }

    /** @param Builder<Announcement> $query */
    public function scopeForBranch(Builder $query, int $branchId): void
    {
        $query->where(fn (Builder $query) => $query->whereNull('branch_id')->orWhere('branch_id', $branchId));
    }

    public function typeLabel(): string
    {
        return match ($this->type) {
            self::TYPE_EVENT => 'Etkinlik',
            self::TYPE_CAMPAIGN => 'Kampanya',
            default => 'Duyuru',
        };
    }
}
