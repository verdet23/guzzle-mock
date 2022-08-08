<?php

declare(strict_types=1);

namespace Verdet\GuzzleMock\Tests\Unit\Exception;

use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\TestCase;
use Verdet\GuzzleMock\Exception\GuzzleMockException;

class GuzzleMockExceptionTest extends TestCase
{
    public function testSuitableResponseNotFound(): void
    {
        $request = new Request(
            'POST',
            'https://example.com/create',
            [
                'Accept-Encoding' => 'gzip',
                'Content-Type' => 'application/json',
            ],
            json_encode(['foo' => 'bar', 'query' => 8472])
        );

        $exception = GuzzleMockException::suitableResponseNotFound($request);

        $this->assertSame(
            "Can`t find suitable response for request [array (
  'method' => 'POST',
  'uri' => 'https://example.com/create',
  'body' => '{\"foo\":\"bar\",\"query\":8472}',
  'protocol_version' => '1.1',
  'headers' => 
  array (
    'Host' => 
    array (
      0 => 'example.com',
    ),
    'Accept-Encoding' => 
    array (
      0 => 'gzip',
    ),
    'Content-Type' => 
    array (
      0 => 'application/json',
    ),
  ),
)]",
            $exception->getMessage()
        );
    }
}
