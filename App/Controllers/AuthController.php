<?php

namespace App\Controllers;

use App\Exceptions\ValidationException;
use App\Services\AuthService;
use App\Services\UserService;
use App\Services\StudentService;
use App\Validators\Validator;

class AuthController
{
    private AuthService $authService;
    private UserService $userService;
    private StudentService $studentService;

    public function __construct(
        AuthService $authService,
        UserService $userService,
        StudentService $studentService
    ) {
        $this->authService = $authService;
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

        return [
            'success' => true,
            'data' => $result
        ];
    }

    public function registerStudent(array 
    $requestData): array
    {
        $validator = new Validator($requestData);

        $validator->required('full_name');
        $validator->required('email');
        $validator->required('phone');
        $validator->required('parent_phone');
        $validator->required('stage_id');
        $validator->required('password');
        $validator->required('confirm_password');

        $validator->validateString('full_name', 2, 100);
        $validator->validateEmail('email');
        $validator->validatePhone('phone');
        $validator->validatePhone('parent_phone');
        $validator->validateNumber('role_id');

        $validator->validateNumber('stage_id');
        $validator->validatePassword('password', 8);
        $validator->match('password', 'confirm_password');
        $validator->mustBeDifferent('phone', 'parent_phone');

        if ($validator->hasErrors()) {
            throw new ValidationException($validator->getErrors());
        }

        $clean = $validator->getSanitized([
            'full_name',
            'email',
            'phone',
            'parent_phone',
            'stage_id',
            "role_id"
        ]);
        $clean['password'] =
            $requestData['password'];

        $result = $this->studentService->registerStudent($clean);

        return [
            'success' => true,
            'data' => $result
        ];
    }

    public function registerAdmin
    (array $requestData): array
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
            ['full_name', 'email']);
        $clean['password_hash'] = 
        $requestData['password'];
        $clean['role_id'] =  
       "3";

        $user = $this->userService->
        
        createUser($clean);

        return ['success' => true, 
        'data' => $user];
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

    

         return ['success' => true, 
        'data' => $user];
      
    }

    public function logout(): array
    {
        $result = $this->authService->logout();
        return ['success' => true, 'data' => $result];
    }
}
