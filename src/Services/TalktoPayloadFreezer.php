<?php

namespace Mrezdev\LaravelTalkto\Services;

use BackedEnum;
use Closure;
use DateTimeInterface;
use Generator;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Collection;
use JsonException;
use JsonSerializable;
use Mrezdev\LaravelTalkto\Exceptions\TalktoJsonEncodingException;
use Mrezdev\LaravelTalkto\Exceptions\TalktoUnsupportedPayloadValueException;
use ReflectionClass;
use SplObjectStorage;
use stdClass;
use Stringable;
use Throwable;
use Traversable;
use UnitEnum;

/**
 * @internal Freezes host-supplied outgoing payloads before persistence, hashing, and sending.
 */
class TalktoPayloadFreezer
{
    private const MAX_DEPTH = 512;

    private const MAX_PATH_LENGTH = 240;

    public function __construct(private readonly TalktoJsonEncoder $encoder) {}

    /**
     * @return array<int|string, mixed>|null
     */
    public function freezePayload(mixed $payload): ?array
    {
        if ($payload === null) {
            return null;
        }

        $activeObjects = new SplObjectStorage;
        $frozenObjects = new SplObjectStorage;

        if (is_array($payload)) {
            return $this->jsonRoundTrip($this->freezeValue($payload, '$', 0, $activeObjects, $frozenObjects));
        }

        if (is_bool($payload) || is_int($payload) || is_float($payload) || is_string($payload)) {
            return $this->jsonRoundTrip([
                'value' => $this->freezeValue($payload, '$', 0, $activeObjects, $frozenObjects),
            ]);
        }

        if ($payload instanceof Arrayable || $payload instanceof JsonSerializable) {
            $frozen = $this->freezeValue($payload, '$', 0, $activeObjects, $frozenObjects);

            if ($frozen !== null && ! is_array($frozen)) {
                $frozen = ['value' => $frozen];
            }

            return $this->jsonRoundTrip($frozen);
        }

        throw TalktoUnsupportedPayloadValueException::atPath(
            '$',
            'top-level payload must be arrayable, json serializable, scalar, array, or null',
            'payload_top_level_type'
        );
    }

    /**
     * @param  SplObjectStorage<object, true>  $activeObjects
     * @param  SplObjectStorage<object, mixed>  $frozenObjects
     */
    private function freezeValue(
        mixed $value,
        string $path,
        int $depth,
        SplObjectStorage $activeObjects,
        SplObjectStorage $frozenObjects
    ): mixed {
        if ($depth > self::MAX_DEPTH) {
            throw TalktoUnsupportedPayloadValueException::atPath(
                $path,
                'payload exceeds the maximum supported nesting depth',
                'payload_depth_exceeded'
            );
        }

        if ($value === null || is_bool($value) || is_int($value)) {
            return $value;
        }

        if (is_string($value)) {
            $this->assertValidUtf8($value, $path);

            return $value;
        }

        if (is_float($value)) {
            if (! is_finite($value)) {
                throw TalktoUnsupportedPayloadValueException::atPath(
                    $path,
                    'non-finite floats are not supported',
                    'payload_non_finite_float'
                );
            }

            return $value;
        }

        if (is_array($value)) {
            $frozen = [];

            foreach ($value as $key => $item) {
                $frozen[$key] = $this->freezeValue($item, $this->childPath($path, $key), $depth + 1, $activeObjects, $frozenObjects);
            }

            return $frozen;
        }

        if (is_resource($value)) {
            throw TalktoUnsupportedPayloadValueException::atPath(
                $path,
                'resources and streams are not supported',
                'payload_resource'
            );
        }

        if (! is_object($value)) {
            throw TalktoUnsupportedPayloadValueException::atPath(
                $path,
                'value type ['.$this->typeName($value).'] is not supported',
                'payload_unsupported_type'
            );
        }

        if ($value instanceof Closure) {
            throw TalktoUnsupportedPayloadValueException::atPath(
                $path,
                'closures are not supported',
                'payload_closure'
            );
        }

        if ($value instanceof Generator) {
            throw TalktoUnsupportedPayloadValueException::atPath(
                $path,
                'generators are not supported',
                'payload_generator'
            );
        }

        if ($value instanceof BackedEnum) {
            return $this->freezeValue($value->value, $path, $depth + 1, $activeObjects, $frozenObjects);
        }

        if ($value instanceof UnitEnum) {
            throw TalktoUnsupportedPayloadValueException::atPath(
                $path,
                'pure enums are not supported; use a backed enum or scalar value',
                'payload_unit_enum'
            );
        }

        if ($value instanceof Collection) {
            return $this->freezeObjectResult(
                $value,
                $path,
                $depth,
                $activeObjects,
                $frozenObjects,
                fn (): array => $value->all(),
                'Collection'
            );
        }

        if ($value instanceof Arrayable) {
            return $this->freezeObjectResult(
                $value,
                $path,
                $depth,
                $activeObjects,
                $frozenObjects,
                fn (): mixed => $value->toArray(),
                'Arrayable'
            );
        }

        if ($value instanceof JsonSerializable) {
            return $this->freezeObjectResult(
                $value,
                $path,
                $depth,
                $activeObjects,
                $frozenObjects,
                fn (): mixed => $value->jsonSerialize(),
                'JsonSerializable'
            );
        }

        if ($value instanceof DateTimeInterface) {
            throw TalktoUnsupportedPayloadValueException::atPath(
                $path,
                'native DateTimeInterface values are not supported; use Carbon/JsonSerializable or pass a formatted string explicitly',
                'payload_datetime_unsupported'
            );
        }

        if ($value instanceof Traversable) {
            throw TalktoUnsupportedPayloadValueException::atPath(
                $path,
                'traversable objects without an explicit supported serialization contract are not supported',
                'payload_traversable_object'
            );
        }

        if (is_callable($value)) {
            throw TalktoUnsupportedPayloadValueException::atPath(
                $path,
                'callable objects without an explicit supported serialization contract are not supported',
                'payload_callable_object'
            );
        }

        if ($value instanceof Stringable) {
            throw TalktoUnsupportedPayloadValueException::atPath(
                $path,
                'Stringable objects are not cast implicitly; pass a string explicitly',
                'payload_stringable_object'
            );
        }

        $this->assertSupportedPublicObject($value, $path);

        return $this->freezeObjectResult(
            $value,
            $path,
            $depth,
            $activeObjects,
            $frozenObjects,
            fn (): array => get_object_vars($value),
            'object public properties'
        );
    }

