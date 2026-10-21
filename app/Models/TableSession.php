<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TableSession extends Model
{
    use HasFactory;

    public const STATUS_OPEN = 'open';

    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'branch_id',
        'dining_table_id',
        'status',
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
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
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

    public function dueAmountCents(): int
    {
        $ordersTotalCents = self::moneyToCents((string) $this->orders()
            ->whereIn('status', [Order::STATUS_ACCEPTED, Order::STATUS_SERVED, Order::STATUS_PAID])
            ->sum('total'));

        $paidTotalCents = self::moneyToCents((string) $this->payments()->whereNull('voided_at')->sum('amount'));

        return max(0, $ordersTotalCents - $paidTotalCents);
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
