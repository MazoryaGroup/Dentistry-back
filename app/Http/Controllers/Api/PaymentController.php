<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Payment;
use App\Services\PaymentService;
use App\Services\ZarinPalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\RedirectResponse;


class PaymentController extends Controller
{
    protected PaymentService $paymentService;

    public function __construct(PaymentService $paymentService)
    {
        $this->paymentService = $paymentService;
    }

    /*
    |--------------------------------------------------------------------------
    | Create Deposit Payment
    |--------------------------------------------------------------------------
    */

    public function deposit(Request $request, int $bookingId): JsonResponse
    {
        try {
            $client = auth('api')->user();

            if (!$client) {
                return response()->json([
                    'success' => false,
                    'statusCode' => 401,
                    'message' => 'Unauthenticated.',
                ], 401);
            }

            $booking = $this->findClientBooking(
                $bookingId,
                $client->id
            );

            if (!$booking) {
                return response()->json([
                    'success' => false,
                    'statusCode' => 404,
                    'message' => 'Booking not found.',
                ], 404);
            }

            $payment = $this->paymentService
                ->createDepositPayment(
                    $booking,
                    $client
                );

            return response()->json([
                'success' => true,
                'statusCode' => 200,
                'message' => 'Deposit payment created successfully.',
                'data' => [
                    'payment_id' => $payment->id,
                    'booking_id' => $booking->id,
                    'amount' => $payment->amount,
                    'type' => $payment->type,
                    'status' => $payment->status,
                    'gateway' => $payment->gateway,
                    'transaction_id' => $payment->transaction_id,

                    'payment' => $payment,

                    'booking' => $booking->fresh([
                        'bookingServices.service',
                        'bookingServices.staff',
                    ]),
                ],
            ], 200);

        } catch (RuntimeException $e) {

            return response()->json([
                'success' => false,
                'statusCode' => 422,
                'message' => $e->getMessage(),
            ], 422);

        } catch (Throwable $e) {

            return response()->json([
                'success' => false,
                'statusCode' => 500,
                'message' => 'Failed to create deposit payment.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Start Deposit Payment With ZarinPal
    |--------------------------------------------------------------------------
    */

    public function start(Request $request, int $bookingId): JsonResponse
    {
        $reservedPaymentId = null;

        try {
            $client = auth('api')->user();

            if (!$client) {
                return response()->json([
                    'success' => false,
                    'statusCode' => 401,
                    'message' => 'Unauthenticated.',
                ], 401);
            }

            $booking = $this->findClientBooking(
                $bookingId,
                $client->id
            );

            if (!$booking) {
                return response()->json([
                    'success' => false,
                    'statusCode' => 404,
                    'message' => 'Booking not found.',
                ], 404);
            }

            $this->validateGatewayConfiguration();

            /*
            |--------------------------------------------------------------------------
            | Reserve Deposit Initiation
            |--------------------------------------------------------------------------
            */

            $reservation = $this->paymentService
                ->reserveDepositPaymentInitiation(
                    $booking,
                    $client
                );

            $payment = $reservation['payment'];
            $reservedPaymentId = $payment->id;
            $token = $reservation['token'];

            /*
            |--------------------------------------------------------------------------
            | Request ZarinPal
            |--------------------------------------------------------------------------
            */

            $paymentData = app(ZarinPalService::class)
                ->requestPayment(
                    amount: (float) $payment->amount,
                    callbackUrl: config('services.zarinpal.callback_url'),
                    description: 'Dentistry booking deposit #' . $booking->id,
                    email: $client->email,
                    mobile: $client->phone
                );

            $authority = trim(
                (string) ($paymentData['authority'] ?? '')
            );

            $paymentUrl = trim(
                (string) ($paymentData['payment_url'] ?? '')
            );

            if ($authority === '' || $paymentUrl === '') {
                throw new RuntimeException(
                    'ZarinPal did not return valid payment information.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Persist Authority
            |--------------------------------------------------------------------------
            */

            $payment = $this->paymentService
                ->completeDepositPaymentInitiation(
                    payment: $payment,
                    token: $token,
                    authority: $authority
                );

            return response()->json([
                'success' => true,
                'statusCode' => 200,
                'message' => 'Deposit payment started successfully.',
                'data' => [
                    'payment_id' => $payment->id,
                    'booking_id' => $booking->id,
                    'amount' => $payment->amount,
                    'type' => $payment->type,
                    'status' => $payment->status,
                    'gateway' => $payment->gateway,
                    'authority' => $payment->authority,
                    'payment_url' => $paymentUrl,
                ],
            ], 200);

        } catch (Throwable $e) {
            return $this->handleInitiationFailure(
                $e,
                $bookingId,
                $reservedPaymentId
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Create Remaining Payment
    |--------------------------------------------------------------------------
    */

    public function remaining(
        Request $request,
        int $bookingId
    ): JsonResponse {
        try {
            $client = auth('api')->user();

            if (!$client) {
                return response()->json([
                    'success' => false,
                    'statusCode' => 401,
                    'message' => 'Unauthenticated.',
                ], 401);
            }

            $booking = $this->findClientBooking(
                $bookingId,
                $client->id
            );

            if (!$booking) {
                return response()->json([
                    'success' => false,
                    'statusCode' => 404,
                    'message' => 'Booking not found.',
                ], 404);
            }

            $payment = $this->paymentService
                ->createRemainingPayment(
                    $booking,
                    $client
                );

            return response()->json([
                'success' => true,
                'statusCode' => 200,
                'message' =>
                    'Remaining payment created successfully.',

                'data' => [
                    'payment_id' => $payment->id,
                    'booking_id' => $booking->id,
                    'amount' => $payment->amount,
                    'type' => $payment->type,
                    'status' => $payment->status,
                    'gateway' => $payment->gateway,
                    'transaction_id' =>
                        $payment->transaction_id,

                    'payment' => $payment,

                    'booking' => $booking->fresh([
                        'bookingServices.service',
                        'bookingServices.staff',
                    ]),
                ],
            ], 200);

        } catch (RuntimeException $e) {

            return response()->json([
                'success' => false,
                'statusCode' => 422,
                'message' => $e->getMessage(),
            ], 422);

        } catch (Throwable $e) {

            return response()->json([
                'success' => false,
                'statusCode' => 500,
                'message' =>
                    'Failed to create remaining payment.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Start Remaining Payment With ZarinPal
    |--------------------------------------------------------------------------
    */

    public function remainingStart(
        Request $request,
        int $bookingId
    ): JsonResponse {
        $reservedPaymentId = null;

        try {
            $client = auth('api')->user();

            if (!$client) {
                return response()->json([
                    'success' => false,
                    'statusCode' => 401,
                    'message' => 'Unauthenticated.',
                ], 401);
            }

            $booking = $this->findClientBooking(
                $bookingId,
                $client->id
            );

            if (!$booking) {
                return response()->json([
                    'success' => false,
                    'statusCode' => 404,
                    'message' => 'Booking not found.',
                ], 404);
            }

            $this->validateGatewayConfiguration();

            /*
            |--------------------------------------------------------------------------
            | Reserve Payment Initiation
            |--------------------------------------------------------------------------
            |
            | Booking and Payment are locked inside PaymentService.
            | A second request cannot reserve the same payment.
            |
            */

            $reservation = $this->paymentService
                ->reserveRemainingPaymentInitiation(
                    $booking,
                    $client
                );

            $payment = $reservation['payment'];
            $reservedPaymentId = $payment->id;
            $token = $reservation['token'];

            /*
            |--------------------------------------------------------------------------
            | Request ZarinPal Payment
            |--------------------------------------------------------------------------
            |
            | Do not hold a database transaction during HTTP requests.
            |
            */

            $paymentData = app(ZarinPalService::class)
                ->requestPayment(
                    amount: (float) $payment->amount,
                    callbackUrl: config('services.zarinpal.callback_url'),
                    description: 'Dentistry booking remaining payment #' . $booking->id,
                    email: $client->email,
                    mobile: $client->phone
                );

            $authority = trim(
                (string) ($paymentData['authority'] ?? '')
            );

            $paymentUrl = trim(
                (string) ($paymentData['payment_url'] ?? '')
            );

            if ($authority === '' || $paymentUrl === '') {
                throw new RuntimeException(
                    'ZarinPal did not return valid payment information.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Complete Payment Initiation
            |--------------------------------------------------------------------------
            |
            | Authority is saved only when initiation token matches.
            |
            */

            $payment = $this->paymentService
                ->completeRemainingPaymentInitiation(
                    payment: $payment,
                    token: $token,
                    authority: $authority
                );

            return response()->json([
                'success' => true,
                'statusCode' => 200,
                'message' => 'Remaining payment started successfully.',
                'data' => [
                    'payment_id' => $payment->id,
                    'booking_id' => $booking->id,
                    'amount' => $payment->amount,
                    'type' => $payment->type,
                    'status' => $payment->status,
                    'gateway' => $payment->gateway,
                    'authority' => $payment->authority,
                    'payment_url' => $paymentUrl,
                ],
            ], 200);

        } catch (Throwable $e) {
            return $this->handleInitiationFailure(
                $e,
                $bookingId,
                $reservedPaymentId
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | ZarinPal Callback
    |--------------------------------------------------------------------------
    */


    public function callback(Request $request): RedirectResponse
    {
        $frontendUrl = rtrim(
            (string) config('services.dentistry.frontend_url'),
            '/'
        );

        $successUrl = $frontendUrl . '/payment/success';
        $failedUrl = $frontendUrl . '/payment/failed';

        $redirectTo = function (string $url, array $params = []): RedirectResponse {
            $query = http_build_query(
                array_filter($params, fn ($value) => $value !== null)
            );

            return redirect()->away(
                $url . ($query !== '' ? '?' . $query : '')
            );
        };

        $authority = trim((string) $request->query('Authority'));

        $status = strtoupper(
            trim((string) $request->query('Status'))
        );

        $payment = null;
        $transactionId = null;
        $gatewayVerified = false;

        try {
            if ($authority === '') {
                return $redirectTo($failedUrl, [
                    'reason' => 'missing_authority',
                ]);
            }

            $payment = Payment::query()
                ->where('authority', $authority)
                ->where('gateway', 'zarinpal')
                ->whereIn('type', ['deposit', 'remaining'])
                ->first();

            if (!$payment) {
                Log::warning('ZarinPal callback: payment not found', [
                    'authority' => $authority,
                    'status' => $status,
                ]);

                return $redirectTo($failedUrl, [
                    'reason' => 'payment_not_found',
                ]);
            }

            // Payment was already verified.
            if ($payment->status === 'paid') {
                return $redirectTo($successUrl, [
                    'payment_id' => $payment->id,
                    'booking_id' => $payment->booking_id,
                ]);
            }

            // Customer cancelled or gateway did not complete payment.
            if ($status !== 'OK') {
                Log::info('ZarinPal callback: payment not completed', [
                    'payment_id' => $payment->id,
                    'authority' => $authority,
                    'status' => $status,
                ]);

                return $redirectTo($failedUrl, [
                    'payment_id' => $payment->id,
                    'booking_id' => $payment->booking_id,
                    'reason' => 'cancelled_or_failed',
                ]);
            }

            if ($payment->status !== 'pending') {
                Log::warning('ZarinPal callback: invalid payment state', [
                    'payment_id' => $payment->id,
                    'payment_status' => $payment->status,
                ]);

                return $redirectTo($failedUrl, [
                    'payment_id' => $payment->id,
                    'booking_id' => $payment->booking_id,
                    'reason' => 'manual_review',
                ]);
            }

            // Payment amounts are stored in toman.
            // ZarinPalService converts toman to rial.
            $verifyResult = app(ZarinPalService::class)
                ->verifyPayment(
                    amount: (float) $payment->amount,
                    authority: $authority
                );

            $transactionId = trim(
                (string) ($verifyResult['ref_id'] ?? '')
            );

            if ($transactionId === '') {
                throw new RuntimeException(
                    'ZarinPal reference ID is missing.'
                );
            }

            $gatewayVerified = true;

            Log::info('ZarinPal payment verified by gateway', [
                'payment_id' => $payment->id,
                'booking_id' => $payment->booking_id,
                'transaction_id' => $transactionId,
                'amount' => $payment->amount,
            ]);

            // Save payment using existing PaymentService.
            if ($payment->type === 'deposit') {

                $paidPayment = $this->paymentService
                    ->markDepositAsPaid(
                        payment: $payment,
                        gateway: 'zarinpal',
                        transactionId: $transactionId,
                        verifiedAmount: (float) $payment->amount
                    );

            } elseif ($payment->type === 'remaining') {

                $paidPayment = $this->paymentService
                    ->markRemainingAsPaid(
                        payment: $payment,
                        gateway: 'zarinpal',
                        transactionId: $transactionId,
                        verifiedAmount: (float) $payment->amount
                    );

            } else {
                throw new RuntimeException(
                    'Unsupported payment type.'
                );
            }

            Log::info('ZarinPal payment finalized successfully', [
                'payment_id' => $paidPayment->id,
                'booking_id' => $paidPayment->booking_id,
                'transaction_id' => $paidPayment->transaction_id,
            ]);

            // Redirect customer to success page.
            return $redirectTo($successUrl, [
                'payment_id' => $paidPayment->id,
                'booking_id' => $paidPayment->booking_id,
            ]);

        } catch (Throwable $e) {

            Log::error('ZarinPal callback failed', [
                'payment_id' => $payment?->id,
                'booking_id' => $payment?->booking_id,
                'authority' => $authority,
                'transaction_id' => $transactionId,
                'gateway_verified' => $gatewayVerified,
                'error' => $e->getMessage(),
            ]);

            // Gateway confirmed payment, but local processing failed.
            // Do not tell the customer that their money was not paid.
            if ($gatewayVerified) {
                return $redirectTo($failedUrl, [
                    'payment_id' => $payment?->id,
                    'booking_id' => $payment?->booking_id,
                    'reason' => 'manual_review',
                ]);
            }

            return $redirectTo($failedUrl, [
                'payment_id' => $payment?->id,
                'booking_id' => $payment?->booking_id,
                'reason' => 'verification_failed',
            ]);
        }
    }


    /*
    |--------------------------------------------------------------------------
    | Validate Gateway Settings Before Payment Reservation
    |--------------------------------------------------------------------------
    */
    private function validateGatewayConfiguration(): void
    {
        $merchantId = trim((string) config('services.zarinpal.merchant_id', ''));

        if (
            $merchantId === '' ||
            in_array($merchantId, [
                'YOUR_MERCHANT_ID',
                'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx',
            ], true)
        ) {
            throw new RuntimeException('ZarinPal merchant ID is not configured.');
        }

        $callbackUrl = trim((string) config('services.zarinpal.callback_url', ''));
        $scheme = strtolower((string) parse_url($callbackUrl, PHP_URL_SCHEME));
        $sandbox = filter_var(
            config('services.zarinpal.sandbox', false),
            FILTER_VALIDATE_BOOLEAN
        );

        if (
            !filter_var($callbackUrl, FILTER_VALIDATE_URL) ||
            !in_array($scheme, ['http', 'https'], true)
        ) {
            throw new RuntimeException('ZarinPal callback URL is invalid.');
        }

        if (!$sandbox && $scheme !== 'https') {
            throw new RuntimeException('Production ZarinPal callback must use HTTPS.');
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Handle Uncertain Gateway Initiation (Do Not Auto-Retry)
    |--------------------------------------------------------------------------
    */
    private function handleInitiationFailure(
        Throwable $e,
        int $bookingId,
        ?int $reservedPaymentId
    ): JsonResponse {
        $requiresReview = $reservedPaymentId !== null;

        Log::error('Payment initiation failed', [
            'booking_id' => $bookingId,
            'payment_id' => $reservedPaymentId,
            'requires_review' => $requiresReview,
            'exception' => get_class($e),
            'error' => $e->getMessage(),
        ]);

        $statusCode = $requiresReview
            ? 409
            : ($e instanceof RuntimeException ? 422 : 500);

        return response()->json([
            'success' => false,
            'statusCode' => $statusCode,
            'message' => $requiresReview
                ? 'Payment initiation requires review. Do not retry until resolved.'
                : ($e instanceof RuntimeException
                    ? $e->getMessage()
                    : 'Failed to start payment.'),
            'data' => $requiresReview ? [
                'booking_id' => $bookingId,
                'payment_id' => $reservedPaymentId,
                'requires_review' => true,
            ] : null,
        ], $statusCode);
    }

    /*
    |--------------------------------------------------------------------------
    | Find Client Booking
    |--------------------------------------------------------------------------
    */

    private function findClientBooking(
        int $bookingId,
        int $clientId
    ): ?Booking {
        return Booking::query()
            ->where('id', $bookingId)
            ->where('client_id', $clientId)
            ->with([
                'bookingServices.service',
                'bookingServices.staff',
            ])
            ->first();
    }
}
