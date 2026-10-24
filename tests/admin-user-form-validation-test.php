<?php
declare(strict_types=1);
require_once __DIR__ . '/../ADMIN/phpfiles/admin-common.php';
$validator = __DIR__ . '/../ADMIN/phpfiles/admin-user-validation.php';
if (is_file($validator)) require_once $validator;
function user_validation_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}
user_validation_assert(function_exists('admin_validate_create_user'), 'Add User needs server-side field validation.');
$valid = ['first_name' => 'María', 'middle_name' => '', 'last_name' => "Dela Cruz-O’Neil", 'email' => 'maria@example.test', 'password' => 'TestUser!42', 'confirm_password' => 'TestUser!42', 'role_code' => 'pswdo', 'status_code' => 'active', 'contact_number' => '09171234567', 'position_title' => 'Social Welfare Officer II', 'address' => ''];
$result = admin_validate_create_user($valid);
user_validation_assert($result['errors'] === [], 'Accented names and ordinary name punctuation must be accepted.');
user_validation_assert(!array_key_exists('password', $result['values']) && !array_key_exists('confirm_password', $result['values']), 'Passwords must never be included in reusable or flashed form values.');
foreach ([
    ['first_name', 'Ana123'], ['last_name', '<script>'], ['first_name', '   '],
    ['middle_name', '123'], ['first_name', str_repeat('A', 121)],
    ['first_name', "Ana\xFF"], ['position_title', "Officer\xFF"],
    ['email', 'invalid-email'], ['email', str_repeat('a', 170) . '@example.test'],
    ['contact_number', '12345'], ['contact_number', '0917abc4567'],
    ['contact_number', '---'],
    ['password', 'weak'], ['password', str_repeat('A', 73) . 'a1!'],
    ['confirm_password', 'different'], ['role_code', 'owner'], ['status_code', 'invented'],
    ['position_title', '<b>Officer</b>'], ['address', str_repeat('x', 256)],
] as [$field, $value]) {
    $candidate = array_replace($valid, [$field => $value]);
    user_validation_assert(isset(admin_validate_create_user($candidate)['errors'][$field]), 'Invalid ' . $field . ' was accepted.');
}
$result = admin_validate_create_user(array_replace($valid, ['contact_number' => '+63 917 123 4567', 'first_name' => '  Ana   María  ']));
user_validation_assert($result['errors'] === [] && $result['values']['contact_number'] === '09171234567' && $result['values']['first_name'] === 'Ana María', 'Safe spacing and Philippine mobile formats must be normalized.');
$result = admin_validate_create_user(array_replace($valid, ['contact_number' => '', 'position_title' => '', 'middle_name' => '']));
user_validation_assert($result['errors'] === [], 'Optional fields must remain optional.');
echo "Admin Add User server validation checks passed.\n";
