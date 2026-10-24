<?php
declare(strict_types=1);

/** Render the Add User dialog without coupling it to database reads. */
function admin_render_create_user_form(array $roles, array $statuses, array $values, array $errors, string $search, string $roleFilter, string $notice = ''): void
{
    $field = static function (string $name, string $label, string $icon, string $type = 'text', bool $required = false, string $hint = '', string $attributes = '', ?array $options = null) use ($values, $errors): void {
        $id = 'create-' . $name;
        $error = $errors[$name] ?? '';
        $describedBy = $id . '-error' . ($hint !== '' ? ' ' . $id . '-hint' : '');
        ?>
        <div class="create-user-field"<?= $name === 'position_title' ? ' data-profile-field="staff"' : ($name === 'address' ? ' data-profile-field="applicat"' : '') ?>>
          <label for="<?= admin_h($id) ?>"><?= admin_h($label) ?> <?= $required ? '<span class="create-required" aria-hidden="true">*</span>' : '<span class="create-optional">(optional)</span>' ?></label>
          <div class="create-user-control">
            <i class="fas <?= admin_h($icon) ?> create-field-icon" aria-hidden="true"></i>
            <?php if ($options !== null): ?>
              <select id="<?= admin_h($id) ?>" name="<?= admin_h($name) ?>" aria-describedby="<?= admin_h($describedBy) ?>"<?= $error !== '' ? ' aria-invalid="true"' : '' ?><?= $required ? ' required' : '' ?> <?= $attributes ?>>
                <?php foreach ($options as $value => $text): ?>
                  <option value="<?= admin_h((string) $value) ?>"<?= ($values[$name] ?? '') === (string) $value ? ' selected' : '' ?>><?= admin_h($text) ?></option>
                <?php endforeach; ?>
              </select>
            <?php else: ?>
              <input id="<?= admin_h($id) ?>" name="<?= admin_h($name) ?>" type="<?= admin_h($type) ?>"<?= $type !== 'password' ? ' value="' . admin_h($values[$name] ?? '') . '"' : '' ?> aria-describedby="<?= admin_h($describedBy) ?>"<?= $error !== '' ? ' aria-invalid="true"' : '' ?><?= $required ? ' required' : '' ?> <?= $attributes ?>>
              <?php if ($type === 'password'): ?>
                <button type="button" class="create-password-toggle" data-password-toggle data-target="<?= admin_h($id) ?>" aria-label="Show password" aria-pressed="false" aria-controls="<?= admin_h($id) ?>"><i class="fas fa-eye" aria-hidden="true"></i></button>
              <?php endif; ?>
            <?php endif; ?>
          </div>
          <?php if ($hint !== ''): ?><p class="create-field-hint" id="<?= admin_h($id) ?>-hint"><?= admin_h($hint) ?></p><?php endif; ?>
          <p class="create-field-error" id="<?= admin_h($id) ?>-error"<?= $error === '' ? ' hidden' : '' ?>><?= admin_h($error) ?></p>
        </div>
        <?php
    };
    $roleOptions = ['' => 'Select role'];
    foreach ($roles as $role) {
        $roleOptions[(string) $role['role_code']] = (string) $role['role_name'];
    }
    $statusOptions = [];
    foreach ($statuses as $status) {
        $statusOptions[(string) $status['status_code']] = (string) $status['status_name'];
    }
    ?>
    <div class="admin-modal-shell" id="modal-create-user" aria-hidden="true">
      <div class="admin-modal-backdrop" data-modal-close></div>
      <div class="admin-modal-card create-user-card" role="dialog" aria-modal="true" aria-labelledby="modal-create-title" aria-describedby="modal-create-description">
        <header class="admin-modal-head create-user-head">
          <div class="create-user-heading">
            <span class="create-user-heading-icon"><i class="fas fa-user-plus" aria-hidden="true"></i></span>
            <div><h3 id="modal-create-title">Add User Account</h3><p id="modal-create-description">Set up a profile and choose their access to AICS.</p></div>
          </div>
          <button type="button" class="admin-modal-close" data-modal-close aria-label="Close Add User Account"><i class="fas fa-xmark" aria-hidden="true"></i></button>
        </header>
        <form action="" method="post" class="create-user-form" id="create-user-form">
          <?= admin_csrf_input() ?>
          <input type="hidden" name="action" value="create_user">
          <input type="hidden" name="return_q" value="<?= admin_h($search) ?>">
          <input type="hidden" name="return_role" value="<?= admin_h($roleFilter) ?>">
          <div class="create-user-body">
            <p class="create-required-note">Fields marked <span class="create-required">*</span> are required.</p>
            <div class="create-error-summary" id="create-user-errors" role="alert" tabindex="-1"<?= $errors === [] && $notice === '' ? ' hidden' : '' ?>>
              <strong>Check the account details</strong>
              <p><?= admin_h($notice !== '' ? $notice : 'Correct these fields, then create the account.') ?></p>
              <ul><?php foreach ($errors as $name => $error): ?><li><a href="#create-<?= admin_h($name) ?>"><?= admin_h($error) ?></a></li><?php endforeach; ?></ul>
            </div>
            <fieldset class="create-user-section">
              <legend>Personal details</legend>
              <div class="create-user-grid create-user-names">
                <?php
                $field('first_name', 'First name', 'fa-user', 'text', true, '', 'maxlength="120" autocomplete="given-name"');
                $field('middle_name', 'Middle name', 'fa-user', 'text', false, '', 'maxlength="120" autocomplete="additional-name"');
                $field('last_name', 'Last name', 'fa-user', 'text', true, '', 'maxlength="120" autocomplete="family-name"');
                ?>
              </div>
              <p class="create-section-hint">Use letters and name punctuation. Accented names are supported.</p>
            </fieldset>
            <fieldset class="create-user-section">
              <legend>Account access</legend>
              <div class="create-user-grid">
                <?php
                $field('email', 'Email address', 'fa-envelope', 'email', true, 'They will use this email to sign in.', 'maxlength="180" autocomplete="off" spellcheck="false" autocapitalize="none"');
                $field('role_code', 'Account role', 'fa-user-shield', 'text', true, 'Choose the access this person needs.', '', $roleOptions);
                $field('password', 'Password', 'fa-lock', 'password', true, '8 or more characters; maximum 72 bytes.', 'autocomplete="new-password" minlength="8"');
                $field('confirm_password', 'Confirm password', 'fa-lock', 'password', true, 'Enter the password again to confirm it.', 'autocomplete="new-password"');
                ?>
              </div>
              <ul class="create-password-rules" aria-label="Password requirements">
                <?php foreach (['length' => 'At least 8 characters', 'case' => 'Uppercase & lowercase', 'number' => 'One number', 'symbol' => 'One symbol'] as $rule => $label): ?>
                  <li data-password-rule="<?= admin_h($rule) ?>"><i class="fas fa-circle" aria-hidden="true"></i><span><?= admin_h($label) ?></span><span class="create-rule-state sr-only">: not met</span></li>
                <?php endforeach; ?>
              </ul>
              <div class="create-user-grid create-status-row">
                <?php $field('status_code', 'Account status', 'fa-circle-check', 'text', true, 'Active accounts can sign in. Restrictions apply immediately.', '', $statusOptions); ?>
              </div>
              <?php if ($notice !== '' || $errors !== []): ?><p class="create-section-hint">For security, enter the password and confirmation again.</p><?php endif; ?>
            </fieldset>
            <fieldset class="create-user-section">
              <legend>Profile details <span class="create-optional">(optional)</span></legend>
              <div class="create-user-grid">
                <?php
                $field('contact_number', 'Mobile number', 'fa-mobile-screen-button', 'tel', false, 'Use 09171234567 or +63 917 123 4567.', 'maxlength="24" inputmode="tel" autocomplete="off"');
                $field('position_title', 'Position title', 'fa-briefcase', 'text', false, 'For Admin and PSWDO staff.', 'maxlength="120" autocomplete="organization-title"');
                $field('address', 'Address', 'fa-location-dot', 'text', false, 'For applicant profiles.', 'maxlength="255" autocomplete="off"');
                ?>
              </div>
              <p class="create-section-hint" id="create-role-hint">Select a role to see the relevant profile fields.</p>
            </fieldset>
          </div>
          <footer class="create-user-actions">
            <p><i class="fas fa-shield-halved" aria-hidden="true"></i> Account creation is saved in the security log.</p>
            <div><button type="button" class="btn-outline" data-modal-close>Cancel</button><button type="submit" class="btn-solid" id="create-user-submit"><i class="fas fa-user-plus" aria-hidden="true"></i><span>Create account</span></button></div>
          </footer>
        </form>
      </div>
    </div>
    <?php
}
