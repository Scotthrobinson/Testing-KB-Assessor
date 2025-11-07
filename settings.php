<?php
declare(strict_types=1);

$deps = require __DIR__ . '/bootstrap.php';
/** @var App\Db $db */
$db = $deps['db'];

// Create Settings instance
$settings = new App\Settings($db);
$allSettings = $settings->getAllByCategory();
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Settings - KB Assessor</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link id="theme-css"
        href="https://cdn.jsdelivr.net/npm/bootswatch@5.3.3/dist/cosmo/bootstrap.min.css"
        rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"
        integrity="sha512-DTOQO9RWCH3ppGqcWaEA1BIZOC6xxalwEsw9c2QQeAIftl+Vegovlnee1c9QX4TctnWMn13TZye+giMm8e2LwA=="
        crossorigin="anonymous" referrerpolicy="no-referrer">
    <link rel="stylesheet" href="styles.css">
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-primary mb-4 shadow-sm">
    <div class="container-fluid">
        <span class="navbar-brand d-flex align-items-center">
            <i class="fas fa-cog me-2"></i>
            <span class="fw-bold">Settings</span>
        </span>
        <div class="ms-auto">
            <a href="index.php" class="btn btn-outline-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i>
                Back to Articles
            </a>
        </div>
    </div>
</nav>

<main class="container mb-5">
    <div class="row">
        <div class="col-lg-10 mx-auto">
            <div class="alert alert-info mb-4">
                <i class="fas fa-info-circle me-2"></i>
                <strong>Configuration Settings</strong> - Changes take effect immediately after saving.
            </div>

            <form id="settings-form">
                <?php foreach ($allSettings as $category => $categorySettings): ?>
                    <div class="card mb-4">
                        <div class="card-header bg-primary text-white">
                            <h5 class="mb-0">
                                <i class="fas fa-folder-open me-2"></i>
                                <?= htmlspecialchars(ucwords(str_replace('_', ' ', $category))) ?>
                            </h5>
                        </div>
                        <div class="card-body">
                            <?php foreach ($categorySettings as $setting): ?>
                                <div class="mb-3">
                                    <label for="<?= htmlspecialchars($setting['key']) ?>" class="form-label">
                                        <strong><?= htmlspecialchars($setting['label']) ?></strong>
                                        <?php if (!empty($setting['description'])): ?>
                                            <small class="text-muted d-block"><?= htmlspecialchars($setting['description']) ?></small>
                                        <?php endif; ?>
                                    </label>

                                    <?php if ($setting['type'] === 'textarea'): ?>
                                        <textarea
                                            id="<?= htmlspecialchars($setting['key']) ?>"
                                            name="<?= htmlspecialchars($setting['key']) ?>"
                                            class="form-control"
                                            rows="6"
                                        ><?= htmlspecialchars($setting['value']) ?></textarea>

                                    <?php elseif ($setting['type'] === 'boolean'): ?>
                                        <div class="form-check form-switch">
                                            <input
                                                type="checkbox"
                                                id="<?= htmlspecialchars($setting['key']) ?>"
                                                name="<?= htmlspecialchars($setting['key']) ?>"
                                                class="form-check-input"
                                                value="1"
                                                <?= filter_var($setting['value'], FILTER_VALIDATE_BOOLEAN) ? 'checked' : '' ?>
                                            >
                                        </div>

                                    <?php elseif ($setting['type'] === 'password'): ?>
                                        <div class="input-group">
                                            <input
                                                type="password"
                                                id="<?= htmlspecialchars($setting['key']) ?>"
                                                name="<?= htmlspecialchars($setting['key']) ?>"
                                                class="form-control password-field"
                                                value="<?= htmlspecialchars($setting['value']) ?>"
                                            >
                                            <button class="btn btn-outline-secondary toggle-password" type="button">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                        </div>

                                    <?php else: ?>
                                        <input
                                            type="<?= htmlspecialchars($setting['type']) ?>"
                                            id="<?= htmlspecialchars($setting['key']) ?>"
                                            name="<?= htmlspecialchars($setting['key']) ?>"
                                            class="form-control"
                                            value="<?= htmlspecialchars($setting['value']) ?>"
                                            <?= $setting['type'] === 'number' ? 'step="any"' : '' ?>
                                        >
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>

                <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                    <button type="reset" class="btn btn-secondary">
                        <i class="fas fa-undo me-1"></i>
                        Reset
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i>
                        Save Settings
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Toast Container for Notifications -->
    <div class="toast-container position-fixed bottom-0 end-0 p-3" id="toast-container"></div>
</main>

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
    crossorigin="anonymous"
></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Toggle password visibility
    document.querySelectorAll('.toggle-password').forEach(button => {
        button.addEventListener('click', function() {
            const input = this.parentElement.querySelector('.password-field');
            const icon = this.querySelector('i');

            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        });
    });

    // Handle form submission
    const form = document.getElementById('settings-form');
    form.addEventListener('submit', async function(e) {
        e.preventDefault();

        const formData = new FormData(form);
        const settings = {};

        // Convert FormData to object, handling checkboxes specially
        form.querySelectorAll('input, textarea, select').forEach(element => {
            if (element.type === 'checkbox') {
                settings[element.name] = element.checked ? '1' : '0';
            } else if (element.name) {
                settings[element.name] = element.value;
            }
        });

        try {
            const response = await fetch('api/save_settings.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(settings),
            });

            const result = await response.json();

            if (result.success) {
                showToast('Success', 'Settings saved successfully', 'success');
            } else {
                showToast('Error', result.error || 'Failed to save settings', 'danger');
            }
        } catch (error) {
            showToast('Error', 'Network error: ' + error.message, 'danger');
        }
    });

    function showToast(title, message, type = 'info') {
        const toastId = 'toast-' + Date.now();
        const bgClass = type === 'success' ? 'bg-success' : type === 'danger' ? 'bg-danger' : 'bg-info';

        const toastHtml = `
            <div id="${toastId}" class="toast align-items-center text-white ${bgClass} border-0" role="alert" aria-live="assertive" aria-atomic="true">
                <div class="d-flex">
                    <div class="toast-body">
                        <strong>${title}</strong>: ${message}
                    </div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
                </div>
            </div>
        `;

        document.getElementById('toast-container').insertAdjacentHTML('beforeend', toastHtml);
        const toastElement = document.getElementById(toastId);
        const toast = new bootstrap.Toast(toastElement, { delay: 3000 });
        toast.show();

        toastElement.addEventListener('hidden.bs.toast', function() {
            toastElement.remove();
        });
    }
});
</script>
</body>
</html>
