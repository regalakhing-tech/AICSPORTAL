# AICS on Railway: Apache + PHP 8.2 for the portals, Python + Tesseract + Poppler for the AI worker.
FROM php:8.2-apache-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        python3 python3-venv tesseract-ocr poppler-utils libgl1 libglib2.0-0 tzdata \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install mysqli \
    && a2enmod headers remoteip rewrite

COPY ai_processor/requirements.txt /tmp/requirements.txt
RUN python3 -m venv /opt/venv \
    && /opt/venv/bin/pip install --no-cache-dir -r /tmp/requirements.txt

COPY deploy/apache.conf /etc/apache2/sites-available/000-default.conf
COPY deploy/php.ini /usr/local/etc/php/conf.d/aics.ini
RUN echo 'Listen ${PORT}' > /etc/apache2/ports.conf

# Same layout as XAMPP: landing page and shared assets in /AICS, the app in /AICS/AICS.
COPY deploy/site/ /var/www/html/AICS/
COPY . /var/www/html/AICS/AICS/
RUN mkdir -p /var/www/html/AICS/AICS/ai_processor/logs \
    && chown -R www-data:www-data /var/www/html/AICS/AICS/ai_processor/logs

# Railway mounts the volume at Applicants/uploads; private files live on it too.
ENV PORT=8080 \
    TZ=Asia/Manila \
    AICS_PROJECT_ROOT=/var/www/html/AICS/AICS \
    AICS_UPLOAD_ROOT=/var/www/html/AICS/AICS/Applicants/uploads \
    AICS_PRIVATE_ROOT=/var/www/html/AICS/AICS/Applicants/uploads/.private \
    AICS_AI_REVIEW_ARTIFACT_ROOT=/var/www/html/AICS/AICS/Applicants/uploads/.private/ai_review_artifacts \
    AICS_EXTERNAL_AI_STATE_ROOT=/var/www/html/AICS/AICS/Applicants/uploads/.private/external_ai \
    AICS_EXTERNAL_AI_ENV_FILE=/var/www/html/AICS/AICS/Applicants/uploads/.private/aics-ai.env \
    AICS_PHP_EXECUTABLE=/usr/local/bin/php \
    AICS_AI_GATE_SCHEDULER=1 \
    AICS_PYTHON_EXECUTABLE=/opt/venv/bin/python \
    AICS_TESSERACT_PATH=/usr/bin/tesseract \
    AICS_POPPLER_PATH=/usr/bin

CMD ["sh", "/var/www/html/AICS/AICS/deploy/start.sh"]
