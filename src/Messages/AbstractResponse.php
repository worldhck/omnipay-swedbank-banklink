<?php

namespace Omnipay\SwedbankBanklink\Messages;

use Omnipay\Common\Message\AbstractResponse as BaseAbstractResponse;
use Omnipay\Common\Message\RequestInterface;

/**
 * Abstract Response for Swedbank V3 API
 * 
 * Base class for all Swedbank Payment Initiation API V3 responses.
 * 
 * @link https://pi.swedbank.com/developer?version=public_V3
 */
abstract class AbstractResponse extends BaseAbstractResponse
{
    /**
     * @var int
     */
    protected $statusCode;

    /**
     * Constructor
     *
     * @param RequestInterface $request
     * @param mixed $data
     * @param int $statusCode
     */
    public function __construct(RequestInterface $request, $data, int $statusCode = 200)
    {
        parent::__construct($request, $data);
        $this->statusCode = $statusCode;
    }

    /**
     * Is the response successful?
     *
     * @return bool
     */
    public function isSuccessful(): bool
    {
        return $this->statusCode >= 200 
            && $this->statusCode < 300 
            && !isset($this->data['error'])
            && !isset($this->data['_signature_invalid']);
    }

    /**
     * Get the error message from the response
     *
     * @return string|null
     */
    public function getMessage(): ?string
    {
        if (isset($this->data['_signature_invalid'])) {
            $msg = 'Invalid response signature from bank';
            if (isset($this->data['_signature_error'])) {
                $msg .= ': ' . $this->data['_signature_error'];
            }
            return $msg;
        }

        if (isset($this->data['error'])) {
            return $this->data['error'];
        }

        if (isset($this->data['message'])) {
            return $this->data['message'];
        }

        // V3 API error format: errorMessages.general[] and errorMessages.fields[]
        if (isset($this->data['errorMessages'])) {
            $errorMessages = $this->data['errorMessages'];
            $messages = [];

            // Collect general errors
            if (isset($errorMessages['general']) && is_array($errorMessages['general'])) {
                foreach ($errorMessages['general'] as $error) {
                    if (is_array($error) && isset($error['message'])) {
                        $messages[] = $error['message'];
                    }
                }
            }

            // Collect field-level errors
            if (isset($errorMessages['fields']) && is_array($errorMessages['fields'])) {
                foreach ($errorMessages['fields'] as $error) {
                    if (is_array($error) && isset($error['field'], $error['message'])) {
                        $messages[] = sprintf("%s: %s", $error['field'], $error['message']);
                    } elseif (is_array($error) && isset($error['message'])) {
                        $messages[] = $error['message'];
                    }
                }
            }

            if (!empty($messages)) {
                return implode('; ', $messages);
            }
        }

        if (isset($this->data['errors']) && is_array($this->data['errors'])) {
            $errors = [];
            foreach ($this->data['errors'] as $error) {
                if (is_array($error) && isset($error['message'])) {
                    $errors[] = $error['message'];
                } elseif (is_string($error)) {
                    $errors[] = $error;
                }
            }
            return implode('; ', $errors);
        }

        return null;
    }

    /**
     * Get the error code from the response
     *
     * @return string|null
     */
    public function getCode(): ?string
    {
        if (isset($this->data['code'])) {
            return (string) $this->data['code'];
        }

        // V3 API error format: errorMessages.general[] and errorMessages.fields[]
        if (isset($this->data['errorMessages'])) {
            $errorMessages = $this->data['errorMessages'];

            // Try general errors first
            if (isset($errorMessages['general']) && is_array($errorMessages['general'])) {
                foreach ($errorMessages['general'] as $error) {
                    if (is_array($error) && isset($error['code'])) {
                        return (string) $error['code'];
                    }
                }
            }

            // Fall back to field errors
            if (isset($errorMessages['fields']) && is_array($errorMessages['fields'])) {
                foreach ($errorMessages['fields'] as $error) {
                    if (is_array($error) && isset($error['code'])) {
                        return (string) $error['code'];
                    }
                }
            }
        }

        if (isset($this->data['errors']) && is_array($this->data['errors'])) {
            foreach ($this->data['errors'] as $error) {
                if (is_array($error) && isset($error['code'])) {
                    return (string) $error['code'];
                }
            }
        }

        return null;
    }

    /**
     * Get HTTP status code
     *
     * @return int
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * Get transaction reference (payment ID from Swedbank)
     * V3 API returns 'id' field for payment creation responses
     *
     * @return string|null
     */
    public function getTransactionReference(): ?string
    {
        return $this->data['id'] ?? null;
    }

    /**
     * Get transaction ID (merchant's order ID)
     *
     * @return string|null
     */
    public function getTransactionId(): ?string
    {
        return $this->data['merchantTransactionId'] ?? null;
    }

    /**
     * Get field-level errors (V3 API format)
     * Returns array of field errors: ['fieldName' => 'error message', ...]
     *
     * @return array
     */
    public function getFieldErrors(): array
    {
        $fieldErrors = [];

        if (isset($this->data['errorMessages']['fields']) && is_array($this->data['errorMessages']['fields'])) {
            foreach ($this->data['errorMessages']['fields'] as $error) {
                if (is_array($error) && isset($error['field'], $error['message'])) {
                    $fieldErrors[$error['field']] = $error['message'];
                }
            }
        }

        return $fieldErrors;
    }

    /**
     * Get general errors (V3 API format)
     * Returns array of general errors: ['code' => 'message', ...]
     *
     * @return array
     */
    public function getGeneralErrors(): array
    {
        $generalErrors = [];

        if (isset($this->data['errorMessages']['general']) && is_array($this->data['errorMessages']['general'])) {
            foreach ($this->data['errorMessages']['general'] as $error) {
                if (is_array($error) && isset($error['code'], $error['message'])) {
                    $generalErrors[$error['code']] = $error['message'];
                }
            }
        }

        return $generalErrors;
    }
}
