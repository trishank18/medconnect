import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from routing import Category, classify_query, has_general_component


class RoutingTests(unittest.TestCase):
    def test_medconnect_questions_use_retrieval_route(self):
        self.assertEqual(classify_query("What is MedConnect?"), Category.MEDCONNECT_RAG)
        self.assertEqual(classify_query("How do I book an appointment in the dashboard?"), Category.MEDCONNECT_RAG)

    def test_personal_and_doctor_data_are_separated(self):
        self.assertEqual(classify_query("What is my latest heart rate?"), Category.PATIENT_DATA)
        self.assertEqual(classify_query("Show patient 42's readings"), Category.DOCTOR_DATA)
        self.assertEqual(classify_query("Explain their vitals"), Category.DOCTOR_DATA)
        self.assertEqual(classify_query("Show Aisha Khan's readings"), Category.AMBIGUOUS_OR_UNAUTHORIZED)
        self.assertEqual(classify_query("Show that patient's readings"), Category.AMBIGUOUS_OR_UNAUTHORIZED)

    def test_general_questions_do_not_require_retrieval(self):
        for question in (
            "Explain Python decorators.",
            "Write a SQL query to find duplicate records.",
            "What is the difference between CNN and Random Forest?",
            "Explain what SpO2 means.",
            "Write a patient registration form in Python.",
        ):
            with self.subTest(question=question):
                self.assertEqual(classify_query(question), Category.GENERAL_AI)

    def test_mixed_project_and_general_question_is_detected(self):
        question = "What is MedConnect, and explain Python decorators."
        self.assertEqual(classify_query(question), Category.MEDCONNECT_RAG)
        self.assertTrue(has_general_component(question))


if __name__ == "__main__":
    unittest.main()
