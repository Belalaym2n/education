<?php
namespace App\Services;

  class JWT {
    private static $secret = "your-secret-key-change-this";

    public static function encode($data) {
      // Header 
      $header = json_encode(["alg" => "HS256", 
      "typ" => "JWT"]);
      $header = base64_encode($header);

      // Payload
      $payload = json_encode($data);
      $payload = base64_encode($payload);

      // Signature
      $signature = hash_hmac("sha256", 
      "$header.$payload", self::$secret, true);
      $signature = base64_encode($signature);

      // Token
      return "$header.$payload.$signature";
    }

    public static function decode($token) {
      $parts = explode(".", $token);

     if (count($parts) !== 3) {
    throw new \Exception("Invalid token");
}

      $payload = base64_decode($parts[1]);
      $signature = $parts[2];

      // Verify signature
      $expected_signature = hash_hmac("sha256",
       "{$parts[0]}.{$parts[1]}", 
       
                                    
       self::$secret, true);
      $expected_signature = 
      base64_encode($expected_signature);

if (!hash_equals($expected_signature, $signature)) {
    throw new \Exception("Invalid signature");
}

      return json_decode($payload, true);
    }
  } 
?>