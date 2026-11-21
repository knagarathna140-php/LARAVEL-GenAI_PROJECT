<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Stock;
use Illuminate\Database\Eloquent\Builder;

class InventoryService
{
    /** Case-insensitive match on name, color, material or SKU. */
    public function productQuery(?string $text, ?int $thicknessCm = null): Builder
    {
        $query = Product::query();

        if ($text !== null && trim($text) !== '') {
            $like = '%'.mb_strtolower(trim($text)).'%';
            $query->where(fn (Builder $q) => $q
                ->whereRaw('LOWER(name) LIKE ?', [$like])
                ->orWhereRaw('LOWER(color) LIKE ?', [$like])
                ->orWhereRaw('LOWER(material) LIKE ?', [$like])
                ->orWhereRaw('LOWER(sku) LIKE ?', [$like]));
        }
        if ($thicknessCm !== null) {
            $query->where('thickness_cm', $thicknessCm);
        }

        return $query;
    }

    /**
     * Stock per product and warehouse. Stock is visible to every role.
     *
     * @return array<int, array<string, mixed>>
     */
    public function stock(?string $product, ?int $thicknessCm = null, ?string $warehouse = null): array
    {
        $query = Stock::with(['product', 'warehouse'])
            ->whereIn('product_id', $this->productQuery($product, $thicknessCm)->select('id'));

        if ($warehouse !== null && trim($warehouse) !== '') {
            $like = '%'.mb_strtolower(trim($warehouse)).'%';
            $query->whereHas('warehouse', fn (Builder $w) => $w
                ->whereRaw('LOWER(city) LIKE ?', [$like])
                ->orWhereRaw('LOWER(name) LIKE ?', [$like]));
        }

        return $query->get()
            ->sortBy(fn (Stock $s) => $s->product->sku.$s->warehouse->city)
            ->map(fn (Stock $s) => [
                'sku' => $s->product->sku,
                'product' => $s->product->name,
                'thickness_cm' => $s->product->thickness_cm,
                'warehouse' => $s->warehouse->city,
                'slabs_on_hand' => $s->slabs_on_hand,
            ])
            ->values()
            ->all();
    }
}
