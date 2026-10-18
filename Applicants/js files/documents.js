function initDocumentsUploadUI() {
  const form = document.getElementById('documents-upload-form');
  if (!form) {
    return;
  }

  const fileInputs = Array.from(form.querySelectorAll('input[type="file"]'));

  fileInputs.forEach((input) => {
    input.addEventListener('change', () => {
      const drop = input.closest('.upload-drop');
      if (!drop) {
        return;
      }

      const title = drop.querySelector('.upload-title');
      const fileName = input.files && input.files[0] ? input.files[0].name : '';
      if (title) {
        title.textContent = fileName || 'Click to upload or drag and drop';
      }
    });
  });
}

initDocumentsUploadUI();