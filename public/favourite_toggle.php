<?php
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/favourites.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: catalogue.php');
    exit;
}

$modelId = isset($_POST['model_id']) ? (int)$_POST['model_id'] : 0;
$isFavourite = !empty($_POST['is_favourite']);
$email = favourites_normalize_user_email((string)($currentUser['email'] ?? ''));

if ($modelId > 0 && $email !== '') {
    favourites_set_model($pdo, $email, $modelId, $isFavourite);
}

$returnUrl = trim((string)($_POST['return_url'] ?? ''));
if ($returnUrl === '' || preg_match('#^https?://#i', $returnUrl)) {
    $returnUrl = 'catalogue.php?prefetch=1';
}

header('Location: ' . $returnUrl);
exit;
