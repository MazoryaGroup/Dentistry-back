<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TreatmentPlan;
use App\Models\TreatmentPlanItem;
use Illuminate\Http\JsonResponse;

class TreatmentPlanController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Find a Treatment Plan Owned by the Authenticated Patient
    |--------------------------------------------------------------------------
    */

    private function findOwnedPlan(int $id): ?TreatmentPlan
    {
        return TreatmentPlan::query()
            ->where('client_id', auth('api')->id())
            ->find($id);
    }

    /*
    |--------------------------------------------------------------------------
    | Treatment Plan Response Formatter
    |--------------------------------------------------------------------------
    */

    private function formatPlan(TreatmentPlan $plan): array
    {
        return [
            'id' => $plan->id,
            'title' => $plan->title,
            'diagnosis' => $plan->diagnosis,
            'description' => $plan->description,
            'status' => $plan->status,

            'doctor' => $plan->staff ? [
                'id' => $plan->staff->id,
                'name' => $plan->staff->name,
                'avatar' => $plan->staff->avatar,
            ] : null,

            'planned_start_date' =>
                $plan->planned_start_date?->format('Y-m-d'),

            'completed_at' =>
                $plan->completed_at?->toIso8601String(),

            'items_count' => $plan->items_count ?? 0,

            'created_at' =>
                $plan->created_at?->toIso8601String(),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | GET /api/v1/treatment-plans
    |--------------------------------------------------------------------------
    */

    public function index(): JsonResponse
    {
        $plans = TreatmentPlan::query()
            ->where('client_id', auth('api')->id())
            ->with('staff:id,name,avatar')
            ->withCount('items')
            ->latest('id')
            ->get();

        return response()->json([
            'success' => true,
            'statusCode' => 200,
            'message' => 'Treatment plans retrieved successfully.',
            'data' => $plans
                ->map(fn (TreatmentPlan $plan) =>
                $this->formatPlan($plan)
                )
                ->values(),
        ], 200);
    }

    /*
    |--------------------------------------------------------------------------
    | GET /api/v1/treatment-plans/{id}
    |--------------------------------------------------------------------------
    */

    public function show(int $id): JsonResponse
    {
        $plan = $this->findOwnedPlan($id);

        if (!$plan) {
            return $this->notFound();
        }

        $plan->load('staff:id,name,avatar');
        $plan->loadCount('items');

        return response()->json([
            'success' => true,
            'statusCode' => 200,
            'message' => 'Treatment plan retrieved successfully.',
            'data' => $this->formatPlan($plan),
        ], 200);
    }

    /*
    |--------------------------------------------------------------------------
    | GET /api/v1/treatment-plans/{id}/items
    |--------------------------------------------------------------------------
    */

    public function items(int $id): JsonResponse
    {
        $plan = $this->findOwnedPlan($id);

        if (!$plan) {
            return $this->notFound();
        }

        $plan->load('items.service:id,name');

        $items = $plan->items->map(
            function (TreatmentPlanItem $item): array {
                return [
                    'id' => $item->id,
                    'title' => $item->title,
                    'description' => $item->description,
                    'tooth_number' => $item->tooth_number,
                    'sort_order' => $item->sort_order,
                    'estimated_cost' => $item->estimated_cost,
                    'status' => $item->status,

                    'service' => $item->service ? [
                        'id' => $item->service->id,
                        'name' => $item->service->name,
                    ] : null,

                    'started_at' =>
                        $item->started_at?->toIso8601String(),

                    'completed_at' =>
                        $item->completed_at?->toIso8601String(),
                ];
            }
        )->values();

        return response()->json([
            'success' => true,
            'statusCode' => 200,
            'message' => 'Treatment plan items retrieved successfully.',
            'data' => $items,
        ], 200);
    }

    /*
    |--------------------------------------------------------------------------
    | GET /api/v1/treatment-plans/{id}/progress
    |--------------------------------------------------------------------------
    */

    public function progress(int $id): JsonResponse
    {
        $plan = $this->findOwnedPlan($id);

        if (!$plan) {
            return $this->notFound();
        }

        $items = $plan->items()
            ->get(['id', 'status']);

        $total = $items->count();

        $completed = $items
            ->where('status', 'completed')
            ->count();

        $inProgress = $items
            ->where('status', 'in_progress')
            ->count();

        $pending = $items
            ->where('status', 'pending')
            ->count();

        $cancelled = $items
            ->where('status', 'cancelled')
            ->count();

        // Cancelled items do not affect progress.
        $eligible = $total - $cancelled;

        $percentage = $eligible > 0
            ? round(($completed / $eligible) * 100, 2)
            : 0;

        return response()->json([
            'success' => true,
            'statusCode' => 200,
            'message' => 'Treatment progress retrieved successfully.',
            'data' => [
                'treatment_plan_id' => $plan->id,
                'status' => $plan->status,

                'total_items' => $total,
                'eligible_items' => $eligible,
                'completed_items' => $completed,
                'in_progress_items' => $inProgress,
                'pending_items' => $pending,
                'cancelled_items' => $cancelled,

                'progress_percentage' => $percentage,
            ],
        ], 200);
    }

    /*
    |--------------------------------------------------------------------------
    | Shared Not Found Response
    |--------------------------------------------------------------------------
    */

    private function notFound(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'statusCode' => 404,
            'message' => 'Treatment plan not found.',
        ], 404);
    }
}
