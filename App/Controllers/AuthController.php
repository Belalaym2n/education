<?php

namespace App\Controllers;

use App\Exceptions\ValidationException;
use App\Services\AuthService;
use App\Services\UserService;
use App\Services\StudentService;
use App\Validators\Validator;
use App\Services\DeviceBindingService;

class AuthController
{
    private AuthService $authService;
    private UserService $userService;
    private StudentService $studentService;
    private DeviceBindingService $deviceBindingService;

    public function __construct(
        AuthService $authService,
        UserService $userService,
        StudentService $studentService,
        DeviceBindingService $deviceBindingService

    ) {
        $this->authService = $authService;
        $this->deviceBindingService = $deviceBindingService;

        $this->userService = $userService;
        $this->studentService = $studentService;
    }

    public function login(array $requestData): array
    {
        $validator = new Validator($requestData);

        $validator->required('email');
        $validator->required('password');
        $validator->validateEmail('email');

        if ($validator->hasErrors()) {
            throw new ValidationException($validator->getErrors());
        }

        $clean = $validator->getSanitized(['email', 'password']);
        // الباسورد بيتبعت زي ما هو (متغيرش) عشان الـ hash comparison تشتغل صح
        $clean['password'] = $requestData['password'];

        $result = $this->authService->login($clean['email'], $clean['password']);
        $token = $result['token'] ?? ($result['data']['token'] ?? null);


        if ($token) {
            try {
                $payload = \App\Services\JWT::decode($token);
            } catch (\Throwable $e) {
                $payload = null;
            }

            if ($payload && (int) ($payload['role_id'] ?? 0) === 1 /* student */) {
                $headers = function_exists('getallheaders')
                    ? getallheaders()
                    : [];

                $deviceId =
                    $headers['X-Device-ID']
                    ?? $headers['x-device-id']
                    ?? ($_SERVER['HTTP_X_DEVICE_ID'] ?? null);

                $deviceId = is_string($deviceId)
                    ? trim($deviceId)
                    : null;

                $this->deviceBindingService->checkAndBindOnLogin(
                    (int) $payload['user_id'],
                    $deviceId
                );
            }
        }
        return [
            'success' => true,
            'data' => $result
        ];
    }

    public function registerStudent(array
    $requestData): array
    {
        // Student and parent phone numbers are optional. A missing, null or
        // blank value is dropped here so it is stored as NULL; a supplied
        // value is trimmed and must still be a valid Egyptian number.
        foreach (['phone', 'parent_phone'] as $phoneField) {
            $value = $requestData[$phoneField] ?? null;
            if (!is_string($value) && !is_numeric($value)) {
                unset($requestData[$phoneField]);
                continue;
            }
            $value = trim((string) $value);
            if ($value === '') {
                unset($requestData[$phoneField]);
            } else {
                $requestData[$phoneField] = $value;
            }
        }

        $validator = new Validator($requestData);

        $validator->required('full_name');
        $validator->required('email');
        $validator->required('stage_id');
        $validator->required('password');
        $validator->required('confirm_password');

        $validator->validateString('full_name', 2, 100);
        $validator->validateEmail('email');
        if (isset($requestData['phone'])) {
            $validator->validatePhone('phone');
        }
        if (isset($requestData['parent_phone'])) {
            $validator->validatePhone('parent_phone');
        }
        $validator->validateNumber('role_id');

        $validator->validateNumber('stage_id');
        $validator->validatePassword('password', 8);
        $validator->match('password', 'confirm_password');
        if (isset($requestData['phone'], $requestData['parent_phone'])) {
            $validator->mustBeDifferent('phone', 'parent_phone');
        }

        if ($validator->hasErrors()) {
            throw new ValidationException($validator->getErrors());
        }

        $clean = $validator->getSanitized([
            'full_name',
            'email',
            'phone',
            'parent_phone',
            'stage_id',
            'role_id'
        ]);
        $clean['password'] = $requestData['password'];
        $clean['device_identifier'] = isset($requestData['device_identifier']) && is_string($requestData['device_identifier']) && trim($requestData['device_identifier']) !== ''
            ? trim($requestData['device_identifier'])
            : null;

        $result = $this->studentService->registerStudent($clean);

        // $result = $this->studentService->registerStudent($clean);

        return [
            'success' => true,
            'data' => $result
        ];
    }

    public function registerAdmin(array $requestData): array
    {


        $validator = new Validator($requestData);

        $validator->required('full_name');
        $validator->required('email');

        $validator->required('password');
        $validator->required('confirm_password');

        $validator->validateString('full_name', 2, 100);
        $validator->validateEmail('email');
        $validator->validatePassword('password', 8);
        $validator->match('password',  'confirm_password');

        if ($validator->hasErrors()) {
            throw new ValidationException($validator->getErrors());
        }

        $clean = $validator->getSanitized(
            ['full_name', 'email']
        );
        $clean['password_hash'] =
            $requestData['password'];
        $clean['role_id'] =
            "3";

        $user = $this->userService->createUser($clean);

        return [
            'success' => true,
            'data' => $user
        ];
    }

    public function registerTeacher(array $requestData): array
    {
        $validator = new Validator($requestData);

        $validator->required('name');
        $validator->required('email');
        $validator->required('password');
        $validator->required('confirm_password');

        $validator->validateString('name', 2, 100);
        $validator->validateEmail('email');
        $validator->validatePassword('password', 8);
        $validator->match('password', 'confirm_password');

        if ($validator->hasErrors()) {
            throw new ValidationException($validator->getErrors());
        }

        $clean = $validator->getSanitized(['name', 'email']);
        $clean['password'] = $requestData['password'];
        $clean['role'] = 'teacher';

        $user = $this->userService->createUser($clean);

        return ['success' => true, 'data' => $user];
    }

    public function me(): array
    {
        $userId =
            (int) ($_SERVER['auth_user_id'] ?? 0);
        $user = $this->userService->getUser($userId);



        return [
            'success' => true,
            'data' => $user
        ];
    }

    public function logout(): array
    {
        $result = $this->authService->logout();
        return ['success' => true, 'data' => $result];
    }
}
