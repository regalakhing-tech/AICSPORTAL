import unittest
from unittest.mock import MagicMock, call, patch
from ai_processor.processor import process_request

class RequestBatchTests(unittest.TestCase):
    def test_request_documents_are_processed_once_in_database_order(self):
        connection = MagicMock()
        cursor = connection.cursor.return_value.__enter__.return_value
        cursor.fetchall.return_value = [{'id': 31}, {'id': 32}, {'id': 33}]
        with patch('ai_processor.processor.connect', return_value=connection), patch('ai_processor.processor.process_one', return_value=True) as process:
            self.assertTrue(process_request(9))
        process.assert_has_calls([call(False, 31), call(False, 32), call(False, 33)])
        self.assertEqual(process.call_count, 3)

    def test_empty_request_does_not_start_document_processing(self):
        connection = MagicMock(); cursor = connection.cursor.return_value.__enter__.return_value; cursor.fetchall.return_value = []
        with patch('ai_processor.processor.connect', return_value=connection), patch('ai_processor.processor.process_one') as process:
            self.assertFalse(process_request(9))
        process.assert_not_called()

if __name__ == '__main__': unittest.main()
