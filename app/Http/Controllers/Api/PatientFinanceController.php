<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Models\Booking;

class PatientFinanceController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Patient Payment History
    |--------------------------------------------------------------------------
    */

    public function payments(Request $request): JsonResponse
    {
        $clientId = auth('api')->id();

        $payments = Payment::query()
            ->where('client_id', $clientId)
            ->whereHas('booking', function ($query) use ($clientId) {
                $query->where('client_id', $clientId);
            })
            ->select([
                'id',
                'booking_id',
                'client_id',
                'amount',
                'type',
                'payment_method',
                'status',
                'gateway',
                'transaction_id',
                'reference_number',
                'paid_at',
                'created_at',
            ])
            ->orderByDesc('id')
            ->paginate(10);

        $items = $payments->getCollection()->map(function ($payment) {
            return [
                'id' => $payment->id,
                'booking_id' => $payment->booking_id,
                'amount' => (float) $payment->amount,
                'type' => $payment->type,
                'payment_method' => $payment->payment_method,
                'status' => $payment->status,
                'gateway' => $payment->gateway,
                'transaction_id' => $payment->transaction_id,
                'reference_number' => $payment->reference_number,
                'paid_at' => $payment->paid_at?->toIso8601String(),
                'created_at' => $payment->created_at?->toIso8601String(),
            ];
        })->values();

        return response()->json([
            'success' => true,
            'statusCode' => 200,
            'message' => 'Payment history retrieved successfully.',
            'data' => [
                'payments' => $items,
                'pagination' => [
                    'current_page' => $payments->currentPage(),
                    'per_page' => $payments->perPage(),
                    'total' => $payments->total(),
                    'last_page' => $payments->lastPage(),
                ],
            ],
        ]);
    }
    /*
|--------------------------------------------------------------------------
| Patient Payment Details
|--------------------------------------------------------------------------
*/

    public function paymentDetails(int $id): JsonResponse
    {
        $clientId = auth('api')->id();

        $payment = Payment::query()
            ->where('client_id', $clientId)
            ->whereHas('booking', function ($query) use ($clientId) {
                $query->where('client_id', $clientId);
            })
            ->with([
                'booking:id,client_id,booking_date,total_amount,deposit_amount,paid_amount,payment_status,status',
            ])
            ->find($id);

        if (!$payment) {
            return response()->json([
                'success' => false,
                'statusCode' => 404,
                'message' => 'Payment not found.',
            ], 404);
        }

        $booking = $payment->booking;

        return response()->json([
            'success' => true,
            'statusCode' => 200,
            'message' => 'Payment details retrieved successfully.',
            'data' => [
                'id' => $payment->id,
                'booking_id' => $payment->booking_id,
                'amount' => (float) $payment->amount,
                'type' => $payment->type,
                'payment_method' => $payment->payment_method,
                'status' => $payment->status,
                'gateway' => $payment->gateway,
                'transaction_id' => $payment->transaction_id,
                'reference_number' => $payment->reference_number,
                'paid_at' => $payment->paid_at?->toIso8601String(),
                'created_at' => $payment->created_at?->toIso8601String(),

                'booking' => $booking ? [
                    'id' => $booking->id,
                    'booking_date' => $booking->booking_date?->format('Y-m-d'),
                    'total_amount' => (float) $booking->total_amount,
                    'deposit_amount' => (float) $booking->deposit_amount,
                    'paid_amount' => (float) $booking->paid_amount,
                    'remaining_amount' => (float) $booking->remaining_amount,
                    'payment_status' => $booking->payment_status,
                    'status' => $booking->status,
                ] : null,
            ],
        ]);
    }
    /*
|--------------------------------------------------------------------------
| Patient Finance Summary
|--------------------------------------------------------------------------
*/

    public function summary(): JsonResponse
    {
        $clientId = auth('api')->id();

        // Booking هایی که در محاسبه بدهی لحاظ می‌شوند
        $bookings = Booking::query()
            ->where('client_id', $clientId)
            ->whereIn('status', [
                'awaiting_payment',
                'confirmed',
                'completed',
            ])
            ->select(['id', 'total_amount'])
            ->get();

        // تمام تراکنش‌های مالی معتبر بیمار، شامل استرداد رزروهای لغوشده
        $payments = Payment::query()
            ->where('client_id', $clientId)
            ->whereHas('booking', function ($query) use ($clientId) {
                $query->where('client_id', $clientId);
            })
            ->where(function ($query) {
                $query->where(function ($q) {
                    $q->whereIn('type', ['deposit', 'remaining'])
                        ->where('status', 'paid');
                })->orWhere(function ($q) {
                    $q->where('type', 'refund')
                        ->whereIn('status', ['paid', 'refunded']);
                });
            })
            ->get(['booking_id', 'amount', 'type', 'status']);

        $grossPaid = (float) $payments
            ->whereIn('type', ['deposit', 'remaining'])
            ->sum('amount');

        $totalRefunded = (float) $payments
            ->where('type', 'refund')
            ->sum('amount');

        $totalAmount = 0;
        $totalRemaining = 0;
        $unpaidBookings = 0;

        $paymentsByBooking = $payments->groupBy('booking_id');

        foreach ($bookings as $booking) {
            $total = (float) $booking->total_amount;

            $bookingPayments = $paymentsByBooking
                ->get($booking->id, collect());

            $paid = (float) $bookingPayments
                ->whereIn('type', ['deposit', 'remaining'])
                ->sum('amount');

            $refunded = (float) $bookingPayments
                ->where('type', 'refund')
                ->sum('amount');

            $netPaid = max(0, $paid - $refunded);

            $remaining = max(0, $total - $netPaid);

            $totalAmount += $total;
            $totalRemaining += $remaining;

            if ($remaining > 0) {
                $unpaidBookings++;
            }
        }

        return response()->json([
            'success' => true,
            'statusCode' => 200,
            'message' => 'Patient finance summary retrieved successfully.',
            'data' => [
                'currency' => 'IRT',

                'total_bookings' => $bookings->count(),

                'total_amount' => round($totalAmount, 2),

                'total_paid' => round($grossPaid, 2),

                'total_refunded' => round($totalRefunded, 2),

                'net_paid' => round(
                    max(0, $grossPaid - $totalRefunded),
                    2
                ),

                'total_remaining' => round($totalRemaining, 2),

                'unpaid_bookings' => $unpaidBookings,
            ],
        ]);
    }
    /*
|--------------------------------------------------------------------------
| Patient Invoices
|--------------------------------------------------------------------------
*/

    public function invoices(): JsonResponse
    {
        $clientId = auth('api')->id();

        $invoices = Booking::query()
            ->where('client_id', $clientId)
            ->whereIn('status', [
                'awaiting_payment',
                'confirmed',
                'completed',
            ])
            ->with([
                'payments' => function ($query) {
                    $query
                        ->select([
                            'id',
                            'booking_id',
                            'amount',
                            'type',
                            'status',
                        ])
                        ->where(function ($q) {
                            $q->where(function ($sub) {
                                $sub->whereIn('type', [
                                    'deposit',
                                    'remaining',
                                ])->where('status', 'paid');
                            })
                                ->orWhere(function ($sub) {
                                    $sub->where('type', 'refund')
                                        ->whereIn('status', [
                                            'paid',
                                            'refunded',
                                        ]);
                                });
                        });
                },
            ])
            ->orderByDesc('id')
            ->paginate(10);

        $items = $invoices->getCollection()
            ->map(function ($booking) {

                $total = (float) $booking->total_amount;

                $paid = (float) $booking->payments
                    ->whereIn('type', [
                        'deposit',
                        'remaining',
                    ])
                    ->sum('amount');

                $refunded = (float) $booking->payments
                    ->where('type', 'refund')
                    ->sum('amount');

                $netPaid = max(0, $paid - $refunded);

                $remaining = max(0, $total - $netPaid);

                return [
                    'id' => $booking->id,
                    'booking_id' => $booking->id,

                    'booking_date' =>
                        $booking->booking_date?->format('Y-m-d'),

                    'subtotal' =>
                        (float) $booking->subtotal,

                    'discount_amount' =>
                        (float) $booking->discount_amount,

                    'total_amount' => $total,

                    'deposit_amount' =>
                        (float) $booking->deposit_amount,

                    'paid_amount' => round($netPaid, 2),

                    'remaining_amount' => round($remaining, 2),

                    'status' => $remaining <= 0
                        ? 'paid'
                        : ($netPaid > 0 ? 'partially_paid' : 'unpaid'),

                    'booking_status' => $booking->status,

                    'created_at' =>
                        $booking->created_at?->toIso8601String(),
                ];
            })->values();

        return response()->json([
            'success' => true,
            'statusCode' => 200,
            'message' => 'Patient invoices retrieved successfully.',
            'data' => [
                'currency' => 'IRT',

                'invoices' => $items,

                'pagination' => [
                    'current_page' => $invoices->currentPage(),
                    'per_page' => $invoices->perPage(),
                    'total' => $invoices->total(),
                    'last_page' => $invoices->lastPage(),
                ],
            ],
        ]);
    }
    /*
|--------------------------------------------------------------------------
| Patient Invoice Details
|--------------------------------------------------------------------------
*/

    public function invoiceDetails(int $id): JsonResponse
    {
        $clientId = auth('api')->id();

        $booking = Booking::query()
            ->where('client_id', $clientId)
            ->whereIn('status', [
                'awaiting_payment',
                'confirmed',
                'completed',
            ])
            ->with([
                'bookingServices.service',
                'bookingServices.staff',
            ])
            ->find($id);

        if (!$booking) {
            return response()->json([
                'success' => false,
                'statusCode' => 404,
                'message' => 'Invoice not found.',
            ], 404);
        }

        /*
        |--------------------------------------------------------------------------
        | Calculate Financial Information
        |--------------------------------------------------------------------------
        */

        $payments = Payment::query()
            ->where('booking_id', $booking->id)
            ->where('client_id', $clientId)
            ->where(function ($query) {

                $query->where(function ($q) {
                    $q->whereIn('type', ['deposit', 'remaining'])
                        ->where('status', 'paid');
                })
                    ->orWhere(function ($q) {
                        $q->where('type', 'refund')
                            ->whereIn('status', ['paid', 'refunded']);
                    });

            })
            ->get(['amount', 'type', 'status']);

        $paid = (float) $payments
            ->whereIn('type', ['deposit', 'remaining'])
            ->sum('amount');

        $refunded = (float) $payments
            ->where('type', 'refund')
            ->sum('amount');

        $netPaid = max(0, $paid - $refunded);

        $total = (float) $booking->total_amount;

        $remaining = max(0, $total - $netPaid);

        /*
        |--------------------------------------------------------------------------
        | Invoice Services
        |--------------------------------------------------------------------------
        */

        $services = $booking->bookingServices
            ->map(function ($item) {

                return [
                    'id' => $item->id,

                    'service_id' => $item->service_id,

                    'service_name' =>
                        $item->service?->name,

                    'doctor' => [
                        'id' => $item->staff_id,
                        'name' => $item->staff?->name,
                    ],

                    'duration' =>
                        (int) $item->duration,

                    'price' =>
                        (float) $item->price,

                    'deposit_amount' =>
                        (float) $item->deposit_amount,

                    'start_time' => $item->start_time,

                    'end_time' => $item->end_time,
                ];
            })
            ->values();

        /*
        |--------------------------------------------------------------------------
        | Response
        |--------------------------------------------------------------------------
        */

        return response()->json([
            'success' => true,
            'statusCode' => 200,
            'message' => 'Invoice details retrieved successfully.',

            'data' => [
                'id' => $booking->id,
                'booking_id' => $booking->id,

                'currency' => 'IRT',

                'booking_date' =>
                    $booking->booking_date?->format('Y-m-d'),

                'start_time' => $booking->start_time,
                'end_time' => $booking->end_time,

                'services' => $services,

                'financial' => [
                    'subtotal' =>
                        (float) $booking->subtotal,

                    'discount_amount' =>
                        (float) $booking->discount_amount,

                    'total_amount' => $total,

                    'deposit_amount' =>
                        (float) $booking->deposit_amount,

                    'paid_amount' =>
                        round($netPaid, 2),

                    'refunded_amount' =>
                        round($refunded, 2),

                    'remaining_amount' =>
                        round($remaining, 2),
                ],

                'status' => $remaining <= 0
                    ? 'paid'
                    : ($netPaid > 0 ? 'partially_paid' : 'unpaid'),

                'booking_status' => $booking->status,

                'created_at' =>
                    $booking->created_at?->toIso8601String(),
            ],
        ]);
    }
    /*
|--------------------------------------------------------------------------
| Patient Payment Status
|--------------------------------------------------------------------------
*/

    public function paymentStatus(int $id): JsonResponse
    {
        $clientId = auth('api')->id();

        $payment = Payment::query()
            ->where('client_id', $clientId)
            ->whereHas('booking', function ($query) use ($clientId) {
                $query->where('client_id', $clientId);
            })
            ->find($id);

        if (!$payment) {
            return response()->json([
                'success' => false,
                'statusCode' => 404,
                'message' => 'Payment not found.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'statusCode' => 200,
            'message' => 'Payment status retrieved successfully.',
            'data' => [
                'payment_id' => $payment->id,
                'booking_id' => $payment->booking_id,

                'amount' => (float) $payment->amount,
                'type' => $payment->type,

                'status' => $payment->status,
                'is_paid' => $payment->status === 'paid',

                'payment_method' => $payment->payment_method,
                'gateway' => $payment->gateway,

                'transaction_id' => $payment->transaction_id,
                'paid_at' => $payment->paid_at?->toIso8601String(),
            ],
        ]);
    }
}
