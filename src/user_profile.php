<?php

require_once __DIR__ . '/bootstrap.php';

function user_profile_photo_upload(array $file, int $userId, array &$errors): ?string
{
    $uploadError = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($uploadError === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($uploadError !== UPLOAD_ERR_OK || $userId <= 0) {
        $errors[] = 'Profile photo upload failed.';
        return null;
    }
    if ((int)($file['size'] ?? 0) > 4 * 1024 * 1024) {
        $errors[] = 'Profile photo must be 4 MB or smaller.';
        return null;
    }

    $tmpPath = (string)($file['tmp_name'] ?? '');
    $mime = '';
    if ($tmpPath !== '' && function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo ? (string)finfo_file($finfo, $tmpPath) : '';
        if ($finfo) finfo_close($finfo);
    }
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
    if (!isset($extensions[$mime])) {
        $errors[] = 'Profile photo must be a JPG, PNG, GIF, or WEBP image.';
        return null;
    }

    $targetDir = APP_ROOT . '/public/uploads/profiles';
    if (!is_dir($targetDir) && !@mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
        $errors[] = 'Could not create the profile photo directory.';
        return null;
    }
    $filename = $userId . '-' . bin2hex(random_bytes(10)) . '.' . $extensions[$mime];
    if (!@move_uploaded_file($tmpPath, $targetDir . '/' . $filename)) {
        $errors[] = 'Could not save the profile photo.';
        return null;
    }
    return 'uploads/profiles/' . $filename;
}

