<?php

namespace App\Controllers;

use App\Exceptions\ValidationException;
use App\Services\DeviceService;
use App\Validators\Validator;

final class DeviceController
{
    private DeviceService $deviceService;
 
    public function __construct(DeviceService $deviceService)
    {
        $this->deviceService = $deviceService;
    }

    public function register(array $requestData): array
    {
        $validator = new Validator($requestData);
        $validator->required('fcm_token');
        $validator->required('platform');

        if ($validator->hasErrors()) {
            throw new ValidationException($validator->getErrors());
        }

        $userId = (int) $_SERVER['auth_user_id'];

        $deviceId = $this->deviceService->registerDevice(
            $userId,
            $requestData['fcm_token'],
            $requestData['platform'],
            $requestData['device_identifier'] ?? null,
            $requestData['app_version'] ?? null
        );

        return [
            'success' => true,
            'data' => [
                'device_id' => $deviceId
            ]
        ];
    }

    public function unregister(array $requestData): array
    {
        $validator = new Validator($requestData);
        $validator->required('fcm_token');

        if ($validator->hasErrors()) {
            throw new ValidationException($validator->getErrors());
        }

        $this->deviceService->unregisterDevice(
            (int) $_SERVER['auth_user_id'],
            (string) $requestData['fcm_token']
        );

        return [
            'success' => true,
            'message' => 'Device unregistered'
        ];
    }

    public function index(): array
    {
        $userId = (int) $_SERVER['auth_user_id'];

        return [
            'success' => true,
            'data' => $this->deviceService->listDevices($userId)
        ];
    }
}