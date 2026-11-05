<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The direct route to this server for clients on its own network: the LAN listener's https
 * addresses and the SHA-256 of its certificate's public key (SubjectPublicKeyInfo, base64,
 * the form `curl --pinnedpubkey sha256//` takes). Clients pin that key, so this must only be
 * served over the public, CA-validated connection and only to signed-in users.
 */
final class LocalTransfer
{
    /**
     * @return array{urls: list<string>, spkiSha256: string}|null
     */
    public static function route(): ?array
    {
        $urls = self::urls();
        if ($urls === []) {
            return null;
        }

        $pin = self::certificatePin((string) config('local_transfer.cert_path'));

        return $pin === null ? null : ['urls' => $urls, 'spkiSha256' => $pin];
    }

    public static function isAvailable(): bool
    {
        return self::route() !== null;
    }

    /**
     * @return list<string>
     */
    private static function urls(): array
    {
        $urls = [];
        foreach (explode(',', (string) config('local_transfer.urls')) as $candidate) {
            $url = rtrim(trim($candidate), '/');
            if (parse_url($url, PHP_URL_SCHEME) === 'https' && parse_url($url, PHP_URL_HOST) !== null) {
                $urls[] = $url;
            }
        }

        return $urls;
    }

    private static function certificatePin(string $path): ?string
    {
        if ($path === '' || !is_readable($path)) {
            return null;
        }
        $certificate = @openssl_x509_read((string) file_get_contents($path));
        if ($certificate === false) {
            return null;
        }
        $key = openssl_pkey_get_public($certificate);
        $details = $key === false ? false : openssl_pkey_get_details($key);
        if ($details === false || !isset($details['key'])) {
            return null;
        }
        $der = base64_decode(
            (string) preg_replace('/-----[^-]+-----|\s+/', '', (string) $details['key']),
            true
        );

        return $der === false ? null : base64_encode(hash('sha256', $der, true));
    }
}
