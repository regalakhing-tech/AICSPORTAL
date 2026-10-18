# Stage 10 reference assets

Do not fabricate or substitute these assets.

Expected destination root: `C:\Users\brian\Documents\AICS-Development\ADCD-Net\reference_assets`

| Asset | Expected destination | Official source |
| --- | --- | --- |
| Quantization table | `qt_table.pk` | DocTamper repository data preparation materials: https://github.com/qcf-568/DocTamper |
| JPEG/QF records | `pks\*.pk` | DocTamper repository data preparation materials: https://github.com/qcf-568/DocTamper |
| Pre-generated OCR masks | `DocTamperOCR\{TrainingSet,TestingSet,FCD,SCD}\` | ADCD-Net release archive linked in its README: https://drive.google.com/file/d/1-5BU3Bavs6SGJpaByua_FhDuUJGoo-iS/view?usp=sharing |
| Authorized images and GT masks | Separate, authorized dataset root | DocTamper access instructions: https://github.com/qcf-568/DocTamper |

The reference evaluator additionally requires an explicit JSON sample list. Each entry names an image, ground-truth mask, official OCR mask, and the official JPEG/QF record. This avoids guessing dataset layouts or silently using unauthorized data.
