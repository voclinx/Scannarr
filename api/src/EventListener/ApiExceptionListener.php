<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Exception\DomainException;
use App\Exception\EntityNotFoundException;
use App\Exception\ExternalServiceException;
use App\Exception\ValidationException;
use Stringable;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class ApiExceptionListener
{
    public function onKernelException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();

        if (!str_starts_with($request->getPathInfo(), '/api/')) {
            return;
        }

        $exception = $event->getThrowable();
        [$statusCode, $body] = $this->buildErrorResponse($exception);

        $event->setResponse(new JsonResponse($body, $statusCode));
    }

    /**
     * Build the error response body and HTTP status code from an exception.
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function buildErrorResponse(Throwable $exception): array
    {
        [$statusCode, $message] = match (true) {
            $exception instanceof EntityNotFoundException => [Response::HTTP_NOT_FOUND, $exception->getMessage()],
            $exception instanceof DomainException => [Response::HTTP_FORBIDDEN, $exception->getMessage()],
            $exception instanceof ValidationException => [Response::HTTP_UNPROCESSABLE_ENTITY, $exception->getMessage()],
            $exception instanceof ExternalServiceException => [Response::HTTP_BAD_GATEWAY, $exception->getMessage()],
            $exception instanceof HttpExceptionInterface => [$exception->getStatusCode(), $exception->getMessage()],
            default => [Response::HTTP_INTERNAL_SERVER_ERROR, 'An unexpected error occurred.'],
        };

        $body = ['error' => ['code' => $statusCode, 'message' => $message]];

        if ($exception instanceof ValidationException) {
            $body['error']['details'] = $this->formatViolations($exception);
        }

        return [$statusCode, $body];
    }

    /**
     * Format validation violations into an array of field/message pairs.
     *
     * @return array<int, array{field: string, message: string|Stringable}>
     */
    private function formatViolations(ValidationException $exception): array
    {
        $errors = [];
        foreach ($exception->getViolations() as $violation) {
            $errors[] = [
                'field' => $violation->getPropertyPath(),
                'message' => $violation->getMessage(),
            ];
        }

        return $errors;
    }
}
