<?php
declare(strict_types=1);

/** Count Unicode characters without requiring the optional mbstring extension. */
function admin_user_text_length(string $value): int
{
    $length = preg_match_all('/./us', $value);
    return $length === false ? PHP_INT_MAX : $length;
}

/** Passwords deliberately stay outside the reusable form values. */
function admin_validate_create_user(array $input): array
{
    $values = [];
    $errors = [];
    foreach (['first_name', 'middle_name', 'last_name', 'email', 'role_code', 'status_code', 'contact_number', 'position_title', 'address'] as $field) {
        $values[$field] = is_string($input[$field] ?? null) ? trim($input[$field]) : '';
    }
    foreach (['first_name' => 'First name', 'middle_name' => 'Middle name', 'last_name' => 'Last name'] as $field => $label) {
        $value = $values[$field];
        if ($value === '' && $field !== 'middle_name') {
            $errors[$field] = $label . ' is required.';
        } elseif ($value !== '' && (admin_user_text_length($value) > 120 || !preg_match("/\\A[\\p{L}\\p{M} .’'\\-]+\\z/u", $value) || !preg_match('/\\p{L}/u', $value))) {
            $errors[$field] = 'Use letters, spaces, apostrophes, periods or hyphens, up to 120 characters.';
        } else {
            $values[$field] = preg_replace('/ +/u', ' ', $value) ?? $value;
        }
    }
    if ($values['email'] === '' || strlen($values['email']) > 180 || filter_var($values['email'], FILTER_VALIDATE_EMAIL) === false) {
        $errors['email'] = 'Enter a valid email address, up to 180 characters.';
    }
    if (!in_array($values['role_code'], ['admin', 'pswdo', 'applicat'], true)) {
        $errors['role_code'] = 'Select an account role.';
    }
    if (!in_array($values['status_code'], ['active', 'restricted', 'disabled', 'banned'], true)) {
        $errors['status_code'] = 'Select a valid account status.';
    }
    $mobile = $values['contact_number'];
    if ($mobile !== '') {
        $normalized = preg_replace('/[ ()-]/', '', $mobile) ?? $mobile;
        if (preg_match('/\A(?:09\d{9}|\+?639\d{9})\z/', $normalized)) {
            $values['contact_number'] = str_starts_with($normalized, '09') ? $normalized : '0' . substr(ltrim($normalized, '+'), 2);
        } else {
            $errors['contact_number'] = 'Enter a Philippine mobile number: 09171234567 or +63 917 123 4567.';
        }
    }
    foreach (['position_title' => 120, 'address' => 255] as $field => $limit) {
        if (admin_user_text_length($values[$field]) > $limit || preg_match('/[<>\x00-\x1F\x7F]/u', $values[$field])) {
            $errors[$field] = 'Use plain text, up to ' . $limit . ' characters.';
        }
    }
    $password = is_string($input['password'] ?? null) ? $input['password'] : '';
    $confirmation = is_string($input['confirm_password'] ?? null) ? $input['confirm_password'] : '';
    if (strlen($password) > 72 || preg_match('/[\x00-\x1F\x7F]/', $password)) {
        $errors['password'] = 'Use no more than 72 bytes and no control characters.';
    } elseif (admin_user_text_length($password) === PHP_INT_MAX || admin_user_text_length($password) < 8 || !preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/[0-9]/', $password) || !preg_match('/[^A-Za-z0-9]/', $password)) {
        $errors['password'] = 'Use at least 8 characters with uppercase, lowercase, a number and a symbol.';
    }
    if ($confirmation === '' || $confirmation !== $password) {
        $errors['confirm_password'] = 'Enter the same password again.';
    }
    return ['values' => $values, 'errors' => $errors];
}
