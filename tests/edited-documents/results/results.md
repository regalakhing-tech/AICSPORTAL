# AICS AI scanner: edited-document test results

Run: 2026-10-01 10:54. Every file was scanned as an upload to the Medical Certificate slot for the fictional beneficiary Juan Santos Dela Cruz.

| Result | File | Edit | Status | Headline | Complete | Consistent | Readable | Unaltered | Untampered | Signals |
|---|---|---|---|---|---|---|---|---|---|---|
| PASS | `clean-01-phone-photo.jpg` | Unedited phone photo of the certificate. | needs_staff_review | Staff review needed | ✓ | ✓ | ✓ | ✓ | ⚠ | sample_or_watermark |
| PASS | `clean-02-flat-scan.png` | Unedited clean scan (black text on white, no camera effects). | likely_valid | Document looks good | ✓ | ✓ | ✓ | ✓ | ✓ | — |
| PASS | `clean-03-cool-light-photo.jpg` | Unedited photo under cool light with stronger noise and blur. | likely_valid | Document looks good | ✓ | ✓ | ✓ | ✓ | ✓ | typography_mismatch |
| PASS | `edit-01-canva-typed-date.png` | Signature date covered and retyped in Canva (flat black, different font); 2021 events vs 2026 date. | flagged_inconsistency | Possible edited date | ✓ | ✗ | ✓ | ✓ | ✗ | date_context_conflict, digital_text_overlay, name_mismatch_or_internal_conflict |
| PASS | `edit-02-pasted-beneficiary-name.jpg` | Someone else's certificate; the beneficiary's name was typed over the patient name at the top (body still names ROBERTO C. VILLANUEVA). | flagged_inconsistency | Name does not match the application | ✓ | ✗ | ✓ | ⚠ | ✗ | digital_text_overlay, name_mismatch_or_internal_conflict, typography_mismatch |
| PASS | `edit-03-cloned-signature.jpg` | The doctor's signature pixels were copied onto the patient signature area (copy-move). | needs_staff_review | Staff review needed | ✓ | ✓ | ✓ | ✓ | ⚠ | copy_move_region, sample_or_watermark |
| PASS | `edit-04-whiteout-retyped-dates.jpg` | Confinement dates covered with flat white (correction tape) and retyped as 11 - 25 instead of 11 - 15. | flagged_inconsistency | Details do not agree | ✓ | ✗ | ✓ | ✓ | ⚠ | name_mismatch_or_internal_conflict, pasted_region_boundary |
| PASS | `edit-05-spliced-diagnosis.jpg` | Diagnosis line replaced by a cleaner, brighter, heavily compressed patch from another image (splice). | needs_staff_review | Staff review needed | ✓ | ✓ | ✓ | ✓ | ⚠ | pasted_region_boundary |
| PASS | `edit-06-sample-watermark.jpg` | A sample form downloaded from a website (SAMPLE ONLY watermark and website address). | needs_staff_review | Staff review needed | ✓ | ✓ | ✓ | ✓ | ⚠ | sample_or_watermark, typography_mismatch |
| PASS | `edit-07-wrong-document-type.jpg` | A death-certificate-titled form uploaded in the Medical Certificate slot. | flagged_inconsistency | Wrong document uploaded | ✓ | ✗ | ✓ | ✓ | ✓ | wrong_document |
| PASS | `edit-08-navy-typed-date-hard.jpg` | HARD CASE: date retyped in dark navy (not flat black), saved as JPEG without editor metadata. | flagged_inconsistency | Details do not agree | ✓ | ✗ | ✓ | ✓ | ✓ | date_context_conflict, name_mismatch_or_internal_conflict, typography_mismatch |
| PASS | `id-clean-01-national-id.jpg` | Unedited national ID of the beneficiary. | likely_valid | Document looks good | ✓ | ✓ | ✓ | ✓ | ✓ | — |
| PASS | `id-clean-02-drivers-license.jpg` | Unedited driver's license of the beneficiary. | likely_valid | Document looks good | ✓ | ✓ | ✓ | ✓ | ✓ | — |
| PASS | `id-clean-03-umid.jpg` | Unedited UMID of the beneficiary. | likely_valid | Document looks good | ✓ | ✓ | ✓ | ✓ | ✓ | — |
| PASS | `id-edit-01-national-name-typed.jpg` | Another person's national ID with the beneficiary's name typed over the name fields (birth date still 1990). | flagged_inconsistency | Name does not match the application | ✓ | ✗ | ✓ | ✓ | ⚠ | digital_text_overlay, name_mismatch_or_internal_conflict |
| PASS | `id-edit-02-drivers-birthdate-typed.jpg` | Driver's license birth date retyped from 1990/07/21 to match the application (1975/03/12). | flagged_inconsistency | Possible document changes found | ✓ | ✓ | ✓ | ✓ | ✗ | digital_text_overlay, ink_or_color_mismatch |
| PASS | `id-edit-03-postal-expiry-typed.png` | Expired postal ID (2017) with the validity date retyped as 2027 in a phone editor (Picsart). | flagged_inconsistency | Possible document changes found | ✓ | ✓ | ✓ | ✓ | ✗ | digital_text_overlay |
| PASS | `id-edit-04-umid-photo-swapped.jpg` | UMID portrait replaced with a sharper, brighter, heavily compressed picture pasted from another image. | needs_staff_review | Staff review needed | ✓ | ✓ | ✓ | ✓ | ⚠ | sample_or_watermark |
| PASS | `id-edit-05-someone-elses-id.jpg` | Unedited national ID of a different person (PEDRO MANALO REYES) uploaded for the beneficiary. | flagged_inconsistency | Name does not match the application | ✓ | ✗ | ✓ | ✓ | ⚠ | name_mismatch_or_internal_conflict, typography_mismatch |

