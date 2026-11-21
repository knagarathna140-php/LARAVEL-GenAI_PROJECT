<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Stock;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/** Sample data only. Everything is deterministic so demos are repeatable. */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::create(['name' => 'Sam Sales', 'email' => 'sales@example.com', 'password' => 'password', 'role' => 'sales']);
        User::create(['name' => 'Pat Purchasing', 'email' => 'purchasing@example.com', 'password' => 'password', 'role' => 'purchasing']);

        $warehouses = collect([['Houston Warehouse', 'Houston'], ['Dallas Warehouse', 'Dallas'], ['Phoenix Warehouse', 'Phoenix']])
            ->map(fn ($w) => Warehouse::create(['name' => $w[0], 'city' => $w[1]]));

        $suppliers = collect([
            ['Supplier X', 'Maria Lopez', 'Net 30'],
            ['Supplier Y', 'Chen Wei', 'Net 45'],
            ['Marmi Italia', 'Luca Rossi', 'Net 60'],
            ['Anatolia Stone Co', 'Ayse Demir', 'Net 30'],
            ['Brazil Quartz Ltd', 'Joao Silva', 'Prepaid'],
        ])->map(fn ($s, $i) => Supplier::create([
            'name' => $s[0], 'contact' => $s[1], 'payment_terms' => $s[2],
            'bank_details' => 'IBAN SAMPLE-000'.($i + 1).' / SWIFT SMPL'.$i,
        ]));

        // 12 designs x 2 thicknesses = 24 products.
        $designs = [
            ['Calacatta', 'Marble', 'White'], ['Carrara', 'Marble', 'White'], ['Statuario', 'Marble', 'White'],
            ['Nero Marquina', 'Marble', 'Black'], ['Emperador', 'Marble', 'Brown'], ['Taj Mahal', 'Quartzite', 'Cream'],
            ['Pure White Quartz', 'Quartz', 'White'], ['Calacatta Quartz', 'Quartz', 'White'],
            ['Concrete Grey Quartz', 'Quartz', 'Grey'], ['Midnight Black Quartz', 'Quartz', 'Black'],
            ['Sandstone Beige Quartz', 'Quartz', 'Beige'], ['Blue Ice Quartz', 'Quartz', 'Blue'],
        ];
        $products = collect();
        $usedSkuBases = [];
        foreach ($designs as $d => [$name, $material, $color]) {
            $skuBase = strtoupper(Str::substr(Str::slug($name, ''), 0, 6));
            if (isset($usedSkuBases[$skuBase])) {
                $skuBase .= $d;
            }
            $usedSkuBases[$skuBase] = true;

            foreach ([2, 3] as $t) {
                $products->push(Product::create([
                    'sku' => $skuBase.'-'.$t.'CM',
                    'name' => $name, 'material' => $material, 'color' => $color,
                    'thickness_cm' => $t, 'slab_size' => $material === 'Quartz' ? '126x63 in' : '120x75 in',
                ]));
            }
        }

        foreach ($products as $i => $product) {
            foreach ($warehouses as $w => $warehouse) {
                Stock::create([
                    'product_id' => $product->id, 'warehouse_id' => $warehouse->id,
                    'slabs_on_hand' => (($i + 3) * ($w + 5) * 7) % 60,
                ]);
            }
        }
        // Fixed numbers that match the example conversation.
        $calacatta3 = Product::where('sku', 'CALACA-3CM')->first();
        Stock::where('product_id', $calacatta3->id)->where('warehouse_id', $warehouses[0]->id)->update(['slabs_on_hand' => 42]);
        Stock::where('product_id', $calacatta3->id)->where('warehouse_id', $warehouses[1]->id)->update(['slabs_on_hand' => 18]);

        // 15 POs: PO-1010 .. PO-1024. Several are open and past their ETA.
        $statuses = ['received', 'in_transit', 'ordered', 'in_transit', 'ordered'];
        for ($n = 0; $n < 15; $n++) {
            $status = $statuses[$n % 5];
            $orderDate = today()->subDays(60 - $n * 3);
            $eta = match ($status) {
                'received' => $orderDate->copy()->addDays(30),
                // every other open PO is late
                default => $n % 2 === 0 ? today()->subDays(3 + $n) : today()->addDays(5 + $n),
            };
            $po = PurchaseOrder::create([
                'po_number' => 'PO-'.(1010 + $n),
                'supplier_id' => $suppliers[($n + intdiv($n, 5)) % 5]->id,
                'status' => $status,
                'order_date' => $orderDate,
                'eta' => $eta,
                'freight_cost' => 800 + ($n * 175),
            ]);
            for ($l = 0; $l < 2 + $n % 3; $l++) {
                $product = $products[($n * 3 + $l * 5) % $products->count()];
                $po->lines()->create([
                    'product_id' => $product->id,
                    'quantity' => 10 + (($n + $l) * 5) % 40,
                    'unit_cost' => ($product->material === 'Quartz' ? 210 : 340) + $product->thickness_cm * 25 + $l * 10,
                ]);
            }
        }
    }
}
