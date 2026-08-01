<?php

namespace App\Validators;

class Validator
{
  private $errors = [];
  protected  $data = [];

  public function __construct($data = [])
  {
    $this->data = $data;
  }

  // ===== EMAIL =====
  public function validateEmail($field) 
  {
    if (!isset($this->data[$field])) {
      $this->errors[$field] = "$field is required";
      return false;
    }

    $email = $this->data[$field];

    if (empty($email)) {
      $this->errors[$field] = "$field is required";
      return false;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      $this->errors[$field] = "$field must be a valid email";
      return false;
    }

    return true;
  }

  // ===== PASSWORD =====
  public function validatePassword($field, $min_length = 6)
  {
    if (!isset($this->data[$field])) {
      $this->errors[$field] = "$field is required";
      return false;
    }

    $password = $this->data[$field];

    if (empty($password)) {
      $this->errors[$field] = "$field is required";
      return false;
    }

    if (strlen($password) < $min_length) {
      $this->errors[$field] = "$field must be at least $min_length characters";
      return false;
    }

    // Check for uppercase




    return true;
  }

  // ===== STRING =====
  public function validateString($field, $min = 1, $max = 255)
  {
    if (!isset($this->data[$field])) {
      $this->errors[$field] = "$field is required";
      return false;
    }

    $value = $this->data[$field];

    if (empty($value) && $min > 0) {
      $this->errors[$field] = "$field is required";
      return false;
    }

    $length = strlen($value);

    if ($length < $min) {
      $this->errors[$field] = "$field must be at least $min characters";
      return false;
    }

    if ($length > $max) {
      $this->errors[$field] = "$field must not exceed $max characters";
      return false;
    }

    return true;
  }

  // ===== PHONE =====
  public function validatePhone($field)
  {
    if (!isset($this->data[$field])) {
      $this->errors[$field] = "$field is required";
      return false;
    }

    $phone = $this->data[$field];

    if (empty($phone)) {
      $this->errors[$field] = "$field is required";
      return false;
    }

    // Egyptian phone format: 01XXXXXXXXX
    if (!preg_match('/^01[0-9]{9}$/', $phone)) {
      $this->errors[$field] = "$field must be a valid Egyptian phone number";
      return false;
    }

    return true;
  }

  // ===== NUMBER =====
  public function validateNumber($field, $min = 0, $max = null)
  {
    if (!isset($this->data[$field])) {
      $this->errors[$field] = "$field is required";
      return false;
    }

    $value = $this->data[$field];

    if (!is_numeric($value)) {
      $this->errors[$field] = "$field must be a number";
      return false;
    }

    if ($value < $min) {
      $this->errors[$field] = "$field must be at least $min";
      return false;
    }

    if ($max !== null && $value > $max) {
      $this->errors[$field] = "$field must not exceed $max";
      return false;
    }

    return true;
  }

  // ===== URL =====
  public function validateURL($field)
  {
    if (!isset($this->data[$field])) {
      $this->errors[$field] = "$field is required";
      return false;
    }

    $url = $this->data[$field];

    if (!filter_var($url, FILTER_VALIDATE_URL)) {
      $this->errors[$field] = "$field must be a valid URL";
      return false;
    }

    return true;
  }

  // ===== REQUIRED =====
  public function required($field)
  {
    if (!isset($this->data[$field]) || empty($this->data[$field])) {
      $this->errors[$field] = "$field is required";
      return false;
    }

    return true;
  }

  // ===== MATCH =====
  public function match(
    $field1,
    $field2,
    $message = null
  ) {
    if (!isset($this->data[$field1]) || !isset($this->data[$field2])) {
      $this->errors[$field1] = "Both fields are required";
      return false;
    }

    if ($this->data[$field1] !== $this->data[$field2]) {
      $msg = $message ?? "$field1 and $field2 do not match";
      $this->errors[$field1] = $msg;
      return false;
    }

    return true;
  }


  public function mustBeDifferent(string $field1, string $field2, ?string $message = null): bool
  {
    if (!isset($this->data[$field1], $this->data[$field2])) {
      $this->errors[$field1] = "Both fields are required.";
      return false;
    }

    if ($this->data[$field1] === $this->data[$field2]) {
      $this->errors[$field1] = $message ?? "$field1 must be different from $field2.";
      return false;
    }

    return true;
  }
  // ===== MIN LENGTH =====
  public function min($field, $length)
  {
    if (!isset($this->data[$field])) return false;

    if (strlen($this->data[$field]) < $length) {
      $this->errors[$field] = "$field must be at least $length characters";
      return false;
    }

    return true;
  }

  // ===== MAX LENGTH =====
  public function max($field, $length)
  {
    if (!isset($this->data[$field])) return false;

    if (strlen($this->data[$field]) > $length) {
      $this->errors[$field] = "$field must not exceed $length characters";
      return false;
    }

    return true;
  }

  // ===== REGEX PATTERN =====
  public function pattern($field, $pattern, $message = null)
  {
    if (!isset($this->data[$field])) return false;

    if (!preg_match($pattern, $this->data[$field])) {
      $msg = $message ?? "$field has invalid format";
      $this->errors[$field] = $msg;
      return false;
    }

    return true;
  }

  // ===== GET ERRORS =====
  public function getErrors()
  {
    return $this->errors;
  }

  // ===== HAS ERRORS =====
  public function hasErrors()
  {
    return !empty($this->errors);  // true false false 
  }

  // ===== GET ERROR MESSAGE =====
  public function getError($field)
  {
    return $this->errors[$field] ?? null;
  }

  // ===== CLEAR ERRORS =====
  public function clearErrors()
  {
    $this->errors = [];
  }

  // ===== GET SANITIZED DATA =====
  public function getSanitized($fields = [])
  {
    $sanitized = [];

    foreach ($fields as $field) {
      if (isset($this->data[$field])) {
        $sanitized[$field] = $this->sanitize($this->data[$field]);
      }
    }
 
    return $sanitized;
  }

  public function validateYoutubeURL($field)
  {
    if (!isset($this->data[$field])) {
      $this->errors[$field] = "$field is required";
      return false;
    }

    $url = trim($this->data[$field]);

    if (!filter_var($url, FILTER_VALIDATE_URL)) {
      $this->errors[$field] = "$field must be a valid YouTube URL";
      return false;
    }

    $host = strtolower(parse_url($url, PHP_URL_HOST));

    $allowedHosts = [
      'youtube.com',
      'www.youtube.com',
      'youtu.be',
      'www.youtu.be',
      'm.youtube.com',
    ];

    if (!in_array($host, $allowedHosts)) {
      $this->errors[$field] = "$field must be a valid YouTube URL";
      return false;
    }

    return true;
  }
  // ===== SANITIZE =====
  public function sanitize($value)
  {
    // Remove tags
    $value = strip_tags($value);

    // Remove special characters
    $value = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

    // Trim whitespace
    $value = trim($value);

    return $value;
  }
  // ===== BOOLEAN =====
  public function validateBoolean($field, $required = true)
  {
    if (!isset($this->data[$field])) {
      if ($required) {
        $this->errors[$field] = "$field is required";
      }
      return false;
    }

    $value = $this->data[$field];

    if (!in_array($value, [true, false, 0, 1, '0', '1'], true)) {
      $this->errors[$field] = "$field must be boolean (true/false or 0/1)";
      return false;
    }

    return true;
  }
}
