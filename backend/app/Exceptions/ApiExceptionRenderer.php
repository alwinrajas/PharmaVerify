<?php

namespace App\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Turns any exception raised on an API route into the application's standard
 * JSON envelope with a message a business user can actually read.
 *
 * Technical detail is written to the log, never to the response.
 */
class ApiExceptionRenderer
{
    public static function render(Throwable $e, Request $request): ?JsonResponse
    {
        if (! $request->is('api/*') && ! $request->expectsJson()) {
            return null;
        }

        return match (true) {
            $e instanceof ValidationException => self::respond(
                'The information supplied is not valid. Please correct the highlighted fields.',
                422,
                ['errors' => $e->errors()]
            ),

            $e instanceof AuthenticationException => self::respond(
                'Your session has expired. Please sign in again.',
                401
            ),

            $e instanceof AuthorizationException => self::respond(
                'You do not have permission to perform this action.',
                403
            ),

            $e instanceof ModelNotFoundException,
            $e instanceof NotFoundHttpException => self::respond(
                'The requested record could not be found.',
                404
            ),

            $e instanceof BusinessRuleException => self::respond($e->getMessage(), $e->getStatusCode()),

            $e instanceof QueryException => self::logged(
                $e,
                'The operation could not be completed because of a database error. Please try again or contact your administrator.',
                500
            ),

            $e instanceof HttpExceptionInterface => self::respond(
                $e->getMessage() !== '' ? $e->getMessage() : 'The request could not be completed.',
                $e->getStatusCode()
            ),

            default => self::logged(
                $e,
                'Something went wrong while processing your request. Please try again or contact your administrator.',
                500
            ),
        };
    }

    private static function logged(Throwable $e, string $message, int $status): JsonResponse
    {
        report($e);

        $payload = [];

        if (config('app.debug')) {
            $payload['debug'] = [
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'file' => $e->getFile().':'.$e->getLine(),
            ];
        }

        return self::respond($message, $status, $payload);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private static function respond(string $message, int $status, array $extra = []): JsonResponse
    {
        return response()->json(array_merge([
            'success' => false,
            'message' => $message,
        ], $extra), $status);
    }
}
