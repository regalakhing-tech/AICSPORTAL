// Birth date typed as MM / DD / YYYY in three boxes, kept in step with a calendar
// picker (type="date") that holds the date as YYYY-MM-DD. Used by the request form and sign-up.
function initBirthdatePartsInput(ids) {
  const month = document.getElementById(ids.month);
  const day = document.getElementById(ids.day);
  const year = document.getElementById(ids.year);
  const iso = document.getElementById(ids.iso);
  if (!(month instanceof HTMLInputElement) || !(day instanceof HTMLInputElement)
      || !(year instanceof HTMLInputElement) || !(iso instanceof HTMLInputElement)) {
    return { syncFromIso() {} };
  }

  let changingFromParts = false;
  const parts = [month, day, year];
  const clearValidity = () => parts.forEach((field) => field.setCustomValidity(''));

  function syncFromIso() {
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso.value);
    month.value = match ? match[2] : '';
    day.value = match ? match[3] : '';
    year.value = match ? match[1] : '';
    clearValidity();
  }

  function syncFromParts(event) {
    if (event?.target instanceof HTMLInputElement) {
      const field = event.target;
      field.value = field.value.replace(/\D/g, '').slice(0, field.maxLength);
    }
    clearValidity();
    const m = month.value;
    const d = day.value;
    const y = year.value;
    let value = '';
    if (m && d && y) {
      if (m.length !== 2) month.setCustomValidity('Enter a two-digit month.');
      else if (d.length !== 2) day.setCustomValidity('Enter a two-digit day.');
      else if (y.length !== 4) year.setCustomValidity('Enter a four-digit year.');
      else {
        const monthNumber = Number(m);
        const dayNumber = Number(d);
        const yearNumber = Number(y);
        const parsed = new Date(Date.UTC(yearNumber, monthNumber - 1, dayNumber));
        if (yearNumber < 1000 || parsed.getUTCFullYear() !== yearNumber
            || parsed.getUTCMonth() + 1 !== monthNumber || parsed.getUTCDate() !== dayNumber) {
          day.setCustomValidity('Enter a valid date of birth.');
        } else {
          const today = new Date();
          const latest = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}-${String(today.getDate()).padStart(2, '0')}`;
          const candidate = `${y}-${m}-${d}`;
          if (candidate > latest) day.setCustomValidity('Date of birth cannot be in the future.');
          else value = candidate;
        }
      }
    }
    if (iso.value !== value) {
      iso.value = value;
      changingFromParts = true;
      try {
        iso.dispatchEvent(new Event('change', { bubbles: true }));
      } finally {
        changingFromParts = false;
      }
    }
  }

  parts.forEach((field) => field.addEventListener('input', syncFromParts));
  iso.addEventListener('change', () => { if (!changingFromParts) syncFromIso(); });
  syncFromIso();
  return { syncFromIso };
}

function initBeneficiaryBirthdateInput() {
  return initBirthdatePartsInput({
    month: 'beneficiary-birth-month',
    day: 'beneficiary-birth-day',
    year: 'beneficiary-birth-year',
    iso: 'beneficiary-birth-date',
  });
}
