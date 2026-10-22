<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DentalRecord;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DentalRecordController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Patient Access
    |--------------------------------------------------------------------------
    */

    private function visibleRecords()
    {
        return DentalRecord::query()
            ->where('client_id', auth('api')->id())
            ->where('status', 'finalized')
            ->where('is_visible_to_patient', true);
    }

    private function findRecord(int $id): ?DentalRecord
    {
        return $this->visibleRecords()->find($id);
    }

    private function notFound(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'statusCode' => 404,
            'message' => 'Dental record not found.',
        ], 404);
    }

    /*
    |--------------------------------------------------------------------------
    | GET /api/v1/dental-records
    |--------------------------------------------------------------------------
    */

    public function index(): JsonResponse
    {
        $records = $this->visibleRecords()
            ->with('staff:id,name,avatar')
            ->withCount([
                'treatments',
                'teeth',
                'attachments as visible_attachments_count' =>
                    fn ($query) => $query
                        ->where('is_visible_to_patient', true),
            ])
            ->orderByDesc('examined_at')
            ->get();

        $data = $records->map(function (DentalRecord $record) {
            return [
                'id' => $record->id,
                'examined_at' =>
                    $record->examined_at?->toIso8601String(),

                'chief_complaint' => $record->chief_complaint,
                'diagnosis' => $record->diagnosis,
                'status' => $record->status,

                'doctor' => $record->staff ? [
                    'id' => $record->staff->id,
                    'name' => $record->staff->name,
                    'avatar' => $record->staff->avatar,
                ] : null,

                'treatments_count' =>
                    $record->treatments_count,

                'teeth_count' =>
                    $record->teeth_count,

                'attachments_count' =>
                    $record->visible_attachments_count,
            ];
        })->values();

        return response()->json([
            'success' => true,
            'statusCode' => 200,
            'message' => 'Dental records retrieved successfully.',
            'data' => $data,
        ], 200);
    }

    /*
    |--------------------------------------------------------------------------
    | GET /api/v1/dental-records/{id}
    |--------------------------------------------------------------------------
    */

    public function show(int $id): JsonResponse
    {
        $record = $this->findRecord($id);

        if (!$record) {
            return $this->notFound();
        }

        $record->load('staff:id,name,avatar');

        return response()->json([
            'success' => true,
            'statusCode' => 200,
            'message' => 'Dental record retrieved successfully.',
            'data' => [
                'id' => $record->id,
                'treatment_plan_id' =>
                    $record->treatment_plan_id,

                'examined_at' =>
                    $record->examined_at?->toIso8601String(),

                'chief_complaint' =>
                    $record->chief_complaint,

                'examination' =>
                    $record->examination,

                'diagnosis' =>
                    $record->diagnosis,

                'patient_notes' =>
                    $record->patient_notes,

                'status' =>
                    $record->status,

                'doctor' => $record->staff ? [
                    'id' => $record->staff->id,
                    'name' => $record->staff->name,
                    'avatar' => $record->staff->avatar,
                ] : null,

                'created_at' =>
                    $record->created_at?->toIso8601String(),
            ],
        ], 200);
    }

    /*
    |--------------------------------------------------------------------------
    | GET /api/v1/dental-records/{id}/treatments
    |--------------------------------------------------------------------------
    */

    public function treatments(int $id): JsonResponse
    {
        $record = $this->findRecord($id);

        if (!$record) {
            return $this->notFound();
        }

        $treatments = $record->treatments()
            ->with([
                'service:id,name',
                'staff:id,name',
            ])
            ->get()
            ->map(function ($treatment) {
                return [
                    'id' => $treatment->id,
                    'title' => $treatment->title,
                    'description' => $treatment->description,
                    'tooth_number' => $treatment->tooth_number,

                    'performed_at' =>
                        $treatment->performed_at?->toIso8601String(),

                    'service' => $treatment->service ? [
                        'id' => $treatment->service->id,
                        'name' => $treatment->service->name,
                    ] : null,

                    'doctor' => $treatment->staff ? [
                        'id' => $treatment->staff->id,
                        'name' => $treatment->staff->name,
                    ] : null,
                ];
            })
            ->values();

        return response()->json([
            'success' => true,
            'statusCode' => 200,
            'message' => 'Dental treatments retrieved successfully.',
            'data' => $treatments,
        ], 200);
    }

    /*
    |--------------------------------------------------------------------------
    | GET /api/v1/dental-records/{id}/odontogram
    |--------------------------------------------------------------------------
    */

    public function odontogram(int $id): JsonResponse
    {
        $record = $this->findRecord($id);

        if (!$record) {
            return $this->notFound();
        }

        $teeth = $record->teeth()
            ->get()
            ->map(function ($tooth) {
                return [
                    'id' => $tooth->id,
                    'tooth_number' => $tooth->tooth_number,
                    'tooth_status' => $tooth->tooth_status,
                    'surfaces' => $tooth->surfaces,
                    'notes' => $tooth->notes,
                ];
            })
            ->values();

        return response()->json([
            'success' => true,
            'statusCode' => 200,
            'message' => 'Odontogram retrieved successfully.',
            'data' => [
                'dental_record_id' => $record->id,
                'teeth_count' => $teeth->count(),
                'teeth' => $teeth,
            ],
        ], 200);
    }

    /*
    |--------------------------------------------------------------------------
    | GET /api/v1/dental-records/{id}/attachments
    |--------------------------------------------------------------------------
    */

    public function attachments(int $id): JsonResponse
    {
        $record = $this->findRecord($id);

        if (!$record) {
            return $this->notFound();
        }

        $attachments = $record->attachments()
            ->where('is_visible_to_patient', true)
            ->orderByDesc('id')
            ->get()
            ->map(function ($attachment) {
                return [
                    'id' => $attachment->id,
                    'title' => $attachment->title,
                    'attachment_type' =>
                        $attachment->attachment_type,

                    'original_name' =>
                        $attachment->original_name,

                    'mime_type' => $attachment->mime_type,
                    'file_size' => $attachment->file_size,

                    'created_at' =>
                        $attachment->created_at?->toIso8601String(),
                ];
            })
            ->values();

        return response()->json([
            'success' => true,
            'statusCode' => 200,
            'message' => 'Dental attachments retrieved successfully.',
            'data' => $attachments,
        ], 200);
    }

    /*
    |--------------------------------------------------------------------------
    | Download Private Medical Attachment
    |--------------------------------------------------------------------------
    */

    public function downloadAttachment(
        int $id,
        int $attachmentId
    ): JsonResponse|StreamedResponse {

        // Check patient ownership and record visibility.
        $record = $this->findRecord($id);

        if (!$record) {
            return $this->notFound();
        }

        // Only attachments visible to the patient.
        $attachment = $record->attachments()
            ->where('is_visible_to_patient', true)
            ->find($attachmentId);

        if (!$attachment) {
            return response()->json([
                'success' => false,
                'statusCode' => 404,
                'message' => 'Attachment not found.',
            ], 404);
        }

        // Medical files must be stored on the private local disk.
        $path = $attachment->file_path;

        if (
            !is_string($path) ||
            !str_starts_with($path, 'dental-records/') ||
            !Storage::disk('local')->exists($path)
        ) {
            return response()->json([
                'success' => false,
                'statusCode' => 404,
                'message' => 'Attachment file not found.',
            ], 404);
        }

        $filename = $attachment->original_name
            ?: basename($path);

        return Storage::disk('local')->download(
            $path,
            $filename,
            [
                'Content-Type' => 'application/octet-stream',
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ]
        );
    }
    public function viewAttachment(
        int $id,
        int $attachmentId
    ): JsonResponse|\Symfony\Component\HttpFoundation\StreamedResponse {

        $record = $this->findRecord($id);

        if (!$record) {
            return $this->notFound();
        }

        $attachment = $record->attachments()
            ->where('is_visible_to_patient', true)
            ->find($attachmentId);

        if (!$attachment) {
            return response()->json([
                'success' => false,
                'statusCode' => 404,
                'message' => 'Attachment not found.',
            ], 404);
        }

        $disk = Storage::disk('local');
        $path = $attachment->file_path;

        if (
            !is_string($path) ||
            !str_starts_with($path, 'dental-records/') ||
            str_contains($path, '..') ||
            !$disk->exists($path)
        ) {
            return response()->json([
                'success' => false,
                'statusCode' => 404,
                'message' => 'Attachment file not found.',
            ], 404);
        }

        $mimeType = $disk->mimeType($path);

        $allowedMimeTypes = [
            'image/jpeg',
            'image/png',
            'image/webp',
            'application/pdf',
        ];

        if (!in_array($mimeType, $allowedMimeTypes, true)) {
            return response()->json([
                'success' => false,
                'statusCode' => 415,
                'message' => 'Unsupported file type.',
            ], 415);
        }

        return $disk->response(
            $path,
            null,
            [
                'Content-Type' => $mimeType,
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ],
            'inline'
        );
    }


}
