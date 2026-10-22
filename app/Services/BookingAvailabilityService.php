<?php

namespace App\Services;

use App\Models\BookingService as BookingServiceModel;
use App\Models\Staff;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class BookingAvailabilityService
{
    public function getAvailableSlots(
        int $staffId,
        string $date,
        int $duration,
        int $interval = 30,
        ?int $ignoreBookingId = null
    ): Collection {
        if ($duration <= 0 || $interval <= 0) {
            return collect();
        }

        $staff = Staff::with([
            'schedules',
            'breaks',
            'leaves',
        ])->findOrFail($staffId);

        if (!$staff->is_active) {
            return collect();
        }

        $dateObject = Carbon::createFromFormat(
            '!Y-m-d',
            $date
        );

        $schedules = $staff->schedules
            ->where('day_of_week', $dateObject->dayOfWeek)
            ->where('is_active', true)
            ->sortBy('start_time');

        if ($schedules->isEmpty()) {
            return collect();
        }

        $bookings = $this->bookingsFor(
            $staffId,
            $date,
            $ignoreBookingId
        );

        $holds = $this->holdsFor(
            $staff,
            $date,
            $ignoreBookingId
        );

        $slots = collect();

        foreach ($schedules as $schedule) {
            $scheduleStart = $this->at(
                $date,
                $schedule->start_time
            );

            $scheduleEnd = $this->at(
                $date,
                $schedule->end_time
            );

            if ($scheduleEnd->lte($scheduleStart)) {
                continue;
            }

            $current = $scheduleStart->copy();

            while (
            $current->copy()
                ->addMinutes($duration)
                ->lte($scheduleEnd)
            ) {
                $start = $current->copy();
                $end = $start->copy()->addMinutes($duration);

                $current->addMinutes($interval);

                // Do not return past time slots.
                if ($start->lte(now())) {
                    continue;
                }

                if (!$this->rangeIsAvailable(
                    $staff,
                    $date,
                    $start,
                    $end,
                    $bookings,
                    $holds
                )) {
                    continue;
                }

                $slots->push([
                    'start_time' => $start->format('H:i'),
                    'end_time' => $end->format('H:i'),
                    'duration' => $duration,
                ]);
            }
        }

        return $slots
            ->unique('start_time')
            ->values();
    }

    public function isTimeAvailable(
        int $staffId,
        string $date,
        string $startTime,
        string $endTime,
        ?int $ignoreBookingId = null,
        ?int $ignoreBookingServiceId = null,
        ?int $ignoreHoldBookingId = null
    ): bool {
        $staff = Staff::with([
            'schedules',
            'breaks',
            'leaves',
        ])->findOrFail($staffId);

        if (!$staff->is_active) {
            return false;
        }

        $start = $this->at($date, $startTime);
        $end = $this->at($date, $endTime);

        if (
            $end->lte($start) ||
            $start->lte(now())
        ) {
            return false;
        }

        $bookings = $this->bookingsFor(
            $staffId,
            $date,
            $ignoreBookingId,
            $ignoreBookingServiceId
        );

        $holds = $this->holdsFor(
            $staff,
            $date,
            $ignoreHoldBookingId
        );

        return $this->rangeIsAvailable(
            $staff,
            $date,
            $start,
            $end,
            $bookings,
            $holds
        );
    }

    private function bookingsFor(
        int $staffId,
        string $date,
        ?int $ignoreBookingId = null,
        ?int $ignoreBookingServiceId = null
    ): Collection {
        return BookingServiceModel::query()
            ->where('staff_id', $staffId)
            ->when(
                $ignoreBookingServiceId !== null,
                fn ($query) => $query->where(
                    'id',
                    '!=',
                    $ignoreBookingServiceId
                )
            )
            ->whereHas(
                'booking',
                function ($query) use (
                    $date,
                    $ignoreBookingId
                ) {
                    $query
                        ->whereDate('booking_date', $date)
                        ->whereNotIn('status', [
                            'cancelled',
                            'rejected',
                            'no_show',
                        ])
                        ->when(
                            $ignoreBookingId !== null,
                            fn ($query) => $query->where(
                                'id',
                                '!=',
                                $ignoreBookingId
                            )
                        );
                }
            )
            ->get([
                'id',
                'staff_id',
                'start_time',
                'end_time',
            ]);
    }

    private function holdsFor(
        Staff $staff,
        string $date,
        ?int $ignoreHoldBookingId
    ): Collection {
        return $staff->bookingHolds()
            ->whereDate('hold_date', $date)
            ->where('status', 'active')
            ->where('expires_at', '>', now())
            ->when(
                $ignoreHoldBookingId !== null,
                fn ($query) => $query->where(
                    'booking_id',
                    '!=',
                    $ignoreHoldBookingId
                )
            )
            ->get();
    }

    private function rangeIsAvailable(
        Staff $staff,
        string $date,
        Carbon $start,
        Carbon $end,
        Collection $bookings,
        Collection $holds
    ): bool {
        $dayOfWeek = $start->dayOfWeek;

        // Check working schedule.
        $insideSchedule = $staff->schedules
            ->where('day_of_week', $dayOfWeek)
            ->where('is_active', true)
            ->contains(function ($schedule) use (
                $date,
                $start,
                $end
            ) {
                $workStart = $this->at(
                    $date,
                    $schedule->start_time
                );

                $workEnd = $this->at(
                    $date,
                    $schedule->end_time
                );

                return $start->gte($workStart)
                    && $end->lte($workEnd);
            });

        if (!$insideSchedule) {
            return false;
        }

        // Check full-day and partial leaves.
        foreach (
            $staff->leaves->where('is_active', true)
            as $leave
        ) {
            if (
                !$leave->leave_date ||
                $leave->leave_date->format('Y-m-d') !== $date
            ) {
                continue;
            }

            if (
                $leave->start_time === null &&
                $leave->end_time === null
            ) {
                return false;
            }

            if (
                $leave->start_time !== null &&
                $leave->end_time !== null &&
                $this->overlaps(
                    $date,
                    $start,
                    $end,
                    $leave->start_time,
                    $leave->end_time
                )
            ) {
                return false;
            }
        }

        // Check weekly breaks.
        foreach (
            $staff->breaks
                ->where('day_of_week', $dayOfWeek)
                ->where('is_active', true)
            as $break
        ) {
            if ($this->overlaps(
                $date,
                $start,
                $end,
                $break->start_time,
                $break->end_time
            )) {
                return false;
            }
        }

        // Check existing bookings.
        foreach ($bookings as $bookingService) {
            if ($this->overlaps(
                $date,
                $start,
                $end,
                $bookingService->start_time,
                $bookingService->end_time
            )) {
                return false;
            }
        }

        // Check active booking holds.
        foreach ($holds as $hold) {
            if ($this->overlaps(
                $date,
                $start,
                $end,
                $hold->start_time,
                $hold->end_time
            )) {
                return false;
            }
        }

        return true;
    }

    private function overlaps(
        string $date,
        Carbon $start,
        Carbon $end,
        string $blockedStart,
        string $blockedEnd
    ): bool {
        return $start->lt(
                $this->at($date, $blockedEnd)
            ) && $end->gt(
                $this->at($date, $blockedStart)
            );
    }

    private function at(
        string $date,
        string $time
    ): Carbon {
        $normalizedTime = strlen($time) === 5
            ? $time . ':00'
            : $time;

        return Carbon::createFromFormat(
            '!Y-m-d H:i:s',
            $date . ' ' . $normalizedTime
        );
    }
}
