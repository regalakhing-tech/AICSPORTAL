"""Aggregate independent alteration evidence into a staff-review recommendation."""
from __future__ import annotations

from copy import deepcopy


_VISUAL_TYPES = frozenset({
    'typography_mismatch', 'baseline_or_spacing_mismatch', 'ink_or_color_mismatch',
    'pasted_region_boundary', 'background_texture_discontinuity',
    'compression_or_resampling_difference', 'signature_or_stamp_anomaly',
    'other_visual_anomaly', 'copy_move_region', 'repeated_visual_region',
    'erasure_or_cover_region', 'digital_text_overlay',
})
def boxes_overlap(left: list[int], right: list[int], minimum_iou: float = .10) -> bool:
    if not all(isinstance(box, list) and len(box) == 4 for box in (left, right)):
        return False
    y1, x1 = max(left[0], right[0]), max(left[1], right[1])
    y2, x2 = min(left[2], right[2]), min(left[3], right[3])
    intersection = max(0, y2 - y1) * max(0, x2 - x1)
    left_area = max(0, left[2] - left[0]) * max(0, left[3] - left[1])
    right_area = max(0, right[2] - right[0]) * max(0, right[3] - right[1])
    union = left_area + right_area - intersection
    return union > 0 and intersection / union >= minimum_iou


def _external_indicators(result: dict) -> list[dict]:
    if not isinstance(result, dict) or result.get('status') != 'completed' or result.get('payload_mode') != 'image':
        return []
    assessment = result.get('assessment')
    tamper = assessment.get('tamper_assessment') if isinstance(assessment, dict) else None
    if not isinstance(tamper, dict) or tamper.get('state') != 'review_signal':
        return []
    return [item for item in tamper.get('indicators', [])
            if isinstance(item, dict) and item.get('confidence_band') in {'moderate', 'high'}]


def _related(left: dict, right: dict) -> bool:
    if int(left.get('page') or 0) != int(right.get('page') or 0):
        return False
    return boxes_overlap(left.get('box_2d'), right.get('box_2d'))


