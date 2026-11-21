<?php

namespace App\Http\Controllers;

use App\Services\InventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StockController extends Controller
{
    public function index(Request $request, InventoryService $inventory): JsonResponse
    {
        $thickness = $request->query('thickness_cm');

        return response()->json($inventory->stock(
            $request->query('product'),
            $thickness !== null ? (int) $thickness : null,
            $request->query('warehouse'),
        ));
    }
}
