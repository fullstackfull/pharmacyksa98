<?php

namespace App\Services\Fulfillment\Support;

/**
 * Removes credentials, and partially masks personal data, from anything about to be logged or shown.
 *
 * Applied to every outbound request/response summary and every stored webhook payload, so that
 * `provider_api_logs` and the admin screens that read it can never leak a password, token or secret —
 * and hold only a masked phone/e-mail for the customer.
 */
final class SecretRedactor
{
    public const MASK = '***';

    /** Any key containing one of these (case-insensitive, punctuation ignored) is fully redacted. */
    private const SECRET_FRAGMENTS = [
        'password', 'passwd', 'secret', 'authorization', 'accesstoken', 'refreshtoken', 'apikey', 'bearer',
        'privatekey', 'credential', 'signature',
        /*
        | The field NAMES this application stores as credentials, which the rules above did not all cover.
        |
        | Found by arming the leak guard across the whole suite: `client_id` reached the log on every Amazon token call while
        | `client_secret` and `refresh_token` beside it were masked, because the rules above are about words like "secret" and
        | "token" and an identifier field is named neither. `ChannelCredentialStore::SECRET_KEYS` is the list of what this
        | application actually treats as a credential, and `SecretRedactorCoversEveryStoredSecretTest` drives itself from that
        | list -- so a credential field added tomorrow fails until its name is covered here, instead of quietly appearing in a
        | table that is kept for weeks and read by staff.
        |
        | Each is the NORMALISED name (punctuation stripped, lower case), so `lwa_client_id`, `client_id` and
        | `default_project_code` are all caught by the fragment they contain.
        */
        'clientid', 'accesskeyid', 'keyid', 'projectcode', 'channelidentifier',
    ];

    private const SECRET_EXACT = ['token', 'key', 'pass', 'pwd', 'jwt', 'username'];

    private const PHONE_KEYS = ['phone', 'mobile', 'receiverphone', 'receiverphone2', 'senderphone', 'senderphone2', 'driverphone', 'customerphone'];

    private const EMAIL_KEYS = ['email', 'receiveremail', 'customeremail'];

    public static function redact(mixed $data): mixed
    {
        if (is_array($data)) {
            $result = [];
            foreach ($data as $key => $value) {
                $result[$key] = is_string($key) ? self::redactValue($key, $value) : self::redact($value);
            }

            return $result;
        }

        return is_string($data) ? self::redactString($data) : $data;
    }

    /**
     * Credentials written into free text: an exception message, a response body, a header line, a query string. A pattern that
     * cannot run (it hit a limit) gives up the whole text rather than let it through.
     */
    public static function redactString(string $text): string
    {
        foreach ([
            // A PEM private key, whole or cut short, with real line breaks or JSON-escaped ones. It goes first: a name in front of it
            // (`private_key=-----BEGIN…`) would otherwise take only the first line as its value and leave the key itself.
            '/-----BEGIN [A-Z ]{0,30}PRIVATE KEY(?: BLOCK)?-----(?:[A-Za-z0-9+\/=\s]|\\\\(?:[nrt\/]|u[0-9a-fA-F]{4}))*+(?:-----END [A-Z ]{0,30}PRIVATE KEY(?: BLOCK)?-----)?/' => self::MASK,
            // A header line carries its whole value: `Authorization: Basic …`, `Cookie: a=b; c=d`, `X-Api-Key: …`. It is applied to one
            // string at a time, never to a whole encoded document, because the rest of the line goes with it.
            '/\b(authorization|proxy-authorization|cookie|set-cookie|x-api-key|x-auth-token)\s*[:=][^\r\n]*/i' => '$1: ' . self::MASK,
            '/\b(Bearer)\s+[A-Za-z0-9\-._~+\/]{6,}=*/i' => '$1 ' . self::MASK,
            // A Basic, Token or Digest credential is at least twelve characters and is either long (sixteen), or holds a digit, one of + / =, or a
            // capital letter after its first character (base64 does); the word that follows "Basic" in a sentence ("Basic Care Cream",
            // "Basic Moisturizing") is plain, and capitalised at its start only.
            '/\b(Basic|Token|Digest)\s+(?=(?-i:[A-Za-z0-9\-._~+\/]*[0-9=+\/]|[A-Za-z0-9\-._~+\/]+[A-Z])|[A-Za-z0-9\-._~+\/]{16})[A-Za-z0-9\-._~+\/]{12,}=*/i' => '$1 ' . self::MASK,
            // `password=hunter2`, `api_token: abc`, `DB_PASSWORD=…`, `"secret":"…"` inside a message or a query string. A name may be joined to
            // what it is a password or token OF with underscores or hyphens; the prefix is bounded so no input can make the scan slow.
            '/\b((?:[a-z0-9]{1,20}[_-]){0,4}(?:password|passwd|pwd|secret|api[_-]?key|app[_-]?key|private[_-]?key|access[_-]?token|refresh[_-]?token|auth[_-]?token|token|signature|phpsessid))\b(["\']?\s*[=:]\s*["\']?)[^\s,;&"\'<>]+/i' => '$1$2' . self::MASK,
            '/([?&]key=)[^&\s"\'<>]+/i' => '$1' . self::MASK,
            // The user and password in front of the host of a URL: https://user:secret@host/
            '/(\b[a-z][a-z0-9+.\-]{1,10}:\/\/)[^\/\s:@]{1,100}:[^\/\s@]{1,100}@/i' => '$1' . self::MASK . '@',
            // JWT-shaped values (three base64url segments, the first starting "eyJ").
            '/eyJ[A-Za-z0-9_-]{5,}\.[A-Za-z0-9_-]{5,}\.[A-Za-z0-9_-]{5,}/' => self::MASK,
        ] as $pattern => $replacement) {
            $text = preg_replace($pattern, $replacement, $text) ?? self::MASK;
        }

        return $text;
    }

    public static function truncate(?string $text, int $maxBytes): ?string
    {
        if ($text === null || strlen($text) <= $maxBytes) {
            return $text;
        }

        return mb_strcut($text, 0, $maxBytes) . '…[truncated]';
    }

    public static function maskPhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        return strlen($digits) <= 3 ? self::MASK : str_repeat('*', strlen($digits) - 3) . substr($digits, -3);
    }

    public static function maskEmail(string $email): string
    {
        if (!str_contains($email, '@')) {
            return self::MASK;
        }

        [$local, $domain] = explode('@', $email, 2);

        return mb_substr($local, 0, 1) . '***@' . $domain;
    }

    private static function redactValue(string $key, mixed $value): mixed
    {
        $normalized = strtolower((string) preg_replace('/[^a-zA-Z0-9]/', '', $key));

        if (self::isSecretKey($normalized)) {
            return is_scalar($value) || $value === null ? self::MASK : self::MASK;
        }

        if (is_scalar($value) && in_array($normalized, self::PHONE_KEYS, true) && $value !== '') {
            return self::maskPhone((string) $value);
        }

        if (is_string($value) && in_array($normalized, self::EMAIL_KEYS, true) && $value !== '') {
            return self::maskEmail($value);
        }

        return self::redact($value);
    }

    private static function isSecretKey(string $normalized): bool
    {
        // "token", "api_token", "auth_token", "csrf-token"… but not "token_expires_at".
        if (in_array($normalized, self::SECRET_EXACT, true) || str_ends_with($normalized, 'token')) {
            return true;
        }

        foreach (self::SECRET_FRAGMENTS as $fragment) {
            if (str_contains($normalized, $fragment)) {
                return true;
            }
        }

        return false;
    }
}
