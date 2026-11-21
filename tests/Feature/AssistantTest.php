<?php

namespace Tests\Feature;

use App\Models\AiInteraction;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\Ai\AiProvider;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Uses a scripted fake AI so tests need no network or API key. */
class AssistantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    /** Fake model: first turn calls the given tool, second turn echoes the tool result. */
    private function fakeAi(string $tool, array $args): void
    {
        $this->app->instance(AiProvider::class, new class($tool, $args) implements AiProvider {
            public function __construct(private string $tool, private array $args) {}

            public function chat(string $system, array $messages, array $tools): array
            {
                $last = end($messages);
                if ($last['role'] === 'tool') {
                    return ['text' => $last['content'], 'tool_calls' => []];
                }

                return ['text' => '', 'tool_calls' => [['id' => 't1', 'name' => $this->tool, 'args' => $this->args]]];
            }
        });
    }

    public function test_stock_comes_from_the_tool(): void
    {
        Sanctum::actingAs(User::where('role', 'sales')->first());
        $this->fakeAi('checkStock', ['product' => 'Calacatta', 'thickness_cm' => 3, 'warehouse' => 'Houston']);

        $reply = $this->postJson('/api/assistant', ['message' => 'Calacatta 3cm in Houston?'])->assertOk()->json('reply');

        $this->assertStringContainsString('"slabs_on_hand":42', $reply);
    }

    public function test_sales_is_blocked_from_landed_cost(): void
    {
        Sanctum::actingAs(User::where('role', 'sales')->first());
        $this->fakeAi('getLandedCost', ['po_number' => 'PO-1023']);

        $reply = $this->postJson('/api/assistant', ['message' => 'landed cost PO-1023'])->json('reply');

        $this->assertStringContainsString('forbidden', $reply);
        $this->assertStringNotContainsString('landed_cost_per_slab', $reply);
    }

    public function test_purchasing_gets_landed_cost(): void
    {
        Sanctum::actingAs(User::where('role', 'purchasing')->first());
        $this->fakeAi('getLandedCost', ['po_number' => 'PO-1023']);

        $reply = $this->postJson('/api/assistant', ['message' => 'landed cost PO-1023'])->json('reply');

        $this->assertStringContainsString('landed_cost_per_slab', $reply);
    }

    public function test_draft_is_saved_only_after_confirm(): void
    {
        Sanctum::actingAs(User::where('role', 'purchasing')->first());
        $this->fakeAi('draftPurchaseOrder', ['supplier' => 'Supplier Y', 'lines' => [['product' => 'Carrara', 'thickness_cm' => 2, 'quantity' => 20]]]);
        $before = PurchaseOrder::count();

        $id = $this->postJson('/api/assistant', ['message' => 'draft 20 2cm Carrara from Supplier Y'])->json('conversation_id');
        $this->assertSame($before, PurchaseOrder::count());

        $this->postJson('/api/assistant', ['message' => 'confirm', 'conversation_id' => $id])->assertOk();
        $this->assertSame($before + 1, PurchaseOrder::count());
        $this->assertSame('draft', PurchaseOrder::latest('id')->first()->status);
    }

    public function test_sales_cannot_see_po_costs_or_create_purchase_orders(): void
    {
        Sanctum::actingAs(User::where('role', 'sales')->first());

        $this->getJson('/api/purchase-orders/PO-1023')
            ->assertOk()
            ->assertJsonMissingPath('freight_cost')
            ->assertJsonMissingPath('lines.0.unit_cost');
        $this->postJson('/api/purchase-orders', [])->assertForbidden();
    }

    public function test_assistant_interaction_is_logged(): void
    {
        Sanctum::actingAs(User::where('role', 'sales')->first());
        $this->fakeAi('checkStock', ['product' => 'Calacatta', 'thickness_cm' => 3, 'warehouse' => 'Houston']);

        $this->postJson('/api/assistant', ['message' => 'Calacatta 3cm in Houston?'])->assertOk();

        $log = AiInteraction::with('user')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame('Calacatta 3cm in Houston?', $log->question);
        $this->assertSame('checkStock', $log->tool_calls[0]['name']);
        $this->assertGreaterThanOrEqual(0, $log->response_time_ms);
    }

    public function test_sales_can_see_supplier_payment_terms_but_not_bank_details(): void
    {
        Sanctum::actingAs(User::where('role', 'sales')->first());

        $this->getJson('/api/suppliers')
            ->assertOk()
            ->assertJsonFragment(['payment_terms' => 'Net 30'])
            ->assertJsonMissing(['bank_details']);
    }
}
