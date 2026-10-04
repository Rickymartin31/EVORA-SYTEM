<?php
Auth::requireRole('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('admin-categories');
}
Csrf::requireValid();

$db = Database::connection();
$userId = Auth::id();
$action = $_POST['category_action'] ?? '';

function slugify(string $text): string
{
    $slug = strtolower(trim($text));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    return trim($slug, '-') ?: 'category';
}

if ($action === 'create') {
    $name = trim($_POST['name'] ?? '');
    $slug = trim($_POST['slug'] ?? '') ?: slugify($name);
    $slug = slugify($slug);

    if ($name === '') {
        flash('error', 'Category name is required.');
        redirect('admin-categories');
    }

    try {
        $stmt = $db->prepare('INSERT INTO event_categories (name, slug) VALUES (:name, :slug)');
        $stmt->execute(['name' => $name, 'slug' => $slug]);
        Logger::log($userId, 'category_created', 'Created event category "' . $name . '"');
        flash('success', 'Category added.');
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            flash('error', 'A category with that name or slug already exists.');
        } else {
            throw $e;
        }
    }
} elseif ($action === 'update') {
    $categoryId = (int) ($_POST['category_id'] ?? 0);
    $name = trim($_POST['name'] ?? '');

    if ($name === '') {
        flash('error', 'Category name is required.');
        redirect('admin-categories');
    }

    try {
        $stmt = $db->prepare('UPDATE event_categories SET name = :name WHERE id = :id');
        $stmt->execute(['name' => $name, 'id' => $categoryId]);
        Logger::log($userId, 'category_updated', 'Renamed event category #' . $categoryId . ' to "' . $name . '"');
        flash('success', 'Category updated.');
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            flash('error', 'A category with that name already exists.');
        } else {
            throw $e;
        }
    }
} elseif ($action === 'delete') {
    $categoryId = (int) ($_POST['category_id'] ?? 0);

    try {
        $stmt = $db->prepare('DELETE FROM event_categories WHERE id = :id');
        $stmt->execute(['id' => $categoryId]);
        Logger::log($userId, 'category_deleted', 'Deleted event category #' . $categoryId);
        flash('success', 'Category deleted.');
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            flash('error', 'This category cannot be deleted — one or more events still use it.');
        } else {
            throw $e;
        }
    }
}

redirect('admin-categories');
