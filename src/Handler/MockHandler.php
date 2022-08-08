<?php

declare(strict_types=1);

namespace Verdet\GuzzleMock\Handler;

use Countable;
use Exception;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise as P;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\TransferStats;
use GuzzleHttp\Utils;
use InvalidArgumentException;
use OutOfBoundsException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Throwable;
use TypeError;
use Verdet\GuzzleMock\Exception\GuzzleMockException;

class MockHandler implements Countable
{
    /**
     * @var array <int, array{RequestInterface, ResponseInterface|Throwable|PromiseInterface|callable}>
     */
    private array $queue = [];

    private ?RequestInterface $lastRequest;

    /**
     * @var array <string, mixed>
     */
    private array $lastOptions = [];

    /**
     * @var callable|null
     */
    private $onFulfilled;

    /**
     * @var callable|null
     */
    private $onRejected;

    /**
     * Creates a new MockHandler that uses the default handler stack list of
     * middlewares.
     *
     * @param array<int, array{RequestInterface, ResponseInterface|Throwable|PromiseInterface|callable}>|null $queue
     */
    public static function createWithMiddleware(
        array $queue = null,
        callable $onFulfilled = null,
        callable $onRejected = null
    ): HandlerStack {
        return HandlerStack::create(new self($queue, $onFulfilled, $onRejected));
    }

    /**
     * @param array<int, array{RequestInterface, ResponseInterface|Throwable|PromiseInterface|callable}>|null $queue
     */
    public function __construct(array $queue = null, callable $onFulfilled = null, callable $onRejected = null)
    {
        $this->onFulfilled = $onFulfilled;
        $this->onRejected = $onRejected;

        if ($queue) {
            foreach ($queue as $item) {
                $this->append(...$item);
            }
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    public function __invoke(RequestInterface $request, array $options): PromiseInterface
    {
        if (!$this->queue) {
            throw new OutOfBoundsException('Mock queue is empty');
        }

        if (isset($options['delay']) && \is_numeric($options['delay'])) {
            \usleep((int) $options['delay'] * 1000);
        }

        $this->lastRequest = $request;
        $this->lastOptions = $options;

        $response = $this->getSuitableResponse($request);

        if (isset($options['on_headers'])) {
            if (!\is_callable($options['on_headers'])) {
                throw new InvalidArgumentException('on_headers must be callable');
            }
            try {
                $options['on_headers']($response);
            } catch (Exception $e) {
                $msg = 'An error was encountered during the on_headers event';
                $response = new RequestException($msg, $request, $response, $e);
            }
        }

        if (\is_callable($response)) {
            $response = $response($request, $options);
        }

        $response = $response instanceof Throwable
            ? P\Create::rejectionFor($response)
            : P\Create::promiseFor($response);

        return $response->then(
            function (?ResponseInterface $value) use ($request, $options) {
                $this->invokeStats($request, $options, $value);
                if ($this->onFulfilled) {
                    ($this->onFulfilled)($value);
                }

                if (null !== $value && isset($options['sink'])) {
                    $contents = (string) $value->getBody();
                    $sink = $options['sink'];

                    if (\is_resource($sink)) {
                        \fwrite($sink, $contents);
                    } elseif (\is_string($sink)) {
                        \file_put_contents($sink, $contents);
                    } elseif ($sink instanceof StreamInterface) {
                        $sink->write($contents);
                    }
                }

                return $value;
            },
            function ($reason) use ($request, $options) {
                $this->invokeStats($request, $options, null, $reason);
                if ($this->onRejected) {
                    ($this->onRejected)($reason);
                }

                return P\Create::rejectionFor($reason);
            }
        );
    }

    /**
     * @param ResponseInterface|Throwable|PromiseInterface|callable $response
     */
    public function append(RequestInterface $request, mixed $response): void
    {
        if (
            $response instanceof ResponseInterface
            || $response instanceof Throwable
            || $response instanceof PromiseInterface
            || \is_callable($response)
        ) {
            $this->queue[] = [$request, $response];
        } else {
            $message = sprintf(
                'Expected $response to be a Response, Promise, Throwable or callable. Found %s',
                Utils::describeType($response)
            );
            throw new TypeError($message);
        }
    }

    public function getLastRequest(): ?RequestInterface
    {
        return $this->lastRequest;
    }

    /**
     * @return array<string, mixed>
     */
    public function getLastOptions(): array
    {
        return $this->lastOptions;
    }

    public function count(): int
    {
        return \count($this->queue);
    }

    public function reset(): void
    {
        $this->queue = [];
    }

    private function getSuitableResponse(RequestInterface $request): mixed
    {
        foreach (array_reverse($this->queue) as $key => $fixture) {
            $queueRequest = $fixture[0];
            if (
                !$this->isSuitableMethod($queueRequest, $request)
                || !$this->isSuitableUri($queueRequest, $request)
                || !$this->isSuitableHeaders($queueRequest, $request)
                || !$this->isSuitableBody($queueRequest, $request)
            ) {
                continue;
            }

            unset($this->queue[$key]);

            return $fixture[1];
        }

        throw GuzzleMockException::suitableResponseNotFound($request);
    }

    private function isSuitableMethod(RequestInterface $expectedRequest, RequestInterface $actualRequest): bool
    {
        return $this->isSuitableString($expectedRequest->getMethod(), $actualRequest->getMethod());
    }

    private function isSuitableUri(RequestInterface $expectedRequest, RequestInterface $actualRequest): bool
    {
        return $this->isSuitableString(
            urldecode($expectedRequest->getUri()->__toString()),
            urldecode($actualRequest->getUri()->__toString())
        );
    }

    private function isSuitableBody(RequestInterface $expectedRequest, RequestInterface $actualRequest): bool
    {
        return $this->isSuitableString(
            $expectedRequest->getBody()->__toString(),
            $actualRequest->getBody()->__toString()
        );
    }

    private function isSuitableHeaders(RequestInterface $expectedRequest, RequestInterface $actualRequest): bool
    {
        return empty($this->arrayDiffRecursive($expectedRequest->getHeaders(), $actualRequest->getHeaders()));
    }

    /**
     * @param array<mixed> $a
     * @param array<mixed> $b
     *
     * @return array<mixed>
     */
    private function arrayDiffRecursive(array $a, array $b): array
    {
        $result = [];

        foreach ($a as $key => $value) {
            if (array_key_exists($key, $b)) {
                if (is_array($value)) {
                    $diff = $this->arrayDiffRecursive($value, $b[$key]);
                    if (count($diff)) {
                        $result[$key] = $diff;
                    }
                } elseif (!$this->isSuitableString($value, $b[$key])) {
                    $result[$key] = $value;
                }
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    private function isSuitableString(string $expected, string $actual): bool
    {
        return 0 === strcasecmp($expected, $actual) || @preg_match('/^' . $expected . '$/i', $actual);
    }

    /**
     * @param array<string,mixed> $options
     */
    private function invokeStats(
        RequestInterface $request,
        array $options,
        ResponseInterface $response = null,
        mixed $reason = null
    ): void {
        if (isset($options['on_stats'])) {
            $transferTime = $options['transfer_time'] ?? 0;
            $stats = new TransferStats($request, $response, $transferTime, $reason);
            ($options['on_stats'])($stats);
        }
    }
}
