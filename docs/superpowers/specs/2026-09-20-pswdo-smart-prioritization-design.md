# PSWDO Document-Based Smart Prioritization Design

## Goal

Give PSWDO personnel one consistent queue order that prioritizes verified time-sensitive medical needs before beneficiary category, while preserving Senior Citizen and PWD priority and keeping final decisions under staff control.

## Approved Policy

The system treats beneficiary category and medical urgency as different facts:

- Senior Citizen and PWD are vulnerability categories and receive high base priority.
- Medical is an assistance type, not automatically an urgent case.
- Medical urgency may be set to `URGENT` only when reliable AI/OCR findings extract explicit timing evidence from an uploaded medical certificate or billing/prescription document.
- Examples of qualifying evidence are scheduled surgery or operation, current hospital admission, emergency treatment, an immediate medical intervention, or an explicit time-sensitive treatment date.
- A diagnosis, disease name, requested amount, applicant statement, or the word `medical` alone must not create urgent priority.
- Missing, unreadable, incomplete, inconsistent, low-confidence, or unprocessed medical evidence must remain `REQUIRES_STAFF_REVIEW`; it must not be silently promoted to urgent.
- AI priority is advisory. PSWDO personnel review the original documents and remain responsible for approval, denial, and release decisions.

## Priority Order

Lower tier numbers appear first:

| Tier | Base category | Medical urgency | Meaning |
|---|---|---|---|
| 0 | Senior Citizen or PWD | `URGENT` | Verified urgent medical case with a vulnerable beneficiary |
| 1 | Other | `URGENT` | Verified urgent medical case |
| 2 | Senior Citizen or PWD | `STANDARD` | Vulnerable beneficiary without verified urgent medical evidence |
| 3 | Other | `STANDARD` | Standard application |
| 4 | Senior Citizen or PWD | `REQUIRES_STAFF_REVIEW` | Vulnerable beneficiary whose medical evidence is not reliable enough to classify |
| 5 | Other | `REQUIRES_STAFF_REVIEW` | Other application whose medical evidence is not reliable enough to classify |

Within the same tier, the oldest application is first; request ID is the deterministic final tie-breaker. A medical applicant with verified surgery evidence therefore ranks above a Senior Citizen applying only for standard medicine. If both medical cases are urgent, Senior Citizen/PWD status breaks the tie before application date.

## Architecture

`includes/beneficiary-priority.php` is the policy boundary. It normalizes base priority and urgency values, returns the canonical tier, and exposes the category calculation already used during intake. PSWDO helpers consume the same tier policy for display metadata and generate the SQL `CASE` used by Request Management, global rank calculation, and Screening Queue ordering.

The document-verification pipeline remains the evidence source. `ai_processor/field_extractor.py` already extracts only explicit timing phrases into `urgency_evidence`. `aics_medical_urgency_from_document_reviews()` converts reliable completed findings to `URGENT`, reliable findings with no explicit timing to `STANDARD`, and absent or uncertain evidence to `REQUIRES_STAFF_REVIEW`.

No new model, external API, or database table is required. Existing `priority_level`, `urgency_indicator`, `priority_rank`, and `priority_basis` fields remain the persisted audit surface.

## PSWDO Experience

- Request Management defaults to Smart Priority order instead of newest-first order.
- Screening Queue and stored `priority_rank` use exactly the same precedence and oldest-first tie-breaker.
- The existing High Priority sort/filter is renamed Smart Priority in the interface.
- “Priority Cases Only” includes either verified urgent medical cases or Senior Citizen/PWD cases; it does not exclude an urgent applicant merely because that applicant is not Senior/PWD.
- Row badges distinguish `Urgent Medical + Senior/PWD`, `Urgent Medical`, `Senior/PWD Priority`, `Standard`, and `Review Needed` states.
- Full Application Details continues showing the human-readable category, assistance type, urgency result, and evidence basis.

## Data Flow

1. Applicant selects an assistance type and uploads its required documents.
2. The AI/OCR worker extracts structured fields, including explicit medical timing evidence for medical documents.
3. PSWDO refresh logic reads only completed, sufficiently confident document results.
4. Category calculation produces base priority `HIGH` for Senior Citizen/PWD and `NORMAL` for other categories.
5. Medical evidence calculation produces `URGENT`, `STANDARD`, or `REQUIRES_STAFF_REVIEW`.
6. The canonical precedence table produces the queue tier.
7. Request Management, Screening Queue, and persisted priority ranks order cases by tier, oldest application, then request ID.
8. Staff inspect the evidence and make the actual screening/release decision.

## Error Handling and Safeguards

- Unknown priority or urgency values normalize to the safest review-needed tier.
- SQL aliases supplied to the ordering helper must match a strict identifier pattern.
- An AI failure or low-confidence result cannot create `URGENT`.
- Non-medical assistance always receives `STANDARD` medical urgency because the medical rule does not apply.
- Existing age/category validation remains: Senior Citizen requires age 60 or above, while PWD has no age restriction and may overlap Senior Citizen.
- Priority affects work order only. It never auto-approves, auto-rejects, or skips required-document checks.

## Alternatives Considered

### Explicit precedence table — selected

This is deterministic, easy for PSWDO to explain, and directly represents the approved policy. It also avoids arbitrary numeric weights.

### Weighted score

A score could add points for urgency, Senior/PWD, readiness, and risk. It was rejected because small weight changes can create unexpected ordering and make decisions harder to defend.

### Separate manual lanes

Separate Medical Urgent and Senior/PWD lanes would be visually clear but would not answer which applicant should be handled first across lanes. It was rejected for the default queue, though filters remain available for staff exploration.

## Testing and Acceptance Criteria

- A normal-category case with verified scheduled surgery ranks above a Senior/PWD case with standard medical need.
- An urgent Senior/PWD case ranks above an urgent non-Senior/PWD case.
- A standard Senior/PWD case ranks above a standard other-category case.
- Review-needed evidence never receives automatic urgent priority.
- Older applications rank first inside an identical tier.
- Request Management, `priority_rank`, and Screening Queue share the same order.
- Priority-only filtering returns both verified urgent cases and Senior/PWD cases.
- Existing medical extraction tests continue proving that explicit timing creates evidence and diagnosis alone does not.

