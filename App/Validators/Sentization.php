<?php
namespace App\Validators;

  // XSS Attack محاولة:
  $input = "<script>alert('hacked')</script>";

  // بدون sanitize:
  echo $input;  // ينفذ الـ script!

  // مع sanitize:
  $safe = htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
  echo $safe;  // يعرض النص فقط
?>