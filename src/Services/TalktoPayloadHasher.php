<?php

namespace Mrezdev\LaravelTalkto\Services;

use JsonException;
use JsonSerializable;
use RuntimeException;

class TalktoPayloadHasher
{
    public function hash(mixed $payload): string
    {
        try {
            $json = json_encode(
                $this->normalize($payload),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
        } catch (JsonException $exception) {
            throw new RuntimeException('Unable to encode Talkto payload for hashing.', 0, $exception);
        }

        return hash('sha256', $json);
    }

    public function normalize(mixed $value): mixed
    {
        if (is_array($value)) {
            if (! $this->isAssocArray($value)) {
                return array_map(fn (mixed $item): mixed => $this->normalize($item), $value);
            }

            ksort($value, SORT_STRING);

            foreach ($value as $key => $item) {
                $value[$key] = $this->normalize($item);
            }

            return $value;
        }

        if ($value instanceof JsonSerializable) {
            return $this->normalize($value->jsonSerialize());
        }

        if (is_object($value)) {
            return $this->normalize(get_object_vars($value));
        }

        return $value;
    }

    private function isAssocArray(array $value): bool
    {
        return array_keys($value) !== range(0, count($value) - 1);
    }
}
