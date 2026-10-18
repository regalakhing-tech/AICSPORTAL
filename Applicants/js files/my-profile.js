function initMyProfilePage() {
  const form = document.getElementById('profile-update-form');
  if (!form) {
    return;
  }

  const getUrl = form.dataset.getUrl || 'profile-get.php';
  const saveUrl = form.dataset.saveUrl || 'profile-save.php';

  const firstNameInput = document.getElementById('profile-first-name');
  const middleNameInput = document.getElementById('profile-middle-name');
  const lastNameInput = document.getElementById('profile-last-name');
  const extensionInput = document.getElementById('profile-extension-name');
  const dobInput = document.getElementById('profile-date-of-birth');
  const ageInput = document.getElementById('profile-age');
  const civilStatusInput = document.getElementById('profile-civil-status');
  const civilStatusOtherInput = document.getElementById('profile-civil-status-other');
  const civilStatusOtherField = document.getElementById('profile-civil-status-other-field');
  const educationInput = document.getElementById('profile-education');
  const emailInput = document.getElementById('profile-email');
  const contactInput = document.getElementById('profile-contact-number');
  const occupationInput = document.getElementById('profile-occupation');
  const occupationOtherInput = document.getElementById('profile-occupation-other');
  const occupationOtherField = document.getElementById('profile-occupation-other-field');
  const occupationOtherAnchor = occupationOtherField ? document.createComment('occupation-other') : null;
  if (occupationOtherField && occupationOtherAnchor) {
    occupationOtherField.before(occupationOtherAnchor);
  }
  const incomeInput = document.getElementById('profile-income');
  const addressLineInput = document.getElementById('profile-address-line');
  const provinceInput = document.getElementById('profile-province');
  const municipalityInput = document.getElementById('profile-municipality');
  const barangayInput = document.getElementById('profile-barangay');
  const addressInput = document.getElementById('profile-complete-address');

  const saveBtn = document.getElementById('profile-save-btn');
  const feedback = document.getElementById('profile-feedback');

  const summaryName = document.getElementById('summary-full-name');
  const summaryEmail = document.getElementById('summary-email');
  const summaryPhone = document.getElementById('summary-phone');
  const summaryAddress = document.getElementById('summary-address');
  const summaryAvatar = document.getElementById('summary-avatar');
  const idPhotoCard = document.getElementById('profile-id-card');
  const idTypeLabel = document.getElementById('profile-id-type');
  const idPhotoDialog = document.getElementById('profile-id-dialog');
  const idPhotoLargeImage = document.getElementById('profile-id-large-image');
  const idPhotoCaption = document.getElementById('profile-id-dialog-caption');
  const idPhotoTypeLabel = document.getElementById('profile-id-dialog-type');
  const idPhotoRotation = { front: 0, back: 0 };
  let idPhotoTrigger = null;
  const municipalityBarangays =
    window.ANTIQUE_MUNICIPALITY_BARANGAYS && typeof window.ANTIQUE_MUNICIPALITY_BARANGAYS === 'object'
      ? window.ANTIQUE_MUNICIPALITY_BARANGAYS
      : {};
  const municipalityList = Object.keys(municipalityBarangays);
  const defaultProvince = provinceInput && provinceInput.value.trim() ? provinceInput.value.trim() : 'Antique';

  let snapshot = null;
  let hasSavedProfile = false;
  let profileWasComplete = false;
  let isSaving = false;
  let feedbackDismissTimer = null;

  function setFeedback(message, type = '') {
    if (!feedback) {
      return;
    }

    const messageElement = feedback.querySelector('.profile-save-feedback-message');
    const icon = feedback.querySelector('.fa-circle-check');
    if (messageElement) messageElement.textContent = message;
    else feedback.textContent = message;
    feedback.classList.remove('success', 'error');
    if (type) {
      feedback.classList.add(type);
    }
    feedback.hidden = message === '';
    if (icon) icon.className = type === 'error' ? 'fas fa-circle-exclamation' : 'fas fa-circle-check';
  }

  function dismissFeedback() {
    if (feedbackDismissTimer !== null) window.clearTimeout(feedbackDismissTimer);
    feedbackDismissTimer = null;
    setFeedback('');
  }

  function showSavedFeedback(message) {
    if (feedbackDismissTimer !== null) window.clearTimeout(feedbackDismissTimer);
    setFeedback(message, 'success');
    feedbackDismissTimer = window.setTimeout(dismissFeedback, 6000);
  }

  function normalizedSnapshot(data) {
    const values = data || formDataFromInputs();
    const fields = [
      'first_name', 'middle_name', 'last_name', 'extension_name', 'date_of_birth',
      'civil_status', 'civil_status_other', 'highest_educational_attainment', 'email',
      'contact_number', 'occupation', 'occupation_other', 'estimated_monthly_income',
      'province', 'municipality', 'barangay', 'address_line',
    ];
    return fields.map((field) => [field, String(values[field] ?? '').trim()]);
  }

  function isDirty() {
    if (!snapshot) return false;
    const current = normalizedSnapshot();
    return current.some(([field, value], index) => !snapshot[index] || snapshot[index][0] !== field || snapshot[index][1] !== value);
  }

  function updateSaveButton() {
    if (!saveBtn) return;
    const label = isSaving ? 'Saving...' : (hasSavedProfile ? 'Save Changes' : 'Save');
    const labelElement = saveBtn.querySelector('span');
    if (labelElement) labelElement.textContent = label;
    else saveBtn.textContent = label;
    saveBtn.disabled = isSaving || !isDirty();
  }

  function setSaving(saving) {
    isSaving = saving;
    updateSaveButton();
  }

  function initialsOf(name) {
    return name
      .split(' ')
      .filter(Boolean)
      .map((part) => part[0])
      .slice(0, 2)
      .join('')
      .toUpperCase() || 'AP';
  }

  function displayOrDash(value) {
    const trimmed = (value || '').trim();
    return trimmed !== '' ? trimmed : '-';
  }

  function formDataFromInputs() {
    const addressLine = addressLineInput ? addressLineInput.value.trim() : '';
    const municipality = municipalityInput ? municipalityInput.value.trim() : '';
    const barangay = barangayInput ? barangayInput.value.trim() : '';
    const province = provinceInput ? provinceInput.value.trim() : defaultProvince;

    return {
      first_name: firstNameInput ? firstNameInput.value.trim() : '',
      middle_name: middleNameInput ? middleNameInput.value.trim() : '',
      last_name: lastNameInput ? lastNameInput.value.trim() : '',
      extension_name: extensionInput ? extensionInput.value.trim() : '',
      date_of_birth: dobInput ? dobInput.value : '',
      civil_status: civilStatusInput ? civilStatusInput.value : '',
      civil_status_other: civilStatusOtherInput ? civilStatusOtherInput.value.trim() : '',
      highest_educational_attainment: educationInput ? educationInput.value.trim() : '',
      email: emailInput ? emailInput.value.trim() : '',
      contact_number: contactInput ? contactInput.value.trim() : '',
      occupation: occupationInput ? occupationInput.value.trim() : '',
      occupation_other: occupationOtherInput ? occupationOtherInput.value.trim() : '',
      estimated_monthly_income: incomeInput ? incomeInput.value.trim() : '',
      province, municipality, barangay, address_line: addressLine,
      complete_address: composeFullAddress(addressLine, barangay, municipality, province),
    };
  }

  function composeFullAddress(addressLine, barangay, municipality, province) {
    return [addressLine, barangay, municipality, province]
      .map((value) => (value || '').trim())
      .filter(Boolean)
      .join(', ');
  }

  function populateMunicipalityOptions() {
    if (!municipalityInput) {
      return;
    }

    municipalityInput.innerHTML = '<option value="">Select municipality</option>';
    const placeholder = municipalityInput.options[0];
    placeholder.disabled = true;
    placeholder.hidden = true;
    municipalityList.forEach((name) => {
      const option = document.createElement('option');
      option.value = name;
      option.textContent = name;
      municipalityInput.appendChild(option);
    });
  }

  function updateBarangayOptions(selectedBarangay = '') {
    if (!municipalityInput || !barangayInput) {
      return;
    }

    const municipality = municipalityInput.value.trim();
    const barangays = municipalityBarangays[municipality] || [];

    barangayInput.innerHTML = '';

    const placeholder = document.createElement('option');
    placeholder.value = '';
    placeholder.textContent = municipality ? 'Select barangay' : 'Select municipality first';
    placeholder.disabled = true;
    placeholder.hidden = true;
    barangayInput.appendChild(placeholder);

    barangays.forEach((name) => {
      const option = document.createElement('option');
      option.value = name;
      option.textContent = name;
      barangayInput.appendChild(option);
    });

    barangayInput.disabled = barangays.length === 0;

    if (selectedBarangay && barangays.includes(selectedBarangay)) {
      barangayInput.value = selectedBarangay;
    } else {
      barangayInput.value = '';
    }
  }

  function refreshAddressPreview() {
    if (!addressInput) {
      return;
    }

    const fullAddress = composeFullAddress(
      addressLineInput ? addressLineInput.value.trim() : '',
      barangayInput ? barangayInput.value.trim() : '',
      municipalityInput ? municipalityInput.value.trim() : '',
      provinceInput ? provinceInput.value.trim() : defaultProvince
    );

    addressInput.value = fullAddress;
  }

  function parseCompleteAddress(rawAddress) {
    const parsed = {
      address_line: '',
      barangay: '',
      municipality: '',
      province: defaultProvince,
    };

    const cleanAddress = (rawAddress || '').trim();
    if (!cleanAddress) {
      return parsed;
    }

    const segments = cleanAddress
      .split(',')
      .map((part) => part.trim())
      .filter(Boolean);

    if (segments.length === 0) {
      return parsed;
    }

    const lastSegment = segments[segments.length - 1];
    if (/^antique$/i.test(lastSegment)) {
      parsed.province = lastSegment;
      segments.pop();
    }

    const municipalityIndex = segments.findIndex((part) => municipalityList.includes(part));
    if (municipalityIndex !== -1) {
      parsed.municipality = segments[municipalityIndex];
      const possibleBarangays = municipalityBarangays[parsed.municipality] || [];
      const barangayIndex = segments.findIndex((part, index) => index !== municipalityIndex && possibleBarangays.includes(part));

      if (barangayIndex !== -1) {
        parsed.barangay = segments[barangayIndex];
      }

      parsed.address_line = segments
        .filter((part, index) => index !== municipalityIndex && index !== barangayIndex)
        .join(', ');
    } else {
      parsed.address_line = cleanAddress;
    }

    return parsed;
  }

  const displayValue = (value) => value === null || value === undefined || String(value).trim().toUpperCase() === 'NULL' ? '' : String(value);

  function applyIdentityLock(locked, idTypeLabel) {
    [firstNameInput, middleNameInput, lastNameInput, dobInput].forEach((input) => {
      if (!(input instanceof HTMLInputElement)) return;
      input.readOnly = Boolean(locked);
      input.classList.toggle('is-id-locked', Boolean(locked));
      input.title = locked ? `From your verified ${idTypeLabel || 'ID'}. Contact PSWDO to correct it.` : '';
    });
  }

  function applyProfileToForm(profile) {
    if (firstNameInput) firstNameInput.value = displayValue(profile.first_name);
    if (middleNameInput) middleNameInput.value = displayValue(profile.middle_name);
    if (lastNameInput) lastNameInput.value = displayValue(profile.last_name);
    if (extensionInput) extensionInput.value = displayValue(profile.extension_name);
    if (dobInput) dobInput.value = displayValue(profile.date_of_birth);
    if (civilStatusInput) civilStatusInput.value = displayValue(profile.civil_status);
    if (civilStatusOtherInput) civilStatusOtherInput.value = displayValue(profile.civil_status_other);
    syncCivilStatusOther();
    if (educationInput) educationInput.value = displayValue(profile.highest_educational_attainment);
    if (emailInput) emailInput.value = displayValue(profile.email);
    if (contactInput) contactInput.value = displayValue(profile.contact_number);
    if (occupationInput) occupationInput.value = displayValue(profile.occupation);
    if (occupationOtherInput) occupationOtherInput.value = displayValue(profile.occupation_other);
    updateOccupationOtherField();
    if (incomeInput) {
      const income = displayValue(profile.estimated_monthly_income);
      incomeInput.value = Array.from(incomeInput.options).some((option) => option.value === income) ? income : '';
    }

    const parsedAddress = profile.address_line || profile.municipality || profile.barangay ? { address_line: displayValue(profile.address_line), province: displayValue(profile.province) || defaultProvince, municipality: displayValue(profile.municipality), barangay: displayValue(profile.barangay) } : parseCompleteAddress(displayValue(profile.complete_address));

    if (addressLineInput) {
      addressLineInput.value = parsedAddress.address_line;
    }
    if (provinceInput) {
      provinceInput.value = parsedAddress.province || defaultProvince;
    }
    if (municipalityInput) {
      municipalityInput.value = parsedAddress.municipality || '';
    }

    updateBarangayOptions(parsedAddress.barangay || '');
    refreshAddressPreview();
    refreshAge();
  }

  function refreshAge() {
    if (!ageInput) return;
    const dob = dobInput && dobInput.value ? new Date(`${dobInput.value}T00:00:00`) : null;
    if (!dob || Number.isNaN(dob.getTime())) { ageInput.value = ''; return; }
    const today = new Date();
    let age = today.getFullYear() - dob.getFullYear();
    const beforeBirthday = today.getMonth() < dob.getMonth() || (today.getMonth() === dob.getMonth() && today.getDate() < dob.getDate());
    ageInput.value = String(beforeBirthday ? age - 1 : age);
  }

  function updateOccupationOtherField() {
    const isOther = occupationInput && occupationInput.value === 'Other';
    if (occupationOtherField && occupationOtherAnchor) {
      if (isOther && !occupationOtherField.isConnected) {
        occupationOtherAnchor.after(occupationOtherField);
      }
      if (isOther) occupationOtherField.hidden = false;
      if (!isOther && occupationOtherField.isConnected) {
        occupationOtherField.remove();
      }
    }
    if (occupationOtherInput) {
      occupationOtherInput.required = Boolean(isOther);
      if (!isOther) occupationOtherInput.value = '';
    }
  }

  function updateSummary(profile) {
    const displayName = (profile.display_name || `${profile.first_name || ''} ${profile.last_name || ''}`).trim() || 'Applicant';
    const initials = initialsOf(displayName);

    if (summaryName) summaryName.textContent = displayName;
    if (summaryEmail) summaryEmail.textContent = displayOrDash(profile.email || '');
    if (summaryPhone) summaryPhone.textContent = displayOrDash(profile.contact_number || '');
    if (summaryAddress) summaryAddress.textContent = displayOrDash(profile.complete_address || '');
    if (summaryAvatar) summaryAvatar.textContent = initials;

    document.querySelectorAll('.profile-chip span').forEach((el) => {
      el.textContent = displayName;
    });
    document.querySelectorAll('.profile-av').forEach((el) => {
      el.textContent = initials;
    });
  }

  function updateIdPhotos(available, typeLabel) {
    if (!idPhotoCard) return;
    idPhotoCard.hidden = !available;
    if (!available) return;
    const label = typeLabel || 'Valid ID';
    if (idTypeLabel) idTypeLabel.textContent = label;
    if (idPhotoTypeLabel) idPhotoTypeLabel.textContent = label;
    const photoUrl = idPhotoCard.dataset.photoUrl || '';
    idPhotoCard.querySelectorAll('[data-id-side]').forEach((tile) => {
      const side = tile.dataset.idSide;
      const url = `${photoUrl}?side=${encodeURIComponent(side)}`;
      tile.dataset.photoSrc = url;
      const image = tile.querySelector('img');
      if (image) image.src = url;
      const thumb = idPhotoDialog ? idPhotoDialog.querySelector(`[data-id-thumb="${side}"]`) : null;
      if (thumb) thumb.src = url;
    });
  }

  // Size the photo to the dark stage ourselves so a quarter-turned photo still fits.
  function fitIdPhoto() {
    if (!idPhotoDialog || !idPhotoDialog.open || !idPhotoLargeImage) return;
    const degrees = idPhotoRotation[idPhotoDialog.dataset.side] || 0;
    idPhotoLargeImage.style.transform = `translate(-50%, -50%) rotate(${degrees}deg)`;
    const stage = idPhotoLargeImage.parentElement;
    const width = idPhotoLargeImage.naturalWidth;
    const height = idPhotoLargeImage.naturalHeight;
    if (!stage || !width || !height) return;
    const turned = degrees % 180 !== 0;
    const gap = window.innerWidth <= 600 ? 24 : 48;
    const scale = Math.min((stage.clientWidth - gap) / (turned ? height : width),
      (stage.clientHeight - gap) / (turned ? width : height));
    idPhotoLargeImage.style.maxWidth = 'none';
    idPhotoLargeImage.style.maxHeight = 'none';
    idPhotoLargeImage.style.width = `${Math.max(1, Math.floor(width * scale))}px`;
    idPhotoLargeImage.style.height = `${Math.max(1, Math.floor(height * scale))}px`;
  }

  function showIdDialogSide(side) {
    if (!idPhotoCard || !idPhotoDialog || !idPhotoLargeImage || !['front', 'back'].includes(side)) return;
    const tile = idPhotoCard.querySelector(`[data-id-side="${side}"]`);
    const url = tile ? (tile.dataset.photoSrc || '') : '';
    if (!url) return;
    const name = side === 'front' ? 'Front' : 'Back';
    idPhotoDialog.dataset.side = side;
    idPhotoLargeImage.style.transition = 'none';
    if (idPhotoLargeImage.getAttribute('src') !== url) {
      idPhotoLargeImage.style.visibility = 'hidden';
      idPhotoLargeImage.src = url;
    }
    idPhotoLargeImage.alt = `${name} of sign-up ID`;
    if (idPhotoCaption) idPhotoCaption.textContent = `${name} of ID`;
    idPhotoDialog.querySelectorAll('[data-view-side]').forEach((button) => {
      button.setAttribute('aria-pressed', String(button.dataset.viewSide === side));
    });
    if (idPhotoLargeImage.complete && idPhotoLargeImage.naturalWidth) {
      idPhotoLargeImage.style.visibility = '';
      fitIdPhoto();
    }
    window.requestAnimationFrame(() => { idPhotoLargeImage.style.transition = ''; });
  }

  function rotateIdPhoto(step) {
    if (!idPhotoDialog || !idPhotoDialog.open) return;
    const side = idPhotoDialog.dataset.side === 'back' ? 'back' : 'front';
    idPhotoRotation[side] += step;
    fitIdPhoto();
  }

  function closeIdDialog() {
    if (!idPhotoDialog || !idPhotoDialog.open) return;
    idPhotoDialog.close();
    document.body.classList.remove('profile-id-viewer-open');
    if (idPhotoTrigger) idPhotoTrigger.focus();
  }

  if (idPhotoCard && idPhotoDialog) {
    idPhotoCard.querySelectorAll('[data-id-side]').forEach((tile) => {
      tile.addEventListener('click', (event) => {
        event.preventDefault();
        idPhotoTrigger = tile;
        idPhotoDialog.showModal();
        document.body.classList.add('profile-id-viewer-open');
        showIdDialogSide(tile.dataset.idSide);
        idPhotoDialog.querySelector('[data-close-id-dialog]')?.focus();
      });
    });
    idPhotoLargeImage?.addEventListener('load', () => {
      idPhotoLargeImage.style.visibility = '';
      fitIdPhoto();
    });
    window.addEventListener('resize', fitIdPhoto);
    idPhotoDialog.querySelector('[data-close-id-dialog]')?.addEventListener('click', closeIdDialog);
    idPhotoDialog.querySelectorAll('[data-rotate-id-photo]').forEach((button) => {
      button.addEventListener('click', () => rotateIdPhoto(Number(button.dataset.rotateIdPhoto) || 90));
    });
    idPhotoDialog.querySelectorAll('[data-view-side]').forEach((button) => {
      button.addEventListener('click', () => showIdDialogSide(button.dataset.viewSide));
    });
    idPhotoDialog.querySelectorAll('[data-id-arrow]').forEach((button) => {
      button.addEventListener('click', () => showIdDialogSide(button.dataset.idArrow));
    });
    idPhotoDialog.addEventListener('click', (event) => {
      if (event.target === idPhotoDialog) closeIdDialog();
    });
    idPhotoDialog.addEventListener('cancel', (event) => {
      event.preventDefault();
      closeIdDialog();
    });
    document.addEventListener('keydown', (event) => {
      if (!idPhotoDialog.open) return;
      if (event.key === 'Escape') {
        event.preventDefault();
        closeIdDialog();
      } else if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
        event.preventDefault();
        showIdDialogSide(event.key === 'ArrowLeft' ? 'front' : 'back');
      } else if (event.key === 'r' || event.key === 'R') {
        rotateIdPhoto(event.shiftKey ? -90 : 90);
      }
    });
  }

  function validateData(data) {
    const required = [['First Name', data.first_name], ['Last Name', data.last_name], ['Date of Birth', data.date_of_birth], ['Civil Status', data.civil_status], ['Highest Educational Attainment', data.highest_educational_attainment], ['Phone Number', data.contact_number], ['Occupation / Source of Income', data.occupation], ['Estimated Monthly Income', data.estimated_monthly_income]];
    const missingBasics = required.filter(([, value]) => value === '').map(([label]) => label);
    if (missingBasics.length) {
      return `Complete required fields: ${missingBasics.join(', ')}.`;
    }
    if (data.civil_status === 'other' && data.civil_status_other === '') return 'Specify Civil Status when Others is selected.';
    if (data.occupation === 'Other' && !data.occupation_other) {
      return 'Specify Occupation / Source of Income when Other is selected.';
    }

    if (data.email && !/^\S+@\S+\.\S+$/.test(data.email)) {
      return 'Enter a valid email address or leave it blank.';
    }

    if (!data.contact_number) {
      return 'Phone Number is required.';
    }

    if (!/^(?:09\d{9}|(?:\+?63)9\d{9})$/.test(data.contact_number)) {
      return 'Phone Number must use 09XXXXXXXXX, 639XXXXXXXXX, or +639XXXXXXXXX format.';
    }

    const requiredAddressFields = [
      ['Province', provinceInput ? provinceInput.value.trim() : ''],
      ['Municipality', municipalityInput ? municipalityInput.value.trim() : ''],
      ['Barangay', barangayInput ? barangayInput.value.trim() : ''],
      ['Complete Address', data.complete_address],
    ];
    const missing = requiredAddressFields.filter(([, value]) => !value).map(([label]) => label);
    if (missing.length > 0) {
      return `Complete required fields: ${missing.join(', ')}.`;
    }

    if (data.complete_address && data.complete_address.length > 255) {
      return 'Complete address must be 255 characters or less.';
    }

    return null;
  }

  function syncCivilStatusOther() {
    const isOther = civilStatusInput && civilStatusInput.value === 'other';
    if (civilStatusOtherField) civilStatusOtherField.hidden = !isOther;
    if (civilStatusOtherInput) {
      civilStatusOtherInput.required = Boolean(isOther);
      if (!isOther) civilStatusOtherInput.value = '';
    }
  }

  if (civilStatusInput) civilStatusInput.addEventListener('change', syncCivilStatusOther);

  async function loadProfile() {
    setFeedback('');

    const response = await fetch(getUrl, {
      method: 'GET',
      headers: {
        Accept: 'application/json',
      },
    });

    let payload = null;
    try {
      payload = await response.json();
    } catch (error) {
      payload = null;
    }

    if (!response.ok || !payload || payload.ok !== true) {
      throw new Error(payload && payload.message ? payload.message : 'Unable to load profile data.');
    }

    const profile = payload.profile || {};
    applyProfileToForm(profile);
    applyIdentityLock(payload.identity_locked === true, String(payload.id_type_label || ''));
    updateIdPhotos(payload.id_photos_available === true, String(payload.id_type_label || ''));
    updateSummary(profile);
    if (typeof window.setSubmitRequestAvailability === 'function' && typeof payload.profile_complete === 'boolean') {
      window.setSubmitRequestAvailability(payload.profile_complete);
    }
    hasSavedProfile = payload.profile_saved === true || payload.profile_complete === true;
    profileWasComplete = payload.profile_complete === true;
    snapshot = normalizedSnapshot();
    updateSaveButton();
    setFeedback('');
  }

  async function saveProfile() {
    if (!form.reportValidity()) {
      setFeedback('Complete the required profile fields before saving.', 'error');
      return;
    }

    const data = formDataFromInputs();
    const validationMessage = validateData(data);

    if (validationMessage) {
      setFeedback(validationMessage, 'error');
      return;
    }

    setSaving(true);
    setFeedback('');

    try {
      const response = await fetch(saveUrl, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
        },
        body: JSON.stringify(data),
      });

      let payload = null;
      try {
        payload = await response.json();
      } catch (error) {
        payload = null;
      }

      if (!response.ok || !payload || payload.ok !== true) {
        throw new Error(payload && payload.message ? payload.message : 'Unable to save profile.');
      }

      const wasCompleteBeforeSave = typeof payload.profile_complete_before_save === 'boolean'
        ? payload.profile_complete_before_save
        : profileWasComplete;
      const updatedProfile = payload.profile || data;
      applyProfileToForm(updatedProfile);
      updateSummary(updatedProfile);
      if (typeof window.setSubmitRequestAvailability === 'function' && typeof payload.profile_complete === 'boolean') {
        window.setSubmitRequestAvailability(payload.profile_complete);
      }
      hasSavedProfile = true;
      profileWasComplete = payload.profile_complete === true;
      snapshot = normalizedSnapshot();
      const successMessage = !wasCompleteBeforeSave && profileWasComplete
        ? 'Profile completed successfully.'
        : (profileWasComplete
          ? 'Profile changes saved successfully.'
          : 'Profile saved successfully. Complete the remaining required information.');
      showSavedFeedback(successMessage);
      const returnTo = form.dataset.returnTo || '';
      if (payload.profile_complete === true && returnTo !== '') {
        window.setTimeout(() => window.location.assign(returnTo), 900);
        return;
      }
    } catch (error) {
      const message = error instanceof Error ? error.message : 'Unable to save profile right now.';
      setFeedback(message, 'error');
    } finally {
      setSaving(false);
    }
  }

  if (form) {
    form.addEventListener('submit', (event) => {
      event.preventDefault();
      saveProfile();
    });
  }

  const feedbackCloseButton = feedback?.querySelector('.profile-save-feedback-close');
  if (feedbackCloseButton) feedbackCloseButton.addEventListener('click', dismissFeedback);

  if (municipalityInput) {
    municipalityInput.addEventListener('change', () => {
      updateBarangayOptions();
      refreshAddressPreview();
      updateSaveButton();
    });
  }

  if (barangayInput) {
    barangayInput.addEventListener('change', () => {
      refreshAddressPreview();
      updateSaveButton();
    });
  }

  [addressLineInput, provinceInput].forEach((field) => {
    if (!field) {
      return;
    }

    field.addEventListener('input', () => {
      refreshAddressPreview();
      updateSaveButton();
    });
  });
  if (dobInput) dobInput.addEventListener('change', () => { refreshAge(); updateSaveButton(); });
  if (occupationInput) occupationInput.addEventListener('change', () => { updateOccupationOtherField(); updateSaveButton(); });
  if (civilStatusInput) civilStatusInput.addEventListener('change', updateSaveButton);

  [firstNameInput, middleNameInput, lastNameInput, extensionInput, civilStatusOtherInput,
    educationInput, emailInput, contactInput, occupationOtherInput, incomeInput].forEach((field) => {
    if (!field) return;
    field.addEventListener(field.tagName === 'SELECT' ? 'change' : 'input', updateSaveButton);
  });

  populateMunicipalityOptions();
  updateOccupationOtherField();
  updateBarangayOptions();
  refreshAddressPreview();
  updateSaveButton();

  loadProfile().catch((error) => {
    // The endpoint records diagnostic detail server-side. Do not leave the
    // hero in a misleading perpetual loading state when retrieval fails.
    setFeedback("We couldn't load your profile information. Please refresh and try again.", 'error');
  });
}

initMyProfilePage();
