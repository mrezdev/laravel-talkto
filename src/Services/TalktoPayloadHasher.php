<?php

namespace Mrezdev\LaravelTalkto\Services;

/**
 * Advanced public utility for deterministic Talkto payload hashes.
 */
class TalktoPayloadHasher
{
    public function __construct(private readonly ?TalktoJsonEncoder $json = null) {}

    public function hash(mixed $payload): string
    {
        return hash('sha256', $this->encoder()->encodeCanonical($payload));
    }

    public function normalize(mixed $value): mixed
    {
        return $this->encoder()->normalize($value, sortAssociativeKeys: true);
    }

    private function encoder(): TalktoJsonEncoder
    {
        return $this->json ?? new TalktoJsonEncoder;
    }
}
