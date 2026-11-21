<?php

namespace App\Services\Assistant;

use App\Models\User;
use App\Services\InventoryService;
use App\Services\PurchaseOrderService;
use Illuminate\Support\Facades\Cache;

/**
 * The only things the AI can do. Each tool is a plain PHP function; the AI
 * never writes SQL. Role checks live HERE, not in the prompt, so a clever
 * question cannot get around them.
 */
class ToolRegistry
{
    public function __construct(
        private InventoryService $inventory,
        private PurchaseOrderService $purchaseOrders,
    ) {
    }

    /** @return array<int, array<string, mixed>> */
    public function definitions(): array
    {
        return [
            [
                'name' => 'checkStock',
                'description' => 'Get slabs on hand per warehouse. Use for any stock or availability question.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'product' => ['type' => 'string', 'description' => 'Design, color, material or SKU, e.g. "Calacatta"'],
                    'thickness_cm' => ['type' => 'integer', 'description' => 'Slab thickness in cm, e.g. 2 or 3'],
                    'warehouse' => ['type' => 'string', 'description' => 'Warehouse city, e.g. "Houston". Omit for all warehouses.'],
                ]],
            ],
            [
                'name' => 'searchPurchaseOrders',
                'description' => 'Find purchase orders by supplier, status, or PO number. Set delayed_only to list open POs that are past their ETA.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'supplier' => ['type' => 'string'],
                    'status' => ['type' => 'string', 'enum' => ['draft', 'ordered', 'in_transit', 'received']],
                    'po_number' => ['type' => 'string', 'description' => 'e.g. PO-1023'],
                    'delayed_only' => ['type' => 'boolean'],
                ]],
            ],
            [
                'name' => 'getLandedCost',
                'description' => 'Landed cost per slab (unit cost plus freight share) for a PO. PURCHASING ROLE ONLY.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'po_number' => ['type' => 'string'],
                    'sku' => ['type' => 'string', 'description' => 'Optional: only this product line'],
                ], 'required' => ['po_number']],
            ],
            [
                'name' => 'draftPurchaseOrder',
                'description' => 'Prepare a draft PO for the user to review. It is NOT saved until the user replies "confirm". PURCHASING ROLE ONLY.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'supplier' => ['type' => 'string'],
                    'lines' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                        'product' => ['type' => 'string'],
                        'thickness_cm' => ['type' => 'integer'],
                        'quantity' => ['type' => 'integer'],
                    ], 'required' => ['product', 'quantity']]],
                ], 'required' => ['supplier', 'lines']],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function execute(string $name, array $args, User $user, string $pendingKey): array
    {
        return match ($name) {
            'checkStock' => $this->checkStock($args),
            'searchPurchaseOrders' => ['purchase_orders' => $this->purchaseOrders->search($user, $args)],
            'getLandedCost' => $this->getLandedCost($args, $user),
            'draftPurchaseOrder' => $this->draftPurchaseOrder($args, $user, $pendingKey),
            default => ['error' => "Unknown tool '$name'."],
        };
    }

    private function checkStock(array $args): array
    {
        $rows = $this->inventory->stock(
            $args['product'] ?? null,
            isset($args['thickness_cm']) ? (int) $args['thickness_cm'] : null,
            $args['warehouse'] ?? null,
        );

        return $rows === [] ? ['stock' => [], 'message' => 'No matching stock records.'] : ['stock' => $rows];
    }

    private function getLandedCost(array $args, User $user): array
    {
        if (! $user->isPurchasing()) {
            return $this->forbidden('Cost details are only available to the Purchasing team.');
        }
        $po = $this->purchaseOrders->find($args['po_number'] ?? '');

        return $po ? $this->purchaseOrders->landedCost($po, $args['sku'] ?? null) : ['error' => 'PO not found.'];
    }

    private function draftPurchaseOrder(array $args, User $user, string $pendingKey): array
    {
        if (! $user->isPurchasing()) {
            return $this->forbidden('Only the Purchasing team can create purchase orders.');
        }
        $result = $this->purchaseOrders->prepareDraft($args['supplier'] ?? '', $args['lines'] ?? []);
        if (isset($result['error'])) {
            return $result;
        }

        // Held in cache until the user confirms. Nothing is written to the database yet.
        Cache::put($pendingKey, $result['draft'], now()->addMinutes(30));

        return ['draft' => $result['draft'], 'status' => 'NOT SAVED. Show this draft and ask the user to reply "confirm" to save it.'];
    }

    private function forbidden(string $message): array
    {
        return ['error' => 'forbidden', 'message' => $message];
    }
}
