import unittest
from pathlib import Path
from unittest.mock import MagicMock, call, patch

import numpy as np

from ai_processor import database
from ai_processor.processor import process_one


class ProcessingStageDatabaseTests(unittest.TestCase):
    def test_stage_writer_is_available(self):
        self.assertTrue(hasattr(database, 'update_stage'), 'Worker stage writer is missing.')

    def test_unknown_stage_is_rejected_before_database_write(self):
        connection = MagicMock()

        with self.assertRaisesRegex(ValueError, 'Invalid AI processing stage'):
            database.update_stage(connection, 7, 'invented', 50)

        connection.cursor.assert_not_called()

    def test_valid_stage_updates_progress_and_heartbeat(self):
        connection = MagicMock()
        cursor = connection.cursor.return_value.__enter__.return_value

        database.update_stage(connection, 7, 'ocr', 35)

        sql, params = cursor.execute.call_args.args
        self.assertIn('worker_heartbeat_at=NOW()', sql)
        self.assertIn("status='processing'", sql)
        self.assertEqual(params, ('ocr', 35, 7))
        connection.commit.assert_called_once_with()


class ProcessingStageOrchestrationTests(unittest.TestCase):
    @patch('ai_processor.processor.finish')
    @patch('ai_processor.processor.update_stage', create=True)
    @patch('ai_processor.processor.review_document', return_value={'status': 'not_needed', 'reason': 'local_evidence_sufficient', 'payload_mode': 'text', 'elapsed_ms': 0})
    @patch('ai_processor.processor.attach_external_assessment', side_effect=lambda findings, _provider, _reason: findings)
    @patch('ai_processor.processor.apply_tamper_recommendation', side_effect=lambda result, _combined: result)
    @patch('ai_processor.processor.attach_combined_finding', side_effect=lambda findings, _combined: findings)
    @patch('ai_processor.processor.aggregate_tamper_evidence', return_value={'status': 'completed', 'decision': 'clear'})
    @patch('ai_processor.processor.analyze_regional_inconsistency', return_value={'status': 'completed', 'family': 'local_regional', 'decision': 'clear', 'indicators': []})
    @patch('ai_processor.processor.analyze_chronology', return_value={'status': 'completed', 'family': 'semantic_chronology', 'decision': 'clear', 'indicators': []})
    @patch('ai_processor.processor.persist_private_artifacts', side_effect=lambda result, _review_id, _settings: result)
    @patch('ai_processor.processor.analyze_tampering', return_value={'providers': {'adcd_net': {'status': 'completed', 'pages': []}}})
    @patch('ai_processor.processor.evaluate', return_value={'status': 'needs_staff_review', 'detected_document_type': 'valid_id', 'confidence_score': 75, 'extracted_fields': {}, 'findings': {}})
    @patch('ai_processor.processor.get_request_verification_context', return_value={})
    @patch('ai_processor.processor.prior_fields', return_value=[])
    @patch('ai_processor.processor.analyze_document_quality', return_value={'decision': 'acceptable'})
    @patch('ai_processor.processor.extract', return_value={})
    @patch('ai_processor.processor.resolve_safe_roi_fields', return_value=(None, set(), {}))
    @patch('ai_processor.processor.classify_document', return_value={'detected_type': 'valid_id', 'confidence_band': 'high', 'decision': 'match'})
    @patch('ai_processor.processor.extract_document_text', return_value=('VALID ID', 82.0, {'ocr_boxes_by_page': [[]]}))
    @patch('ai_processor.processor.load_images', return_value=[np.zeros((32, 32, 3), dtype=np.uint8)])
    @patch('ai_processor.processor.resolve_path', return_value=Path('synthetic-valid-id.png'))
    @patch('ai_processor.processor.claim_document')
    @patch('ai_processor.processor.connect')
    def test_process_one_reports_ordered_stages(
        self,
        connect,
        claim_document,
        _resolve_path,
        _load_images,
        _extract_document_text,
        _classify_document,
        _resolve_safe_roi_fields,
        _extract,
        _quality,
        _prior_fields,
        _context,
        _evaluate,
        _tamper,
        _persist,
        chronology,
        regional,
        aggregate,
        _attach,
        apply_recommendation,
        _external_attach,
        _external_review,
        update_stage_mock,
        finish,
    ):
        connection = MagicMock()
        connect.return_value = connection
        claim_document.return_value = {
            'id': 19,
            'document_id': 17,
            'request_id': 5,
            'file_path': 'AICS/Applicants/uploads/TEST/synthetic-valid-id.png',
            'expected_document_type': 'valid_id',
            'beneficiary_complete_name': 'Test Person',
            'beneficiary_birth_date': '2000-01-02',
        }

        self.assertTrue(process_one(document_id=17))

        self.assertEqual(
            update_stage_mock.call_args_list,
            [
                call(connection, 19, 'preprocessing', 10),
                call(connection, 19, 'ocr', 35),
                call(connection, 19, 'verifying', 65),
                call(connection, 19, 'external_review', 75),
                call(connection, 19, 'tamper_scan', 88),
            ],
        )
        chronology.assert_called_once()
        regional.assert_called_once()
        aggregate.assert_called_once()
        apply_recommendation.assert_called_once()
        finish.assert_called_once()


if __name__ == '__main__':
    unittest.main()
