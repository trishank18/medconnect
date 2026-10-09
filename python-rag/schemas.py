from pydantic import BaseModel, Field


class ChatRequest(BaseModel):
    question: str = Field(min_length=1, max_length=2000)
    request_id: str = Field(min_length=1, max_length=80)


class Source(BaseModel):
    document: str
    page: int | None = None


class ChatResponse(BaseModel):
    reply: str
    sources: list[Source] = []
