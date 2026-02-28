<?php

declare(strict_types=1);

namespace App\Exception;

use RuntimeException;
use Symfony\Component\Validator\ConstraintViolationListInterface;

final class ValidationException extends RuntimeException
{
    public function __construct(
        private readonly ConstraintViolationListInterface $violations,
        string $message = 'Validation failed.',
    ) {
        parent::__construct($message);
    }

    public function getViolations(): ConstraintViolationListInterface
    {
        return $this->violations;
    }
}
