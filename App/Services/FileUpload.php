<?php
  class FileUpload {
    private $upload_dir = "../uploads/";
    private $allowed_types = ["image/jpeg", "image/png", "image/gif", "application/pdf"];
    private $max_size = 5 * 1024 * 1024;  // 5MB

    public function __construct() {
      if (!is_dir($this->upload_dir)) {
        mkdir($this->upload_dir, 0755, true);
      }
    }

    // Upload file
    public function upload($file, $folder = "profiles") {
      try {

         // Validate file exists
        if (!isset($file) || $file['error'] !== UPLOAD_ERR_OK) {
          return [
            "success" => false,
            "error" => "File upload failed"
          ];
        }

        // Validate file type
        $file_type = mime_content_type($file['tmp_name']);
        if (!in_array($file_type, $this->allowed_types)) {
          return [
            "success" => false,
            "error" => "Invalid file type. Allowed: JPG, PNG, GIF, PDF"
          ];
        }

        // Validate file size
        if ($file['size'] > $this->max_size) {
          return [
            "success" => false,
            "error" => "File size exceeds 5MB limit"
          ];
        }

        // Create folder if not exists
        $folder_path = $this->upload_dir . $folder . "/";
        if (!is_dir($folder_path)) {
          mkdir($folder_path, 0755, true);
        }

        // Generate unique filename
        $file_ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $file_name = uniqid() . "_" . time() . "." . $file_ext;
        $file_path = $folder_path . $file_name;

        // Move uploaded file
        if (!move_uploaded_file($file['tmp_name'], $file_path)) {
          return [
            "success" => false,
            "error" => "Failed to save file"
          ];
        }

        return [
          "success" => true,
          "data" => [
            "filename" => $file_name,
            "path" => "/education/uploads/" . $folder . "/" . $file_name,
            "url" => "http://localhost/education/uploads/" . $folder . "/" . $file_name
          ]
        ];
      } catch (Exception $e) {
        return [
          "success" => false,
          "error" => $e->getMessage()
        ];
      }
    }

    // Delete file
    public function delete($file_path) {
      try {
        $full_path = $this->upload_dir . str_replace("/education/uploads/", "", $file_path);

        if (file_exists($full_path)) {
          unlink($full_path);
          return [
            "success" => true,
            "message" => "File deleted successfully"
          ];
        } else {
          return [
            "success" => false,
            "error" => "File not found"
          ];
        }
      } catch (Exception $e) {
        return [
          "success" => false,
          "error" => $e->getMessage()
        ];
      }
    }

    // Set allowed types
    public function setAllowedTypes($types) {
      $this->allowed_types = $types;
    }

    // Set max size
    public function setMaxSize($bytes) {
      $this->max_size = $bytes;
    }
  }
?>