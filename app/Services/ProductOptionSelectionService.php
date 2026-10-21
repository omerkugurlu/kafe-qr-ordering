<?php

namespace App\Services;

use App\Models\MenuOption;
use App\Models\MenuOptionGroup;
use App\Models\MenuProduct;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class ProductOptionSelectionService
{
    /**
     * Validate submitted choices against the locked product menu and return unit price additions
     * and immutable option snapshots indexed by product ID.
     *
     * @param  Collection<int, MenuProduct>  $products
     * @param  array<int|string, mixed>  $submitted
     * @return array<int, array{price_delta_cents: int, snapshots: array<int, array<string, mixed>>}>
     */
    public function resolve(Collection $products, array $submitted): array
    {
        $productIds = $products->keys()->map(fn ($id): int => (int) $id);
        foreach ($submitted as $productId => $groups) {
            if (! is_numeric($productId) || ! is_array($groups)) {
                $this->invalid('Ürün seçenekleri seçilen ürünlerle eşleşmiyor.');
            }

            // Browsers submit option fields even when their product quantity is zero.
            // Those selections cannot affect an order line and are safely ignored.
        }

        $groups = MenuOptionGroup::query()
            ->whereIn('menu_product_id', $productIds)
            ->where('is_active', true)
            ->orderBy('menu_product_id')
            ->orderBy('sort_order')
            ->lockForUpdate()
            ->get();
        $optionsByGroup = MenuOption::query()
            ->whereIn('menu_option_group_id', $groups->modelKeys())
            ->where('is_available', true)
            ->orderBy('menu_option_group_id')
            ->orderBy('sort_order')
            ->lockForUpdate()
            ->get()
            ->groupBy('menu_option_group_id');

        $groupsByProduct = $groups->groupBy('menu_product_id');
        $resolved = [];

        foreach ($products as $product) {
            $productSelections = $submitted[$product->id] ?? $submitted[(string) $product->id] ?? [];
            $activeGroups = $groupsByProduct->get($product->id, collect());
            $allowedGroupIds = $activeGroups->pluck('id')->map(fn ($id): int => (int) $id)->all();

            foreach ($productSelections as $groupId => $_selection) {
                if (! is_numeric($groupId) || ! in_array((int) $groupId, $allowedGroupIds, true)) {
                    $this->invalid('Menüde bulunmayan bir seçenek grubu gönderildi. Menüyü yenileyip tekrar deneyin.');
                }
            }

            $deltaCents = 0;
            $snapshots = [];
            foreach ($activeGroups as $group) {
                $rawSelection = $productSelections[$group->id] ?? $productSelections[(string) $group->id] ?? null;
                $selectedIds = $this->normalizeSelection($rawSelection, $group);
                $availableOptions = $optionsByGroup->get($group->id, collect())->keyBy('id');
                $maxSelections = $group->selection_mode === 'single'
                    ? 1
                    : ($group->max_selections ?? $availableOptions->count());

                if (count($selectedIds) < $group->min_selections || count($selectedIds) > $maxSelections) {
                    $this->invalid("{$group->name}: seçilen seçenek sayısını kontrol edin.");
                }

                foreach ($selectedIds as $optionId) {
                    /** @var MenuOption|null $option */
                    $option = $availableOptions->get($optionId);
                    if ($option === null) {
                        $this->invalid("{$group->name}: seçilen seçenek artık kullanılamıyor.");
                    }

                    $priceDeltaCents = self::moneyToCents($option->price_delta);
                    $deltaCents += $priceDeltaCents;
                    $snapshots[] = [
                        'menu_option_id' => $option->id,
                        'group_name_snapshot' => $group->name,
                        'name_snapshot' => $option->name,
                        'price_delta' => self::centsToMoney($priceDeltaCents),
                    ];
                }
            }

            $resolved[$product->id] = ['price_delta_cents' => $deltaCents, 'snapshots' => $snapshots];
        }

        return $resolved;
    }

    /** @return array<int, int> */
    private function normalizeSelection(mixed $selection, MenuOptionGroup $group): array
    {
        if ($selection === null || $selection === '') {
            return [];
        }

        if ($group->selection_mode === 'single') {
            if (is_array($selection)) {
                $selection = array_values(array_filter($selection, fn ($value): bool => $value !== null && $value !== ''));
            } else {
                $selection = [$selection];
            }
        } elseif (! is_array($selection)) {
            $this->invalid("{$group->name}: birden fazla seçenek seçin.");
        }

        $ids = [];
        foreach ($selection as $value) {
            if (! is_numeric($value) || (int) $value < 1) {
                $this->invalid("{$group->name}: geçerli bir seçenek seçin.");
            }
            $ids[] = (int) $value;
        }

        if (count($ids) !== count(array_unique($ids))) {
            $this->invalid("{$group->name}: aynı seçenek birden fazla seçilemez.");
        }

        return $ids;
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['options' => $message]);
    }

    private static function moneyToCents(string $amount): int
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '0');

        return ((int) $whole * 100) + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    }

    private static function centsToMoney(int $cents): string
    {
        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}
