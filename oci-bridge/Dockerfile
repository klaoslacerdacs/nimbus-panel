FROM python:3.12-slim

WORKDIR /app

COPY pyproject.toml .
COPY oci_bridge/ ./oci_bridge/

RUN pip install --no-cache-dir -e .

EXPOSE 8080

CMD ["uvicorn", "oci_bridge.app:app", "--host", "0.0.0.0", "--port", "8080"]
