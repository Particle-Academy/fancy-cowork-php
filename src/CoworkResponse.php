<?php

declare(strict_types=1);

namespace FancyCowork;

use Illuminate\Http\JsonResponse;

/** What every handler returns: a status and a JSON body. A refusal's body is `{reason, blockedUntil?}`. */
final readonly class CoworkResponse
{
    /** @param  array<string, mixed>  $body */
    public function __construct(
        public int $status,
        public array $body,
    ) {}

    /** @param  array<string, mixed>  $extra */
    public static function refuse(int $status, string $reason, array $extra = []): self
    {
        return new self($status, ['reason' => $reason, ...$extra]);
    }

    public function toResponse(): JsonResponse
    {
        $response = new JsonResponse($this->body === [] ? new \stdClass : $this->body, $this->status);
        if ($this->status === 204) {
            $response->setContent('');
        }
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
