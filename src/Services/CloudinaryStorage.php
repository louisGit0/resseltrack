<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Env;
use CURLFile;
use RuntimeException;

/**
 * Cloudinary image storage via the signed REST upload API (no SDK — plain curl).
 *
 * Serverless filesystems are ephemeral, so product images live on Cloudinary.
 * Server-side signed requests (SHA-1 of sorted params + api_secret) authenticate
 * uploads and deletes. The returned `secure_url` (https://res.cloudinary.com/...)
 * is stored verbatim in the DB and rendered directly by the views.
 *
 * Config (via Env): CLOUDINARY_CLOUD_NAME, CLOUDINARY_API_KEY, CLOUDINARY_API_SECRET.
 */
final class CloudinaryStorage
{
    private string $cloudName;
    private string $apiKey;
    private string $apiSecret;

    public function __construct()
    {
        $this->cloudName = (string) Env::get('CLOUDINARY_CLOUD_NAME', '');
        $this->apiKey = (string) Env::get('CLOUDINARY_API_KEY', '');
        $this->apiSecret = (string) Env::get('CLOUDINARY_API_SECRET', '');
    }

    /**
     * Upload a local temp file under the given public id; returns the secure public URL.
     */
    public function upload(string $tmpPath, string $publicId): string
    {
        $timestamp = (string) time();
        $signature = $this->sign(['public_id' => $publicId, 'timestamp' => $timestamp]);

        $body = $this->request($this->endpoint('upload'), [
            'file' => new CURLFile($tmpPath),
            'api_key' => $this->apiKey,
            'timestamp' => $timestamp,
            'public_id' => $publicId,
            'signature' => $signature,
        ]);

        $data = json_decode($body, true);
        if (!is_array($data) || empty($data['secure_url'])) {
            throw new RuntimeException('Cloudinary upload returned no secure_url: ' . $body);
        }
        return (string) $data['secure_url'];
    }

    /**
     * Delete the asset behind a stored secure URL (best-effort; derives the public id).
     */
    public function delete(string $url): void
    {
        $publicId = $this->derivePublicId($url);
        if ($publicId === '') {
            return;
        }
        $timestamp = (string) time();
        $signature = $this->sign(['public_id' => $publicId, 'timestamp' => $timestamp]);

        $this->request($this->endpoint('destroy'), [
            'api_key' => $this->apiKey,
            'timestamp' => $timestamp,
            'public_id' => $publicId,
            'signature' => $signature,
        ]);
    }

    private function endpoint(string $action): string
    {
        return 'https://api.cloudinary.com/v1_1/' . $this->cloudName . '/image/' . $action;
    }

    /**
     * Cloudinary signature: sorted `key=value` pairs joined with `&`, api_secret
     * appended (no separator), SHA-1 hex. `file`, `api_key` and `signature` are
     * never signed (they are excluded by the caller).
     *
     * @param array<string, string> $params
     */
    private function sign(array $params): string
    {
        ksort($params);
        $pairs = [];
        foreach ($params as $key => $value) {
            $pairs[] = $key . '=' . $value;
        }
        return sha1(implode('&', $pairs) . $this->apiSecret);
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function request(string $url, array $fields): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $fields,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);
        $body = curl_exec($ch);
        if ($body === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('Cloudinary request failed: ' . $error);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($status >= 400) {
            throw new RuntimeException('Cloudinary HTTP ' . $status . ': ' . (string) $body);
        }
        return (string) $body;
    }

    /**
     * Extract the Cloudinary public id from a stored secure URL, e.g.
     * https://res.cloudinary.com/<cloud>/image/upload/v123/<public_id>.<ext>
     */
    private function derivePublicId(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        if (!preg_match('#/image/upload/(?:v\d+/)?(.+)$#', $path, $matches)) {
            return '';
        }
        $idWithExt = $matches[1];
        $dot = strrpos($idWithExt, '.');
        return $dot !== false ? substr($idWithExt, 0, $dot) : $idWithExt;
    }
}
