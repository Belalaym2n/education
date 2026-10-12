<?php

namespace App\Services;

use App\Contracts\StudentRepositoryInterface;
use App\Core\Logger;
use App\Database\Database;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Helpers\ImageUploadHelper;

/**
 * Student profile photo: validate, store, persist, serve.
 *
 * - The student is always resolved from the authenticated user id, never
 *   from request input, so one student cannot change another's photo.
 * - Validation reuses ImageUploadHelper::validate() (size limit, finfo MIME
 *   check, extension whitelist, getimagesize() decode check).
 * - The stored name is random and its extension is derived from the
 *   detected MIME type (the client filename is never trusted).
 * - The new file is written and the DB updated BEFORE the old file is
 *   removed; if the DB update fails, the new file is deleted again.
 * - The stored reference is a path relative to the API base
 *   ("media/profile-images/<file>"), served by streamFile(), so it works
 *   behind any host / base path.
 */
class ProfileImageService
{
    public const PUBLIC_PREFIX = 'media/profile-images/';
    private const FILENAME_PATTERN = '/^[a-f0-9]{32}\.(jpg|png|webp)$/';
    private const MIME_EXT = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    private StudentRepositoryInterface $studentRepo;
    private Database $db;
    private ImageUploadHelper $validator;
    private Logger $logger;
    private string $storageDir;

    public function __construct(
        StudentRepositoryInterface $studentRepo,
        Database $db,
        ImageUploadHelper $validator,
        Logger $logger,
        string $storageDir
    ) {
        $this->studentRepo = $studentRepo;
        $this->db = $db;
        $this->validator = $validator;
        $this->logger = $logger;
        $this->storageDir = rtrim($storageDir, '/\\');
    }

    /**
     * @param array|null $file raw $_FILES['image'] entry
     * @return array{profile_image: string}
     */
    public function updateForUser(int $authUserId, ?array $file): array
    {
        $student = $this->studentRepo->findByUserId($authUserId);
        if (!$student) {
            throw new NotFoundException('Student');
        }

        if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            throw new ValidationException(['image' => 'يرجى اختيار صورة.']);
        }

        // Size, MIME (finfo), extension and decodability.
        $this->validator->validate($file);

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        $ext = self::MIME_EXT[$mime] ?? null;
        if ($ext === null) {
            throw new ValidationException(['image' => 'Image must be JPEG, PNG, or WEBP']);
        }

        $this->ensureStorageDir();

        $filename = bin2hex(random_bytes(16)) . '.' . $ext;
        $destination = $this->storageDir . DIRECTORY_SEPARATOR . $filename;
        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            throw new ValidationException(['image' => 'تعذّر حفظ الصورة، حاول مرة أخرى.']);
        }

        $previous = $this->currentReference((int) $student['id']);
        $reference = self::PUBLIC_PREFIX . $filename;

        try {
            $this->db->update(
                'students',
                ['profile_image' => $reference],
                'id = ?',
                [(int) $student['id']]
            );
        } catch (\Throwable $e) {
            @unlink($destination); // keep the old photo intact
            $this->logger->error('Profile image DB update failed', [
                'student_id' => $student['id'],
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        // Only now remove the replaced file (and only files we own).
        $this->deleteOwnedFile($previous);

        $this->logger->info('Profile image updated', ['student_id' => $student['id']]);

        return ['profile_image' => $reference];
    }

    /**
     * Streams a stored photo. Returns false when the name is invalid or the
     * file does not exist.
     */
    public function streamFile(string $filename): bool
    {
        if (!preg_match(self::FILENAME_PATTERN, $filename)) {
            return false;
        }

        $path = $this->storageDir . DIRECTORY_SEPARATOR . $filename;
        $real = realpath($path);
        $root = realpath($this->storageDir);
        if ($real === false || $root === false || !str_starts_with($real, $root) || !is_file($real)) {
            return false;
        }

        $ext = pathinfo($real, PATHINFO_EXTENSION);
        $type = array_search($ext, self::MIME_EXT, true) ?: 'application/octet-stream';

        header_remove('Content-Type');
        header('Content-Type: ' . $type);
        header('Content-Length: ' . filesize($real));
        // Names are random and never reused, so the file can be cached forever.
        header('Cache-Control: public, max-age=31536000, immutable');
        header('X-Content-Type-Options: nosniff');
        readfile($real);

        return true;
    }

    private function currentReference(int $studentId): ?string
    {
        $row = $this->db->selectOne('SELECT profile_image FROM students WHERE id = ?', [$studentId]);
        $value = $row['profile_image'] ?? null;
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function deleteOwnedFile(?string $reference): void
    {
        if ($reference === null || !str_starts_with($reference, self::PUBLIC_PREFIX)) {
            return; // never touch files this service didn't create
        }
        $name = substr($reference, strlen(self::PUBLIC_PREFIX));
        if (!preg_match(self::FILENAME_PATTERN, $name)) {
            return;
        }
        $path = $this->storageDir . DIRECTORY_SEPARATOR . $name;
        if (is_file($path)) {
            @unlink($path);
        }
    }

    private function ensureStorageDir(): void
    {
        if (!is_dir($this->storageDir)) {
            mkdir($this->storageDir, 0755, true);
        }

        // Defence in depth if the directory is ever reachable directly:
        // no listings, no script execution.
        $htaccess = $this->storageDir . DIRECTORY_SEPARATOR . '.htaccess';
        if (!is_file($htaccess)) {
            @file_put_contents(
                $htaccess,
                "Options -Indexes\n<FilesMatch \"\\.(php|phtml|phar|pl|py|cgi|sh)$\">\n  Require all denied\n</FilesMatch>\n"
            );
        }
    }
}
