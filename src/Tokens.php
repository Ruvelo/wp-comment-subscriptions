<?php

declare(strict_types=1);

namespace Ruvelo\WP\CommentSubscriptions;

/**
 * Every link in every email is either a random confirmation token (only its
 * SHA-256 is stored) or an HMAC-signed token. Nothing in a link is a
 * guessable ID: change one character and it stops working.
 *
 * Signed tokens are keyed with a random secret kept in the database, mixed
 * with the site's AUTH salt from wp-config.php, so a leaked database alone
 * can't forge links.
 */
final class Tokens
{
    public const SECRET_OPTION = 'ruvelo_comment_subscriptions_secret';

    public static function secret(): string
    {
        $secret = get_option(self::SECRET_OPTION);
        if (! is_string($secret) || strlen($secret) < 32) {
            $secret = bin2hex(random_bytes(32));
            update_option(self::SECRET_OPTION, $secret, false);
        }

        return $secret;
    }

    /**
     * Replaces the secret: every manage and unsubscribe link sent so far stops working.
     */
    public static function rotate(): void
    {
        update_option(self::SECRET_OPTION, bin2hex(random_bytes(32)), false);
    }

    /**
     * @param array<string, scalar> $data
     * @param int $ttl Seconds the token works for; 0 for no expiry (negative: already expired).
     */
    public static function sign(string $purpose, array $data, int $ttl = 0): string
    {
        $payload = (string) wp_json_encode(['p' => $purpose, 'd' => $data, 'x' => $ttl !== 0 ? time() + $ttl : 0]);

        return self::encode($payload).'.'.self::encode(hash_hmac('sha256', $payload, self::key(), true));
    }

    /**
     * The token's data, or null when it's forged, for another purpose or expired.
     *
     * @return array<string, mixed>|null
     */
    public static function verify(string $purpose, string $token): ?array
    {
        $parts = explode('.', trim($token));
        if (count($parts) !== 2 || strlen($token) > 1000) {
            return null;
        }
        $payload = self::decode($parts[0]);
        $signature = self::decode($parts[1]);
        if ($payload === null || $signature === null || ! hash_equals(hash_hmac('sha256', $payload, self::key(), true), $signature)) {
            return null;
        }
        $data = json_decode($payload, true);
        if (! is_array($data) || ($data['p'] ?? null) !== $purpose || ! is_array($data['d'] ?? null)) {
            return null;
        }
        $expires = (int) ($data['x'] ?? 0);
        if ($expires !== 0 && $expires < time()) {
            return null;
        }

        return $data['d'];
    }

    /**
     * A random confirmation token: 192 bits, URL-safe.
     */
    public static function random(): string
    {
        return self::encode(random_bytes(24));
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * A short keyed fingerprint of an address, to tie a token to it without
     * putting the address in the link.
     */
    public static function fingerprint(string $email): string
    {
        return substr(hash_hmac('sha256', strtolower(trim($email)), self::key()), 0, 16);
    }

    private static function key(): string
    {
        return hash_hmac('sha256', self::secret(), wp_salt('auth'), true);
    }

    private static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '='); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- URL-safe encoding of signed tokens.
    }

    private static function decode(string $text): ?string
    {
        if ($text === '' || preg_match('/^[A-Za-z0-9_-]+$/', $text) !== 1) {
            return null;
        }
        $bytes = base64_decode(strtr($text, '-_', '+/'), true); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Reading a signed token back.

        return $bytes === false ? null : $bytes;
    }
}
