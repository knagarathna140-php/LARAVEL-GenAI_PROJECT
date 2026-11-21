<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePurchaseOrderRequest;
use App\Services\PurchaseOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PurchaseOrderController extends Controller
{
    public function __construct(private PurchaseOrderService $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->service->search($request->user(), [
            'supplier' => $request->query('supplier'),
            'po_number' => $request->query('po_number'),
            'status' => $request->query('status'),
            'delayed_only' => $request->boolean('delayed_only'),
        ]));
    }

    public function show(Request $request, string $poNumber): JsonResponse
    {
        $po = $this->service->find($poNumber);

        return $po
            ? response()->json($this->service->present($po, $request->user()))
            : response()->json(['message' => 'PO not found.'], 404);
    }

    public function store(StorePurchaseOrderRequest $request): JsonResponse
    {
        $po = $this->service->createDraft(
            $request->user(),
            $request->validated('supplier_id'),
            $request->validated('lines'),
            (float) $request->validated('freight_cost', 0),
        );

        return response()->json($this->service->present($po, $request->user()), 201);
    }
}
