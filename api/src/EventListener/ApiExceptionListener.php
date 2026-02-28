<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Exception\DomainException;
use App\Exception\EntityNotFoundException;
use App\Exception\ExternalServiceException;
use App\Exception\ValidationException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class ApiExceptionListener
{
    public function onKernelException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();

        if (!str_starts_with($request->getPathInfo(), '/api/')) {
            return;
        }

        $e = $event->getThrowable();

        [$statusCode, $message] = match (true) {
            $e instanceof EntityNotFoundException => [Response::HTTP_NOT_FOUND, $e->getMessage()],
            $e instanceof DomainException => [Response::HTTP_FORBIDDEN, $e->getMessage()],
            $e instanceof ValidationException => [Response::HTTP_UNPROCESSABLE_ENTITY, $e->getMessage()],
            $e instanceof ExternalServiceException => [Response::HTTP_BAD_GATEWAY, $e->getMessage()],
            $e instanceof HttpExceptionInterface => [$e->getStatusCode(), $e->getMessage()],
            default => [Response::HTTP_INTERNAL_SERVER_ERROR, 'An unexpected error occurred.'],
        };

        $body = ['error' => ['code' => $statusCode, 'message' => $message]];

        if ($e instanceof ValidationException) {
            $errors = [];
            foreach ($e->getViolations() as $violation) {
                $errors[] = [
                    'field' => $violation->getPropertyPath(),
                    'message' => $violation->getMessage(),
                ];
            }
            $body['error']['details'] = $errors;
        }

        $event->setResponse(new JsonResponse($body, $statusCode));
    }
}
