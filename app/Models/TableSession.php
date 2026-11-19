<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class TableSession extends Model
{
    use HasFactory;

    public const STATUS_OPEN = 'open';

    public const STATUS_CLOSED = 'closed';

    public const WAITER_CLAIM_TTL_SECONDS = 30;

    protected $fillable = [
        'branch_id',
        'dining_table_id',
        'status',
        'qr_ordering_enabled',
        'qr_visit_token_hash',
        'qr_visit_metadata',
        'opened_by',
        'closed_by',
        'opened_at',
        'closed_at',
        'closing_note',
    ];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'qr_ordering_enabled' => 'boolean',
            'qr_visit_metadata' => 'array',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function claimForWaiter(User $waiter): bool
    {
        if ($this->status !== self::STATUS_OPEN || $waiter->role !== User::ROLE_WAITER) {
            return false;
        }

        $claimed = DB::transaction(function () use ($waiter): bool {
            $session = self::query()->whereKey($this->id)->lockForUpdate()->first();
            if ($session === null || $session->status !== self::STATUS_OPEN) {
                return false;
            }

            $ownerId = (int) $session->opened_by;
            $ownerRole = $ownerId > 0 ? User::query()->whereKey($ownerId)->value('role') : null;
            $activeOwnerId = Cache::get($this->waiterClaimCacheKey());

            if (
                $ownerRole === User::ROLE_WAITER
                && $ownerId !== (int) $waiter->id
                && is_numeric($activeOwnerId)
                && (int) $activeOwnerId === $ownerId
            ) {
                return false;
            }

            if ($ownerId !== (int) $waiter->id) {
                $session->forceFill(['opened_by' => $waiter->id])->save();
            }

            Cache::put(
                $this->waiterClaimCacheKey(),
                (int) $waiter->id,
                now()->addSeconds(self::WAITER_CLAIM_TTL_SECONDS),
            );

            return true;
        });

        if ($claimed) {
            $this->opened_by = $waiter->id;
            $this->setRelation('openedBy', $waiter);
        }

        return $claimed;
    }

    public function activeWaiterOwnerId(): ?int
    {
        if ($this->status !== self::STATUS_OPEN) {
            return null;
        }

        $ownerId = Cache::get($this->waiterClaimCacheKey());

        return is_numeric($ownerId) && (int) $ownerId === (int) $this->opened_by
            ? (int) $ownerId
            : null;
    }

    private function waiterClaimCacheKey(): string
    {
        return 'table-session-waiter-claim:'.$this->getKey();
    }

    public function diningTable(): BelongsTo
    {
        return $this->belongsTo(DiningTable::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function paymentSplitGroups(): HasMany
    {
        return $this->hasMany(PaymentSplitGroup::class);
    }

    public function serviceRequests(): HasMany
    {
        return $this->hasMany(ServiceRequest::class);
    }

    public function dueAmountCents(): int
    {
        return max(0, $this->ordersTotalCents() - $this->paidTotalCents());
    }

    public function ordersTotalCents(): int
    {
        return self::moneyToCents((string) $this->orders()
            ->whereNotIn('status', [Order::STATUS_CANCELLED])
            ->sum('total'));
    }

    public function paidTotalCents(): int
    {
        return self::moneyToCents((string) $this->payments()->whereNull('voided_at')->sum('amount'));
    }

    public function activeOrdersCount(): int
    {
        return $this->orders()
            ->whereIn('status', [Order::STATUS_PENDING, Order::STATUS_ACCEPTED, Order::STATUS_READY])
            ->count();
    }

    public function dueAmount(): string
    {
        $cents = $this->dueAmountCents();

        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    private static function moneyToCents(string $amount): int
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '0');

        return ((int) $whole * 100) + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    }
}
