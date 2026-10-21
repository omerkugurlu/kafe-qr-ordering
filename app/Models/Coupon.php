<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Coupon extends Model
{
    use HasFactory;

    public const TYPE_PERCENT = 'percent';

    public const TYPE_FIXED = 'fixed';

    protected $fillable = [
        'cafe_id',
        'code',
        'type',
        'value',
        'min_subtotal',
        'starts_at',
        'ends_at',
        'usage_limit',
        'used_count',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'min_subtotal' => 'decimal:2',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'usage_limit' => 'integer',
            'used_count' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function cafe(): BelongsTo
    {
        return $this->belongsTo(Cafe::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public static function normalizeCode(string $code): string
    {
        return Str::upper(trim($code));
    }

    public function isCurrentlyActive(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->starts_at !== null && $this->starts_at->isFuture()) {
            return false;
        }

        if ($this->ends_at !== null && $this->ends_at->isPast()) {
            return false;
        }

        return $this->usage_limit === null || $this->used_count < $this->usage_limit;
    }

    public function discountCentsFor(int $subtotalCents): int
    {
        if ($subtotalCents < self::moneyToCents((string) $this->min_subtotal)) {
            return 0;
        }

        $discount = $this->type === self::TYPE_PERCENT
            ? (int) floor($subtotalCents * ((float) $this->value) / 100)
            : self::moneyToCents((string) $this->value);

        return max(0, min($subtotalCents, $discount));
    }

    public function displayValue(): string
    {
        return $this->type === self::TYPE_PERCENT
            ? '%'.rtrim(rtrim(number_format((float) $this->value, 2, ',', '.'), '0'), ',').' indirim'
            : '₺'.number_format((float) $this->value, 2, ',', '.').' indirim';
    }

    /** @return array{valid: bool, message: string, discount_cents: int} */
    public static function evaluate(?self $coupon, int $subtotalCents): array
    {
        if ($coupon === null) {
            return ['valid' => false, 'message' => 'Kupon kodu bulunamadı.', 'discount_cents' => 0];
        }

        if (! $coupon->isCurrentlyActive()) {
            return ['valid' => false, 'message' => 'Bu kupon artık kullanılamıyor.', 'discount_cents' => 0];
        }

        if ($subtotalCents < self::moneyToCents((string) $coupon->min_subtotal)) {
            return [
                'valid' => false,
                'message' => 'Bu kupon için minimum sepet tutarı ₺'.number_format((float) $coupon->min_subtotal, 2, ',', '.').'.',
                'discount_cents' => 0,
            ];
        }

        return ['valid' => true, 'message' => 'Kupon uygulandı.', 'discount_cents' => $coupon->discountCentsFor($subtotalCents)];
    }

    private static function moneyToCents(string $amount): int
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '0');

        return ((int) $whole * 100) + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    }
}
