"""Structure-aware text chunking with bounded recursive splitting."""
import re


def split_text(text: str, size: int, overlap: int) -> list[str]:
    """Legacy character-window helper retained for callers and compatibility."""
    if size < 1 or overlap < 0 or overlap >= size:
        raise ValueError("Chunk size must be positive and overlap must be smaller than size")
    normalized = re.sub(r"\s+", " ", text).strip()
    chunks, start = [], 0
    while start < len(normalized):
        end = min(start + size, len(normalized))
        chunks.append(normalized[start:end])
        if end == len(normalized):
            break
        start = end - overlap
    return chunks


def _split_long(text: str, size: int, overlap: int) -> list[str]:
    if len(text) <= size:
        return [text]
    # Prefer paragraph, sentence, then whitespace boundaries.
    units = re.split(r"(?<=[.!?])\s+|\n+|(?<=\s)", text)
    out, current = [], ""
    for unit in units:
        if not unit:
            continue
        if len(unit) > size:
            if current.strip():
                out.append(current.strip()); current = ""
            out.extend(split_text(unit, size, min(overlap, size - 1)))
            continue
        candidate = (current + " " + unit).strip()
        if len(candidate) <= size:
            current = candidate
        else:
            if current.strip(): out.append(current.strip())
            carry = current[-overlap:].strip() if overlap else ""
            room = max(0, size - len(unit) - (1 if carry else 0))
            current = ((carry[-room:] + " ") if room and carry else "") + unit
    if current.strip(): out.append(current.strip())
    return out


def chunk_document(text: str, size: int = 900, overlap: int = 120) -> list[dict]:
    """Group paragraphs under headings, then recursively split oversized sections."""
    if size < 1 or overlap < 0 or overlap >= size:
        raise ValueError("Chunk size must be positive and overlap must be smaller than size")
    sections, heading = [], ""
    for block in re.split(r"\n\s*\n", text.replace("\r\n", "\n")):
        block = block.strip()
        if not block:
            continue
        match = re.match(r"^(#{1,6})\s+([^\n]+)(?:\n|$)", block)
        if match:
            heading = match.group(2).strip()
            block = block[match.end():].strip()
            if not block:
                # Headings are context metadata; do not create heading-only vectors.
                continue
        sections.append((heading, block))
    result = []
    for heading, block in sections:
        # Keep list/table rows together when possible; recursive split applies to long blocks.
        for part in _split_long(block, size, overlap):
            if part.strip(): result.append({"text": part, "section": heading or None, "strategy": "structure_recursive_v1"})
    # Remove exact repeated blocks without dropping distinct section metadata.
    unique, seen = [], set()
    for item in result:
        key = (item["text"], item["section"])
        if key not in seen:
            unique.append(item); seen.add(key)
    return unique
