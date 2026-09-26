FROM mcr.microsoft.com/playwright:v1.55.0-noble
RUN apt-get update && apt-get install -y --no-install-recommends poppler-utils fonts-dejavu-core fonts-liberation \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /opt/render
RUN npm init -y >/dev/null && npm install --no-audit --no-fund playwright@1.55.0 >/dev/null
COPY render.js /opt/render/render.js
ENTRYPOINT ["node", "/opt/render/render.js"]
