<?php
declare(strict_types=1);
require_once __DIR__ . '/../ADMIN/phpfiles/admin-common.php';
require_once __DIR__ . '/../ADMIN/phpfiles/admin-user-form.php';
require_once __DIR__ . '/../ADMIN/phpfiles/admin-user-validation.php';
admin_start_session();
$values = admin_validate_create_user(['status_code' => 'active'])['values'];
$values['first_name'] = 'Ana "<test>';
$values['email'] = 'existing@example.test';
$errors = ['email' => 'This email address is already in use.'];
ob_start();
echo '<!doctype html><html><body class="admin-app-page"><section><button data-modal-open="modal-create-user">Add user</button><div id="user-management-root"></div>';
admin_render_create_user_form(
    [['role_code' => 'admin', 'role_name' => 'Admin'], ['role_code' => 'pswdo', 'role_name' => 'PSWDO'], ['role_code' => 'applicat', 'role_name' => 'Applicant']],
    [['status_code' => 'active', 'status_name' => 'Active'], ['status_code' => 'disabled', 'status_name' => 'Disabled']],
    $values, $errors, '', 'all'
);
echo '</section></body></html>';
$html = ob_get_clean();
if (in_array('--fixture', $argv ?? [], true)) { echo $html; exit; }
$dom = new DOMDocument();
libxml_use_internal_errors(true);
$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
$xpath = new DOMXPath($dom);
$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$assert($xpath->query('//input[@name="first_name"]')->item(0)?->getAttribute('value') === $values['first_name'], 'Failed values must be escaped and preserved.');
foreach (['password', 'confirm_password'] as $name) {
    $assert(!$xpath->query('//input[@name="' . $name . '"]')->item(0)?->hasAttribute('value'), 'Never repopulate passwords.');
}
foreach ($xpath->query('//form[@id="create-user-form"]//input[not(@type="hidden")]|//form[@id="create-user-form"]//select') as $field) {
    $id = $field->getAttribute('id');
    $assert($xpath->query('//label[@for="' . $id . '"]')->length === 1, 'Every field needs a visible label.');
    foreach (explode(' ', $field->getAttribute('aria-describedby')) as $description) {
        $assert($xpath->query('//*[@id="' . $description . '"]')->length === 1, 'Field descriptions must resolve.');
    }
}
$assert($xpath->query('//input[@name="email" and @aria-invalid="true"]')->length === 1, 'Server errors must identify the invalid field.');
$assert($xpath->query('//*[@id="create-user-errors"]//a[@href="#create-email"]')->length === 1, 'Errors need links to their fields.');
$assert($xpath->query('//input[@name="_csrf"]')->length === 1, 'The form must retain CSRF protection.');
$assert($xpath->query('//*[@id="create-status_code"]//option[@value="active" and @selected]')->length === 1, 'Active must remain the default status.');
echo "Admin Add User rendered form checks passed.\n";
