#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Fetch official pinned recipient API sources; no PHP is executed on the host.

Usage: python3 tests/fixtures/nextcloud-api/fetch-recipients.py <destination>
Then run generate-recipients.php <major> <destination> inside the integration harness.
"""
import hashlib
import json
import pathlib
import sys
import time
import urllib.request

# Network budgets for reproducible source fetching, independent of runtime recipient limits.
DOWNLOAD_ATTEMPTS = 3
DOWNLOAD_TIMEOUT_SECONDS = 60
RETRY_DELAY_SECONDS = 2

pins = json.loads(pathlib.Path(__file__).with_name('recipient-sources.json').read_text())
destination = pathlib.Path(sys.argv[1])
destination.mkdir(parents=True, exist_ok=True)
for major, apps in pins.items():
    for app, pin in apps.items():
        suffix = 'php' if app == 'server' else 'tar.gz'
        target = destination / f'{app}-{pin["version"]}.{suffix}'
        if not target.exists():
            for attempt in range(DOWNLOAD_ATTEMPTS):
                try:
                    with urllib.request.urlopen(pin['url'], timeout=DOWNLOAD_TIMEOUT_SECONDS) as response:
                        target.write_bytes(response.read())
                    break
                except OSError:
                    if attempt == DOWNLOAD_ATTEMPTS - 1:
                        raise
                    time.sleep(RETRY_DELAY_SECONDS)
        if hashlib.sha256(target.read_bytes()).hexdigest() != pin['sha256']:
            raise SystemExit(f'SHA256 mismatch: {target}')
        print(major, app, pin['version'], pin['sha256'])
