<?php
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/layout.php';
require_once SRC_PATH . '/db.php';

$active  = basename($_SERVER['PHP_SELF']);
$isAdmin = !empty($currentUser['is_admin']);
$isStaff = !empty($currentUser['is_staff']) || $isAdmin;
$sectionRaw = $_GET['section'] ?? $_POST['section'] ?? 'inventory';
$section = in_array($sectionRaw, ['categories', 'models', 'inventory'], true) ? $sectionRaw : 'inventory';

if (!$isAdmin) {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}

$messages = [];
$errors   = [];

$modelEditId = (int)($_GET['model_edit'] ?? 0);
$assetEditId = 0;

$statusOptions = ['available', 'checked_out', 'maintenance', 'retired'];
$pageSize = 50;
$page = max(1, (int)($_GET['page'] ?? 1));
$assetsSearch = $section === 'inventory' ? trim($_GET['q'] ?? '') : '';
$modelsSearch = $section === 'models' ? trim($_GET['q'] ?? '') : '';
$categoriesSearch = $section === 'categories' ? trim($_GET['q'] ?? '') : '';
$assetsSort = $section === 'inventory' ? (trim($_GET['sort'] ?? '') ?: 'tag:asc') : 'tag:asc';
$modelsSort = $section === 'models' ? (trim($_GET['sort'] ?? '') ?: 'name:asc') : 'name:asc';
$categoriesSort = $section === 'categories' ? (trim($_GET['sort'] ?? '') ?: 'name:asc') : 'name:asc';
$assetsStatusFilter = $section === 'inventory' ? trim($_GET['status'] ?? '') : '';
$assetsModelFilter = $section === 'inventory' ? trim($_GET['model'] ?? '') : '';
if ($section === 'inventory' && $assetsModelFilter === '' && isset($_GET['asset_model'])) {
    $assetsModelFilter = trim($_GET['asset_model']);
}
$modelsCategoryFilter = $section === 'models' ? trim($_GET['category'] ?? '') : '';
$categoriesDescriptionFilter = $section === 'categories' ? trim($_GET['desc'] ?? '') : '';

$uploadDirRelative = 'uploads/images';
$uploadDir = APP_ROOT . '/public/' . $uploadDirRelative;
$uploadBaseUrl = $uploadDirRelative . '/';

if (!is_dir($uploadDir) && !@mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
    $errors[] = 'Upload directory could not be created. Check permissions for public/uploads/images.';
}

$exportType = $_GET['export'] ?? '';
if (in_array($exportType, ['categories', 'models', 'assets'], true)) {
    $filenameMap = [
        'categories' => 'categories.csv',
        'models' => 'models.csv',
        'assets' => 'assets.csv',
    ];
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filenameMap[$exportType] . '"');
    $out = fopen('php://output', 'w');
    if ($exportType === 'categories') {
        fputcsv($out, ['id', 'name', 'description']);
        $rows = $pdo->query('SELECT id, name, description FROM asset_categories ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $row) {
            fputcsv($out, [
                (int)$row['id'],
                $row['name'] ?? '',
                $row['description'] ?? '',
            ]);
        }
    } elseif ($exportType === 'models') {
        fputcsv($out, ['id', 'name', 'manufacturer', 'category_id', 'category_name', 'notes', 'image_url']);
        $rows = $pdo->query('
            SELECT m.id, m.name, m.manufacturer, m.category_id, m.notes, m.image_url, c.name AS category_name
              FROM asset_models m
              LEFT JOIN asset_categories c ON c.id = m.category_id
             ORDER BY m.name ASC
        ')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $row) {
            fputcsv($out, [
                (int)$row['id'],
                $row['name'] ?? '',
                $row['manufacturer'] ?? '',
                (int)($row['category_id'] ?? 0),
                $row['category_name'] ?? '',
                $row['notes'] ?? '',
                $row['image_url'] ?? '',
            ]);
        }
    } elseif ($exportType === 'assets') {
        fputcsv($out, ['id', 'asset_tag', 'name', 'model_id', 'model_name', 'status']);
        $rows = $pdo->query('
            SELECT a.id, a.asset_tag, a.name, a.model_id, a.status, m.name AS model_name
              FROM assets a
              JOIN asset_models m ON m.id = a.model_id
             ORDER BY a.asset_tag ASC
        ')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $row) {
            fputcsv($out, [
                (int)$row['id'],
                $row['asset_tag'] ?? '',
                $row['name'] ?? '',
                (int)($row['model_id'] ?? 0),
                $row['model_name'] ?? '',
                $row['status'] ?? '',
            ]);
        }
    }
    fclose($out);
    exit;
}

$templateType = $_GET['template'] ?? '';
if (in_array($templateType, ['categories', 'models', 'assets'], true)) {
    $templateMap = [
        'categories' => APP_ROOT . '/templates/csv/categories_template.csv',
        'models' => APP_ROOT . '/templates/csv/models_template.csv',
        'assets' => APP_ROOT . '/templates/csv/assets_template.csv',
    ];
    $path = $templateMap[$templateType];
    if (is_file($path)) {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . basename($path) . '"');
        readfile($path);
        exit;
    }
    http_response_code(404);
    echo 'Template not found.';
    exit;
}

$readCsvUpload = static function (string $field, array &$errors): array {
    if (empty($_FILES[$field]) || !is_array($_FILES[$field])) {
        $errors[] = 'CSV upload is required.';
        return [];
    }
    $file = $_FILES[$field];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $errors[] = 'CSV upload failed.';
        return [];
    }
    $handle = fopen($file['tmp_name'], 'r');
    if (!$handle) {
        $errors[] = 'Could not read uploaded CSV.';
        return [];
    }
    $header = fgetcsv($handle);
    if (!$header) {
        fclose($handle);
        $errors[] = 'CSV header row is missing.';
        return [];
    }
    $header = array_map(static function ($value) {
        $value = trim((string)$value);
        return strtolower(preg_replace('/^\xEF\xBB\xBF/', '', $value));
    }, $header);
    $rows = [];
    while (($row = fgetcsv($handle)) !== false) {
        if ($row === [null] || $row === false) {
            continue;
        }
        $row = array_pad($row, count($header), '');
        $rows[] = array_combine($header, $row);
    }
    fclose($handle);
    return $rows;
};

$handleUpload = static function (string $field) use ($uploadDir, $uploadBaseUrl): ?string {
    if (empty($_FILES[$field]) || !is_array($_FILES[$field])) {
        return null;
    }
    $file = $_FILES[$field];
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('Image upload failed.');
    }
    if (!is_dir($uploadDir) && !@mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        throw new Exception('Could not create upload directory.');
    }

    $original = (string)($file['name'] ?? '');
    $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    $ext = preg_replace('/[^a-z0-9]+/', '', $ext);
    if ($ext === '') {
        $ext = 'bin';
    }
    $filename = bin2hex(random_bytes(8)) . '.' . $ext;
    $target = rtrim($uploadDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;

    if (!is_uploaded_file($file['tmp_name'] ?? '')) {
        throw new Exception('Invalid upload.');
    }
    if (!@move_uploaded_file($file['tmp_name'], $target)) {
        throw new Exception('Could not save uploaded file.');
    }
    if (!is_file($target)) {
        throw new Exception('Upload did not persist on disk.');
    }

    return $uploadBaseUrl . $filename;
};

$buildQuery = static function (array $overrides = []) use ($section): string {
    $params = $_GET;
    unset($params['export'], $params['template']);
    $params['section'] = $section;
    foreach ($overrides as $key => $value) {
        if ($value === null || $value === '') {
            unset($params[$key]);
            continue;
        }
        $params[$key] = $value;
    }
    return http_build_query($params);
};

