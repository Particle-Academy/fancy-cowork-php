<?php

declare(strict_types=1);

namespace FancyCowork\Http;

use FancyCowork\CoworkManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The HTTP face of {@see CoworkManager}. Thin on purpose: every decision is the
 * manager's, so the routes and a host driving the manager directly behave the
 * same. Bodies are JSON; every response is `Cache-Control: no-store`.
 */
final class CoworkController
{
    public function __construct(private readonly CoworkManager $cowork) {}

    public function create(Request $request): JsonResponse
    {
        return $this->cowork->create($request, self::body($request))->toResponse();
    }

    public function show(Request $request, string $session): JsonResponse
    {
        return $this->cowork->sync($request, $session)->toResponse();
    }

    public function activate(Request $request, string $session): JsonResponse
    {
        return $this->cowork->activate($request, $session, self::body($request))->toResponse();
    }

    public function join(Request $request, string $session): JsonResponse
    {
        return $this->cowork->join($request, $session, self::body($request))->toResponse();
    }

    public function consent(Request $request, string $session): JsonResponse
    {
        return $this->cowork->consent($request, $session)->toResponse();
    }

    public function leave(Request $request, string $session): JsonResponse
    {
        return $this->cowork->leave($request, $session)->toResponse();
    }

    public function end(Request $request, string $session): JsonResponse
    {
        return $this->cowork->end($request, $session)->toResponse();
    }

    public function follow(Request $request, string $session): JsonResponse
    {
        return $this->cowork->follow($request, $session, self::body($request))->toResponse();
    }

    public function intent(Request $request, string $session): JsonResponse
    {
        return $this->cowork->intent($request, $session, self::body($request))->toResponse();
    }

    public function auth(Request $request): JsonResponse
    {
        $body = self::body($request);

        return $this->cowork->authorizeChannel($request, $body['socket_id'] ?? null, $body['channel_name'] ?? null)->toResponse();
    }

    /**
     * The decoded JSON body, keys in the order received (the size limit is
     * measured on the intent as received). Form posts fall back to the input bag.
     *
     * @return array<string, mixed>
     */
    private static function body(Request $request): array
    {
        $raw = (string) $request->getContent();
        if ($raw !== '') {
            $decoded = json_decode($raw, true, 64);

            return is_array($decoded) ? $decoded : [];
        }

        return $request->request->all();
    }
}
