import unittest
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from chunking import split_text


class ChunkingTests(unittest.TestCase):
    def test_normalizes_whitespace_and_keeps_overlap(self):
        chunks = split_text("one  two\nthree four", size=9, overlap=3)
        self.assertEqual(chunks[0], "one two t")
        self.assertEqual(chunks[0][-3:], chunks[1][:3])

    def test_empty_input_produces_no_chunks(self):
        self.assertEqual(split_text("  \n ", size=20, overlap=2), [])

    def test_invalid_overlap_is_rejected(self):
        with self.assertRaises(ValueError):
            split_text("text", size=4, overlap=4)


if __name__ == "__main__":
    unittest.main()
