<?php

declare(strict_types=1);

namespace Verdet\GuzzleMock\Tests\Unit\Handler;

use Exception;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Stream;
use GuzzleHttp\TransferStats;
use OutOfBoundsException;
use PHPUnit\Framework\TestCase;
use TypeError;
use Verdet\GuzzleMock\Exception\GuzzleMockException;
use Verdet\GuzzleMock\Handler\MockHandler;
use RuntimeException;

class MockHandlerTest extends TestCase
{
    public function testIsCountable(): void
    {
        $handler = new MockHandler([[new Request('GET', 'https://example.com'), new Response()]]);

        $this->assertCount(1, $handler);
    }

    public function testEmptyHandlerIsCountable(): void
    {
        $handler = new MockHandler();

        $this->assertCount(0, $handler);
    }

    public function testEnsuresEachAppendOnCreationIsValid(): void
    {
        $this->expectException(TypeError::class);
        /* @phpstan-ignore-next-line */
        new MockHandler([new Request('GET', 'https://example.com'), 'd']);
    }

    public function testEnsuresEachAppendIsValid(): void
    {
        $this->expectException(TypeError::class);

        $mock = new MockHandler();

        /* @phpstan-ignore-next-line */
        $mock->append(new Request('GET', 'https://example.com'), 'd');
    }

    public function testCanQueueExceptions(): void
    {
        $request = new Request('GET', 'https://example.com');

        $queuedException = new Exception('a');
        $mock = new MockHandler([[$request, $queuedException]]);
        $p = $mock($request, []);
        try {
            $p->wait();
            self::fail();
        } catch (Exception $exception) {
            $this->assertSame($queuedException, $exception);
        }
    }

    public function testCanGetLastRequestAndOptions(): void
    {
        $response = new Response();
        $request = new Request('GET', 'https://example.com');

        $mock = new MockHandler([[$request, $response]]);
        $mock($request, ['foo' => 'bar']);

        $this->assertSame($request, $mock->getLastRequest());
        $this->assertSame(['foo' => 'bar'], $mock->getLastOptions());
    }

    public function testSinkFilename(): void
    {
        $filename = \sys_get_temp_dir() . '/mock_test_' . \uniqid('', true);
        $response = new Response(200, [], 'TEST CONTENT');
        $request = new Request('GET', '/');

        $mock = new MockHandler([[$request, $response]]);
        $p = $mock($request, ['sink' => $filename]);
        $p->wait();

        $this->assertFileExists($filename);
        $this->assertStringEqualsFile($filename, 'TEST CONTENT');

        \unlink($filename);
    }

    public function testSinkResource(): void
    {
        $file = \tmpfile() ?: throw new RuntimeException();
        $meta = \stream_get_meta_data($file);
        $response = new Response(200, [], 'TEST CONTENT');
        $request = new Request('GET', '/');

        $mock = new MockHandler([[$request, $response]]);
        $p = $mock($request, ['sink' => $file]);
        $p->wait();

        $this->assertFileExists($meta['uri']);
        $this->assertStringEqualsFile($meta['uri'], 'TEST CONTENT');
    }

    public function testSinkStream(): void
    {
        $stream = new Stream(\tmpfile() ?: throw new RuntimeException());
        $response = new Response(200, [], 'TEST CONTENT');
        $request = new Request('GET', '/');

        $mock = new MockHandler([[$request, $response]]);
        $p = $mock($request, ['sink' => $stream]);
        $p->wait();

        $this->assertFileExists($stream->getMetadata('uri'));
        $this->assertStringEqualsFile($stream->getMetadata('uri'), 'TEST CONTENT');
    }

    public function testCanEnqueueCallables(): void
    {
        $response = new Response();
        $fn = static function ($request, $options) use ($response) {
            return $response;
        };
        $request = new Request('GET', 'https://example.com');

        $mock = new MockHandler([[$request, $fn]]);
        $p = $mock($request, ['foo' => 'bar']);
        $this->assertSame($response, $p->wait());
    }

    public function testEnsuresOnHeadersIsCallable(): void
    {
        $response = new Response();
        $request = new Request('GET', 'https://example.com');
        $mock = new MockHandler([[$request, $response]]);

        $this->expectException(\InvalidArgumentException::class);
        $mock($request, ['on_headers' => 'error!']);
    }

    public function testRejectsPromiseWhenOnHeadersFails(): void
    {
        $response = new Response();
        $request = new Request('GET', 'https://example.com');
        $mock = new MockHandler([[$request, $response]]);
        $promise = $mock($request, [
            'on_headers' => static function () {
                throw new Exception('test');
            },
        ]);

        $this->expectException(RequestException::class);
        $this->expectExceptionMessage('An error was encountered during the on_headers event');
        $promise->wait();
    }

    public function testInvokesOnFulfilled(): void
    {
        $response = new Response();
        $request = new Request('GET', 'https://example.com');

        $mock = new MockHandler([[$request, $response]], static function ($v) use (&$c) {
            $c = $v;
        });
        $mock($request, [])->wait();
        $this->assertSame($response, $c);
    }

    public function testInvokesOnRejected(): void
    {
        $request = new Request('GET', 'https://example.com');
        $exception = new Exception('a');
        $c = null;
        $mock = new MockHandler([[$request, $exception]], null, static function ($v) use (&$c) {
            $c = $v;
        });
        $mock($request, [])->wait(false);
        $this->assertSame($exception, $c);
    }