$renderPagination = static function (array $pagination, callable $buildQuery): string {
    $totalPages = (int)($pagination['pages'] ?? 1);
    $current = (int)($pagination['page'] ?? 1);
    if ($totalPages <= 1) {
        return '';
    }
    $start = max(1, $current - 2);
    $end = min($totalPages, $current + 2);
    $html = '<nav aria-label="Pagination"><ul class="pagination pagination-sm mb-0">';
    $prevDisabled = $current <= 1 ? ' disabled' : '';
    $nextDisabled = $current >= $totalPages ? ' disabled' : '';
    $prevLink = $current > 1 ? ('inventory_admin.php?' . $buildQuery(['page' => $current - 1])) : '#';
    $nextLink = $current < $totalPages ? ('inventory_admin.php?' . $buildQuery(['page' => $current + 1])) : '#';
    $html .= '<li class="page-item' . $prevDisabled . '"><a class="page-link" href="' . h($prevLink) . '">Prev</a></li>';
    for ($i = $start; $i <= $end; $i++) {
        $active = $i === $current ? ' active' : '';
        $link = 'inventory_admin.php?' . $buildQuery(['page' => $i]);
        $html .= '<li class="page-item' . $active . '"><a class="page-link" href="' . h($link) . '">' . $i . '</a></li>';
    }
    $html .= '<li class="page-item' . $nextDisabled . '"><a class="page-link" href="' . h($nextLink) . '">Next</a></li>';
    $html .= '</ul></nav>';
    return $html;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_model') {
        $modelEditId = (int)($_POST['model_id'] ?? 0);
        $name = trim($_POST['model_name'] ?? '');
        $manufacturer = trim($_POST['model_manufacturer'] ?? '');
        $categoryRaw = trim($_POST['model_category_id'] ?? '');
        $categoryId = $categoryRaw === '' ? null : (int)$categoryRaw;
        $notes = trim($_POST['model_notes'] ?? '');
        $imageUrl = trim($_POST['model_image_url'] ?? '');
        $uploadedModelImage = null;

        try {
            $uploadedModelImage = $handleUpload('model_image_upload');
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }

        if ($name === '') {
            $errors[] = 'Model name is required.';
        }

        if (!$errors) {
            try {
                $existingImageUrl = null;
                if ($modelEditId > 0) {
                    $stmt = $pdo->prepare('SELECT image_url FROM asset_models WHERE id = :id LIMIT 1');
                    $stmt->execute([':id' => $modelEditId]);
                    $existingImageUrl = $stmt->fetchColumn() ?: null;
                }
                $finalImageUrl = $imageUrl !== '' ? $imageUrl : $existingImageUrl;
                if ($uploadedModelImage && ($imageUrl === '' || $imageUrl === $existingImageUrl)) {
                    $finalImageUrl = $uploadedModelImage;
                }

                if ($modelEditId > 0) {
                    $stmt = $pdo->prepare("
                        UPDATE asset_models
                           SET name = :name,
                               manufacturer = :manufacturer,
                               category_id = :category_id,
                               notes = :notes,
                               image_url = :image_url
                         WHERE id = :id
                    ");
                    $stmt->execute([
                        ':name' => $name,
                        ':manufacturer' => $manufacturer !== '' ? $manufacturer : null,
                        ':category_id' => $categoryId ?: null,
                        ':notes' => $notes !== '' ? $notes : null,
                        ':image_url' => $finalImageUrl,
                        ':id' => $modelEditId,
                    ]);
                    $messages[] = 'Model updated.';
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO asset_models (name, manufacturer, category_id, notes, image_url, created_at)
                        VALUES (:name, :manufacturer, :category_id, :notes, :image_url, NOW())
                    ");
                    $stmt->execute([
                        ':name' => $name,
                        ':manufacturer' => $manufacturer !== '' ? $manufacturer : null,
                        ':category_id' => $categoryId ?: null,
                        ':notes' => $notes !== '' ? $notes : null,
                        ':image_url' => $finalImageUrl,
                    ]);
                    $modelEditId = (int)$pdo->lastInsertId();
                    $messages[] = 'Model created.';
                }
            } catch (Throwable $e) {
                $errors[] = 'Model save failed: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'delete_model') {
        $modelDeleteId = (int)($_POST['model_id'] ?? 0);
        $deleteRelated = !empty($_POST['delete_related']);
        if ($modelDeleteId <= 0) {
            $errors[] = 'Model not found.';
        }

        if (!$errors) {
            try {
                if (!$deleteRelated) {
                    $stmt = $pdo->prepare('SELECT COUNT(*) FROM assets WHERE model_id = :id');
                    $stmt->execute([':id' => $modelDeleteId]);
                    $assetCount = (int)$stmt->fetchColumn();
                    if ($assetCount > 0) {
                        $errors[] = 'Model has assets. Select "Delete assets too" to remove it.';
                    }
                }
                if (!$errors) {
                    $pdo->beginTransaction();
                    if ($deleteRelated) {
                        $stmt = $pdo->prepare('DELETE FROM assets WHERE model_id = :id');
                        $stmt->execute([':id' => $modelDeleteId]);
                    }
                    $stmt = $pdo->prepare('DELETE FROM asset_models WHERE id = :id');
                    $stmt->execute([':id' => $modelDeleteId]);
                    if ($stmt->rowCount() > 0) {
                        $pdo->commit();
                        $messages[] = 'Model deleted.';
                    } else {
                        $pdo->rollBack();
                        $errors[] = 'Model not found.';
                    }
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = 'Model delete failed: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'save_asset') {
        $assetEditId = (int)($_POST['asset_id'] ?? 0);
        $assetTag = trim($_POST['asset_tag'] ?? '');
        $assetName = trim($_POST['asset_name'] ?? '');
        $modelId = (int)($_POST['asset_model_id'] ?? 0);
        $status = $_POST['asset_status'] ?? 'available';

        if ($assetTag === '') {
            $errors[] = 'Asset tag is required.';
        }
        if ($assetName === '') {
            $errors[] = 'Asset name is required.';
        }
        if ($modelId <= 0) {
            $errors[] = 'Model is required.';
        }
        if (!in_array($status, $statusOptions, true)) {
            $errors[] = 'Asset status is invalid.';
        }
        $existingStatus = null;
        if ($assetEditId > 0) {
            $stmt = $pdo->prepare('SELECT status FROM assets WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $assetEditId]);
            $existingStatus = $stmt->fetchColumn();
            if ($existingStatus === false) {
                $errors[] = 'Asset not found.';
            }
        }
        if ($status === 'checked_out') {
            if ($assetEditId <= 0 || $existingStatus !== 'checked_out') {
                $errors[] = 'Checked out status cannot be set manually.';
            }
        }

        if (!$errors) {
            try {
                $stmt = $pdo->prepare('SELECT id FROM assets WHERE asset_tag = :tag AND id <> :id LIMIT 1');
                $stmt->execute([
                    ':tag' => $assetTag,
                    ':id' => $assetEditId,
                ]);
                if ($stmt->fetch()) {
                    throw new Exception('Asset tag is already in use.');
                }

                $stmt = $pdo->prepare('SELECT id FROM asset_models WHERE id = :id LIMIT 1');
                $stmt->execute([':id' => $modelId]);
                if (!$stmt->fetch()) {
                    throw new Exception('Selected model does not exist.');
                }

                if ($assetEditId > 0) {
                    $stmt = $pdo->prepare("
                        UPDATE assets
                           SET asset_tag = :asset_tag,
                               name = :name,
                               model_id = :model_id,
                               status = :status
                         WHERE id = :id
                    ");
                    $stmt->execute([
                        ':asset_tag' => $assetTag,
                        ':name' => $assetName,
                        ':model_id' => $modelId,
                        ':status' => $status,
                        ':id' => $assetEditId,
                    ]);
                    $messages[] = 'Asset updated.';
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO assets (asset_tag, name, model_id, status, created_at)
                        VALUES (:asset_tag, :name, :model_id, :status, NOW())
                    ");
                    $stmt->execute([
                        ':asset_tag' => $assetTag,
                        ':name' => $assetName,
                        ':model_id' => $modelId,
                        ':status' => $status,
                    ]);
                    $assetEditId = (int)$pdo->lastInsertId();
                    $messages[] = 'Asset created.';
                }
            } catch (Throwable $e) {
                $errors[] = 'Asset save failed: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'delete_asset') {
        $assetDeleteId = (int)($_POST['asset_id'] ?? 0);
        if ($assetDeleteId <= 0) {
            $errors[] = 'Asset not found.';
        }

        if (!$errors) {
            try {
                $stmt = $pdo->prepare('DELETE FROM assets WHERE id = :id');
                $stmt->execute([':id' => $assetDeleteId]);
                if ($stmt->rowCount() > 0) {
                    $messages[] = 'Asset deleted.';
                } else {
                    $errors[] = 'Asset not found.';
                }
            } catch (Throwable $e) {
                $errors[] = 'Asset delete failed: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'save_category') {
        $categoryEditId = (int)($_POST['category_id'] ?? 0);
        $name = trim($_POST['category_name'] ?? '');
        $description = trim($_POST['category_description'] ?? '');

        if ($name === '') {
            $errors[] = 'Category name is required.';
        }

        if (!$errors) {
            try {
                if ($categoryEditId > 0) {
                    $stmt = $pdo->prepare("
                        UPDATE asset_categories
                           SET name = :name,
                               description = :description
                         WHERE id = :id
                    ");
                    $stmt->execute([
                        ':name' => $name,
                        ':description' => $description !== '' ? $description : null,
                        ':id' => $categoryEditId,
                    ]);
                    $messages[] = 'Category updated.';
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO asset_categories (name, description, created_at)
                        VALUES (:name, :description, NOW())
                    ");
                    $stmt->execute([
                        ':name' => $name,
                        ':description' => $description !== '' ? $description : null,
                    ]);
                    $messages[] = 'Category created.';
                }
            } catch (Throwable $e) {
                $errors[] = 'Category save failed: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'delete_category') {
        $categoryDeleteId = (int)($_POST['category_id'] ?? 0);
        $deleteRelated = !empty($_POST['delete_related']);
        if ($categoryDeleteId <= 0) {
            $errors[] = 'Category not found.';
        }

        if (!$errors) {
            try {
                $pdo->beginTransaction();
                if ($deleteRelated) {
                    $stmt = $pdo->prepare('
                        DELETE a
                          FROM assets a
                          JOIN asset_models m ON m.id = a.model_id
                         WHERE m.category_id = :id
                    ');
                    $stmt->execute([':id' => $categoryDeleteId]);
                    $stmt = $pdo->prepare('DELETE FROM asset_models WHERE category_id = :id');
                    $stmt->execute([':id' => $categoryDeleteId]);
                }
                $stmt = $pdo->prepare('DELETE FROM asset_categories WHERE id = :id');
                $stmt->execute([':id' => $categoryDeleteId]);
                if ($stmt->rowCount() > 0) {
                    $pdo->commit();
                    $messages[] = 'Category deleted.';
                } else {
                    $pdo->rollBack();
                    $errors[] = 'Category not found.';
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = 'Category delete failed: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'import_categories') {
        $rows = $readCsvUpload('categories_csv', $errors);
        if ($rows && !$errors) {
            $imported = 0;
            $rowErrors = [];
            foreach ($rows as $idx => $row) {
                $name = trim($row['name'] ?? '');
                $description = trim($row['description'] ?? '');
                if ($name === '') {
                    $rowErrors[] = 'Row ' . ($idx + 2) . ': name is required.';
                    continue;
                }
                try {
                    $stmt = $pdo->prepare('SELECT id FROM asset_categories WHERE name = :name LIMIT 1');
                    $stmt->execute([':name' => $name]);
                    $existingId = (int)$stmt->fetchColumn();
                    if ($existingId > 0) {
                        $stmt = $pdo->prepare('UPDATE asset_categories SET description = :description WHERE id = :id');
                        $stmt->execute([
                            ':description' => $description !== '' ? $description : null,
                            ':id' => $existingId,
                        ]);
                    } else {
                        $stmt = $pdo->prepare('INSERT INTO asset_categories (name, description, created_at) VALUES (:name, :description, NOW())');
                        $stmt->execute([
                            ':name' => $name,
                            ':description' => $description !== '' ? $description : null,
                        ]);
                    }
                    $imported++;
                } catch (Throwable $e) {
                    $rowErrors[] = 'Row ' . ($idx + 2) . ': ' . $e->getMessage();
                }
            }
            if ($rowErrors) {
                $errors[] = 'Category import completed with errors: ' . implode(' | ', array_slice($rowErrors, 0, 5));
            }
            if ($imported > 0) {
                $messages[] = 'Categories imported: ' . $imported . '.';
            }
        }
    } elseif ($action === 'import_models') {
        $rows = $readCsvUpload('models_csv', $errors);
        if ($rows && !$errors) {
            $imported = 0;
            $rowErrors = [];
            foreach ($rows as $idx => $row) {
                $name = trim($row['name'] ?? '');
                $manufacturer = trim($row['manufacturer'] ?? '');
                $notes = trim($row['notes'] ?? '');
                $imageUrl = trim($row['image_url'] ?? '');
                $categoryIdRaw = trim($row['category_id'] ?? '');
                $categoryName = trim($row['category_name'] ?? '');
                $modelIdRaw = trim($row['id'] ?? '');
                if ($name === '') {
                    $rowErrors[] = 'Row ' . ($idx + 2) . ': name is required.';
                    continue;
                }
                $categoryId = $categoryIdRaw !== '' ? (int)$categoryIdRaw : 0;
                if ($categoryId <= 0 && $categoryName !== '') {
                    $stmt = $pdo->prepare('SELECT id FROM asset_categories WHERE name = :name LIMIT 1');
                    $stmt->execute([':name' => $categoryName]);
                    $categoryId = (int)$stmt->fetchColumn();
                    if ($categoryId <= 0) {
                        $rowErrors[] = 'Row ' . ($idx + 2) . ': category "' . $categoryName . '" not found.';
                        continue;
                    }
                }
                try {
                    $modelId = $modelIdRaw !== '' ? (int)$modelIdRaw : 0;
                    if ($modelId > 0) {
                        $stmt = $pdo->prepare("
                            UPDATE asset_models
                               SET name = :name,
                                   manufacturer = :manufacturer,
                                   category_id = :category_id,
                                   notes = :notes,
                                   image_url = :image_url
                             WHERE id = :id
                        ");
                        $stmt->execute([
                            ':name' => $name,
                            ':manufacturer' => $manufacturer !== '' ? $manufacturer : null,
                            ':category_id' => $categoryId > 0 ? $categoryId : null,
                            ':notes' => $notes !== '' ? $notes : null,
                            ':image_url' => $imageUrl !== '' ? $imageUrl : null,
                            ':id' => $modelId,
                        ]);
                    } else {
                        $stmt = $pdo->prepare("
                            INSERT INTO asset_models (name, manufacturer, category_id, notes, image_url, created_at)
                            VALUES (:name, :manufacturer, :category_id, :notes, :image_url, NOW())
                        ");
                        $stmt->execute([
                            ':name' => $name,
                            ':manufacturer' => $manufacturer !== '' ? $manufacturer : null,
                            ':category_id' => $categoryId > 0 ? $categoryId : null,
                            ':notes' => $notes !== '' ? $notes : null,
                            ':image_url' => $imageUrl !== '' ? $imageUrl : null,
                        ]);
                    }
                    $imported++;
                } catch (Throwable $e) {
                    $rowErrors[] = 'Row ' . ($idx + 2) . ': ' . $e->getMessage();
                }
            }
            if ($rowErrors) {
                $errors[] = 'Model import completed with errors: ' . implode(' | ', array_slice($rowErrors, 0, 5));
            }
            if ($imported > 0) {
                $messages[] = 'Models imported: ' . $imported . '.';
            }
        }
    } elseif ($action === 'import_assets') {
        $rows = $readCsvUpload('assets_csv', $errors);
        if ($rows && !$errors) {
            $imported = 0;
            $rowErrors = [];
            foreach ($rows as $idx => $row) {
                $assetTag = trim($row['asset_tag'] ?? '');
                $name = trim($row['name'] ?? '');
                $status = trim($row['status'] ?? '');
                if ($status === '') {
                    $status = 'available';
                }
                $modelIdRaw = trim($row['model_id'] ?? '');
                $modelName = trim($row['model_name'] ?? '');
                if ($assetTag === '') {
                    $rowErrors[] = 'Row ' . ($idx + 2) . ': asset_tag is required.';
                    continue;
                }
                if (!in_array($status, $statusOptions, true)) {
                    $rowErrors[] = 'Row ' . ($idx + 2) . ': invalid status.';
                    continue;
                }
                if ($status === 'checked_out') {
                    $rowErrors[] = 'Row ' . ($idx + 2) . ': checked_out status cannot be set manually.';
                    continue;
                }
                $modelId = $modelIdRaw !== '' ? (int)$modelIdRaw : 0;
                if ($modelId <= 0 && $modelName !== '') {
                    $stmt = $pdo->prepare('SELECT id FROM asset_models WHERE name = :name LIMIT 1');
                    $stmt->execute([':name' => $modelName]);
                    $modelId = (int)$stmt->fetchColumn();
                }
                if ($modelId <= 0) {
                    $rowErrors[] = 'Row ' . ($idx + 2) . ': model not found.';
                    continue;
                }
                try {
                    $stmt = $pdo->prepare('SELECT id FROM assets WHERE asset_tag = :tag LIMIT 1');
                    $stmt->execute([':tag' => $assetTag]);
                    $existingId = (int)$stmt->fetchColumn();
                    if ($existingId > 0) {
                        if ($name === '') {
                            $stmt = $pdo->prepare('SELECT name FROM assets WHERE id = :id LIMIT 1');
                            $stmt->execute([':id' => $existingId]);
                            $name = (string)$stmt->fetchColumn();
                        }
                        $stmt = $pdo->prepare("
                            UPDATE assets
                               SET name = :name,
                                   model_id = :model_id,
                                   status = :status
                             WHERE id = :id
                        ");
                        $stmt->execute([
                            ':name' => $name,
                            ':model_id' => $modelId,
                            ':status' => $status,
                            ':id' => $existingId,
                        ]);
                    } else {
                        if ($name === '') {
                            $rowErrors[] = 'Row ' . ($idx + 2) . ': name is required for new assets.';
                            continue;
                        }
                        $stmt = $pdo->prepare("
                            INSERT INTO assets (asset_tag, name, model_id, status, created_at)
                            VALUES (:asset_tag, :name, :model_id, :status, NOW())
                        ");
                        $stmt->execute([
                            ':asset_tag' => $assetTag,
                            ':name' => $name,
                            ':model_id' => $modelId,
                            ':status' => $status,
                        ]);
                    }
                    $imported++;
                } catch (Throwable $e) {
                    $rowErrors[] = 'Row ' . ($idx + 2) . ': ' . $e->getMessage();
                }
            }
            if ($rowErrors) {
                $errors[] = 'Asset import completed with errors: ' . implode(' | ', array_slice($rowErrors, 0, 5));
            }
            if ($imported > 0) {
                $messages[] = 'Assets imported: ' . $imported . '.';
            }
        }
    }
}

$categories = [];
$models = [];
$assets = [];
$categoriesAll = [];
$modelsAll = [];
$assetNotesById = [];
$editModel = null;
$categoriesPagination = ['page' => 1, 'pages' => 1, 'total' => 0, 'start' => 0, 'end' => 0];
$modelsPagination = ['page' => 1, 'pages' => 1, 'total' => 0, 'start' => 0, 'end' => 0];
$assetsPagination = ['page' => 1, 'pages' => 1, 'total' => 0, 'start' => 0, 'end' => 0];

try {
    $categoriesAll = $pdo->query('SELECT id, name FROM asset_categories ORDER BY name ASC')
        ->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $modelsAll = $pdo->query('SELECT id, name FROM asset_models ORDER BY name ASC')
        ->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if ($section === 'categories') {
        $where = [];
        $params = [];
        if ($categoriesSearch !== '') {
            $where[] = '(c.name LIKE :q OR c.description LIKE :q)';
            $params[':q'] = '%' . $categoriesSearch . '%';
        }
        if ($categoriesDescriptionFilter !== '') {
            if ($categoriesDescriptionFilter === '1') {
                $where[] = "TRIM(COALESCE(c.description, '')) <> ''";
            } elseif ($categoriesDescriptionFilter === '0') {
                $where[] = "TRIM(COALESCE(c.description, '')) = ''";
            }
        }
        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM asset_categories c {$whereSql}");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();
        $totalPages = max(1, (int)ceil($total / $pageSize));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $pageSize;
        $sortMap = [
            'name' => 'c.name',
            'description' => 'c.description',
        ];
        [$sortKey, $sortDir] = array_pad(explode(':', $categoriesSort), 2, 'asc');
        $sortKey = $sortMap[$sortKey] ?? 'c.name';
        $sortDir = strtolower($sortDir) === 'desc' ? 'DESC' : 'ASC';
        $sql = "
            SELECT c.id,
                   c.name,
                   c.description,
                   COUNT(m.id) AS model_count
              FROM asset_categories c
              LEFT JOIN asset_models m ON m.category_id = c.id
             {$whereSql}
             GROUP BY c.id, c.name, c.description
             ORDER BY {$sortKey} {$sortDir}
             LIMIT :limit OFFSET :offset
        ";
        $stmt = $pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $categories = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $categoriesPagination = [
            'page' => $page,
            'pages' => $totalPages,
            'total' => $total,
            'start' => $total > 0 ? $offset + 1 : 0,
            'end' => $total > 0 ? min($offset + count($categories), $total) : 0,
        ];
    } elseif ($section === 'models') {
        $where = [];
        $params = [];
        if ($modelsSearch !== '') {
            $where[] = '(m.name LIKE :q OR m.manufacturer LIKE :q OR m.notes LIKE :q OR c.name LIKE :q)';
            $params[':q'] = '%' . $modelsSearch . '%';
        }
        if ($modelsCategoryFilter !== '') {
            $where[] = 'c.name = :category_name';
            $params[':category_name'] = $modelsCategoryFilter;
        }
        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
        $countStmt = $pdo->prepare("
            SELECT COUNT(*)
              FROM asset_models m
              LEFT JOIN asset_categories c ON c.id = m.category_id
             {$whereSql}
        ");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();
        $totalPages = max(1, (int)ceil($total / $pageSize));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $pageSize;
        $sortMap = [
            'name' => 'm.name',
            'manufacturer' => 'm.manufacturer',
            'category' => 'c.name',
            'assets' => 'asset_count',
        ];
        [$sortKey, $sortDir] = array_pad(explode(':', $modelsSort), 2, 'asc');
        $sortKey = $sortMap[$sortKey] ?? 'm.name';
        $sortDir = strtolower($sortDir) === 'desc' ? 'DESC' : 'ASC';
        $sql = "
            SELECT m.id,
                   m.name,
                   m.manufacturer,
                   m.category_id,
                   m.notes,
                   m.image_url,
                   c.name AS category_name,
                   COUNT(a.id) AS asset_count
              FROM asset_models m
              LEFT JOIN asset_categories c ON c.id = m.category_id
              LEFT JOIN assets a ON a.model_id = m.id
             {$whereSql}
             GROUP BY m.id, m.name, m.manufacturer, m.category_id, m.notes, m.image_url, c.name
             ORDER BY {$sortKey} {$sortDir}
             LIMIT :limit OFFSET :offset
        ";
        $stmt = $pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $models = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $modelsPagination = [
            'page' => $page,
            'pages' => $totalPages,
            'total' => $total,
            'start' => $total > 0 ? $offset + 1 : 0,
            'end' => $total > 0 ? min($offset + count($models), $total) : 0,
        ];
    } else {
        $where = [];
        $params = [];
        if ($assetsSearch !== '') {
            $where[] = '(a.asset_tag LIKE :q OR a.name LIKE :q OR m.name LIKE :q)';
            $params[':q'] = '%' . $assetsSearch . '%';
        }
        if ($assetsStatusFilter !== '') {
            $where[] = 'a.status = :status';
            $params[':status'] = $assetsStatusFilter;
        }
        if ($assetsModelFilter !== '') {
            $where[] = 'm.name = :model_name';
            $params[':model_name'] = $assetsModelFilter;
        }
        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
        $countStmt = $pdo->prepare("
            SELECT COUNT(*)
              FROM assets a
              JOIN asset_models m ON m.id = a.model_id
             {$whereSql}
        ");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();
        $totalPages = max(1, (int)ceil($total / $pageSize));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $pageSize;
        $sortMap = [
            'tag' => 'a.asset_tag',
            'name' => 'a.name',
            'model' => 'm.name',
            'status' => 'a.status',
        ];
        [$sortKey, $sortDir] = array_pad(explode(':', $assetsSort), 2, 'asc');
        $sortKey = $sortMap[$sortKey] ?? 'a.asset_tag';
        $sortDir = strtolower($sortDir) === 'desc' ? 'DESC' : 'ASC';
        $sql = "
            SELECT a.id, a.asset_tag, a.name, a.model_id, a.status, a.created_at, m.name AS model_name
              FROM assets a
              JOIN asset_models m ON m.id = a.model_id
             {$whereSql}
             ORDER BY {$sortKey} {$sortDir}
             LIMIT :limit OFFSET :offset
        ";
        $stmt = $pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $assets = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $assetsPagination = [
            'page' => $page,
            'pages' => $totalPages,
            'total' => $total,
            'start' => $total > 0 ? $offset + 1 : 0,
            'end' => $total > 0 ? min($offset + count($assets), $total) : 0,
        ];
    }

    if (!empty($assets)) {
        $assetIds = array_values(array_filter(array_map('intval', array_column($assets, 'id'))));
        if (!empty($assetIds)) {
            $placeholders = implode(',', array_fill(0, count($assetIds), '?'));
            $stmt = $pdo->prepare("
                SELECT asset_id, note_type, note, created_at, actor_name, actor_email
                  FROM asset_notes
                 WHERE asset_id IN ({$placeholders})
                 ORDER BY created_at DESC
            ");
            $stmt->execute($assetIds);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as $row) {
                $assetId = (int)($row['asset_id'] ?? 0);
                if ($assetId <= 0) {
                    continue;
                }
                if (!isset($assetNotesById[$assetId])) {
                    $assetNotesById[$assetId] = [];
                }
                $assetNotesById[$assetId][] = $row;
            }
        }
    }
} catch (Throwable $e) {
    $errors[] = 'Inventory lookup failed: ' . $e->getMessage();
}

if ($modelEditId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM asset_models WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $modelEditId]);
    $editModel = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$editModel) {
        $errors[] = 'Model not found.';
    }
}

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin – Inventory</title>
    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/style.css">
    <?= layout_theme_styles() ?>
</head>
<body class="p-4">
<div class="container">
    <div class="page-shell">
        <?= layout_logo_tag() ?>
        <div class="page-header">
            <h1>Inventory</h1>
            <div class="page-subtitle">
                Manage models and assets in the local inventory.
            </div>
        </div>

        <?= layout_render_nav($active, $isStaff, $isAdmin) ?>

        <div class="top-bar mb-3">
            <div class="top-bar-user">
                Logged in as:
                <strong><?= h(trim(($currentUser['first_name'] ?? '') . ' ' . ($currentUser['last_name'] ?? ''))) ?></strong>
                (<?= h($currentUser['email'] ?? '') ?>)
            </div>
            <div class="top-bar-actions">
                <a href="logout.php" class="btn btn-link btn-sm">Log out</a>
            </div>
        </div>

        <?php if ($messages): ?>
            <div class="alert alert-success">
                <?= implode('<br>', array_map('h', $messages)) ?>
            </div>
        <?php endif; ?>

        <?php if ($errors): ?>
            <div class="alert alert-danger">
                <?= implode('<br>', array_map('h', $errors)) ?>
            </div>
        <?php endif; ?>

        <ul class="nav nav-tabs reservations-subtabs mb-3">
            <li class="nav-item">
                <a class="nav-link active" href="inventory_admin.php">Inventory</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="users.php">Users</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="activity_log.php">Activity Log</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="settings.php">Settings</a>
            </li>
        </ul>

        <ul class="nav nav-tabs reservations-subtabs mb-3">
            <li class="nav-item">
                <a class="nav-link <?= $section === 'categories' ? 'active' : '' ?>" href="inventory_admin.php?section=categories">Categories</a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $section === 'models' ? 'active' : '' ?>" href="inventory_admin.php?section=models">Models</a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $section === 'inventory' ? 'active' : '' ?>" href="inventory_admin.php">Assets</a>
            </li>
        </ul>

        <?php if ($section === 'inventory'): ?>
            <div class="card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-1">
                        <h5 class="card-title mb-0">Assets</h5>
                        <div class="d-flex gap-2 flex-wrap">
                            <a class="btn btn-outline-secondary" href="inventory_admin.php?section=inventory&export=assets">Export CSV</a>
                            <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#importAssetsModal">Import CSV</button>
                            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createAssetModal">Create Asset</button>
                        </div>
                    </div>
                    <form method="get" id="assets-filter-form">
                        <input type="hidden" name="section" value="inventory">
                        <input type="hidden" name="page" value="1">
                        <div class="row g-2 mb-3">
                            <div class="col-md-4">
                                <input type="text" class="form-control" id="assets-filter" name="q" value="<?= h($assetsSearch) ?>" placeholder="Search assets..." data-auto-submit>
                            </div>
                            <div class="col-md-3">
                                <select class="form-select" id="assets-sort" name="sort">
                                    <option value="tag:asc" <?= $assetsSort === 'tag:asc' ? 'selected' : '' ?>>Sort by tag (A-Z)</option>
                                    <option value="tag:desc" <?= $assetsSort === 'tag:desc' ? 'selected' : '' ?>>Sort by tag (Z-A)</option>
                                    <option value="name:asc" <?= $assetsSort === 'name:asc' ? 'selected' : '' ?>>Sort by name (A-Z)</option>
                                    <option value="name:desc" <?= $assetsSort === 'name:desc' ? 'selected' : '' ?>>Sort by name (Z-A)</option>
                                    <option value="model:asc" <?= $assetsSort === 'model:asc' ? 'selected' : '' ?>>Sort by model (A-Z)</option>
                                    <option value="model:desc" <?= $assetsSort === 'model:desc' ? 'selected' : '' ?>>Sort by model (Z-A)</option>
                                    <option value="status:asc" <?= $assetsSort === 'status:asc' ? 'selected' : '' ?>>Sort by status (A-Z)</option>
                                    <option value="status:desc" <?= $assetsSort === 'status:desc' ? 'selected' : '' ?>>Sort by status (Z-A)</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <select class="form-select" id="assets-status-filter" name="status">
                                    <option value="">All statuses</option>
                                    <?php foreach ($statusOptions as $opt): ?>
                                        <option value="<?= h($opt) ?>" <?= $assetsStatusFilter === $opt ? 'selected' : '' ?>><?= h(ucwords(str_replace('_', ' ', $opt))) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <select class="form-select" id="assets-model-filter" name="model">
                                    <option value="">All models</option>
                                    <?php foreach ($modelsAll as $model): ?>
                                        <option value="<?= h($model['name'] ?? '') ?>" <?= ($model['name'] ?? '') === $assetsModelFilter ? 'selected' : '' ?>><?= h($model['name'] ?? '') ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </form>
                    <p class="text-muted small mb-3">
                        <?= (int)$assetsPagination['total'] ?> total.
                        <?php if ($assetsPagination['total'] > 0): ?>
                            Showing <?= (int)$assetsPagination['start'] ?>-<?= (int)$assetsPagination['end'] ?>.
                        <?php endif; ?>
                    </p>
                    <?php if (empty($assets)): ?>
                        <div class="text-muted small">No assets found yet.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm table-striped align-middle">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Tag</th>
                                        <th>Name</th>
                                        <th>Model</th>
                                        <th>Status</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody id="assets-table">
                                    <?php foreach ($assets as $asset): ?>
                                        <tr data-id="<?= (int)($asset['id'] ?? 0) ?>"
                                            data-tag="<?= h($asset['asset_tag'] ?? '') ?>"
                                            data-name="<?= h($asset['name'] ?? '') ?>"
                                            data-model="<?= h($asset['model_name'] ?? '') ?>"
                                            data-status="<?= h($asset['status'] ?? 'available') ?>">
                                            <td><?= (int)($asset['id'] ?? 0) ?></td>
                                            <td><?= h($asset['asset_tag'] ?? '') ?></td>
                                            <td><?= h($asset['name'] ?? '') ?></td>
                                            <td><?= h($asset['model_name'] ?? '') ?></td>
                                            <td><?= h(ucwords(str_replace('_', ' ', $asset['status'] ?? 'available'))) ?></td>
                                            <td class="text-end">
                                                <button type="button"
                                                        class="btn btn-sm btn-outline-secondary"
                                                        data-bs-toggle="modal"
                                                        data-bs-target="#assetNotesModal-<?= (int)$asset['id'] ?>">
                                                    View Notes History
                                                </button>
                                                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#editAssetModal-<?= (int)$asset['id'] ?>">Edit</button>
                                                <form method="post" class="d-inline js-delete-confirm" data-confirm-default="Delete this asset?">
                                                    <input type="hidden" name="action" value="delete_asset">
                                                    <input type="hidden" name="asset_id" value="<?= (int)($asset['id'] ?? 0) ?>">
                                                    <input type="hidden" name="section" value="inventory">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                                </form>
                                            </td>
                                            </tr>
                                        <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="d-flex justify-content-end mt-2">
                            <?= $renderPagination($assetsPagination, $buildQuery) ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php else: ?>
            <?php if ($section === 'models'): ?>
                <div class="card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-1">
                        <h5 class="card-title mb-0">Models</h5>
                        <div class="d-flex gap-2 flex-wrap">
                            <a class="btn btn-outline-secondary" href="inventory_admin.php?section=models&export=models">Export CSV</a>
                            <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#importModelsModal">Import CSV</button>
                            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createModelModal">Create Model</button>
                        </div>
                    </div>
                    <form method="get" id="models-filter-form">
                        <input type="hidden" name="section" value="models">
                        <input type="hidden" name="page" value="1">
                        <div class="row g-2 mb-3">
                            <div class="col-md-4">
                                <input type="text" class="form-control" id="models-filter" name="q" value="<?= h($modelsSearch) ?>" placeholder="Search models..." data-auto-submit>
                            </div>
                            <div class="col-md-3">
                                <select class="form-select" id="models-sort" name="sort">
                                    <option value="name:asc" <?= $modelsSort === 'name:asc' ? 'selected' : '' ?>>Sort by name (A-Z)</option>
                                    <option value="name:desc" <?= $modelsSort === 'name:desc' ? 'selected' : '' ?>>Sort by name (Z-A)</option>
                                    <option value="manufacturer:asc" <?= $modelsSort === 'manufacturer:asc' ? 'selected' : '' ?>>Sort by manufacturer (A-Z)</option>
                                    <option value="manufacturer:desc" <?= $modelsSort === 'manufacturer:desc' ? 'selected' : '' ?>>Sort by manufacturer (Z-A)</option>
                                    <option value="category:asc" <?= $modelsSort === 'category:asc' ? 'selected' : '' ?>>Sort by category (A-Z)</option>
                                    <option value="category:desc" <?= $modelsSort === 'category:desc' ? 'selected' : '' ?>>Sort by category (Z-A)</option>
                                    <option value="assets:asc" <?= $modelsSort === 'assets:asc' ? 'selected' : '' ?>>Sort by assets (A-Z)</option>
                                    <option value="assets:desc" <?= $modelsSort === 'assets:desc' ? 'selected' : '' ?>>Sort by assets (Z-A)</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <select class="form-select" id="models-category-filter" name="category">
                                    <option value="">All categories</option>
                                    <?php foreach ($categoriesAll as $category): ?>
                                        <option value="<?= h($category['name'] ?? '') ?>" <?= ($category['name'] ?? '') === $modelsCategoryFilter ? 'selected' : '' ?>><?= h($category['name'] ?? '') ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </form>
                    <p class="text-muted small mb-3">
                        <?= (int)$modelsPagination['total'] ?> total.
                        <?php if ($modelsPagination['total'] > 0): ?>
                            Showing <?= (int)$modelsPagination['start'] ?>-<?= (int)$modelsPagination['end'] ?>.
                        <?php endif; ?>
                    </p>
                    <?php if (empty($models)): ?>
                        <div class="text-muted small">No models found yet.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                                <table class="table table-sm table-striped align-middle">
                                    <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Image</th>
                                        <th>Name</th>
                                        <th>Manufacturer</th>
                                        <th>Category</th>
                                        <th>Assets</th>
                                        <th></th>
                                    </tr>
                                    </thead>
                                    <tbody id="models-table">
                                        <?php foreach ($models as $model): ?>
                                            <tr data-id="<?= (int)($model['id'] ?? 0) ?>"
                                                data-name="<?= h($model['name'] ?? '') ?>"
                                                data-manufacturer="<?= h($model['manufacturer'] ?? '') ?>"
                                                data-category="<?= h($model['category_name'] ?? '') ?>">
                                                <td><?= (int)($model['id'] ?? 0) ?></td>
                                                <td>
                                                    <?php if (!empty($model['image_url'])): ?>
                                                        <img src="<?= h($model['image_url']) ?>" alt="" style="width: 56px; height: 56px; object-fit: cover; border-radius: 6px;">
                                                    <?php else: ?>
                                                        <span class="text-muted small">No image</span>
                                                    <?php endif; ?>
                                                </td>
                                            <td><?= h($model['name'] ?? '') ?></td>
                                            <td><?= h($model['manufacturer'] ?? '') ?></td>
                                            <td><?= h($model['category_name'] ?? 'Unassigned') ?></td>
                                            <td><?= (int)($model['asset_count'] ?? 0) ?></td>
                                            <td class="text-end">
                                                <a class="btn btn-sm btn-outline-primary" href="inventory_admin.php?section=inventory&asset_model=<?= urlencode($model['name'] ?? '') ?>">View Assets</a>
                                                <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#createAssetForModelModal-<?= (int)$model['id'] ?>">Create Asset</button>
                                                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#editModelModal-<?= (int)$model['id'] ?>">Edit</button>
                                                <form method="post" class="d-inline js-delete-related" data-delete-type="model" data-delete-label="<?= h($model['name'] ?? '') ?>">
                                                    <input type="hidden" name="action" value="delete_model">
                                                    <input type="hidden" name="model_id" value="<?= (int)($model['id'] ?? 0) ?>">
                                                    <input type="hidden" name="section" value="models">
                                                    <input type="hidden" name="delete_related" value="0">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                                </form>
                                            </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <div class="d-flex justify-content-end mt-2">
                            <?= $renderPagination($modelsPagination, $buildQuery) ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php else: ?>
            <div class="card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-1">
                        <h5 class="card-title mb-0">Categories</h5>
                        <div class="d-flex gap-2 flex-wrap">
                            <a class="btn btn-outline-secondary" href="inventory_admin.php?section=categories&export=categories">Export CSV</a>
                            <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#importCategoriesModal">Import CSV</button>
                            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createCategoryModal">Create Category</button>
                        </div>
                    </div>
                    <form method="get" id="categories-filter-form">
                        <input type="hidden" name="section" value="categories">
                        <input type="hidden" name="page" value="1">
                        <div class="row g-2 mb-3">
                            <div class="col-md-4">
                                <input type="text" class="form-control" id="categories-filter" name="q" value="<?= h($categoriesSearch) ?>" placeholder="Search categories..." data-auto-submit>
                            </div>
                            <div class="col-md-3">
                                <select class="form-select" id="categories-sort" name="sort">
                                    <option value="name:asc" <?= $categoriesSort === 'name:asc' ? 'selected' : '' ?>>Sort by name (A-Z)</option>
                                    <option value="name:desc" <?= $categoriesSort === 'name:desc' ? 'selected' : '' ?>>Sort by name (Z-A)</option>
                                    <option value="description:asc" <?= $categoriesSort === 'description:asc' ? 'selected' : '' ?>>Sort by description (A-Z)</option>
                                    <option value="description:desc" <?= $categoriesSort === 'description:desc' ? 'selected' : '' ?>>Sort by description (Z-A)</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <select class="form-select" id="categories-description-filter" name="desc">
                                    <option value="">All descriptions</option>
                                    <option value="1" <?= $categoriesDescriptionFilter === '1' ? 'selected' : '' ?>>Has description</option>
                                    <option value="0" <?= $categoriesDescriptionFilter === '0' ? 'selected' : '' ?>>No description</option>
                                </select>
                            </div>
                        </div>
                    </form>
                    <p class="text-muted small mb-3">
                        <?= (int)$categoriesPagination['total'] ?> total.
                        <?php if ($categoriesPagination['total'] > 0): ?>
                            Showing <?= (int)$categoriesPagination['start'] ?>-<?= (int)$categoriesPagination['end'] ?>.
                        <?php endif; ?>
                    </p>
                    <?php if (empty($categories)): ?>
                        <div class="text-muted small">No categories found yet.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm table-striped align-middle">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Name</th>
                                        <th>Description</th>
                                        <th>Models</th>
                                        <th></th>
                                        </tr>
                                    </thead>
                                <tbody id="categories-table">
                                    <?php foreach ($categories as $category): ?>
                                        <tr data-id="<?= (int)($category['id'] ?? 0) ?>"
                                            data-name="<?= h($category['name'] ?? '') ?>"
                                            data-description="<?= h($category['description'] ?? '') ?>">
                                            <td><?= (int)($category['id'] ?? 0) ?></td>
                                            <td>
                                                <input type="text" name="category_name" class="form-control form-control-sm" value="<?= h($category['name'] ?? '') ?>" required form="category-form-<?= (int)($category['id'] ?? 0) ?>" disabled>
                                            </td>
                                            <td>
                                                <input type="text" name="category_description" class="form-control form-control-sm" value="<?= h($category['description'] ?? '') ?>" form="category-form-<?= (int)($category['id'] ?? 0) ?>" disabled>
                                            </td>
                                            <td><?= (int)($category['model_count'] ?? 0) ?></td>
                                            <td class="text-end">
                                                <a class="btn btn-sm btn-outline-primary" href="inventory_admin.php?section=models&category=<?= urlencode($category['name'] ?? '') ?>">View Models</a>
                                                <button type="button"
                                                        class="btn btn-sm btn-outline-primary js-create-model"
                                                        data-bs-toggle="modal"
                                                        data-bs-target="#createModelModal"
                                                        data-category-id="<?= (int)($category['id'] ?? 0) ?>">
                                                    Create Model
                                                </button>
                                                <form method="post" id="category-form-<?= (int)($category['id'] ?? 0) ?>" class="d-inline">
                                                    <input type="hidden" name="action" value="save_category">
                                                    <input type="hidden" name="category_id" value="<?= (int)($category['id'] ?? 0) ?>">
                                                    <input type="hidden" name="section" value="categories">
                                                    <button type="button" class="btn btn-sm btn-outline-secondary js-category-edit">Edit</button>
                                                    <button type="submit" class="btn btn-sm btn-outline-primary js-category-save" disabled>Save</button>
                                                </form>
                                                <form method="post" class="d-inline js-delete-related" data-delete-type="category" data-delete-label="<?= h($category['name'] ?? '') ?>">
                                                    <input type="hidden" name="action" value="delete_category">
                                                    <input type="hidden" name="category_id" value="<?= (int)($category['id'] ?? 0) ?>">
                                                    <input type="hidden" name="section" value="categories">
                                                    <input type="hidden" name="delete_related" value="0">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="d-flex justify-content-end mt-2">
                            <?= $renderPagination($categoriesPagination, $buildQuery) ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
<?php layout_footer(); ?>
<div class="modal fade" id="deleteRelatedModal" tabindex="-1" aria-labelledby="deleteRelatedModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="deleteRelatedModalLabel">Delete item</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-2" id="deleteRelatedMessage"></p>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="deleteRelatedToggle">
                    <label class="form-check-label" id="deleteRelatedToggleLabel" for="deleteRelatedToggle"></label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="deleteRelatedConfirm">Delete</button>
            </div>
        </div>
    </div>
</div>
<div class="modal fade" id="importCategoriesModal" tabindex="-1" aria-labelledby="importCategoriesModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="action" value="import_categories">
                <div class="modal-header">
                    <h5 class="modal-title" id="importCategoriesModalLabel">Import categories CSV</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted small mb-3">Columns: name, description</p>
                    <div class="mb-3">
                        <a class="btn btn-outline-secondary btn-sm" href="inventory_admin.php?section=categories&template=categories">Download template CSV</a>
                    </div>
                    <input type="file" name="categories_csv" class="form-control" accept=".csv,text/csv" required>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Import categories</button>
                </div>
            </form>
        </div>
    </div>
</div>
<div class="modal fade" id="importModelsModal" tabindex="-1" aria-labelledby="importModelsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="action" value="import_models">
                <div class="modal-header">
                    <h5 class="modal-title" id="importModelsModalLabel">Import models CSV</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted small mb-3">Columns: id (optional), name, manufacturer, category_id or category_name, notes, image_url</p>
                    <div class="mb-3">
                        <a class="btn btn-outline-secondary btn-sm" href="inventory_admin.php?section=models&template=models">Download template CSV</a>
                    </div>
                    <input type="file" name="models_csv" class="form-control" accept=".csv,text/csv" required>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Import models</button>
                </div>
            </form>
        </div>
    </div>
</div>
<div class="modal fade" id="importAssetsModal" tabindex="-1" aria-labelledby="importAssetsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="action" value="import_assets">
                <div class="modal-header">
                    <h5 class="modal-title" id="importAssetsModalLabel">Import assets CSV</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted small mb-3">Columns: asset_tag, name, model_id or model_name, status</p>
                    <div class="mb-3">
                        <a class="btn btn-outline-secondary btn-sm" href="inventory_admin.php?section=inventory&template=assets">Download template CSV</a>
                    </div>
                    <input type="file" name="assets_csv" class="form-control" accept=".csv,text/csv" required>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Import assets</button>
                </div>
            </form>
        </div>
    </div>
</div>
<div class="modal fade" id="createCategoryModal" tabindex="-1" aria-labelledby="createCategoryModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="post">
                <input type="hidden" name="action" value="save_category">
                <input type="hidden" name="category_id" value="0">
                <input type="hidden" name="section" value="categories">
                <div class="modal-header">
                    <h5 class="modal-title" id="createCategoryModalLabel">Create category</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Category name</label>
                            <input type="text" name="category_name" class="form-control" required>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Description</label>
                            <input type="text" name="category_description" class="form-control">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create category</button>
                </div>
            </form>
        </div>
    </div>
</div>
<div class="modal fade" id="createModelModal" tabindex="-1" aria-labelledby="createModelModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="action" value="save_model">
                <input type="hidden" name="model_id" value="0">
                <input type="hidden" name="section" value="models">
                <div class="modal-header">
                    <h5 class="modal-title" id="createModelModalLabel">Create model</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Model name</label>
                            <input type="text" name="model_name" class="form-control" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Manufacturer</label>
                            <input type="text" name="model_manufacturer" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Category</label>
                            <select name="model_category_id" class="form-select">
                                <option value="">Unassigned</option>
                                <?php foreach ($categoriesAll as $category): ?>
                                    <option value="<?= (int)$category['id'] ?>">
                                        <?= h($category['name'] ?? '') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Notes</label>
                            <textarea name="model_notes" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Image URL</label>
                            <input type="text" name="model_image_url" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Upload image</label>
                            <input type="file" name="model_image_upload" class="form-control">
                            <div class="form-text">Upload replaces the stored image unless a URL is provided.</div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create model</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php foreach ($models as $model): ?>
    <div class="modal fade" id="editModelModal-<?= (int)$model['id'] ?>" tabindex="-1" aria-labelledby="editModelModalLabel-<?= (int)$model['id'] ?>" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="save_model">
                    <input type="hidden" name="model_id" value="<?= (int)$model['id'] ?>">
                    <input type="hidden" name="section" value="models">
                    <div class="modal-header">
                        <h5 class="modal-title" id="editModelModalLabel-<?= (int)$model['id'] ?>">Edit model</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Model name</label>
                                <input type="text" name="model_name" class="form-control" value="<?= h($model['name'] ?? '') ?>" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Manufacturer</label>
                                <input type="text" name="model_manufacturer" class="form-control" value="<?= h($model['manufacturer'] ?? '') ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Category</label>
                                <select name="model_category_id" class="form-select">
                                    <option value="">Unassigned</option>
                                    <?php foreach ($categoriesAll as $category): ?>
                                        <option value="<?= (int)$category['id'] ?>" <?= (int)($model['category_id'] ?? 0) === (int)$category['id'] ? 'selected' : '' ?>>
                                            <?= h($category['name'] ?? '') ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-8">
                                <label class="form-label">Notes</label>
                                <textarea name="model_notes" class="form-control" rows="2"><?= h($model['notes'] ?? '') ?></textarea>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Image URL</label>
                                <input type="text" name="model_image_url" class="form-control" value="<?= h($model['image_url'] ?? '') ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Upload image</label>
                                <input type="file" name="model_image_upload" class="form-control">
                                <div class="form-text">Upload replaces the stored image unless a URL is provided.</div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update model</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
<?php endforeach; ?>
<?php foreach ($models as $model): ?>
    <div class="modal fade" id="createAssetForModelModal-<?= (int)$model['id'] ?>" tabindex="-1" aria-labelledby="createAssetForModelModalLabel-<?= (int)$model['id'] ?>" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="save_asset">
                    <input type="hidden" name="asset_id" value="0">
                    <input type="hidden" name="section" value="inventory">
                    <div class="modal-header">
                        <h5 class="modal-title" id="createAssetForModelModalLabel-<?= (int)$model['id'] ?>">Create asset for <?= h($model['name'] ?? '') ?></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-3">
                                <label class="form-label">Asset tag</label>
                                <input type="text" name="asset_tag" class="form-control" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Asset name</label>
                                <input type="text" name="asset_name" class="form-control" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Model</label>
                                <select name="asset_model_id" class="form-select" required>
                                    <?php foreach ($modelsAll as $modelOption): ?>
                                        <option value="<?= (int)$modelOption['id'] ?>" <?= (int)($modelOption['id'] ?? 0) === (int)($model['id'] ?? 0) ? 'selected' : '' ?>>
                                            <?= h($modelOption['name'] ?? '') ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Status</label>
                                <select name="asset_status" class="form-select">
                                    <?php foreach ($statusOptions as $opt): ?>
                                        <?php if ($opt === 'checked_out') { continue; } ?>
                                        <option value="<?= h($opt) ?>">
                                            <?= h(ucwords(str_replace('_', ' ', $opt))) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                    </div>
                </div>
                <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Create asset</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
<?php endforeach; ?>
<div class="modal fade" id="createAssetModal" tabindex="-1" aria-labelledby="createAssetModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="action" value="save_asset">
                <input type="hidden" name="asset_id" value="0">
                <input type="hidden" name="section" value="inventory">
                <div class="modal-header">
                    <h5 class="modal-title" id="createAssetModalLabel">Create asset</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label">Asset tag</label>
                            <input type="text" name="asset_tag" class="form-control" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Asset name</label>
                            <input type="text" name="asset_name" class="form-control" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Model</label>
                            <select name="asset_model_id" class="form-select" required>
                                <option value="">Select model</option>
                                <?php foreach ($modelsAll as $model): ?>
                                    <option value="<?= (int)$model['id'] ?>">
                                        <?= h($model['name'] ?? '') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Status</label>
                                <select name="asset_status" class="form-select">
                                    <?php foreach ($statusOptions as $opt): ?>
                                        <?php if ($opt === 'checked_out') { continue; } ?>
                                        <option value="<?= h($opt) ?>">
                                            <?= h(ucwords(str_replace('_', ' ', $opt))) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create asset</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php foreach ($assets as $asset): ?>
    <div class="modal fade" id="editAssetModal-<?= (int)$asset['id'] ?>" tabindex="-1" aria-labelledby="editAssetModalLabel-<?= (int)$asset['id'] ?>" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="save_asset">
                    <input type="hidden" name="asset_id" value="<?= (int)$asset['id'] ?>">
                    <input type="hidden" name="section" value="inventory">
                    <div class="modal-header">
                        <h5 class="modal-title" id="editAssetModalLabel-<?= (int)$asset['id'] ?>">Edit asset</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-3">
                                <label class="form-label">Asset tag</label>
                                <input type="text" name="asset_tag" class="form-control" value="<?= h($asset['asset_tag'] ?? '') ?>" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Asset name</label>
                                <input type="text" name="asset_name" class="form-control" value="<?= h($asset['name'] ?? '') ?>" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Model</label>
                                <select name="asset_model_id" class="form-select" required>
                                    <option value="">Select model</option>
                                    <?php foreach ($modelsAll as $model): ?>
                                        <option value="<?= (int)$model['id'] ?>" <?= (int)($asset['model_id'] ?? 0) === (int)$model['id'] ? 'selected' : '' ?>>
                                            <?= h($model['name'] ?? '') ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Status</label>
                                <?php $currentStatus = $asset['status'] ?? 'available'; ?>
                                <?php if ($currentStatus === 'checked_out'): ?>
                                    <input type="text"
                                           class="form-control"
                                           value="Checked out (set by reservation)"
                                           disabled>
                                    <input type="hidden" name="asset_status" value="checked_out">
                                <?php else: ?>
                                    <select name="asset_status" class="form-select">
                                        <?php foreach ($statusOptions as $opt): ?>
                                            <?php if ($opt === 'checked_out') { continue; } ?>
                                            <option value="<?= h($opt) ?>" <?= $currentStatus === $opt ? 'selected' : '' ?>>
                                                <?= h(ucwords(str_replace('_', ' ', $opt))) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update asset</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
<?php endforeach; ?>
<?php foreach ($assets as $asset): ?>
    <?php
        $assetId = (int)($asset['id'] ?? 0);
        $notes = $assetNotesById[$assetId] ?? [];
    ?>
    <div class="modal fade" id="assetNotesModal-<?= $assetId ?>" tabindex="-1" aria-labelledby="assetNotesModalLabel-<?= $assetId ?>" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="assetNotesModalLabel-<?= $assetId ?>">Notes History</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <div class="fw-semibold"><?= h($asset['asset_tag'] ?? '') ?> — <?= h($asset['name'] ?? '') ?></div>
                        <div class="text-muted small"><?= h($asset['model_name'] ?? '') ?></div>
                    </div>
                    <?php if (empty($notes)): ?>
                        <div class="text-muted">No notes recorded yet.</div>
                    <?php else: ?>
                        <div class="row g-2 mb-3">
                            <div class="col-md-8">
                                <input type="text"
                                       class="form-control form-control-sm asset-notes-search"
                                       placeholder="Search notes..."
                                       data-notes-search>
                            </div>
                            <div class="col-md-4">
                                <select class="form-select form-select-sm asset-notes-type" data-notes-type>
                                    <option value="">All types</option>
                                    <option value="checkin">Check-in</option>
                                    <option value="checkout">Checkout</option>
                                </select>
                            </div>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle asset-notes-table">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Type</th>
                                        <th>Added by</th>
                                        <th>Note</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($notes as $note): ?>
                                        <?php
                                            $noteType = $note['note_type'] ?? '';
                                            $noteLabel = $noteType === 'checkout' ? 'Checkout' : 'Check-in';
                                            $createdAt = $note['created_at'] ?? '';
                                            $displayDate = $createdAt !== '' ? layout_format_datetime($createdAt) : '';
                                            $actorName = trim((string)($note['actor_name'] ?? ''));
                                            $actorEmail = trim((string)($note['actor_email'] ?? ''));
                                            $actorLabel = $actorName !== '' ? $actorName : '';
                                            if ($actorEmail !== '') {
                                                $actorLabel = $actorLabel !== '' ? ($actorLabel . ' <' . $actorEmail . '>') : $actorEmail;
                                            }
                                            if ($actorLabel === '') {
                                                $actorLabel = 'System';
                                            }
                                            $searchText = strtolower(trim($noteLabel . ' ' . ($note['note'] ?? '') . ' ' . $actorLabel));
                                        ?>
                                        <tr data-note-type="<?= h($noteType) ?>" data-note-search="<?= h($searchText) ?>">
                                            <td class="text-nowrap"><?= h($displayDate) ?></td>
                                            <td><?= h($noteLabel) ?></td>
                                            <td><?= h($actorLabel) ?></td>
                                            <td><?= h($note['note'] ?? '') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
</div>
<?php endforeach; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    function wireFilterForm(formId) {
        var form = document.getElementById(formId);
        if (!form) {
            return;
        }
        var textInput = form.querySelector('[data-auto-submit]');
        var selects = Array.from(form.querySelectorAll('select'));
        var timer;
        var submit = function () {
            var pageInput = form.querySelector('input[name="page"]');
            if (pageInput) {
                pageInput.value = '1';
            }
            form.submit();
        };
        if (textInput) {
            textInput.addEventListener('input', function () {
                if (timer) {
                    window.clearTimeout(timer);
                }
                timer = window.setTimeout(submit, 300);
            });
        }
        selects.forEach(function (select) {
            select.addEventListener('change', submit);
        });
    }

    wireFilterForm('assets-filter-form');
    wireFilterForm('models-filter-form');
    wireFilterForm('categories-filter-form');

    var deleteModalEl = document.getElementById('deleteRelatedModal');
    var deleteModal = deleteModalEl ? new bootstrap.Modal(deleteModalEl) : null;
    var deleteMessage = document.getElementById('deleteRelatedMessage');
    var deleteToggle = document.getElementById('deleteRelatedToggle');
    var deleteToggleLabel = document.getElementById('deleteRelatedToggleLabel');
    var deleteConfirm = document.getElementById('deleteRelatedConfirm');
    var activeDeleteForm = null;

    document.querySelectorAll('.js-delete-related').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (!deleteModal) {
                return;
            }
            event.preventDefault();
            activeDeleteForm = form;
            var type = form.dataset.deleteType || 'item';
            var label = form.dataset.deleteLabel || '';
            if (deleteMessage) {
                deleteMessage.textContent = label ? ('Delete ' + type + ' "' + label + '"?') : ('Delete this ' + type + '?');
            }
            if (deleteToggleLabel) {
                deleteToggleLabel.textContent = type === 'category'
                    ? 'Also delete models and assets in this category'
                    : 'Also delete all assets for this model';
            }
            if (deleteToggle) {
                deleteToggle.checked = false;
            }
            deleteModal.show();
        });
    });

    if (deleteConfirm) {
        deleteConfirm.addEventListener('click', function () {
            if (!activeDeleteForm) {
                return;
            }
            var relatedInput = activeDeleteForm.querySelector('input[name="delete_related"]');
            if (relatedInput) {
                relatedInput.value = deleteToggle && deleteToggle.checked ? '1' : '0';
            }
            activeDeleteForm.submit();
        });
    }

    document.querySelectorAll('.js-create-model').forEach(function (button) {
        button.addEventListener('click', function () {
            var categoryId = button.dataset.categoryId || '';
            var modal = document.getElementById('createModelModal');
            if (!modal) {
                return;
            }
            var select = modal.querySelector('select[name="model_category_id"]');
            if (select) {
                select.value = categoryId;
            }
        });
    });

    document.querySelectorAll('.js-category-edit').forEach(function (button) {
        button.addEventListener('click', function () {
            var form = button.closest('form');
            if (!form) {
                return;
            }
            var formId = form.getAttribute('id');
            if (!formId) {
                return;
            }
            var inputs = document.querySelectorAll('input[form="' + formId + '"]');
            inputs.forEach(function (input) {
                input.disabled = false;
            });
            var saveButton = form.querySelector('.js-category-save');
            if (saveButton) {
                saveButton.disabled = false;
            }
            if (inputs.length > 0) {
                inputs[0].focus();
            }
        });
    });

    document.querySelectorAll('.asset-notes-table').forEach(function (table) {
        var modal = table.closest('.modal');
        if (!modal) {
            return;
        }
        var searchInput = modal.querySelector('[data-notes-search]');
        var typeSelect = modal.querySelector('[data-notes-type]');
        var rows = Array.from(table.querySelectorAll('tbody tr'));
        var filterRows = function () {
            var q = searchInput ? searchInput.value.trim().toLowerCase() : '';
            var type = typeSelect ? typeSelect.value : '';
            rows.forEach(function (row) {
                var rowType = row.dataset.noteType || '';
                var haystack = row.dataset.noteSearch || '';
                var matchesType = type === '' || rowType === type;
                var matchesText = q === '' || haystack.indexOf(q) !== -1;
                row.style.display = matchesType && matchesText ? '' : 'none';
            });
        };
        if (searchInput) {
            searchInput.addEventListener('input', filterRows);
        }
        if (typeSelect) {
            typeSelect.addEventListener('change', filterRows);
        }
    });
</script>
</body>
</html>
