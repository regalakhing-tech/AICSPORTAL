const assert = require('node:assert/strict');
const { validate } = require('../ADMIN/jsfiles/admin-user-form.js');

const valid = { first_name: 'María', middle_name: '', last_name: 'Dela Cruz-O’Neil', email: 'maria@example.test', password: 'TestUser!42', confirm_password: 'TestUser!42', role_code: 'pswdo', status_code: 'active', contact_number: '+63 917 123 4567', position_title: 'Social Welfare Officer II', address: '' };
assert.deepEqual(validate(valid), {});
for (const [field, value] of [
  ['first_name', 'Ana123'], ['first_name', '   '], ['last_name', '<script>'],
  ['middle_name', '123'], ['first_name', 'A'.repeat(121)], ['email', 'bad-email'],
  ['email', 'a'.repeat(170) + '@example.test'], ['contact_number', '12345'],
  ['contact_number', '0917abc4567'], ['password', 'weak'],
  ['contact_number', '---'],
  ['password', 'A'.repeat(73) + 'a1!'], ['confirm_password', 'different'],
  ['role_code', 'owner'], ['status_code', 'invented'], ['position_title', '<b>Officer</b>'],
  ['address', 'x'.repeat(256)],
]) {
  assert.ok(validate({ ...valid, [field]: value })[field], `Invalid ${field} accepted.`);
}
assert.deepEqual(validate({ ...valid, contact_number: '', position_title: '', address: '' }), {});
assert.ok(validate({ ...valid, password: 'Test!123\0', confirm_password: 'Test!123\0' }).password);
assert.ok(validate({ ...valid, password: 'Aa1!' + 'é'.repeat(35), confirm_password: 'Aa1!' + 'é'.repeat(35) }).password);
console.log('Admin Add User client validation checks passed.');
