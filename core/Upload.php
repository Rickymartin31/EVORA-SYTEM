<?php

class Upload
{
    /**
     * Validate and move an uploaded file into storage/uploads/{subfolder}.
     * Returns the relative path (subfolder/filename) stored in the DB, or null on failure.
     */
    public static function handle(array $file, string $subfolder, array $allowedExt = UPLOAD_ALLOWED_DOC): ?string
    {
        if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
            return null;
        }

        if ($file['size'] <= 0 || $file['size'] > UPLOAD_MAX_BYTES) {
            return null;
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExt, true)) {
            return null;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        $allowedMimes = [
            'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'pdf' => 'application/pdf',
        ];

        if (!isset($allowedMimes[$ext]) || $mime !== $allowedMimes[$ext]) {
            return null;
        }

        $targetDir = STORAGE_PATH . DIRECTORY_SEPARATOR . $subfolder;
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        $filename = bin2hex(random_bytes(16)) . '.' . $ext;
        $targetPath = $targetDir . DIRECTORY_SEPARATOR . $filename;

        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
            return null;
        }

        return $subfolder . '/' . $filename;
    }
}
