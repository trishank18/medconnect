import json
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

import numpy as np

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
import ingest
import retrieval


class IngestionRetrievalTests(unittest.TestCase):
    def test_ingestion_emits_stable_document_section_and_chunk_metadata(self):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder)
            (root / "guide.txt").write_text("# Setup\n\nInstall the MedConnect service carefully.", encoding="utf-8")
            first, errors = ingest.build_records(root)
            second, second_errors = ingest.build_records(root)
        self.assertFalse(errors)
        self.assertFalse(second_errors)
        self.assertEqual(len(first), 1)
        self.assertEqual(first[0]["chunk_id"], second[0]["chunk_id"])
        self.assertEqual(first[0]["document_id"], second[0]["document_id"])
        self.assertEqual(first[0]["section"], "Setup")
        self.assertEqual(first[0]["embedding_model"], retrieval.EMBEDDING_MODEL)

    def test_empty_directory_has_no_chunks(self):
        with tempfile.TemporaryDirectory() as folder:
            records, errors = ingest.build_records(Path(folder))
        self.assertEqual(records, [])
        self.assertEqual(errors, [])

    def test_retrieval_returns_real_metadata_for_indexed_relevant_chunk(self):
        class Index:
            ntotal = 2
            d = 2
            def search(self, vector, count):
                return np.asarray([[0.92, 0.78]], dtype="float32"), np.asarray([[0, 1]], dtype="int64")

        class Embedder:
            def encode(self, texts, **kwargs):
                return np.asarray([[1.0, 0.0]], dtype="float32")

        rows = [
            {"text": "MedConnect uses a PHP dashboard and a Python retrieval service.", "document": "architecture.txt", "page": None, "section": "Architecture", "embedding_model": retrieval.EMBEDDING_MODEL},
            {"text": "Patient readings are stored in MySQL health metrics.", "document": "database.txt", "page": 2, "section": "Database", "embedding_model": retrieval.EMBEDDING_MODEL},
        ]
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder)
            index_path, metadata_path = root / "knowledge.faiss", root / "chunks.json"
            index_path.touch()
            metadata_path.write_text(json.dumps(rows), encoding="utf-8")
            with patch.object(retrieval, "INDEX_PATH", index_path), patch.object(retrieval, "METADATA_PATH", metadata_path), patch.object(retrieval, "_index", return_value=Index()), patch.object(retrieval, "embedder", return_value=Embedder()):
                results = retrieval.retrieve("MedConnect Python architecture")
        self.assertEqual(results[0]["document"], "architecture.txt")
        self.assertEqual(results[0]["section"], "Architecture")
        self.assertGreater(results[0]["score"], 0)

    def test_changed_embedding_model_fails_closed_until_reingestion(self):
        class Index:
            ntotal = 1
            d = 2
        row = {"text": "A document chunk", "embedding_model": "different-model"}
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder)
            index_path, metadata_path = root / "index", root / "metadata.json"
            index_path.touch()
            metadata_path.write_text(json.dumps([row]), encoding="utf-8")
            with patch.object(retrieval, "INDEX_PATH", index_path), patch.object(retrieval, "METADATA_PATH", metadata_path), patch.object(retrieval, "_index", return_value=Index()):
                self.assertEqual(retrieval.retrieve("question"), [])


if __name__ == "__main__":
    unittest.main()
