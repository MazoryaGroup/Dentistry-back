<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

class ServiceController extends Controller
{
    /**
     * Get all active services.
     */
    public function index(): JsonResponse
    {
        $services = Service::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->get([
                'id',
                'name',
                'description',
                'duration',
                'price',
                'deposit_amount',
                'image_1',
                'image_2',
            ])
            ->map(fn (Service $service) => $this->formatService($service));

        return response()->json([
            'status' => true,
            'statusCode' => 200,
            'message' => 'Services retrieved successfully.',
            'data' => $services,
        ], 200);
    }

    /**
     * Get single active service.
     */
    public function show(int $id): JsonResponse
    {
        $service = Service::query()
            ->where('is_active', true)
            ->where('id', $id)
            ->first([
                'id',
                'name',
                'description',
                'duration',
                'price',
                'deposit_amount',
                'image_1',
                'image_2',
            ]);

        if (!$service) {
            return response()->json([
                'status' => false,
                'statusCode' => 404,
                'message' => 'Service not found.',
                'data' => null,
            ], 404);
        }

        return response()->json([
            'status' => true,
            'statusCode' => 200,
            'message' => 'Service retrieved successfully.',
            'data' => $this->formatService($service),
        ], 200);
    }

    /**
     * Format service response.
     */
    private function formatService(Service $service): array
    {
        return [
            'id' => $service->id,
            'name' => $service->name,
            'description' => $service->description,
            'duration' => $service->duration,
            'price' => $service->price,
            'deposit_amount' => $service->deposit_amount,

            'image_1' => $service->image_1,
            'image_2' => $service->image_2,

            'image_1_url' => $service->image_1
                ? Storage::disk('api_public')->url($service->image_1)
                : null,

            'image_2_url' => $service->image_2
                ? Storage::disk('api_public')->url($service->image_2)
                : null,
        ];
    }
}
