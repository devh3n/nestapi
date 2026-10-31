<?php

declare(strict_types=1);

namespace NestApi\Http;

use ArrayAccess;
use LogicException;

/**
 * Read-only view of a successful API response. Array access reads top-level
 * keys of `data`; get() follows dot paths.
 *
 * @implements ArrayAccess<array-key, mixed>
 */
final class Response implements ArrayAccess
{
    /**
     * @param mixed $data   Object (as array), list, string or null.
     * @param mixed $meta   Service-defined; null when absent.
     * @param array<string, mixed> $source
     */
    private function __construct(
        public readonly mixed $data,
        public readonly mixed $meta,
        public readonly array $source,
    ) {
    }

    /**
     * @param array<string, mixed> $payload Decoded JSON of a successful response.
     */
    public static function fromArray(array $payload): self
    {
        $source = $payload['source'] ?? [];

        return new self($payload['data'] ?? null, $payload['meta'] ?? null, is_array($source) ? $source : []);
    }

    /**
     * Read from `data` by dot path, e.g. `input.age` or `items.0.title`.
     * A key that exists with a null value returns null, not $default.
     */
    public function get(string $path, mixed $default = null): mixed
    {
        $value = $this->data;

        foreach (explode('.', $path) as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return $default;
            }

            $value = $value[$key];
        }

        return $value;
    }

    public function offsetExists(mixed $offset): bool
    {
        return is_array($this->data) && isset($this->data[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return is_array($this->data) ? ($this->data[$offset] ?? null) : null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new LogicException('Response is read-only.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new LogicException('Response is read-only.');
    }
}
