<?php

declare(strict_types=1);

namespace Tests\Feature;

use Stripe\HttpClient\ClientInterface;

final class StripeTestClient implements ClientInterface
{
    /** @var list<array{method: string, url: string, headers: list<string>, params: array<string, mixed>}> */
    public array $requests = [];

    /** @param  array<string, string>  $responses  URL fragment → JSON body */
    public function __construct(public array $responses = []) {}

    /**
     * @param  array<string, mixed>  $object
     */
    public static function event(string $type, string $eventId, array $object): string
    {
        return (string) json_encode([
            'id' => $eventId,
            'object' => 'event',
            'type' => $type,
            'data' => ['object' => $object],
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function json(array $data): string
    {
        return (string) json_encode($data);
    }

    public static function sign(string $secret, string $payload, ?int $timestamp = null): string
    {
        $t = $timestamp ?? time();

        return 't='.$t.',v1='.hash_hmac('sha256', $t.'.'.$payload, $secret);
    }

    /**
     * @param  list<string>  $headers
     * @param  array<string, mixed>  $params
     * @return array{string, int, array<string, string>}
     */
    #[\Override]
    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $this->requests[] = [
            'method' => (string) $method,
            'url' => (string) $absUrl,
            'headers' => array_values((array) $headers),
            'params' => (array) $params,
        ];

        foreach ($this->responses as $fragment => $body) {
            if (str_contains((string) $absUrl, $fragment)) {
                return [(string) $body, 200, []];
            }
        }

        throw new \RuntimeException('Unexpected Stripe request: '.$method.' '.$absUrl);
    }
}
