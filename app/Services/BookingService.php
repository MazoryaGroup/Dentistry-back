<?php

namespace App\Services;

use App\Models\AccountingAuditLog;
use App\Models\Booking;
use App\Models\BookingHold;
use App\Models\BookingService as BookingServiceModel;
use App\Models\DiscountCode;
use App\Models\DiscountUsage;
use App\Models\Payment;
use App\Models\Service;
use App\Models\Staff;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Morilog\Jalali\Jalalian;

class BookingService
{
    private const RELATIONS = [
        'bookingServices.service',
        'bookingServices.staff',
        'payments',
    ];

    /*
    |--------------------------------------------------------------------------
    | Create Booking
    |--------------------------------------------------------------------------
    */

    public function createBooking(
        int $clientId,
        string $date,
        array $services,
        ?string $notes = null,
        ?string $discountCode = null,
        bool $useReferralReward = false
    ): Booking {
        return DB::transaction(function () use (
            $clientId,
            $date,
            $services,
            $notes,
            $discountCode,
            $useReferralReward
        ) {
            if (empty($services)) {
                throw ValidationException::withMessages([
                    'services' => 'At least one service is required.',
                ]);
            }

            if (
                $discountCode !== null &&
                trim($discountCode) !== '' &&
                $useReferralReward
            ) {
                throw ValidationException::withMessages([
                    'discount' =>
                        'Discount code and referral reward cannot be used together.',
                ]);
            }

            // Serialize competing bookings.
            $this->lockClient($clientId);

            $this->lockStaff(
                array_column($services, 'staff_id')
            );

            $availability = app(
                BookingAvailabilityService::class
            );

            $items = [];
            $firstStart = null;
            $lastEnd = null;

            $subtotal = 0.0;
            $depositAmount = 0.0;

            /*
            |--------------------------------------------------------------------------
            | Validate Services
            |--------------------------------------------------------------------------
            */

            foreach ($services as $item) {
                $serviceId = (int) (
                    $item['service_id'] ?? 0
                );

                $staffId = (int) (
                    $item['staff_id'] ?? 0
                );

                $startTime = $item['start_time'] ?? null;

                if (
                    !$serviceId ||
                    !$staffId ||
                    !is_string($startTime)
                ) {
                    throw ValidationException::withMessages([
                        'services' =>
                            'Each service must have service_id, staff_id and start_time.',
                    ]);
                }

                $service = Service::query()
                    ->whereKey($serviceId)
                    ->where('is_active', true)
                    ->first();

                $staff = Staff::query()
                    ->whereKey($staffId)
                    ->where('is_active', true)
                    ->first();

                if (!$service || !$staff) {
                    throw ValidationException::withMessages([
                        'services' =>
                            'The selected service or staff member is unavailable.',
                    ]);
                }

                $staffService = $staff->staffServices()
                    ->where('service_id', $serviceId)
                    ->where('is_active', true)
                    ->first();

                if (!$staffService) {
                    throw ValidationException::withMessages([
                        'services' =>
                            "Staff {$staffId} cannot perform service {$serviceId}.",
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | Pricing
                |--------------------------------------------------------------------------
                | Keep current NIL pricing behaviour.
                */

                $duration = (int) $service->duration;
                $price = (float) $service->price;
                $deposit = (float) $service->deposit_amount;

                if ($duration <= 0) {
                    throw ValidationException::withMessages([
                        'services' =>
                            "Invalid duration for service {$serviceId}.",
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | Date and Time
                |--------------------------------------------------------------------------
                */

                $start = $this->strictDateTime(
                    $date,
                    $startTime,
                    'services'
                );

                $end = $start->copy()
                    ->addMinutes($duration);

                if ($end->format('Y-m-d') !== $date) {
                    throw ValidationException::withMessages([
                        'services' =>
                            'A service cannot extend into the next day.',
                    ]);
                }

                if ($start->lte(now())) {
                    throw ValidationException::withMessages([
                        'services' =>
                            'The selected appointment time must be in the future.',
                    ]);
                }

                $startString = $start->format('H:i:s');
                $endString = $end->format('H:i:s');

                /*
                |--------------------------------------------------------------------------
                | Staff Availability
                |--------------------------------------------------------------------------
                */

                if (!$availability->isTimeAvailable(
                    $staffId,
                    $date,
                    $startString,
                    $endString
                )) {
                    throw ValidationException::withMessages([
                        'services' =>
                            "Time {$startTime} is unavailable for service {$serviceId}.",
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | Client Conflict
                |--------------------------------------------------------------------------
                */

                $conflict = BookingServiceModel::query()
                    ->whereHas(
                        'booking',
                        function ($query) use (
                            $clientId,
                            $date
                        ) {
                            $query
                                ->where('client_id', $clientId)
                                ->whereDate('booking_date', $date)
                                ->whereNotIn('status', [
                                    'cancelled',
                                    'rejected',
                                    'no_show',
                                ]);
                        }
                    )
                    ->where('start_time', '<', $endString)
                    ->where('end_time', '>', $startString)
                    ->exists();

                if ($conflict) {
                    throw ValidationException::withMessages([
                        'services' =>
                            'You already have another booking at this time.',
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | Same Booking Overlap
                |--------------------------------------------------------------------------
                */

                foreach ($items as $previous) {
                    if (
                        $startString < $previous['end_time'] &&
                        $endString > $previous['start_time']
                    ) {
                        throw ValidationException::withMessages([
                            'services' =>
                                'Selected services cannot overlap for the same patient.',
                        ]);
                    }
                }

                /*
                |--------------------------------------------------------------------------
                | Calculate Totals
                |--------------------------------------------------------------------------
                */

                $firstStart = $firstStart === null
                    ? $startString
                    : min($firstStart, $startString);

                $lastEnd = $lastEnd === null
                    ? $endString
                    : max($lastEnd, $endString);

                $subtotal += $price;
                $depositAmount += $deposit;

                $items[] = [
                    'service_id' => $serviceId,
                    'staff_id' => $staffId,
                    'start_time' => $startString,
                    'end_time' => $endString,
                    'duration' => $duration,
                    'price' => $price,
                    'deposit_amount' => $deposit,
                ];
            }

            /*
            |--------------------------------------------------------------------------
            | Discount Code
            |--------------------------------------------------------------------------
            */

            $discount = null;
            $discountAmount = 0.0;

            $rewardService = null;
            $rewardResult = null;

            if (
                $discountCode !== null &&
                trim($discountCode) !== ''
            ) {
                $normalizedCode = strtoupper(
                    trim($discountCode)
                );

                $discount = DiscountCode::query()
                    ->whereRaw(
                        'UPPER(code) = ?',
                        [$normalizedCode]
                    )
                    ->lockForUpdate()
                    ->first();

                if (!$discount || !$discount->is_active) {
                    throw ValidationException::withMessages([
                        'discount_code' =>
                            'Invalid or inactive discount code.',
                    ]);
                }

                $now = now();

                if (
                    $discount->starts_at !== null &&
                    $now->lt($discount->starts_at)
                ) {
                    throw ValidationException::withMessages([
                        'discount_code' =>
                            'This discount code is not active yet.',
                    ]);
                }

                if (
                    $discount->expires_at !== null &&
                    $now->gt($discount->expires_at)
                ) {
                    throw ValidationException::withMessages([
                        'discount_code' =>
                            'This discount code has expired.',
                    ]);
                }

                if (
                    $discount->min_order_amount !== null &&
                    $subtotal < (float) $discount->min_order_amount
                ) {
                    throw ValidationException::withMessages([
                        'discount_code' =>
                            'The order amount is below the discount minimum.',
                    ]);
                }

                if (
                    $discount->usage_limit !== null &&
                    $discount->usage_count >= $discount->usage_limit
                ) {
                    throw ValidationException::withMessages([
                        'discount_code' =>
                            'This discount code has reached its usage limit.',
                    ]);
                }

                if ($discount->usage_limit_per_client !== null) {
                    $usageCount = DiscountUsage::query()
                        ->where('discount_code_id', $discount->id)
                        ->where('client_id', $clientId)
                        ->whereIn('status', [
                            'reserved',
                            'consumed',
                        ])
                        ->count();

                    if (
                        $usageCount >=
                        $discount->usage_limit_per_client
                    ) {
                        throw ValidationException::withMessages([
                            'discount_code' =>
                                'You have reached the usage limit for this discount.',
                        ]);
                    }
                }

                $value = (float) $discount->value;

                if (
                    $value <= 0 ||
                    (
                        $discount->type === 'percentage' &&
                        $value > 100
                    )
                ) {
                    throw ValidationException::withMessages([
                        'discount_code' =>
                            'Invalid discount value.',
                    ]);
                }

                $discountAmount =
                    $discount->type === 'percentage'
                        ? $subtotal * ($value / 100)
                        : $value;

                if ($discount->max_discount_amount !== null) {
                    $discountAmount = min(
                        $discountAmount,
                        (float) $discount->max_discount_amount
                    );
                }

                $discountAmount = round(
                    min($subtotal, $discountAmount),
                    2
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Referral Reward
            |--------------------------------------------------------------------------
            */

            if ($useReferralReward) {
                $rewardService = app(
                    ReferralRewardService::class
                );

                $rewardResult = $rewardService->calculateDiscount(
                    clientId: $clientId,
                    orderAmount: $subtotal
                );

                if (
                    !($rewardResult['eligible'] ?? false) ||
                    !($rewardResult['rule'] ?? null)
                ) {
                    throw ValidationException::withMessages([
                        'use_referral_reward' =>
                            'You are not eligible for a referral reward.',
                    ]);
                }

                $discountAmount = (float)
                $rewardResult['discount_amount'];
            }

            /*
            |--------------------------------------------------------------------------
            | Final Amount
            |--------------------------------------------------------------------------
            */

            $discountAmount = round(
                min($subtotal, max(0, $discountAmount)),
                2
            );

            $totalAmount = round(
                max(0, $subtotal - $discountAmount),
                2
            );

            $depositAmount = round(
                min(max(0, $depositAmount), $totalAmount),
                2
            );

            $isFree = $totalAmount <= 0;

            /*
            |--------------------------------------------------------------------------
            | Create Booking
            |--------------------------------------------------------------------------
            */

            $booking = Booking::create([
                'client_id' => $clientId,
                'booking_date' => $date,
                'start_time' => $firstStart,
                'end_time' => $lastEnd,
                'subtotal' => $subtotal,
                'discount_amount' => $discountAmount,
                'total_amount' => $totalAmount,
                'deposit_amount' => $depositAmount,
                'paid_amount' => 0,
                'status' => $isFree
                    ? 'confirmed'
                    : 'awaiting_payment',
                'payment_status' => $isFree
                    ? 'paid'
                    : 'pending',
                'notes' => $notes,
            ]);

            foreach ($items as $item) {
                BookingServiceModel::create([
                    'booking_id' => $booking->id,
                    ...$item,
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | Store Discount Usage
            |--------------------------------------------------------------------------
            */

            if ($discount && $discountAmount > 0) {
                DiscountUsage::create([
                    'discount_code_id' => $discount->id,
                    'client_id' => $clientId,
                    'booking_id' => $booking->id,
                    'discount_amount' => $discountAmount,
                    'status' => $isFree
                        ? 'consumed'
                        : 'reserved',
                ]);

                $discount->increment('usage_count');
            }

            /*
            |--------------------------------------------------------------------------
            | Store Referral Usage
            |--------------------------------------------------------------------------
            */

            if (
                $useReferralReward &&
                $rewardResult &&
                $rewardResult['eligible'] &&
                $rewardResult['rule']
            ) {
                $rewardService->recordUsage(
                    clientId: $clientId,
                    bookingId: $booking->id,
                    orderAmount: $subtotal
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Confirm Free Booking
            |--------------------------------------------------------------------------
            */

            if ($isFree) {
                $booking->notifications()->firstOrCreate(
                    ['type' => 'booking_confirmed'],
                    [
                        'user_id' => null,
                        'client_id' => $clientId,
                        'title' => 'Booking Confirmed',
                        'message' =>
                            'Your booking has been confirmed for '
                            . $date
                            . ' at '
                            . substr($firstStart, 0, 5)
                            . '.',
                        'is_read' => false,
                        'read_at' => null,
                    ]
                );

                $this->sendSmsAfterCommit(
                    $booking->id,
                    'booking_confirmed'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Convert Hold
            |--------------------------------------------------------------------------
            */

            BookingHold::query()
                ->where('booking_id', $booking->id)
                ->where('status', 'active')
                ->update([
                    'status' => 'converted',
                ]);

            return $booking->load(self::RELATIONS);

        }, 3);
    }

    /*
    |--------------------------------------------------------------------------
    | Cancel Booking
    |--------------------------------------------------------------------------
    */

    public function cancelBooking(
        int $bookingId,
        int $clientId,
        ?string $reason = null
    ): Booking {
        return DB::transaction(function () use (
            $bookingId,
            $clientId,
            $reason
        ) {
            $this->lockClient($clientId);

            $booking = Booking::query()
                ->whereKey($bookingId)
                ->where('client_id', $clientId)
                ->lockForUpdate()
                ->first();

            if (!$booking) {
                throw ValidationException::withMessages([
                    'booking' => 'Booking not found.',
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | Booking Status
            |--------------------------------------------------------------------------
            */

            if (in_array($booking->status, [
                'cancelled',
                'completed',
                'rejected',
                'no_show',
            ], true)) {
                throw ValidationException::withMessages([
                    'booking' =>
                        'This booking cannot be cancelled.',
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | 24 Hour Rule
            |--------------------------------------------------------------------------
            */

            $oldStart = $this->bookingStart($booking);

            if ($oldStart->lt(now()->addHours(24))) {
                throw ValidationException::withMessages([
                    'booking' =>
                        'Booking can only be cancelled at least 24 hours before the appointment.',
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | Payment Checks
            |--------------------------------------------------------------------------
            */

            $hasPaidPayment = Payment::query()
                ->where('booking_id', $booking->id)
                ->whereIn('type', [
                    'deposit',
                    'remaining',
                ])
                ->where('status', 'paid')
                ->exists();

            $hasUnresolvedPayment = Payment::query()
                ->where('booking_id', $booking->id)
                ->whereIn('type', [
                    'deposit',
                    'remaining',
                ])
                ->where('status', 'pending')
                ->where(function ($query) {
                    $query
                        ->whereNotNull('authority')
                        ->orWhereNotNull('initiation_token');
                })
                ->exists();

            if ($hasUnresolvedPayment) {
                throw ValidationException::withMessages([
                    'booking' =>
                        'An online payment is still pending verification.',
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | Old Values
            |--------------------------------------------------------------------------
            */

            $oldValues = [
                'status' => $booking->status,
                'cancelled_at' =>
                    $booking->cancelled_at?->format(
                        'Y-m-d H:i:s'
                    ),
                'cancellation_reason' =>
                    $booking->cancellation_reason,
                'paid_amount' =>
                    (float) $booking->paid_amount,
                'payment_status' =>
                    $booking->payment_status,
            ];

            /*
            |--------------------------------------------------------------------------
            | Cancel
            |--------------------------------------------------------------------------
            */

            $booking->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ]);

            /*
            |--------------------------------------------------------------------------
            | Release Unpaid Rewards
            |--------------------------------------------------------------------------
            | Paid bookings are not automatically refunded.
            */

            if (
                (float) $booking->total_amount > 0 &&
                !$hasPaidPayment
            ) {
                app(ReferralRewardService::class)
                    ->releaseUsage($booking->id);

                $discountUsage = DiscountUsage::query()
                    ->where('booking_id', $booking->id)
                    ->lockForUpdate()
                    ->first();

                if (
                    $discountUsage &&
                    $discountUsage->status === 'reserved'
                ) {
                    $discount = DiscountCode::query()
                        ->whereKey(
                            $discountUsage->discount_code_id
                        )
                        ->lockForUpdate()
                        ->firstOrFail();

                    $discountUsage->update([
                        'status' => 'released',
                    ]);

                    if ((int) $discount->usage_count > 0) {
                        $discount->decrement('usage_count');
                    }
                }
            }

            /*
            |--------------------------------------------------------------------------
            | Release Holds
            |--------------------------------------------------------------------------
            */

            BookingHold::query()
                ->where('booking_id', $booking->id)
                ->where('status', 'active')
                ->update([
                    'status' => 'released',
                ]);

            /*
            |--------------------------------------------------------------------------
            | Notification
            |--------------------------------------------------------------------------
            */

            $booking->notifications()->create([
                'user_id' => null,
                'client_id' => $clientId,
                'type' => 'booking_cancelled',
                'title' => 'Booking Cancelled',
                'message' =>
                    'Your booking has been cancelled successfully.',
                'is_read' => false,
                'read_at' => null,
            ]);

            $booking->refresh();

            /*
            |--------------------------------------------------------------------------
            | Audit Log
            |--------------------------------------------------------------------------
            */

            $this->audit(
                $booking,
                'booking_cancelled',
                $reason ?: 'Booking cancelled by client.',
                $oldValues,
                [
                    'status' => $booking->status,
                    'cancelled_at' =>
                        $booking->cancelled_at?->format(
                            'Y-m-d H:i:s'
                        ),
                    'cancellation_reason' =>
                        $booking->cancellation_reason,
                    'paid_amount' =>
                        (float) $booking->paid_amount,
                    'payment_status' =>
                        $booking->payment_status,
                    'refund_created' => false,
                ]
            );

            $this->sendSmsAfterCommit(
                $booking->id,
                'booking_cancelled'
            );

            return $booking->load(self::RELATIONS);

        }, 3);
    }

    /*
    |--------------------------------------------------------------------------
    | Reschedule Booking
    |--------------------------------------------------------------------------
    */

    public function rescheduleBooking(
        int $bookingId,
        int $clientId,
        string $newDate,
        string $newStartTime
    ): Booking {
        return DB::transaction(function () use (
            $bookingId,
            $clientId,
            $newDate,
            $newStartTime
        ) {
            $this->lockClient($clientId);

            $booking = Booking::query()
                ->whereKey($bookingId)
                ->where('client_id', $clientId)
                ->lockForUpdate()
                ->first();

            if (!$booking) {
                throw ValidationException::withMessages([
                    'booking' => 'Booking not found.',
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | Validate Status
            |--------------------------------------------------------------------------
            */

            if (in_array($booking->status, [
                'cancelled',
                'completed',
                'rejected',
                'no_show',
            ], true)) {
                throw ValidationException::withMessages([
                    'booking' =>
                        'This booking cannot be rescheduled.',
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | 24 Hour Rule
            |--------------------------------------------------------------------------
            */

            $oldStart = $this->bookingStart($booking);

            if ($oldStart->lt(now()->addHours(24))) {
                throw ValidationException::withMessages([
                    'booking' =>
                        'Booking can only be rescheduled at least 24 hours before the appointment.',
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | Validate New Date/Time
            |--------------------------------------------------------------------------
            */

            $newStart = $this->strictDateTime(
                $newDate,
                $newStartTime,
                'start_time'
            );

            if ($newStart->lte(now())) {
                throw ValidationException::withMessages([
                    'start_time' =>
                        'The new booking date and time must be in the future.',
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | Booking Services
            |--------------------------------------------------------------------------
            */

            $bookingServices = $booking
                ->bookingServices()
                ->orderBy('start_time')
                ->get();

            if ($bookingServices->isEmpty()) {
                throw ValidationException::withMessages([
                    'booking' => 'Booking has no services.',
                ]);
            }

            $this->lockStaff(
                $bookingServices->pluck('staff_id')->all()
            );

            $oldDate = $booking
                ->booking_date
                ->format('Y-m-d');

            $offsetMinutes = (int) $oldStart->diffInMinutes(
                $this->strictDateTime(
                    $oldDate,
                    $newStartTime,
                    'start_time'
                ),
                false
            );

            /*
            |--------------------------------------------------------------------------
            | Old Values
            |--------------------------------------------------------------------------
            */

            $oldValues = [
                'booking_date' => $oldDate,
                'start_time' => $booking->start_time,
                'end_time' => $booking->end_time,
                'status' => $booking->status,
                'payment_status' => $booking->payment_status,
                'paid_amount' =>
                    (float) $booking->paid_amount,
                'services' => $bookingServices
                    ->map(fn ($item) => [
                        'booking_service_id' => $item->id,
                        'service_id' => $item->service_id,
                        'staff_id' => $item->staff_id,
                        'start_time' => $item->start_time,
                        'end_time' => $item->end_time,
                    ])
                    ->values()
                    ->toArray(),
            ];

            $availability = app(
                BookingAvailabilityService::class
            );

            $updates = [];

            /*
            |--------------------------------------------------------------------------
            | Validate Every Shifted Service
            |--------------------------------------------------------------------------
            */

            foreach ($bookingServices as $bookingService) {
                $shiftedStart = $this->strictDateTime(
                    $oldDate,
                    $bookingService->start_time,
                    'booking'
                )->addMinutes($offsetMinutes);

                $shiftedEnd = $this->strictDateTime(
                    $oldDate,
                    $bookingService->end_time,
                    'booking'
                )->addMinutes($offsetMinutes);

                if (
                    $shiftedStart->format('Y-m-d') !== $oldDate ||
                    $shiftedEnd->format('Y-m-d') !== $oldDate ||
                    $shiftedEnd->lte($shiftedStart)
                ) {
                    throw ValidationException::withMessages([
                        'booking' =>
                            'Appointment services cannot extend into another day.',
                    ]);
                }

                $startString = $shiftedStart->format('H:i:s');
                $endString = $shiftedEnd->format('H:i:s');

                /*
                |--------------------------------------------------------------------------
                | Staff Availability
                |--------------------------------------------------------------------------
                */

                if (!$availability->isTimeAvailable(
                    staffId: (int) $bookingService->staff_id,
                    date: $newDate,
                    startTime: $startString,
                    endTime: $endString,
                    ignoreBookingId: $booking->id,
                    ignoreHoldBookingId: $booking->id
                )) {
                    throw ValidationException::withMessages([
                        'booking' =>
                            "The selected time {$startString} is unavailable.",
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | Patient Conflict
                |--------------------------------------------------------------------------
                */

                $clientConflict = BookingServiceModel::query()
                    ->whereHas(
                        'booking',
                        function ($query) use (
                            $clientId,
                            $newDate,
                            $bookingId
                        ) {
                            $query
                                ->where('client_id', $clientId)
                                ->where('id', '!=', $bookingId)
                                ->whereDate(
                                    'booking_date',
                                    $newDate
                                )
                                ->whereNotIn('status', [
                                    'cancelled',
                                    'rejected',
                                    'no_show',
                                ]);
                        }
                    )
                    ->where('start_time', '<', $endString)
                    ->where('end_time', '>', $startString)
                    ->exists();

                if ($clientConflict) {
                    throw ValidationException::withMessages([
                        'booking' =>
                            'You already have another booking at the selected time.',
                    ]);
                }

                $updates[] = [
                    'id' => $bookingService->id,
                    'service_id' => $bookingService->service_id,
                    'staff_id' => $bookingService->staff_id,
                    'start_time' => $startString,
                    'end_time' => $endString,
                ];
            }

            /*
            |--------------------------------------------------------------------------
            | Final Booking End
            |--------------------------------------------------------------------------
            */

            $shiftedBookingEnd = $this->strictDateTime(
                $oldDate,
                $booking->end_time,
                'booking'
            )->addMinutes($offsetMinutes);

            if (
                $shiftedBookingEnd->format('Y-m-d') !== $oldDate ||
                $shiftedBookingEnd->lte(
                    $this->strictDateTime(
                        $oldDate,
                        $newStartTime,
                        'start_time'
                    )
                )
            ) {
                throw ValidationException::withMessages([
                    'booking' =>
                        'Appointment cannot extend into another day.',
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | Update Booking
            |--------------------------------------------------------------------------
            */

            $booking->update([
                'booking_date' => $newDate,
                'start_time' => $newStart->format('H:i:s'),
                'end_time' =>
                    $shiftedBookingEnd->format('H:i:s'),
            ]);

            foreach ($updates as $item) {
                BookingServiceModel::query()
                    ->whereKey($item['id'])
                    ->where('booking_id', $booking->id)
                    ->update([
                        'start_time' => $item['start_time'],
                        'end_time' => $item['end_time'],
                    ]);
            }

            /*
            |--------------------------------------------------------------------------
            | Release Old Holds
            |--------------------------------------------------------------------------
            */

            BookingHold::query()
                ->where('booking_id', $booking->id)
                ->where('status', 'active')
                ->update([
                    'status' => 'released',
                ]);

            $booking->refresh();

            /*
            |--------------------------------------------------------------------------
            | Notification
            |--------------------------------------------------------------------------
            */

            $booking->notifications()->create([
                'user_id' => null,
                'client_id' => $clientId,
                'type' => 'booking_rescheduled',
                'title' => 'Booking Rescheduled',
                'message' =>
                    'Your booking has been rescheduled to '
                    . $newDate
                    . ' at '
                    . $newStart->format('H:i')
                    . '.',
                'is_read' => false,
                'read_at' => null,
            ]);

            /*
            |--------------------------------------------------------------------------
            | Audit Log
            |--------------------------------------------------------------------------
            */

            $this->audit(
                $booking,
                'booking_rescheduled',
                'Booking rescheduled by client.',
                $oldValues,
                [
                    'booking_date' => $newDate,
                    'start_time' => $booking->start_time,
                    'end_time' => $booking->end_time,
                    'status' => $booking->status,
                    'payment_status' =>
                        $booking->payment_status,
                    'paid_amount' =>
                        (float) $booking->paid_amount,
                    'services' => $updates,
                ]
            );

            $this->sendSmsAfterCommit(
                $booking->id,
                'booking_rescheduled'
            );

            return $booking->load(self::RELATIONS);

        }, 3);
    }

    /*
    |--------------------------------------------------------------------------
    | Database Locks
    |--------------------------------------------------------------------------
    */

    private function lockClient(int $clientId): void
    {
        $exists = DB::table('clients')
            ->where('id', $clientId)
            ->lockForUpdate()
            ->first(['id']);

        if (!$exists) {
            throw ValidationException::withMessages([
                'client' => 'Patient not found.',
            ]);
        }
    }

    private function lockStaff(array $staffIds): void
    {
        $ids = collect($staffIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->sort()
            ->values();

        if ($ids->isEmpty()) {
            return;
        }

        $found = Staff::query()
            ->whereIn('id', $ids->all())
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id']);

        if ($found->count() !== $ids->count()) {
            throw ValidationException::withMessages([
                'staff' =>
                    'One or more selected staff members do not exist.',
            ]);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Date Validation
    |--------------------------------------------------------------------------
    */

    private function strictDateTime(
        string $date,
        string $time,
        string $field
    ): Carbon {
        $dateTime = $date . ' ' . (
            strlen($time) === 5
                ? $time . ':00'
                : $time
            );

        try {
            $value = Carbon::createFromFormat(
                '!Y-m-d H:i:s',
                $dateTime
            );
        } catch (\Throwable $e) {
            throw ValidationException::withMessages([
                $field => 'Invalid date or time.',
            ]);
        }

        if (
            !$value ||
            $value->format('Y-m-d H:i:s') !== $dateTime
        ) {
            throw ValidationException::withMessages([
                $field => 'Invalid date or time.',
            ]);
        }

        return $value;
    }

    private function bookingStart(
        Booking $booking
    ): Carbon {
        return $this->strictDateTime(
            $booking->booking_date->format('Y-m-d'),
            $booking->start_time,
            'booking'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Accounting Audit Log
    |--------------------------------------------------------------------------
    */

    private function audit(
        Booking $booking,
        string $action,
        string $description,
        array $oldValues,
        array $newValues
    ): void {
        AccountingAuditLog::create([
            'user_id' => null,
            'action' => $action,
            'entity_type' => 'booking',
            'entity_id' => $booking->id,
            'booking_id' => $booking->id,
            'client_id' => $booking->client_id,
            'description' => $description,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
            'created_at' => now(),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | SMS After Transaction Commit
    |--------------------------------------------------------------------------
    */

    private function sendSmsAfterCommit(
        int $bookingId,
        string $type
    ): void {
        DB::afterCommit(function () use (
            $bookingId,
            $type
        ) {
            try {
                $booking = Booking::with('client')
                    ->find($bookingId);

                $client = $booking?->client;

                if (
                    !$booking ||
                    !$client ||
                    empty($client->phone)
                ) {
                    return;
                }

                // Keep duplicate guard for confirmation/cancellation.
                if (
                    $type !== 'booking_rescheduled' &&
                    $booking->smsLogs()
                        ->where('type', $type)
                        ->where('status', 'sent')
                        ->exists()
                ) {
                    return;
                }

                $params = [
                    'phone' => $client->phone,
                    'date' => Jalalian::fromCarbon(
                        $booking->booking_date
                    )->format('Y/m/d'),
                    'time' => substr(
                        $booking->start_time,
                        0,
                        5
                    ),
                    'booking' => $booking,
                    'client' => $client,
                ];

                $sms = app(SmsService::class);

                switch ($type) {
                    case 'booking_confirmed':
                        $sms->sendBookingConfirmed(...$params);
                        break;

                    case 'booking_cancelled':
                        $sms->sendBookingCancelled(...$params);
                        break;

                    case 'booking_rescheduled':
                        $sms->sendBookingRescheduled(...$params);
                        break;
                }

            } catch (\Throwable $e) {
                Log::error('Booking SMS failed', [
                    'booking_id' => $bookingId,
                    'type' => $type,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }
}
