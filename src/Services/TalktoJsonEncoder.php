<?php

namespace Mrezdev\LaravelTalkto\Services;

use JsonException;
use JsonSerializable;
use Mrezdev\LaravelTalkto\Exceptions\TalktoJsonEncodingException;
use Throwable;

/**
 * @internal Deterministic JSON encoding for Talkto-controlled hashes and HTTP bodies.
 */
class TalktoJsonEncoder
{
    public const DEFAULT_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

    public function encode(mixed $value, int $flags = self::DEFAULT_FLAGS, int $depth = 512): string
    {
        return $this->encodeWithPrecision($value, '-1', $flags, $depth);
    }

    public function encodeCanonical(mixed $value, int $flags = self::DEFAULT_FLAGS, int $depth = 512): string
    {
        return $this->encode($this->normalize($value, sortAssociativeKeys: true), $flags, $depth);
    }

    public function normalize(mixed $value, bool $sortAssociativeKeys = false): mixed
    {
        if (is_array($value)) {
            $normalized = [];

            foreach ($value as $key => $item) {
                $normalized[$key] = $this->normalize($item, $sortAssociativeKeys);
            }

            if ($sortAssociativeKeys && ! array_is_list($normalized)) {
                ksort($normalized, SORT_STRING);
            }

            return $normalized;
        }

        if ($value instanceof JsonSerializable) {
            return $this->normalize($value->jsonSerialize(), $sortAssociativeKeys);
        }

        if (is_object($value)) {
            return $this->normalize(get_object_vars($value), $sortAssociativeKeys);
        }

        if (is_float($value) && ! is_finite($value)) {
            throw new TalktoJsonEncodingException('Unable to encode Talkto JSON: non-finite float values are not supported.');
        }

        return $value;
    }

    public function encodeWithPrecision(
        mixed $value,
        string $serializePrecision,
        int $flags = self::DEFAULT_FLAGS,
        int $depth = 512
    ): string {
        $originalPrecision = ini_get('serialize_precision');

        try {
            ini_set('serialize_precision', $serializePrecision);

            $json = json_encode($this->normalize($value), $flags, $depth);

            if (! is_string($json)) {
                throw new TalktoJsonEncodingException('Unable to encode Talkto JSON.');
            }

            return $json;
        } catch (TalktoJsonEncodingException $exception) {
            throw $exception;
        } catch (JsonException $exception) {
            throw new TalktoJsonEncodingException('Unable to encode Talkto JSON: '.$exception->getMessage(), 0, $exception);
        } catch (Throwable $exception) {
            throw new TalktoJsonEncodingException('Unable to encode Talkto JSON.', 0, $exception);
        } finally {
            ini_set('serialize_precision', (string) $originalPrecision);
        }
    }
}
