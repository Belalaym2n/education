<?php
  require "JWT.php";

  // Create token
  $data = [
    "id" => 1,
    "email" => "ahmed@mail.com",
    "role" => "student",
    "iat" => time(),
    "exp" => time() + 3600  // Expire after 1 hour
  ];

  $token = JWT::encode($data);
  echo "Token: " . $token . "\n\n";

  // Decode token
  $decoded = JWT::decode($token);

  if ($decoded) {
    echo "✓ Token is valid!\n";
    echo "ID: " . $decoded["id"] . "\n";
    echo "Email: " . $decoded["email"] . "\n";
    echo "Role: " . $decoded["role"] . "\n";
  } else {
    echo "✗ Token is invalid!";
  }
?>