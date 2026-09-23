# Fuzzy Validator 2.1 runner — Ubuntu 26.04 + Playwright-pinned Chromium.
#
# node_modules strategy:
#   Named volume `fv-node-modules` overlays /ork3/node_modules so Linux
#   native addons never clash with a macOS host tree. Entrypoint runs
#   `npm ci` into that volume when @playwright/test is missing.
#   Playwright browser binaries live in-image at PLAYWRIGHT_BROWSERS_PATH
#   (not the host ~/Library/Caches/ms-playwright).
#
# Pin: ubuntu:resolute-20260707 (== ubuntu:26.04 at spike time).

ARG UBUNTU_IMAGE=ubuntu:resolute-20260707
FROM ${UBUNTU_IMAGE}

ENV DEBIAN_FRONTEND=noninteractive \
    LANG=C.UTF-8 \
    PLAYWRIGHT_BROWSERS_PATH=/ms-playwright \
    FUZZY_VALIDATOR_IN_CONTAINER=1 \
    PATH=/opt/fv-venv/bin:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

# OS packages: Playwright Chromium deps installed via `playwright install --with-deps`.
# Also: Node 22, Python 3 + venv, PHP CLI (ork-db), Docker CLI (compose restart / exec).
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        wget \
        git \
        python3 \
        python3-pip \
        python3-venv \
        nodejs \
        npm \
        php8.5-cli \
        php8.5-mysql \
        php8.5-mbstring \
        php8.5-xml \
        php8.5-curl \
        docker.io \
        docker-compose-v2 \
        socat \
        fonts-liberation \
        fonts-dejavu-core \
        fontconfig \
    && rm -rf /var/lib/apt/lists/*

# Python tool deps (baked venv; repo mount does not need host pip).
COPY tools/fuzzy-validator/python/requirements.txt /tmp/fv-requirements.txt
RUN python3 -m venv /opt/fv-venv \
    && /opt/fv-venv/bin/pip install --no-cache-dir -U pip \
    && /opt/fv-venv/bin/pip install --no-cache-dir -r /tmp/fv-requirements.txt \
    && rm -f /tmp/fv-requirements.txt

# Playwright / Chromium pinned to repo lockfile version (install browsers into image).
WORKDIR /tmp/fv-playwright-pin
COPY package.json package-lock.json ./
RUN npm ci --ignore-scripts \
    && npx playwright install --with-deps chromium \
    && node -e "const p=require('playwright/package.json'); const fs=require('fs'); const {execSync}=require('child_process'); let chromium='unknown'; try { chromium=execSync('ls /ms-playwright').toString().trim().split('\\n').filter(Boolean).join(', '); } catch (e) {} fs.writeFileSync('/opt/BROWSER_PIN.txt', ['playwright=' + p.version, 'playwright_browsers_path=/ms-playwright', 'chromium_cache=' + chromium, 'node=' + process.version, 'recorded_at=' + new Date().toISOString(), ''].join('\\n'));" \
    && rm -rf /tmp/fv-playwright-pin

COPY tools/fuzzy-validator/docker/entrypoint.sh /usr/local/bin/fv-entrypoint.sh
RUN chmod +x /usr/local/bin/fv-entrypoint.sh \
    && cp /opt/BROWSER_PIN.txt /BROWSER_PIN.txt

WORKDIR /ork3
ENTRYPOINT ["/usr/local/bin/fv-entrypoint.sh"]
CMD ["sleep", "infinity"]