    /**
     * @param  SplObjectStorage<object, true>  $activeObjects
     * @param  SplObjectStorage<object, mixed>  $frozenObjects
     */
    private function freezeObjectResult(
        object $value,
        string $path,
        int $depth,
        SplObjectStorage $activeObjects,
        SplObjectStorage $frozenObjects,
        Closure $resolver,
        string $source
    ): mixed {
        if ($activeObjects->contains($value)) {
            throw TalktoUnsupportedPayloadValueException::atPath(
                $path,
                'circular object references are not supported',
                'payload_circular_reference'
            );
        }

        if ($frozenObjects->contains($value)) {
            return $frozenObjects[$value];
        }

        $activeObjects->attach($value, true);

        try {
            try {
                $resolved = $resolver();
            } catch (Throwable $throwable) {
                throw TalktoUnsupportedPayloadValueException::atPath(
                    $path,
                    $source.' payload conversion failed',
                    'payload_object_conversion_failed',
                    $throwable
                );
            }

            $frozen = $this->freezeValue($resolved, $path, $depth + 1, $activeObjects, $frozenObjects);
            $frozenObjects[$value] = $frozen;

            return $frozen;
        } finally {
            $activeObjects->detach($value);
        }
    }

    /**
     * @return array<int|string, mixed>|null
     */
    private function jsonRoundTrip(mixed $payload): ?array
    {
        try {
            $json = $this->encoder->encode($payload, depth: self::MAX_DEPTH);
            $decoded = json_decode($json, true, self::MAX_DEPTH, JSON_THROW_ON_ERROR);
        } catch (TalktoJsonEncodingException|JsonException $exception) {
            throw TalktoUnsupportedPayloadValueException::atPath(
                '$',
                'payload cannot be represented as deterministic JSON',
                'payload_json_encoding_failed',
                $exception
            );
        }

        return is_array($decoded) || $decoded === null ? $decoded : ['value' => $decoded];
    }

    private function assertValidUtf8(string $value, string $path): void
    {
        if (function_exists('mb_check_encoding') && ! mb_check_encoding($value, 'UTF-8')) {
            throw TalktoUnsupportedPayloadValueException::atPath(
                $path,
                'strings must be valid UTF-8',
                'payload_invalid_utf8'
            );
        }
    }

    private function assertSupportedPublicObject(object $value, string $path): void
    {
        $reflection = new ReflectionClass($value);

        if ($reflection->isInternal() && ! $value instanceof stdClass) {
            throw TalktoUnsupportedPayloadValueException::atPath(
                $path,
                'internal objects without an explicit supported serialization contract are not supported',
                'payload_internal_object'
            );
        }

        if (! $value instanceof stdClass && get_object_vars($value) === []) {
            throw TalktoUnsupportedPayloadValueException::atPath(
                $path,
                'objects without public payload properties are not supported',
                'payload_unsupported_object'
            );
        }
    }

    private function childPath(string $path, int|string $key): string
    {
        $key = (string) $key;

        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key) === 1) {
            return $this->truncatePath($path.'.'.$key);
        }

        $key = mb_substr($key, 0, 80);
        $encoded = json_encode($key, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $this->truncatePath($path.'['.($encoded ?: '"?"').']');
    }

    private function truncatePath(string $path): string
    {
        if (mb_strlen($path) <= self::MAX_PATH_LENGTH) {
            return $path;
        }

        return mb_substr($path, 0, self::MAX_PATH_LENGTH - 3).'...';
    }

    private function typeName(mixed $value): string
    {
        return is_resource($value) ? 'resource:'.get_resource_type($value) : get_debug_type($value);
    }
}