    public function testThrowsWhenQueueEmpty(): void
    {
        $mock = new MockHandler();
        $request = new Request('GET', 'http:s//example.com');

        $this->expectException(OutOfBoundsException::class);
        $mock($request, []);
    }

    public function testThrowsWhenNoMoreResponses(): void
    {
        $response = new Response();
        $request = new Request('GET', 'https://example.com');

        $mock = new MockHandler([[$request, $response]]);
        $mock($request, []);

        $this->expectException(OutOfBoundsException::class);
        $mock($request, []);
    }

    public function testCanCreateWithDefaultMiddleware(): void
    {
        $response = new Response(500);
        $request = new Request('GET', 'https://example.com');

        $mock = MockHandler::createWithMiddleware([[$request, $response]]);

        $this->expectException(BadResponseException::class);
        $p = $mock($request, ['http_errors' => true]);
        if ($p instanceof PromiseInterface) {
            $p->wait();
        }
    }

    public function testInvokesOnStatsFunctionForResponse(): void
    {
        $response = new Response();
        $request = new Request('GET', 'https://example.com');

        $mock = new MockHandler([[$request, $response]]);

        /* @var TransferStats|null $stats */
        $stats = null;
        $onStats = static function (TransferStats $s) use (&$stats) {
            $stats = $s;
        };
        $p = $mock($request, ['on_stats' => $onStats]);
        $p->wait();
        $this->assertNotNull($stats);
        $this->assertSame($response, $stats->getResponse());
        $this->assertSame($request, $stats->getRequest());
    }

    public function testInvokesOnStatsFunctionForError(): void
    {
        $exception = new Exception('a');

        $request = new Request('GET', 'https://example.com');

        $c = null;
        $mock = new MockHandler([[$request, $exception]], null, static function ($v) use (&$c) {
            $c = $v;
        });

        /** @var TransferStats|null $stats */
        $stats = null;
        $onStats = static function (TransferStats $s) use (&$stats) {
            $stats = $s;
        };
        $mock($request, ['on_stats' => $onStats])->wait(false);

        $this->assertNotNull($stats);
        $this->assertSame($exception, $stats->getHandlerErrorData());
        $this->assertNull($stats->getResponse());
        $this->assertSame($request, $stats->getRequest());
    }

    public function testTransferTime(): void
    {
        $exception = new Exception('a');
        $request = new Request('GET', 'https://example.com');

        $c = null;
        $mock = new MockHandler([[$request, $exception]], null, static function ($v) use (&$c) {
            $c = $v;
        });
        $stats = null;
        $onStats = static function (TransferStats $s) use (&$stats) {
            $stats = $s;
        };
        $mock($request, ['on_stats' => $onStats, 'transfer_time' => 0.4])->wait(false);

        $this->assertNotNull($stats);
        $this->assertEquals(0.4, $stats->getTransferTime());
    }

    public function testResetQueue(): void
    {
        $exception = new Exception('a');
        $response = new Response();
        $request = new Request('GET', 'https://example.com');

        $mock = new MockHandler([[$request, $response], [$request, $exception]]);

        $this->assertCount(2, $mock);

        $mock->reset();
        $this->assertEmpty($mock);

        $mock->append($request, $response);
        $this->assertCount(1, $mock);
    }

    public function testSuitableResponseNotFound(): void
    {
        $this->expectException(GuzzleMockException::class);

        $response = new Response();
        $request = new Request('GET', 'https://example.com');

        $mock = new MockHandler([[$request, $response]]);

        $requestActual = new Request('POST', 'https://example.com/create');

        $mock($requestActual, []);
    }

    public function testFindSuitableByContentType(): void
    {
        $requestXML = new Request(
            'GET',
            'https://example.com/page',
            ['Accept-Charset' => 'utf-8', 'Accept' => 'application/xml']
        );
        $responseXML = new Response(
            200,
            ['Accept' => 'application/xml'],
            '<?xml version="1.0" encoding="UTF-8"?>'
        );

        $requestJson = new Request('GET', 'https://example.com/page', ['Accept' => 'application/json']);
        $responseJson = new Response(
            200,
            ['Content-Type' => 'application/json'],
            '{}'
        );

        $mock = new MockHandler([[$requestJson, $responseJson], [$requestXML, $responseXML]]);

        $result = $mock($requestJson, [])->wait();

        $this->assertSame($responseJson, $result);
    }

    public function testObtainCoupleResult(): void
    {
        $requestXML = new Request(
            'GET',
            'https://example.com/page',
            ['Accept-Charset' => 'utf-8', 'Accept' => 'application/xml']
        );
        $responseXML = new Response(
            200,
            ['Accept' => 'application/xml'],
            '<?xml version="1.0" encoding="UTF-8"?>'
        );

        $requestJson = new Request('GET', 'https://example.com/page', ['Accept' => 'application/json']);
        $responseJson = new Response(
            200,
            ['Content-Type' => 'application/json'],
            '{}'
        );

        $requestHTML = new Request('GET', 'https://example.com/page', ['Accept' => 'text/html']);
        $responseHTML = new Response(
            200,
            ['Content-Type' => 'text/htnl'],
            '<html></html>'
        );

        $mock = new MockHandler(
            [
                [$requestJson, $responseJson],
                [$requestXML, $responseXML],
                [$requestHTML, $responseHTML]
            ]
        );


        $result = $mock($requestHTML, [])->wait();
        $this->assertSame($responseHTML, $result);

        $result = $mock($requestXML, [])->wait();
        $this->assertSame($responseXML, $result);

        $result = $mock($requestJson, [])->wait();
        $this->assertSame($responseJson, $result);
    }
}