## Notes

- `clean-01-phone-photo.jpg`: AI noted the generated test file looks computer-made (allowed for generated fixtures only; no tampering flag). The entire image has a uniform digital texture and font appearance resembling a generated template or digital specimen rather than a natural paper scan.
- `clean-02-flat-scan.png`: no alteration/tampering flags. This document is a Civil Service Medical Certificate issued by Antique Provincial Hospital for patient Juan S. Dela Cruz, signed by Dr. Maria L. Santos. Staff should verify the details against the beneficiary records.
- `clean-03-cool-light-photo.jpg`: no alteration/tampering flags. This is a Civil Service Medical Certificate issued by Antique Provincial Hospital for patient Juan S. Dela Cruz, dated April 16, 2021. Staff should verify that all details match the beneficiary's records.
- `edit-01-canva-typed-date.png`: caught by: date_context_conflict, digital_text_overlay. The date "September 24, 2026" looks typed on digitally and conflicts with the 2021 dates in the document. The file was saved with Canva, an editing app.
- `edit-02-pasted-beneficiary-name.jpg`: caught by: digital_text_overlay, name_mismatch_or_internal_conflict. The patient name on the document is ROBERTO C. VILLANUEVA, but the application is for Juan Santos Dela Cruz.
- `edit-03-cloned-signature.jpg`: caught by: copy_move_region. The document appears to be a digital template or reproduction with uniform computer-generated text and signature styling.
- `edit-04-whiteout-retyped-dates.jpg`: caught by: pasted_region_boundary. The confinement dates (April 11 - 25, 2021) conflict with the illness leave dates (April 11 - 15, 2021) and occur after the document issue date (April 16, 2021).
- `edit-05-spliced-diagnosis.jpg`: caught by: pasted_region_boundary. The nature of disease entry appears digitally pasted with a solid white background box distinct from the rest of the paper texture.
- `edit-06-sample-watermark.jpg`: caught by: sample_or_watermark. The document contains a prominent red watermark reading 'SAMPLE ON' and a website footer indicating it is a specimen template from an archive.
- `edit-07-wrong-document-type.jpg`: caught by: wrong_document. This looks like a Death Certificate ("CERTIFICATE OF DEATH"), not the required Medical Abstract or Medical Certificate.
- `edit-08-navy-typed-date-hard.jpg`: caught by: date_context_conflict, typography_mismatch. The issue date of September 24, 2026 occurs more than five years after the stated confinement period of April 11-15, 2021.
- `id-clean-01-national-id.jpg`: no alteration/tampering flags. The uploaded document is a valid Philippine Identification Card belonging to Juan Santos Dela Cruz. All details are clear and consistent.
- `id-clean-02-drivers-license.jpg`: no alteration/tampering flags. This document is a driver's license issued for Juan Santos Dela Cruz, showing a validity date of March 12, 2028. Staff should verify that the card details match the applicant's records.
- `id-clean-03-umid.jpg`: no alteration/tampering flags. This is a Unified Multi-Purpose ID for Juan Santos Dela Cruz. All details are readable and consistent.
- `id-edit-01-national-name-typed.jpg`: caught by: digital_text_overlay, name_mismatch_or_internal_conflict. The birth date on the document differs from the application.
- `id-edit-02-drivers-birthdate-typed.jpg`: caught by: digital_text_overlay. Some text is flat digital black and darker than the photographed text around it, so it may have been typed on with an editing app.
- `id-edit-03-postal-expiry-typed.png`: caught by: digital_text_overlay. Some text is flat digital black and darker than the photographed text around it, so it may have been typed on with an editing app. The file was saved with Picsart, an editing app.
- `id-edit-04-umid-photo-swapped.jpg`: flagged for review (different signal than expected: sample_or_watermark). The image appears to be a digital graphic or template rather than a photo of a physical card, showing uniform digital fonts and graphic elements.
- `id-edit-05-someone-elses-id.jpg`: caught by: name_mismatch_or_internal_conflict. The cardholder name on the document is Pedro Manalo Reyes, but the application is for Juan Santos Dela Cruz.
