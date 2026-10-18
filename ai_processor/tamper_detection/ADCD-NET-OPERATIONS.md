# ADCD-Net development operations

Run `start-adcd-service.bat` from this folder. It refuses to create another
listener when `127.0.0.1:5001` is already occupied, starts only the local
service, then performs `POST /load` once. The model remains resident; it is
not loaded per applicant document.

Before use, verify `GET http://127.0.0.1:5001/health` returns `status: ready`,
`model_loaded: true`, and `device: cpu`. To stop the development service,
terminate only the process running `service.py`; never expose it on `0.0.0.0`.

This is CPU-only development validation. Observed model load is about 3 seconds
and per-page inference about 18–24 seconds (Stage 5 synthetic inference:
20.86 seconds; full Python processing: 21.83 seconds).

The Stage 8 controlled set contained 20 clean originals, 20 benign variations,
and 40 tampered samples. Its distributions overlapped substantially, so no
validated automatic tampering decision threshold is implemented. ADCD-Net is an
experimental AI-assisted staff-review component; final assessment remains with
authorized PSWDO personnel.

The application records the result as the independent **Alteration signal**
assessment: `No strong alteration signal`, `Review highlighted areas`, or
`Alteration check unavailable`. Service timeouts and malformed responses are
neutral and do not discard OCR/classification findings. A review signal never
approves, rejects, or marks an applicant document fraudulent.

The service must remain bound to `127.0.0.1`; applicant form fields are never
sent to it. Only page image bytes and privacy-bounded OCR boxes are accepted.
Calibration identifiers and aggregate/page diagnostics are retained for audit,
while visual artifacts stay in the private artifact directory outside the web
root.
