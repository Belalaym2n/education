<?php
namespace App\Validators;

 class AdvancedValidator{
  public function sanitizeUsername($value) {
    return preg_replace('/[^a-zA-Z0-9_]/', '', $value); // Belal@2026!!  Belal2026 
  }

  // Sanitize for database (prevent SQL injection)
  public function sanitizeForDB($value) {
    return trim(strip_tags($value));  // <script>alert(1)</script>  alert(1) 
  }

  // Sanitize HTML (allow some tags)
  public function sanitizeHTML($value) {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
  }

  // Remove numbers from string
  public function removeNumbers($value) {
    return preg_replace('/[0-9]/', '', $value);
  }

  // Keep only numbers
   public function onlyNumbers($value) {
    return preg_replace('/[^0-9]/', '', $value);
  }

  // Validate and sanitize together
  public function validateAndSanitize($field, $type) {
    if (!isset($this->data[$field])) {
      $this->errors[$field] = "$field is required";
      return null;
    }

    $value = $this->data[$field];

    switch ($type) {
      case 'email':
        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
          $this->errors[$field] = "Invalid email";
          return null;
        }
        return strtolower($value);

      case 'username':
        if (strlen($value) < 3) {
          $this->errors[$field] = "Username must be at least 3 characters";
          return null;
        }
        return $this->sanitizeUsername($value);

      case 'text':
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

      case 'number':
        if (!is_numeric($value)) {
          $this->errors[$field] = "Must be a number";
          return null;
        }
        return (int)$value;

      case 'phone':
        $phone = preg_replace('/[^0-9]/', '', $value);
        if (strlen($phone) !== 11 || substr($phone, 0, 2) !== '01') {
          $this->errors[$field] = "Invalid phone number";
          return null;
        }
        return $phone;

      default:
        return $value;
    }
  }}
?>