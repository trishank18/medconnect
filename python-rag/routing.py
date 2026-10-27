"""Lightweight, conservative intent routing; private records stay in PHP/MySQL."""
from enum import StrEnum
import re


class Category(StrEnum):
    MEDCONNECT_RAG = "MEDCONNECT_RAG"
    PATIENT_DATA = "PATIENT_DATA"
    DOCTOR_DATA = "DOCTOR_DATA"
    GENERAL_AI = "GENERAL_AI"
    AMBIGUOUS_OR_UNAUTHORIZED = "AMBIGUOUS_OR_UNAUTHORIZED"


_PRIVATE_DATA = re.compile(
    r"\b(my|mine|our|patient(?:\s+[a-z0-9#_-]+)?|their|his|her)\b.{0,100}\b"
    r"(health\s+records?|medical\s+records?|readings?|vitals?|heart\s*rate|spo2|oxygen|"
    r"temperature|blood\s*pressure|prescriptions?|medications?|appointments?|pulse|bpm|hr|bp)\b|"
    r"\b(latest|recent|show|list|summari[sz]e|compare)\b.{0,80}\b"
    r"(patient\s+[a-z0-9#_-]+|my\s+(?:health|medical|vitals?|readings?|prescription|appointment|hr|bp|pulse))\b",
    re.IGNORECASE,
)
_PATIENT_ROLE = re.compile(r"\b(my|mine|our)\b", re.IGNORECASE)
_DOCTOR_ROLE = re.compile(r"\b(patient\s+[a-z0-9#_-]+|assigned\s+patient|their\s+(?:readings?|vitals?|records?))\b", re.IGNORECASE)
_NAMED_PERSON_DATA = re.compile(r"\b[A-Z][a-z]+(?:\s+[A-Z][a-z]+)?(?:['’]s)\b.{0,60}\b(readings?|vitals?|health\s+records?|medical\s+records?|prescriptions?|medications?)\b", re.IGNORECASE)
_MEDCONNECT = re.compile(
    r"\b(medconnect|this\s+(?:website|application|app|system|project)|our\s+(?:website|application|app|system)|"
    r"patient\s+portal|doctor\s+portal|patient\s+workspace|doctor\s+workspace|"
    r"doctor\s+approval|patient\s+assignment|authorized\s+patient|approved\s+references|"
    r"ai\s+analytics|health\s+assistant|rag\s+service|system\s+architecture)\b|"
    r"\bhow\s+do\s+i\s+(?:use|book|schedule|open|access)\b.{0,60}\b(appointment|dashboard|portal|doctor|patient)\b|"
    r"\bwhere\s+(?:do|can)\s+i\s+(?:find|open|access)\b.{0,40}\b(dashboard|portal|readings|prescriptions)\b",
    re.IGNORECASE,
)
_AMBIGUOUS = re.compile(r"\b(that patient|this patient|them|their patient|the patient)\b", re.IGNORECASE)


def classify_query(question: str) -> Category:
    """Classify without an extra LLM request and prefer privacy over convenience."""
    text = " ".join(question.split())
    if _PRIVATE_DATA.search(text) or _NAMED_PERSON_DATA.search(text):
        if _PATIENT_ROLE.search(text):
            return Category.PATIENT_DATA
        if _DOCTOR_ROLE.search(text):
            return Category.DOCTOR_DATA
        return Category.AMBIGUOUS_OR_UNAUTHORIZED
    if _AMBIGUOUS.search(text) and re.search(r"\b(readings?|vitals?|records?|prescriptions?)\b", text, re.I):
        return Category.AMBIGUOUS_OR_UNAUTHORIZED
    if _MEDCONNECT.search(text):
        return Category.MEDCONNECT_RAG
    return Category.GENERAL_AI


def has_general_component(question: str) -> bool:
    """Detect an explicit general-knowledge part inside a MedConnect question."""
    text = question.lower()
    general_topic = re.search(
        r"\b(python|sql|programming|decorators?|cnn|random\s+forest|machine\s+learning|"
        r"mathematics|math|write|grammar|essay|general\s+knowledge)\b",
        text,
    )
    connector = re.search(r"\b(and|also|plus|while|as well as)\b", text)
    return bool(general_topic and connector)
