<?php

namespace App\Services\Oci;

use RuntimeException;

class OciException extends RuntimeException
{
    public function __construct(
        string $message,
        int $ociCode = 500,
        private ?string $ociDetails = null,
    ) {
        parent::__construct($message, $ociCode);
    }

    /**
     * @return array{success: false, error: true, message: string, code: int, details: string|null}
     */
    public function toArray(): array
    {
        return [
            'success' => false,
            'error' => true,
            'message' => $this->getMessage(),
            'code' => $this->getCode(),
            'details' => $this->ociDetails,
        ];
    }
}
