from pydantic import BaseModel, ConfigDict, Field, field_validator


class ChatRequest(BaseModel):
    model_config = ConfigDict(extra="forbid", str_strip_whitespace=True)
    question: str = Field(min_length=1, max_length=2000)
    request_id: str = Field(min_length=1, max_length=80)
    conversation_id: str | None = Field(default=None, min_length=64, max_length=64, pattern=r"^[a-f0-9]{64}$")

    @field_validator("question")
    @classmethod
    def reject_whitespace_only(cls, value: str) -> str:
        if not value.strip():
            raise ValueError("Question is required")
        return value


class Source(BaseModel):
    document: str
    page: int | None = None
    section: str | None = None


class ChatResponse(BaseModel):
    reply: str
    category: str
    sources: list[Source] = Field(default_factory=list)
