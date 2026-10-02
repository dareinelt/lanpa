# Offizielles Nextcloud-Image plus smbclient: noetig fuer die Netzlaufwerke
# der Windows-Clients (externe Speicher, Backend SMB/CIFS).
ARG NEXTCLOUD_IMAGE_TAG=34.0.4-apache
FROM nextcloud:${NEXTCLOUD_IMAGE_TAG}

RUN apt-get update \
    && apt-get install -y --no-install-recommends smbclient \
    && rm -rf /var/lib/apt/lists/*
