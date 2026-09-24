<?php

declare(strict_types=1);

namespace Sikuwa\Whatsapp\Tests;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Sikuwa\Whatsapp\Http\HttpExecutor;

/**
 * Gateway palsu untuk pengujian: membalas dari antrean respons dan merekam
 * setiap request yang masuk.
 *
 * Dipasang lewat opsi `httpClient` pada Client/provider, jadi tidak ada
 * monkey-patching global dan tidak ada jaringan yang tersentuh.
 */
final class MockBackend
{
    public MockHandler $handler;

    /** @var array<int,array{request:RequestInterface,response:Response,options:array<string,mixed>}> */
    public array $history = [];

    private ClientInterface $client;

    /**
     * @param array<int,Response|ConnectException> $responses Dibalas berurutan.
     */
    public function __construct(array $responses = [])
    {
        $this->handler = new MockHandler($responses);

        $stack = HandlerStack::create($this->handler);
        $stack->push(Middleware::history($this->history));

        $this->client = new GuzzleClient(['handler' => $stack]);
    }

    public function client(): ClientInterface
    {
        return $this->client;
    }

    /**
     * Executor yang menembak ke backend ini — untuk menguji provider secara
     * langsung tanpa lewat {@see \Sikuwa\Whatsapp\Client}.
     *
     * @param array<string,string> $defaultHeaders
     */
    public function executor(float $timeout = 10.0, array $defaultHeaders = []): HttpExecutor
    {
        return new HttpExecutor($this->client, $timeout, $defaultHeaders);
    }

    /** @param array<string,mixed> $body */
    public static function json(array $body, int $status = 200): Response
    {
        return new Response(
            $status,
            ['Content-Type' => 'application/json'],
            (string) json_encode($body)
        );
    }

    /** Respons 2xx yang body-nya bukan JSON. */
    public static function raw(string $body = '<html>oops</html>', int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'text/html'], $body);
    }

    public static function timeout(): ConnectException
    {
        return new ConnectException(
            'cURL error 28: Operation timed out after 10000 milliseconds',
            new Request('POST', 'https://example.test'),
            null,
            ['errno' => 28]
        );
    }

    public static function connectionFailure(): ConnectException
    {
        return new ConnectException(
            'cURL error 6: Could not resolve host',
            new Request('POST', 'https://example.test'),
            null,
            ['errno' => 6]
        );
    }

    public function count(): int
    {
        return \count($this->history);
    }

    public function lastRequest(): ?RequestInterface
    {
        return $this->history === [] ? null : $this->history[array_key_last($this->history)]['request'];
    }

    public function lastBody(): string
    {
        return (string) ($this->lastRequest()?->getBody() ?? '');
    }

    /** @return array<string,mixed> */
    public function lastJson(): array
    {
        return json_decode($this->lastBody(), true) ?? [];
    }

    /**
     * Body form-encoded dari request terakhir, sudah di-decode.
     *
     * @return array<string,string>
     */
    public function lastForm(): array
    {
        $form = [];
        parse_str($this->lastBody(), $form);

        /** @var array<string,string> $form */
        return $form;
    }

    public function lastHeader(string $name): string
    {
        return $this->lastRequest()?->getHeaderLine($name) ?? '';
    }
}
