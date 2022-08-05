<?php

declare(strict_types=1);

namespace Verdet\GuzzleMock\Exception;

use Exception;
use Psr\Http\Message\RequestInterface;

class GuzzleMockException extends Exception
{
    public static function suitableResponseNotFound(RequestInterface $request): self
    {
        $data = [
            'method' => $request->getMethod(),
            'uri' => $request->getUri()->__toString(),
            'body' => $request->getBody()->__toString(),
        ];

        if (!empty($request->getProtocolVersion())) {
            $data['protocol_version'] = $request->getProtocolVersion();
        }

        if (!empty($request->getHeaders())) {
            $data['headers'] = $request->getHeaders();
        }

        return new self(sprintf('Can`t find suitable response for request [%s]', var_export($data, true)));
    }

    private function __construct(string $message = '')
    {
        parent::__construct($message);
    }
}