def aggregate_tamper_evidence(external_result: dict, chronology: dict,
                              regional: dict, adcd_result: dict,
                              local_results: list[dict] | None = None) -> dict:
    gemini = _external_indicators(external_result)
    chronology_indicators = list(chronology.get('indicators', [])) if isinstance(chronology, dict) and chronology.get('decision') == 'review' else []
    regional_indicators = list(regional.get('indicators', [])) if isinstance(regional, dict) and regional.get('decision') == 'review' else []
    local_results = [item for item in (local_results or []) if isinstance(item, dict)]
    local_visual = [item for result in local_results if result.get('family') == 'local_visual'
                    for item in result.get('indicators', []) if isinstance(item, dict)
                    and item.get('confidence_band') in {'moderate', 'high'}]
    signature_indicators = [item for result in local_results if result.get('family') == 'metadata'
                            for item in result.get('indicators', []) if isinstance(item, dict)
                            and item.get('type') in {'invalid_pdf_signature', 'pdf_changed_after_signing'}]
    adcd = adcd_result.get('providers', {}).get('adcd_net', {}) if isinstance(adcd_result, dict) else {}
    adcd_review = adcd.get('status') == 'completed' and adcd.get('decision') == 'review'

    chronology_visual = any(_related(item, visual) for item in chronology_indicators for visual in (*gemini, *regional_indicators, *local_visual))
    paired_visual = (any(_related(item, local) for item in gemini if item.get('type') in _VISUAL_TYPES for local in (*regional_indicators, *local_visual))
                     or any(_related(item, local) for item in local_visual for local in regional_indicators))
    # ADCD has no validated localization/threshold calibration yet. Its page
    # metrics remain visible but cannot independently change a staff decision.
    adcd_corroborated = False
    localized_high = any(item.get('confidence_band') == 'high' and item.get('type') in {
        'pasted_region_boundary', 'background_texture_discontinuity',
    } for item in gemini) and paired_visual
    strong_chronology = (
        isinstance(chronology, dict)
        and chronology.get('decision') == 'review'
        and chronology.get('confidence_band') == 'high'
        and chronology.get('repeated_conflict') is True
        and len(chronology_indicators) >= 2
    )
    review = strong_chronology or bool(signature_indicators) or chronology_visual or paired_visual or adcd_corroborated or localized_high
    review_basis = ('cryptographic_signature_problem' if signature_indicators
                    else 'deterministic_chronology_conflict' if strong_chronology
                    else ('corroborated_visual_evidence' if review else 'none'))

    families = []
    for name, present in (('gemini_visual', bool(gemini)), ('semantic_chronology', bool(chronology_indicators)),
                          ('local_regional', bool(regional_indicators)), ('local_visual', bool(local_visual)), ('adcd_net', adcd_review)):
        if present:
            families.append(name)
    indicators = []
    for source, items in (('gemini', gemini), ('chronology', chronology_indicators), ('local_regional', regional_indicators), ('local_visual', local_visual), ('metadata', signature_indicators)):
        for item in items:
            saved = {key: item[key] for key in ('type', 'page', 'source_page', 'box_2d', 'source_box_2d', 'confidence_band', 'reason') if key in item}
            saved['source'] = source
            indicators.append(saved)
    result = {
        'status': 'completed' if any((external_result.get('status') == 'completed' if isinstance(external_result, dict) else False,
                                     chronology.get('status') == 'completed' if isinstance(chronology, dict) else False,
                                     regional.get('status') == 'completed' if isinstance(regional, dict) else False,
                                     adcd.get('status') == 'completed',
                                     any(item.get('status') == 'completed' for item in local_results))) else 'unavailable',
        'integration_version': 2,
        'decision': 'review' if review else 'clear',
        'review_basis': review_basis,
        'label': ('Document date inconsistency — staff review required' if strong_chronology
                  else ('Suspected alteration — staff review required' if review
                        else 'No corroborated alteration signal')),
        'confidence_band': ('high' if strong_chronology or (review and len(families) >= 3)
                            else ('moderate' if review else 'low')),
        'evidence_families': families,
        'indicators': indicators[:20],
        'providers': {
            'gemini': {'status': str(external_result.get('status') or 'unavailable') if isinstance(external_result, dict) else 'unavailable'},
            'semantic_chronology': {'status': str(chronology.get('status') or 'unavailable') if isinstance(chronology, dict) else 'unavailable'},
            'local_regional': {'status': str(regional.get('status') or 'unavailable') if isinstance(regional, dict) else 'unavailable'},
            'adcd_net': {'status': str(adcd.get('status') or 'unavailable'), 'decision': str(adcd.get('decision') or '')},
            **{str(item.get('family')): {'status': str(item.get('status') or 'unavailable')}
               for item in local_results if item.get('family') in {'metadata', 'qr', 'local_visual'}},
        },
        'auxiliary': {
            'file': next((item.get('facts', {}) for item in local_results if item.get('family') == 'metadata'), {}),
            'qr': next((item.get('facts', []) for item in local_results if item.get('family') == 'qr'), []),
        },
    }
    if strong_chronology and isinstance(chronology.get('date_conflict'), dict):
        result['date_conflict'] = deepcopy(chronology['date_conflict'])
    if signature_indicators:
        result['label'] = 'PDF signature requires staff review'
    if adcd.get('status') == 'completed':
        result['aggregate'] = {key: adcd[key] for key in ('tampered_pixel_ratio', 'max_tamper_probability', 'mean_tamper_probability', 'processing_time_seconds') if key in adcd}
        result['pages'] = list(adcd.get('pages', []))
        result['threshold_id'] = str(adcd.get('threshold_id') or 'adcd-review-v1')
    return result


def apply_tamper_recommendation(result: dict, combined: dict) -> dict:
    output = deepcopy(result or {})
    if not isinstance(combined, dict) or combined.get('decision') != 'review':
        return output
    strong_chronology = combined.get('review_basis') == 'deterministic_chronology_conflict'
    if output.get('status') != 'needs_reupload':
        if strong_chronology:
            output['status'] = 'flagged_inconsistency'
        elif output.get('status') != 'flagged_inconsistency':
            output['status'] = 'needs_staff_review'
    findings = deepcopy(output.get('findings') or {})
    reasons = list(findings.get('reasons') or [])
    reason = ('The signature or issue date conflicts with repeated document event dates and requires staff inspection.'
              if strong_chronology else
              'Independent checks found possible document changes that require staff inspection.')
    if combined.get('review_basis') == 'cryptographic_signature_problem':
        reason = 'The PDF signature did not verify or a later file revision needs staff inspection.'
    if reason not in reasons:
        reasons.append(reason)
    findings['reasons'] = reasons
    output['findings'] = findings
    return output
