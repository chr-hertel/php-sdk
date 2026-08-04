<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Stateless;

use Mcp\Exception\InvalidArgumentException;
use Mcp\Exception\RequestStateException;

/**
 * Seals and verifies the opaque `requestState` a server carries across the
 * rounds of a multi round-trip request (SEP-2322).
 *
 * The state exists because the modern era keeps no session: a server that
 * needs more input has nowhere to remember why, so it hands its own context to
 * the client and gets it back on the retry. That round trip is the whole
 * security problem. The value passes through the client, so on return it is
 * attacker-controlled — the spec requires servers to treat it as such and to
 * reject anything that fails verification.
 *
 * So the wire value is `base64url(payload) . '.' . base64url(HMAC)`, and
 * {@see self::verify()} refuses it unless the MAC matches and the stamped
 * expiry is still in the future. The payload is signed, not encrypted: it is
 * protected against tampering, not against being read, so callers must not put
 * anything in it the client may not see.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class RequestStateCodec
{
    /**
     * Rejecting a key this short is not pedantry: the MAC is the only thing
     * standing between a client-held blob and the server trusting its
     * contents, and a short key makes it forgeable.
     */
    public const MINIMUM_KEY_BYTES = 32;

    private const ALGORITHM = 'sha256';

    public function __construct(
        private readonly string $key,
        private readonly int $ttlSeconds = 600,
    ) {
        if (\strlen($this->key) < self::MINIMUM_KEY_BYTES) {
            throw new InvalidArgumentException(\sprintf('The requestState signing key must be at least %d bytes, got %d.', self::MINIMUM_KEY_BYTES, \strlen($this->key)));
        }

        if ($this->ttlSeconds < 1) {
            throw new InvalidArgumentException(\sprintf('The requestState TTL must be at least one second, got %d.', $this->ttlSeconds));
        }
    }

    /**
     * @param array<string, mixed> $payload server context to carry to the retry — never secrets
     */
    public function mint(array $payload, ?int $now = null): string
    {
        $body = self::encode(json_encode([
            'exp' => ($now ?? time()) + $this->ttlSeconds,
            'data' => $payload,
        ], \JSON_THROW_ON_ERROR));

        return $body.'.'.self::encode($this->sign($body));
    }

    /**
     * @return array<string, mixed> the payload that was sealed
     *
     * @throws RequestStateException when the value is malformed, unsigned by this key, or expired
     */
    public function verify(string $state, ?int $now = null): array
    {
        $parts = explode('.', $state);

        if (2 !== \count($parts)) {
            throw new RequestStateException('malformed');
        }

        [$body, $mac] = $parts;

        $expected = $this->sign($body);
        $actual = self::decode($mac);

        // Constant-time, so the comparison cannot be turned into an oracle that
        // reveals the correct MAC one byte at a time.
        if (null === $actual || !hash_equals($expected, $actual)) {
            throw new RequestStateException('mac');
        }

        $decoded = self::decode($body);

        if (null === $decoded) {
            throw new RequestStateException('malformed');
        }

        try {
            /** @var array<string, mixed> $claims */
            $claims = json_decode($decoded, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new RequestStateException('malformed');
        }

        if (!\is_array($claims) || !\is_int($claims['exp'] ?? null) || !\is_array($claims['data'] ?? null)) {
            throw new RequestStateException('malformed');
        }

        // Bounds how long a captured state stays useful. It does not make the
        // state single-use — a server that must consume one exactly once has to
        // enforce that itself, with storage this codec deliberately does not have.
        if ($claims['exp'] < ($now ?? time())) {
            throw new RequestStateException('expired');
        }

        return $claims['data'];
    }

    private function sign(string $body): string
    {
        return hash_hmac(self::ALGORITHM, $body, $this->key, true);
    }

    private static function encode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function decode(string $encoded): ?string
    {
        $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);

        return false === $decoded ? null : $decoded;
    }
}
