<?php

namespace App\Services;

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PurchaseOrderService
{
    public function __construct(private InventoryService $inventory)
    {
    }

    /**
     * @param  array{supplier?:?string,status?:?string,po_number?:?string,delayed_only?:?bool}  $filters
     * @return array<int, array<string, mixed>>
     */
    public function search(User $user, array $filters): array
    {
        $query = PurchaseOrder::with(['supplier', 'lines.product'])->orderBy('eta');

        if (! empty($filters['supplier'])) {
            $like = '%'.mb_strtolower($filters['supplier']).'%';
            $query->whereHas('supplier', fn (Builder $s) => $s->whereRaw('LOWER(name) LIKE ?', [$like]));
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['po_number'])) {
            $query->where('po_number', strtoupper($filters['po_number']));
        }
        if (! empty($filters['delayed_only'])) {
            $query->whereIn('status', PurchaseOrder::OPEN_STATUSES)->whereDate('eta', '<', today());
        }

        return $query->limit(25)->get()->map(fn (PurchaseOrder $po) => $this->present($po, $user))->all();
    }

    public function find(string $poNumber): ?PurchaseOrder
    {
        return PurchaseOrder::with(['supplier', 'lines.product'])->where('po_number', strtoupper($poNumber))->first();
    }

    /** One place decides which PO details each role may see. */
    public function present(PurchaseOrder $po, User $user): array
    {
        $data = [
            'po_number' => $po->po_number,
            'supplier' => $po->supplier->name,
            'status' => $po->status,
            'order_date' => $po->order_date->toDateString(),
            'eta' => $po->eta?->toDateString(),
            'is_delayed' => $po->isDelayed(),
            'days_late' => $po->isDelayed() ? $po->eta->diffInDays(today()) : 0,
            'lines' => $po->lines->map(function (PurchaseOrderLine $line) use ($user) {
                $row = [
                    'sku' => $line->product->sku,
                    'product' => $line->product->name.' '.$line->product->thickness_cm.'cm',
                    'quantity' => $line->quantity,
                ];
                if ($user->isPurchasing()) {
                    $row['unit_cost'] = (float) $line->unit_cost;
                }

                return $row;
            })->all(),
        ];

        if ($user->isPurchasing()) {
            $data['freight_cost'] = (float) $po->freight_cost;
        }

        return $data;
    }

    /**
     * Landed cost per slab = unit cost + this line's share of PO freight.
     * Freight is split by quantity: every slab carries the same freight amount
     * (freight is driven by how many slabs are on the truck, not by their price).
     * Callers must have checked that the user is Purchasing.
     *
     * @return array<string, mixed>|null
     */
    public function landedCost(PurchaseOrder $po, ?string $sku = null): array
    {
        $totalQty = max(1, $po->lines->sum('quantity'));
        $freightPerSlab = round((float) $po->freight_cost / $totalQty, 2);

        $lines = $po->lines
            ->when($sku, fn ($c) => $c->filter(fn ($l) => strcasecmp($l->product->sku, $sku) === 0))
            ->map(fn (PurchaseOrderLine $l) => [
                'sku' => $l->product->sku,
                'product' => $l->product->name.' '.$l->product->thickness_cm.'cm',
                'quantity' => $l->quantity,
                'unit_cost' => (float) $l->unit_cost,
                'freight_per_slab' => $freightPerSlab,
                'landed_cost_per_slab' => round((float) $l->unit_cost + $freightPerSlab, 2),
            ])->values()->all();

        return [
            'po_number' => $po->po_number,
            'freight_cost' => (float) $po->freight_cost,
            'freight_split' => 'by quantity',
            'lines' => $lines,
        ];
    }

    /**
     * Validate a draft request and price it, WITHOUT saving anything.
     *
     * @param  array<int, array{product:string, thickness_cm?:?int, quantity:int}>  $lines
     * @return array{error?:string, draft?:array<string,mixed>}
     */
    public function prepareDraft(string $supplierName, array $lines): array
    {
        $suppliers = Supplier::whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($supplierName).'%'])->get();
        if ($suppliers->count() !== 1) {
            return ['error' => $suppliers->isEmpty()
                ? "No supplier matches '$supplierName'."
                : 'Supplier name is ambiguous: '.$suppliers->pluck('name')->implode(', ').'.'];
        }
        if ($lines === []) {
            return ['error' => 'At least one line is required.'];
        }

        $draftLines = [];
        foreach ($lines as $line) {
            $quantity = (int) ($line['quantity'] ?? 0);
            if ($quantity < 1) {
                return ['error' => 'Quantity must be at least 1.'];
            }
            $matches = $this->inventory->productQuery($line['product'] ?? '', isset($line['thickness_cm']) ? (int) $line['thickness_cm'] : null)->get();
            if ($matches->count() !== 1) {
                return ['error' => $matches->isEmpty()
                    ? "No product matches '{$line['product']}'."
                    : "'{$line['product']}' is ambiguous: ".$matches->map(fn ($p) => $p->name.' '.$p->thickness_cm.'cm ('.$p->sku.')')->implode(', ').'. Ask the user which one.'];
            }
            /** @var Product $product */
            $product = $matches->first();
            $lastCost = PurchaseOrderLine::where('product_id', $product->id)->latest('id')->value('unit_cost');
            $draftLines[] = [
                'product_id' => $product->id,
                'sku' => $product->sku,
                'product' => $product->name.' '.$product->thickness_cm.'cm',
                'quantity' => $quantity,
                'unit_cost' => (float) ($lastCost ?? 0),
            ];
        }

        return ['draft' => [
            'supplier_id' => $suppliers->first()->id,
            'supplier' => $suppliers->first()->name,
            'status' => 'draft',
            'lines' => $draftLines,
            'note' => 'Unit costs are the last price paid for each product; 0 means no price on file.',
        ]];
    }

    /**
     * Save a draft PO. Purchasing only.
     *
     * @param  array<int, array{product_id:int, quantity:int, unit_cost:float|int|string}>  $lines
     */
    public function createDraft(User $user, int $supplierId, array $lines, float $freightCost = 0): PurchaseOrder
    {
        if (! $user->isPurchasing()) {
            throw new InvalidArgumentException('Only Purchasing can create purchase orders.');
        }

        return DB::transaction(function () use ($supplierId, $lines, $freightCost) {
            $last = PurchaseOrder::max('po_number');
            $next = $last ? ((int) substr($last, 3)) + 1 : 1001;

            $po = PurchaseOrder::create([
                'po_number' => 'PO-'.$next,
                'supplier_id' => $supplierId,
                'status' => 'draft',
                'order_date' => today(),
                'freight_cost' => $freightCost,
            ]);
            foreach ($lines as $line) {
                $po->lines()->create([
                    'product_id' => $line['product_id'],
                    'quantity' => $line['quantity'],
                    'unit_cost' => $line['unit_cost'],
                ]);
            }

            return $po->load(['supplier', 'lines.product']);
        });
    }
}
