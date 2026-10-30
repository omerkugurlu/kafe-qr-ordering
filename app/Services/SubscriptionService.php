<?php

namespace App\Services;

use App\Models\Cafe;
use App\Models\CafeSubscription;
use App\Models\SubscriptionPlan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SubscriptionService
{
    public const FEATURES = [
        'customer_accounts' => 'Müşteri girişi ve müşteri kayıtları',
        'coupons' => 'Kupon sistemi',
        'loyalty' => 'Sadakat ve ödül sistemi',
        'reports' => 'Raporlar ve dışa aktarma',
        'announcements' => 'Şube duyuruları',
    ];

    public function current(Cafe $cafe): ?CafeSubscription
    {
        return $cafe->subscriptions()
            ->where('status', 'active')
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>', now())
            ->with('plan')
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->first();
    }

    public function hasHistory(Cafe $cafe): bool
    {
        return $cafe->subscriptions()->exists();
    }

    public function serviceEnabled(Cafe $cafe): bool
    {
        return $this->legacyUnrestricted($cafe)
            || $this->current($cafe) !== null
            || (! $this->hasHistory($cafe) && $this->defaultPlan() !== null);
    }

    public function requireServiceAccess(Cafe $cafe): void
    {
        if (! $this->serviceEnabled($cafe)) {
            throw ValidationException::withMessages([
                'subscription' => 'Paket süreniz dolmuş. Kafe sahibi paket ve abonelik sayfasından yenileme talebi gönderebilir.',
            ]);
        }
    }

    public function featureEnabled(Cafe $cafe, string $feature): bool
    {
        if (! array_key_exists($feature, self::FEATURES)) {
            return false;
        }

        if (! $this->hasHistory($cafe)) {
            if ($this->legacyUnrestricted($cafe)) {
                return true;
            }

            return (bool) ($this->defaultPlan()?->features_json[$feature] ?? false);
        }

        return (bool) ($this->current($cafe)?->features_snapshot_json[$feature] ?? false);
    }

    public function requireFeature(Cafe $cafe, string $feature): void
    {
        if (! $this->featureEnabled($cafe, $feature)) {
            throw ValidationException::withMessages([
                'subscription' => 'Bu özellik mevcut paketinizde bulunmuyor veya paket süreniz dolmuş. Paket ve abonelik sayfasını kontrol edin.',
            ]);
        }
    }

    public function requireCapacity(Cafe $cafe, string $kind): void
    {
        $hasHistory = $this->hasHistory($cafe);
        $subscription = $this->current($cafe);
        if (! $hasHistory && $this->legacyUnrestricted($cafe)) {
            return;
        }

        if ($subscription === null && $hasHistory) {
            throw ValidationException::withMessages([
                'subscription' => 'Paket süreniz dolmuş. Yeni kayıt oluşturmak için paketinizi yenileyin.',
            ]);
        }

        $plan = $subscription?->plan ?? (! $hasHistory ? $this->defaultPlan() : null);
        $maxTables = $subscription?->max_tables_snapshot ?? $plan?->max_tables;
        $maxBranches = $subscription?->max_branches_snapshot ?? $plan?->max_branches;
        if ($maxTables === null || $plan === null) {
            throw ValidationException::withMessages([
                'subscription' => 'Varsayılan paket şu anda kullanılamıyor. Lütfen daha sonra tekrar deneyin.',
            ]);
        }

        if ($kind === 'tables') {
            $limit = $maxTables;
            $count = DB::table('dining_tables')
                ->join('branches', 'branches.id', '=', 'dining_tables.branch_id')
                ->where('branches.cafe_id', $cafe->id)
                ->whereNull('branches.deleted_at')
                ->whereNull('dining_tables.deleted_at')
                ->count('dining_tables.id');
            $label = 'masa';
        } else {
            $limit = $maxBranches;
            if ($limit === null) {
                return;
            }
            $count = $cafe->branches()->whereNull('deleted_at')->count();
            $label = 'şube';
        }

        if ($limit !== null && $count >= $limit) {
            throw ValidationException::withMessages([
                'subscription' => "Paketiniz en fazla {$limit} {$label} içeriyor.",
            ]);
        }
    }

    private function legacyUnrestricted(Cafe $cafe): bool
    {
        return (bool) $cafe->subscription_legacy_unrestricted && ! $this->hasHistory($cafe);
    }

    private function defaultPlan(): ?SubscriptionPlan
    {
        return SubscriptionPlan::query()->where('code', 'giris')->where('is_active', true)->first();
    }

    public function ensureFits(Cafe $cafe, int $maxTables, ?int $maxBranches): void
    {
        $tables = DB::table('dining_tables')
            ->join('branches', 'branches.id', '=', 'dining_tables.branch_id')
            ->where('branches.cafe_id', $cafe->id)
            ->whereNull('branches.deleted_at')
            ->whereNull('dining_tables.deleted_at')
            ->count('dining_tables.id');
        $branches = $cafe->branches()->whereNull('deleted_at')->count();
        if ($tables > $maxTables || ($maxBranches !== null && $branches > $maxBranches)) {
            throw ValidationException::withMessages([
                'subscription' => 'Seçilen paket kafenin mevcut masa veya şube sayısını karşılamıyor.',
            ]);
        }
    }
}
