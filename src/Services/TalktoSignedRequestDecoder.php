<?php

namespace Mrezdev\LaravelTalkto\Services;

use Illuminate\Http\Request;
use JsonException;
use Mrezdev\LaravelTalkto\Exceptions\InvalidTalktoRequestJsonException;
use stdClass;

/**
 * Decodes signed Talkto endpoint envelopes from the immutable HTTP body.
 */
class TalktoSignedRequestDecoder
{
    public function decode(Request $request): array
    {
        if (! $this->isJsonRequest($request)) {
            return $request->all();
        }

        $content = $request->getContent();

        if (trim($content) === '') {
            throw InvalidTalktoRequestJsonException::invalidJson();
        }

        try {
            $decoded = json_decode($content, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw InvalidTalktoRequestJsonException::invalidJson($exception);
        }

        if (! $decoded instanceof stdClass) {
            throw InvalidTalktoRequestJsonException::invalidJson();
        }

        return $this->toArray($decoded);
    }

    public function isJsonRequest(Request $request): bool
    {
        if ($request->isJson()) {
            return true;
        }

        $contentType = strtolower(trim((string) $request->headers->get('Content-Type', '')));
        $mime = trim(explode(';', $contentType, 2)[0]);

        return $mime === 'application/json'
            || (str_starts_with($mime, 'application/') && str_ends_with($mime, '+json'));
    }

    private function toArray(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $items = [];

            foreach (get_object_vars($value) as $key => $item) {
                $items[$key] = $this->toArray($item);
            }

            return $items;
        }

        if (is_array($value)) {
            $items = [];

            foreach ($value as $key => $item) {
                $items[$key] = $this->toArray($item);
            }

            return $items;
        }

        return $value;
    }
}
