FROM python:3.12-slim

WORKDIR /app

# CPU-only torch first so the CUDA wheels are never pulled by requirements.txt
RUN pip install --no-cache-dir torch==2.13.0 --index-url https://download.pytorch.org/whl/cpu

COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

# Source code (ai/) and model weights (models volume) are bind-mounted at runtime,
# so the image only needs the interpreter + pip packages. Rebuilding is only
# required when requirements.txt changes.
ENV PYTHONUNBUFFERED=1 \
    HF_HUB_OFFLINE=1

EXPOSE 8000

HEALTHCHECK --interval=30s --timeout=5s --start-period=300s --retries=3 \
  CMD python -c "import urllib.request; urllib.request.urlopen('http://localhost:8000/health')" || exit 1

CMD ["uvicorn", "ai.main:app", "--host", "0.0.0.0", "--port", "8000"]