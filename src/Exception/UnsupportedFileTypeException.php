<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Unsupported file type exception.
 */
class UnsupportedFileTypeException extends \Exception
{
    /**
     * {@inheritdoc}
     */
    public function __construct($message = 'This file type is not supported.', $code = 0, \Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
