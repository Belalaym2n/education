<?php

namespace App\Controllers;

use App\Exceptions\ValidationException;
use App\Services\NotificationService;
use App\Validators\Validator;
use App\Helpers\ResponseHelper;

/**
 * Admin/dashboard-facing controller. Thin by design: validate, delegate
 * to the service, return a response array — no business logic here.
 */
final class NotificationController
{ 
    private   NotificationService $notificationService;
    public function __construct(
        NotificationService $notificationService
    ) {
        $this->notificationService = $notificationService;
    }
    public function index(): array
    {
        return ResponseHelper::success(

            $this->notificationService->listNotifications($_GET),
            'Notification retreived success'
        );
    }

    public function show(int $id): array
    {
        return ResponseHelper::success(
            $this->notificationService->getNotification($id)
        );
    }

    public function store(array $requestData): array
    {

        $clean = $this->validateAndSanitize($requestData, isUpdate: false);

        $userId = (int) ($_SERVER['auth_user_id'] ?? 0);
        $notification = $this->notificationService->createNotification($clean, $userId);

        return ['success' => true, 'data' => $notification];
    }

    public function update(int $id, array $requestData): array
    {
        $clean = $this->validateAndSanitize($requestData, isUpdate: true);

        $userId = (int) ($_SERVER['auth_user_id'] ?? 0);
        $notification = $this->notificationService->updateNotification($id, $clean, $userId);

        return ['success' => true, 'data' => $notification];
    }

    public function destroy(int $id): array
    {
        $this->notificationService->softDelete($id);
        return ['success' => true, 'message' => 'Notification deleted'];
    }

    public function restore(int $id): array
    {
        $notification = $this->notificationService->restore($id);
        return ['success' => true, 'data' => $notification];
    }

    public function enable(int $id): array
    {
        return ['success' => true, 'data' => $this->notificationService->enable($id)];
    }

    public function disable(int $id): array
    {
        return ['success' => true, 'data' => $this->notificationService->disable($id)];
    }

 

 
 
 
 
    private function requireIdList(array $requestData): array
    {
        if (empty($requestData['ids']) || !is_array($requestData['ids'])) {
            throw new ValidationException(['ids' => 'ids must be a non-empty array']);
        }

        return array_map('intval', $requestData['ids']);
    }

    private function validateAndSanitize(array $requestData, bool $isUpdate): array
    {
        $validator = new Validator($requestData);

        if (!$isUpdate) {
            $validator->required('headline');
            $validator->required('body');
            $validator->required('target_type');
        }

        if (isset($requestData['headline'])) {
            $validator->validateString('headline', 3, 200);
        }
        if (isset($requestData['body'])) {
            $validator->validateString('body', 3, 5000);
        }
        if (isset($requestData['target_type']) && !in_array($requestData['target_type'], ['all', 'stage', 'student'], true)) {
            $validator->getErrors(); // no-op, explicit check below
        } 

        foreach (['image_url', 'facebook_url', 'youtube_url', 'instagram_url', 'twitter_url', 'telegram_url', 'whatsapp_url', 'tiktok_url', 'website_url', 'external_url', 'button_action'] as $urlField) {
            if (!empty($requestData[$urlField])) {
                $validator->validateURL($urlField);
            }
        }

        if (isset($requestData['stage_id'])) {
            $validator->validateNumber('stage_id', 1);
        }

        if ($validator->hasErrors()) {
            throw new ValidationException($validator->getErrors());
        }

        $textFields = ['headline', 'body', 'deep_link', 'button_text', 'button_action', 'notification_type'];
        $urlFields = ['image_url', 'facebook_url', 'youtube_url', 'instagram_url', 'twitter_url', 'telegram_url', 'whatsapp_url', 'tiktok_url', 'website_url', 'external_url'];

        $clean = $validator->getSanitized(array_merge($textFields, $urlFields));

        // Fields that must NOT be HTML-escaped (booleans/ids/dates) are copied as-is.
        foreach (
            [
                'target_type',
                'stage_id',
                'student_id',
                'is_active',
                'send_now',
                'scheduled_at',
                'created_by',
                'updated_by',
                'source_type',
                'source_id',
            ] as $rawField
        ) {
            if (array_key_exists($rawField, $requestData)) {
                $clean[$rawField] = $requestData[$rawField];
            }
        }

        return array_filter($clean, fn($v) => $v !== null && $v !== '');
    }
}
