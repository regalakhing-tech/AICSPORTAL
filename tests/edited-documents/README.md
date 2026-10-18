# Edited-document tests for the AICS AI scanner

Realistic, fictional test documents that are run through the **full** document scanner: OCR, local image forensics, and AICS AI image reading. All people, names, numbers, and cards are made up. The ID cards are plain illustrations with no real seals or security features.

## Run

```powershell
cd C:\xampp\htdocs\AICS\AICS\tests\edited-documents
python generate.py     # (re)creates documents\ and manifest.json
python run_tests.py    # scans every file, writes results\results.md and results\results.json
```

`run_tests.py` uses the real pipeline as if each file were uploaded to its slot (Medical Certificate or Valid ID) for the fictional beneficiary **Juan Santos Dela Cruz** (born 1975-03-12). Database writes are intercepted, so no application data changes. If the AICS AI key is not configured, the local checks still run.

The fast local-only part, without AI calls, also runs in the normal Python suite: `ai_processor/tests/test_edited_documents_regression.py`.

## What is tested

Each document starts as a computer-rendered form that is then "photographed": warm or cool light, uneven lighting, slight tilt, blur, and camera noise. Edits are applied **after** the photo, the way people edit a phone picture.

| File | What was done | Main signal expected |
|---|---|---|
| `clean-01-phone-photo.jpg` | Unedited phone photo | none |
| `clean-02-flat-scan.png` | Unedited clean scan | none |
| `clean-03-cool-light-photo.jpg` | Unedited, cool light, more noise | none |
| `edit-01-canva-typed-date.png` | Signature date covered and retyped in Canva (flat black, different font). Old 2021 events, new 2026 date. The PNG says "Canva" in its metadata | typed-on text + date conflict |
| `edit-02-pasted-beneficiary-name.jpg` | Someone else's certificate with the beneficiary's name typed over the top. The body still names the original patient | typed-on text + name conflict |
| `edit-03-cloned-signature.jpg` | Doctor's signature pixels copied onto the patient signature area | copied area (camera-noise fingerprint) |
| `edit-04-whiteout-retyped-dates.jpg` | Dates covered with flat white and retyped (11–25 instead of 11–15) | pasted / covered area |
| `edit-05-spliced-diagnosis.jpg` | Diagnosis line replaced by a cleaner, compressed patch from another image | pasted area |
| `edit-06-sample-watermark.jpg` | "SAMPLE ONLY" watermark and website address | sample image |
| `edit-07-wrong-document-type.jpg` | Death-certificate-titled form in the Medical Certificate slot | wrong document |
| `edit-08-navy-typed-date-hard.jpg` | **Hard case:** date retyped in dark navy (not flat black), no editor metadata | date conflict + font differs |
| `id-clean-01..03` | Unedited national ID, driver's license, UMID of the beneficiary | none |
| `id-edit-01-national-name-typed.jpg` | Another person's ID with the beneficiary's name typed over (birth date still 1990) | typed-on text + details conflict |
| `id-edit-02-drivers-birthdate-typed.jpg` | Birth date retyped to match the application | typed-on text |
| `id-edit-03-postal-expiry-typed.png` | Expired card (2017) retyped as 2027 in Picsart | typed-on text |
| `id-edit-04-umid-photo-swapped.jpg` | Portrait replaced with a pasted picture | see limitations |
| `id-edit-05-someone-elses-id.jpg` | Unedited ID of a different person | name mismatch |

A test **passes** when:
- an edited file is flagged by at least one check;
- a clean file gets no alteration or tampering flag.

Clean fixtures may receive one allowed note: the AI sometimes, correctly, says a generated test file "looks computer-made". Any other flag counts as a false alarm.

## Known limitations (reported honestly)

- **Swapped ID photo:** no local detector catches a swapped portrait yet. The AI flags the card for review, but because it looks like a mockup, not because of the swap. Use the original card in person for photo checks.
- **Dark navy typed text** is not caught by the typed-on-text detector, which targets flat black. It was caught here only because the date also conflicts with the other dates and the font looks different.
- The test documents are synthetic. The local detectors were also checked against all 211 real uploads in the system: **0 false typed-on-text alarms and 0 high-confidence copy alarms** after tuning.

## Sign-up ID check (front + back)

`documents/signup/` holds front/back pairs for the sign-up ID step (`signup-manifest.json`). Check one pair with:

```powershell
python -m ai_processor.id_check --front documents\signup\signup-ok-front.jpg --back documents\signup\signup-ok-back.jpg
```

Live results (AICS AI): typed-over name → `edited`; same photo twice → `retake`; back of a different card → `retake`. The clean synthetic card also returns `edited`, because AICS AI correctly recognises a computer-generated card. Seeing `ok` needs a photo of a **real** ID. Name and birth-date matching is covered exactly by `tests/applicant-signup-id-test.php`.
