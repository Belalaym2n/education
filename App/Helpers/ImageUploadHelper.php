<?php

namespace App\Helpers;

use App\Exceptions\ValidationException;

class ImageUploadHelper
{
    private const ALLOWED_MIME = ['image/jpeg', 'image/png', 'image/webp'];
    private const ALLOWED_EXT = ['jpg', 'jpeg', 'png', 'webp'];
    private const MAX_BYTES = 5 * 1024 * 1024; // 5MB

    private string $uploadDir;
    private string $publicBaseUrl;

    public function __construct(
        string $uploadDir = __DIR__ . '/../../storage/uploads/student_results',
        string $publicBaseUrl = '/uploads/student_results'
    ) {
        $this->uploadDir = rtrim($uploadDir, '/'); 
        $this->publicBaseUrl = rtrim($publicBaseUrl, '/');

        if (!is_dir($this->uploadDir)) {
            mkdir($this->uploadDir, 0755, true);
        }
    }

    /**
     * Validates a $_FILES[...] entry. Throws ValidationException on failure.
     */
    public function validate(array $file): void
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return; // optional field, nothing uploaded
        }

        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new ValidationException(['image' => 'Image upload failed (error code: ' . $file['error'] . ')']);
        }

        if (!is_uploaded_file($file['tmp_name'])) {
            throw new ValidationException(['image' => 'Invalid upload']);
        }

        if ($file['size'] > self::MAX_BYTES) {
            throw new ValidationException(['image' => 'Image must not exceed 5MB']);
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mime, self::ALLOWED_MIME, true)) {
            throw new ValidationException(['image' => 'Image must be JPEG, PNG, or WEBP']);
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, self::ALLOWED_EXT, true)) {
            throw new ValidationException(['image' => 'Invalid image extension']);
        }

        // Confirm it's actually a decodable image, not just a renamed file
        // with a spoofed mime/extension.
        if (@getimagesize($file['tmp_name']) === false) {
            throw new ValidationException(['image' => 'File is not a valid image']);
        }
    }

    /**
     * Stores the file and returns the public URL to persist on the record.
     * Caller is responsible for deleting the old image (see delete()).
     */
    public function store(array $file): string
    {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $filename = bin2hex(random_bytes(16)) . '.' . $ext;
        $destination = $this->uploadDir . '/' . $filename;

        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            throw new ValidationException(['image' => 'Failed to store uploaded image']);
        }

        return $this->publicBaseUrl . '/' . $filename;
    }

    /**
     * Best-effort delete of a previously stored image, given its public URL.
     * Never throws — a missing/already-removed file should not fail the request.
     */
    public function delete(?string $imageUrl): void
    {
        if (!$imageUrl) {
            return;
        }

        $filename = basename($imageUrl);
        $path = $this->uploadDir . '/' . $filename;

        if (is_file($path)) {
            @unlink($path);
        }
    }
}
