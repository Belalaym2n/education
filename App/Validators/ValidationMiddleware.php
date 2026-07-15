<?php
namespace App\Validators;

  class ValidationMiddleware {
    public static function validateRegister($data) {
      $validator = new Validator($data);

      $validator->validateString("name", 2, 100);
      $validator->validateEmail("email");
      $validator->validatePassword("password", 8);
      $validator->match("password", "confirm_password");

      if ($validator->hasErrors()) {
        return [
          "valid" => false,
          "errors" => $validator->getErrors()
        ];
      }

      return [
        "valid" => true,
        "data" => $validator->getSanitized(["name", "email"])
      ];
    }

    public static function validateLogin($data) {
      $validator = new Validator($data);

      $validator->validateEmail("email");
      $validator->required("password");

      if ($validator->hasErrors()) {
        return [
          "valid" => false,
          "errors" => $validator->getErrors()
        ];
      }

      return [
        "valid" => true,
        "data" => $data
      ];
    }

    public static function validateStudent($data) {
      $validator = new Validator($data);

      if (isset($data["name"])) {
        $validator->validateString("name", 2, 100);
      }

      if (isset($data["email"])) {
        $validator->validateEmail("email");
      }

      if (isset($data["phone"])) {
        $validator->validatePhone("phone");
      }

      if ($validator->hasErrors()) {
        return [
          "valid" => false,
          "errors" => $validator->getErrors()
        ];
      }

      return [
        "valid" => true,
        "data" => $validator->getSanitized(
          array_keys(array_filter($data, fn($v) => $v !== null))
        )
      ];
    }

    public static function validateCourse($data) {
      $validator = new Validator($data);

      $validator->validateString("name", 2, 100);
      $validator->validateString("description", 10, 1000);
      $validator->validateNumber("price", 0, 10000);

      if ($validator->hasErrors()) {
        return [
          "valid" => false,
          "errors" => $validator->getErrors()
        ];
      }

      return [
        "valid" => true,
        "data" => $validator->getSanitized(["name", "description"]) + 
                  ["price" => (float)$data["price"]]
      ];
    }
  }
?>