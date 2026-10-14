<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ServiceController extends Controller
{
    /**
     * List active services.
     */
    public function index(Request $request): JsonResponse
    {
        $locale = $this->resolveLocale($request);

        $services = Service::query()
            ->with('translations')
            ->where('is_active', true)
            ->orderBy('id')
            ->get()
            ->map(
                fn (Service $service) =>
                $this->formatService($service, $locale)
            );

        return response()->json([
            'status' => true,
            'statusCode' => 200,
            'message' => 'Services retrieved successfully.',
            'data' => $services,
        ]);
    }

    /**
     * Show a single active service.
     */
    public function show(
        Request $request,
        int $id
    ): JsonResponse {
        $locale = $this->resolveLocale($request);

        $service = Service::query()
            ->with('translations')
            ->where('is_active', true)
            ->find($id);

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
            'data' => $this->formatService(
                $service,
                $locale
            ),
        ]);
    }

    /**
     * Resolve requested language.
     */
    private function resolveLocale(Request $request): string
    {
        $locale = $request->query('lang', 'fa');

        return in_array($locale, ['fa', 'en'], true)
            ? $locale
            : 'fa';
    }

    /**
     * Format localized service.
     */
    private function formatService(
        Service $service,
        string $locale
    ): array {
        $translation = $service->translations
            ->firstWhere('locale', $locale);

        $persian = $service->translations
            ->firstWhere('locale', 'fa');

        // Fallback to Persian for missing translations.
        $localized = function (
            string $field,
                   $legacy = null
        ) use ($translation, $persian) {
            $values = [
                $translation?->{$field},
                $persian?->{$field},
                $legacy,
            ];

            foreach ($values as $value) {
                if (
                    $value !== null &&
                    trim((string) $value) !== ''
                ) {
                    return $value;
                }
            }

            return null;
        };

        $title1 = $localized('title_1', $service->name);

        $description1 = $localized(
            'description_1',
            $service->description
        );

        return [
            'id' => $service->id,
            'locale' => $locale,

            // Compatibility with old API
            'name' => $title1,
            'description' => $description1,

            // Section 1
            'title_1' => $title1,
            'description_1' => $description1,

            'image_1_url' => $service->image_1
                ? Storage::disk('api_public')
                    ->url($service->image_1)
                : null,

            // Section 2
            'title_2' => $localized(
                'title_2',
                $service->title_2
            ),

            'description_2' => $localized(
                'description_2',
                $service->description_2
            ),

            'image_2_url' => $service->image_2
                ? Storage::disk('api_public')
                    ->url($service->image_2)
                : null,

            // Shared booking information
            'duration' => $service->duration,
            'price' => $service->price,
            'deposit_amount' => $service->deposit_amount,
        ];
    }
}
