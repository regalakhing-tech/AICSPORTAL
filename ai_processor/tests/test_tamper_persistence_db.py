"""Explicit disposable-database proof for Stage 6 persistence (not a unit test)."""
from __future__ import annotations
import json, os
from pathlib import Path
import pymysql
from ai_processor.config import SETTINGS
from ai_processor.database import finish
from ai_processor.tamper_detection.postprocessing import attach_persisted_finding

STAGE5 = Path(os.getenv('AICS_ADCD_STAGE5_RESULT', ''))

def main() -> int:
    if not STAGE5.is_file(): raise RuntimeError('AICS_ADCD_STAGE5_RESULT is unavailable.')
    database = 'aics_tamper_persistence_test_' + str(os.getpid())
    server = pymysql.connect(host=SETTINGS.db_host, port=SETTINGS.db_port, user=SETTINGS.db_user, password=SETTINGS.db_password, autocommit=True)
    try:
        with server.cursor() as cursor: cursor.execute(f'CREATE DATABASE `{database}` CHARACTER SET utf8mb4')
        connection = pymysql.connect(host=SETTINGS.db_host, port=SETTINGS.db_port, user=SETTINGS.db_user, password=SETTINGS.db_password, database=database, autocommit=False, cursorclass=pymysql.cursors.DictCursor)
        try:
            with connection.cursor() as cursor:
                cursor.execute('CREATE TABLE document_ai_reviews (id BIGINT UNSIGNED NOT NULL PRIMARY KEY, status VARCHAR(30) NOT NULL, detected_document_type VARCHAR(100), confidence_score DECIMAL(5,2), extracted_fields LONGTEXT, findings LONGTEXT, model_service VARCHAR(100), model_version VARCHAR(100), last_error_code VARCHAR(100), last_error_message TEXT, processed_at DATETIME)')
                cursor.execute("INSERT INTO document_ai_reviews (id, status) VALUES (1, 'processing')")
            stage5 = json.loads(STAGE5.read_text(encoding='utf-8'))
            findings = attach_persisted_finding({'summary': 'Phase 1 retained', 'checks': {'quality': {'status': 'acceptable'}}}, {'providers': {'adcd_net': stage5['tampering']}})
            finish(connection, 1, {'status': 'completed', 'detected_document_type': 'valid_id', 'confidence_score': 90, 'extracted_fields': {}, 'findings': findings})
            with connection.cursor() as cursor:
                cursor.execute('SELECT findings FROM document_ai_reviews WHERE id = 1')
                stored = json.loads(cursor.fetchone()['findings'])
            assert stored['summary'] == 'Phase 1 retained' and stored['tamper_detection']['pages'][0]['page'] == 1
            assert isinstance(stored['tamper_detection']['aggregate']['max_tamper_probability'], float)
            assert 'artifacts' not in json.dumps(stored) and 'test_outputs' not in json.dumps(stored) and 'C:\\' not in json.dumps(stored)
            print('Synthetic tamper persistence DB test passed.')
        finally: connection.close()
    finally:
        with server.cursor() as cursor: cursor.execute(f'DROP DATABASE IF EXISTS `{database}`')
        server.close()
    return 0

if __name__ == '__main__': raise SystemExit(main())
