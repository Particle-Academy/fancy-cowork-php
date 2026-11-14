<?php

declare(strict_types=1);

namespace FancyCowork;

/**
 * Who a request is, as the host's `identify` resolver says. An opaque ref, an
 * optional label and attributes, and `actingFor` when the host's own auth runs
 * one person inside another's account. The package records both refs on every
 * event and interprets neither; it has no mechanism of its own for acting as
 * someone else.
 */
final readonly class Participant
{
    /** @param  array<string, mixed>|null  $attributes */
    public function __construct(
        public string $ref,
        public ?string $label = null,
        public ?array $attributes = null,
        public ?string $actingFor = null,
    ) {}

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['ref'] ?? ''),
            isset($data['label']) ? (string) $data['label'] : null,
            isset($data['attributes']) && is_array($data['attributes']) ? $data['attributes'] : null,
            isset($data['actingFor']) ? (string) $data['actingFor'] : null,
        );
    }
}
