# Document Forensics Expansion Implementation Plan

> **For agentic workers:** Implement in the current session. Validate each unit before integrating it.

**Goal:** Add the requested file, image, QR, signature, and evidence display checks to AI document review.

**Architecture:** Optional detectors return bounded advisory findings. The worker combines their results with existing OCR, Gemini, chronology, regional checks, and ADCD. Staff see the reason and the affected region; provider failures remain visible as unavailable.

**Tech Stack:** Python 3, OpenCV, Pillow, PHP, JavaScript, CSS.

**Spec:** `docs/superpowers/specs/2026-09-30-document-forensics-expansion-design.md`

## Global constraints

- No official authenticity or fraud conclusion.
- No automatic rejection or re-upload from image evidence.
- No raw personal QR payload in logs or public findings.
- Existing extraction and staff workflow must continue if optional detectors fail.

## Tasks

- [x] Add focused synthetic tests for metadata, QR, regional, copy-move, pixel, and repeated-region checks.
- [x] Implement and verify file metadata and PDF signature assessment, with an explicit unavailable state when OpenSSL cannot verify.
- [x] Implement and verify image checks for QR, copy-move, pixel boundaries, erasure, repetition, and expanded regional comparisons.
- [x] Extend bounded evidence normalization and aggregation; prevent uncalibrated ADCD or a single weak visual signal from changing status.
- [x] Add runtime provider capability checks for Gemini image mode and the loaded local ADCD service.
- [x] Add authorized page evidence overlays and a plain-language findings list.
- [x] Expand synthetic fixtures and run Python and PHP contract checks. Browser execution was unavailable because Node is not installed in this workspace.
