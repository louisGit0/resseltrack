<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Env;
use Aws\Credentials\Credentials;
use Aws\Exception\AwsException;
use Aws\S3\S3Client;

/**
 * Pure I/O service wrapping Aws\S3\S3Client for Cloudflare R2 object storage.
 *
 * Reads all config from environment variables via Env::get() — no Models or Core
 * dependencies beyond Env. Follows the src/Services/ convention (final class,
 * declare strict_types, no DI container, lazy instantiation by callers).
 *
 * R2 is S3-compatible with two key differences from AWS S3:
 *  - region must be 'auto' (R2 ignores the value but SDK requires it)
 *  - endpoint must point to the account-scoped R2 gateway
 *  - ACL is NOT supported (x-amz-acl is silently ignored; public access is
 *    enabled at the bucket level via the r2.dev setting)
 *
 * @see https://developers.cloudflare.com/r2/examples/aws/aws-sdk-php/
 */
final class R2Storage
{
    private S3Client $client;
    private string $bucket;
    private string $publicBaseUrl;

    public function __construct()
    {
        $accountId = (string) Env::get('R2_ACCOUNT_ID');
        $this->bucket = (string) Env::get('R2_BUCKET');
        $this->publicBaseUrl = rtrim((string) Env::get('R2_PUBLIC_BASE_URL'), '/');

        $this->client = new S3Client([
            'version'     => 'latest',
            'region'      => 'auto',
            'endpoint'    => 'https://' . $accountId . '.r2.cloudflarestorage.com',
            'credentials' => new Credentials(
                (string) Env::get('R2_ACCESS_KEY_ID'),
                (string) Env::get('R2_SECRET_ACCESS_KEY')
            ),
        ]);
    }

    /**
     * Upload a local tmp file to R2 and return its full public URL.
     *
     * @param string $tmpPath    Absolute path to the PHP tmp file ($_FILES['tmp_name'])
     * @param string $key        Object key (e.g. "a1b2c3d4e5f6g7h8.jpg")
     * @param string $contentType MIME type detected by finfo (e.g. "image/jpeg")
     * @return string            Full public URL (R2_PUBLIC_BASE_URL + '/' + key)
     * @throws AwsException      On SDK / network failure — callers must catch \Throwable
     */
    public function put(string $tmpPath, string $key, string $contentType): string
    {
        $this->client->putObject([
            'Bucket'      => $this->bucket,
            'Key'         => $key,
            'SourceFile'  => $tmpPath,
            'ContentType' => $contentType,
            // No ACL: R2 ignores x-amz-acl; public access is via the bucket r2.dev setting
        ]);
        return $this->publicBaseUrl . '/' . $key;
    }

    /**
     * Delete an R2 object by its stored URL or bare key. Best-effort — callers catch exceptions.
     *
     * @param string $keyOrUrl  Full r2.dev URL (as stored in DB) or bare object key
     * @throws AwsException     On SDK / network failure — callers should wrap in try/catch
     */
    public function delete(string $keyOrUrl): void
    {
        $key = $this->deriveKey($keyOrUrl);
        $this->client->deleteObject([
            'Bucket' => $this->bucket,
            'Key'    => $key,
        ]);
    }

    /**
     * Derive the bare object key from a stored URL or pass-through a bare key.
     *
     * Example: "https://pub-abc123.r2.dev/a1b2c3d4.jpg" with
     *          publicBaseUrl "https://pub-abc123.r2.dev" → "a1b2c3d4.jpg"
     */
    private function deriveKey(string $keyOrUrl): string
    {
        // If it starts with the public base URL, strip it to get the bare key
        if (str_starts_with($keyOrUrl, $this->publicBaseUrl)) {
            return ltrim(substr($keyOrUrl, strlen($this->publicBaseUrl)), '/');
        }
        // Already a bare key (future-proof)
        return ltrim($keyOrUrl, '/');
    }
}
