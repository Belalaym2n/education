<?php

namespace App\Controllers;

use App\Exceptions\NotFoundException;
use App\Services\ProfileImageService;

class ProfileImageController
{
    private ProfileImageService $service;

    public function __construct(ProfileImageService $service)
    {
        $this->service = $service;
    }

    // =========================================================
    // POST /students/me/profile-image  (auth) — multipart field "image"
    // =========================================================
    public function upload(array $requestData = []): array
    {
        $authUserId = (int) ($_SERVER['auth_user_id'] ?? 0);
        $result = $this->service->updateForUser($authUserId, $_FILES['image'] ?? null);

        return [
            'success' => true,
            'message' => 'Profile image updated',
            'data' => $result,
        ];
    }

    // =========================================================
    // GET /media/profile-images/{file}
    // Public read: names are 128-bit random and never listed, so a photo
    // is only reachable through a reference the API returned.
    // =========================================================
    public function show(string $file)
    {
        if (!$this->service->streamFile($file)) {
            throw new NotFoundException('Image');
        }
        exit;
    }
}
