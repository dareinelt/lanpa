FROM python:3.12-slim

# WeasyPrint-Laufzeitabhängigkeiten (Pango, Cairo, Schriften)
RUN apt-get update && apt-get install -y --no-install-recommends \
        libpango-1.0-0 libpangoft2-1.0-0 libharfbuzz0b libffi8 libcairo2 libgdk-pixbuf-2.0-0 \
        fonts-dejavu-core fonts-liberation shared-mime-info \
    && rm -rf /var/lib/apt/lists/*

RUN pip install --no-cache-dir weasyprint==63.1

RUN useradd --system --create-home --uid 10001 pdf
WORKDIR /app
COPY docker/pdf/server.py /app/server.py
USER pdf

EXPOSE 8000
HEALTHCHECK --interval=30s --timeout=5s --retries=3 CMD python -c "import urllib.request,sys; sys.exit(0 if urllib.request.urlopen('http://127.0.0.1:8000/health', timeout=3).status == 200 else 1)"
CMD ["python", "/app/server.py"]
