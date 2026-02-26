<?php

namespace Omnipay\SwedbankBanklink\Messages;

use Omnipay\Common\Exception\InvalidRequestException;

/**
 * Fetch Transaction Request (Step 3)
 * 
 * Poll payment status or complete payment after return from bank
 * 
 * OpenAPI Response Schema: StatusResponseV3
 * API Endpoint: GET /public/api/v3/transactions/{id}/status
 * Documentation: https://pi.swedbank.com/developer?version=public_V3
 */
class FetchTransactionRequest extends AbstractRequest
{
    /**
     * Get HTTP method (GET for status polling)
     *
     * @return string
     */
    public function getHttpMethod(): string
    {
        return 'GET';
    }

    /**
     * Get the data for this request
     *
     * @return array
     * @throws InvalidRequestException
     */
    public function getData(): array
    {
        $this->validate('transactionReference');

        // GET request has no body
        return [];
    }

    /**
     * Get the endpoint URL
     *
     * @return string
     */
    public function getEndpoint(): string
    {
        // V3 API format: /public/api/v3/transactions/{id}/status
        return $this->getBaseUrl() . '/public/api/v3/transactions/' .
               $this->getTransactionReference() . '/status';
    }

    /**
     * Send the request (override for GET request)
     *
     * @param mixed $data The data to send
     * @return FetchTransactionResponse
     */
    public function sendData($data): AbstractResponse
    {
        $url = $this->getEndpoint();

        // For GET request, body should be empty
        $body = '';

        // Generate JWS signature with empty body
        $jwsSignature = \Omnipay\SwedbankBanklink\Utils\JwsSignature::sign(
            $body,
            $url,
            $this->getMerchantId(),
            $this->getCountry(),
            $this->getPrivateKey(),
            $this->getAlgorithm()
        );

        // Log the request for debugging purposes
        $this->logRequest([
            'method' => 'GET',
            'url' => $url,
            'body' => '(empty for GET)',
            'jws_signature' => $jwsSignature,
            'merchant_id' => $this->getMerchantId(),
            'country' => $this->getCountry(),
            'algorithm' => $this->getAlgorithm(),
            'transaction_id' => $this->getTransactionReference(),
        ]);

        // Prepare headers
        $headers = [
            'Accept' => 'application/json',
            'x-jws-signature' => $jwsSignature,
        ];

        try {
            $httpResponse = $this->httpClient->request(
                'GET',
                $url,
                $headers
            );

            // Get response body - must be exact bytes for signature verification
            $bodyStream = $httpResponse->getBody();
            if (method_exists($bodyStream, 'rewind')) {
                $bodyStream->rewind();
            }
            $responseBody = $bodyStream->getContents();
            $responseData = json_decode($responseBody, true) ?? [];

            // Verify response signature
            $responseJws = $httpResponse->getHeader('x-jws-signature');
            if ($responseJws) {
                // getHeader() can return either string or array, ensure it's a string
                if (is_array($responseJws)) {
                    $responseJws = $responseJws[0] ?? null;
                }
                
                if ($responseJws) {
                    try {
                        $isValid = \Omnipay\SwedbankBanklink\Utils\JwsSignature::verify(
                            $responseJws,
                            $responseBody,
                            $this->getBankPublicKey(),
                            120 // 120 seconds tolerance
                        );
                    } catch (\Exception $signatureEx) {
                        // Signature verification threw an exception
                        $responseData['_signature_error'] = $signatureEx->getMessage();
                        $isValid = false;
                        
                        // Log signature error
                        $this->logResponse([
                            'status' => $httpResponse->getStatusCode(),
                            'body' => $responseBody,
                            'signature_valid' => false,
                            'signature_error' => $signatureEx->getMessage(),
                            'transaction_id' => $this->getTransactionReference(),
                            'timestamp' => date('Y-m-d H:i:s'),
                        ]);
                    }
                } else {
                    $responseData['_signature_error'] = 'No signature header found';
                    $isValid = false;
                    
                    // Log missing signature
                    $this->logResponse([
                        'status' => $httpResponse->getStatusCode(),
                        'body' => $responseBody,
                        'signature_valid' => false,
                        'signature_error' => 'No signature header found',
                        'transaction_id' => $this->getTransactionReference(),
                        'timestamp' => date('Y-m-d H:i:s'),
                    ]);
                }

                if (!$isValid) {
                    $responseData['_signature_invalid'] = true;
                }
            }

            // Log response
            $this->logResponse([
                'status' => $httpResponse->getStatusCode(),
                'body' => $responseBody,
                'signature_valid' => ($isValid ?? true),
                'response_data' => $responseData,
                'transaction_id' => $this->getTransactionReference(),
                'timestamp' => date('Y-m-d H:i:s'),
            ]);

            return $this->createResponse($responseData, $httpResponse->getStatusCode());
        } catch (\Exception $e) {
            // Log the exception/error
            $this->logResponse([
                'error' => true,
                'error_message' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'exception_class' => get_class($e),
                'transaction_id' => $this->getTransactionReference(),
                'timestamp' => date('Y-m-d H:i:s'),
            ]);
            
            return $this->createResponse([
                'error' => $e->getMessage(),
                'status' => 'ERROR'
            ], 500);
        }
    }

    /**
     * Create response object
     *
     * @param array $data
     * @param int $statusCode
     * @return FetchTransactionResponse
     */
    protected function createResponse(array $data, int $statusCode): AbstractResponse
    {
        return new FetchTransactionResponse($this, $data, $statusCode);
    }
}
