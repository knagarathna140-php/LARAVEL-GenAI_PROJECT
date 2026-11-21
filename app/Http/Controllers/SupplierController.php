<?php

namespace App\Http\Controllers;

use App\Models\Supplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupplierController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $suppliers = Supplier::orderBy('name')->get();

        // Bank details are hidden on the model; only Purchasing gets them back.
        if ($request->user()->isPurchasing()) {
            $suppliers->each->makeVisible('bank_details');
        }

        return response()->json($suppliers);
    }
}
