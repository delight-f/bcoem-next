#!/usr/bin/env python3
"""Generate site/config.php for the parity oracle (legacy checkout).

Run from the legacy repo root. Reads connection settings from env:

    PARITY_DB_HOST (default 127.0.0.1)
    PARITY_DB_USER (default root)
    PARITY_DB_PASS (default root)
    PARITY_DB_NAME (required — throwaway database)
    PARITY_DB_PREFIX (default baseline_ — must match the loaded dump)
    LEGACY_BASE_URL (required — absolute URL incl. port so legacy
                     redirects stay on the harness server)
"""

import os
import pathlib
import re

name = os.environ.get("PARITY_DB_NAME")
if not name:
    raise SystemExit("PARITY_DB_NAME is required")

replacements = {
    "hostname": os.environ.get("PARITY_DB_HOST", "127.0.0.1"),
    "username": os.environ.get("PARITY_DB_USER", "root"),
    "password": os.environ.get("PARITY_DB_PASS", "root"),
    "database": name,
    "prefix": os.environ.get("PARITY_DB_PREFIX", "baseline_"),
}

sample = pathlib.Path("site/config.sample.php")
config = sample.read_text()

for var, value in replacements.items():
    config = re.sub(
        rf"^\${var} = .*$",
        f"${var} = '{value}';",
        config,
        flags=re.M,
    )

base_url = os.environ.get("LEGACY_BASE_URL")
if not base_url:
    raise SystemExit("LEGACY_BASE_URL is required")
# The sample builds $base_url across three lines (init, https check, and
# .= SERVER_NAME); replace the whole block, not just the first assignment.
config = re.sub(
    r"^\$base_url = 'http://';\nif \(is_https\(\)\) \$base_url = 'https://';\n\$base_url \.= .*$",
    f"$base_url = '{base_url}';",
    config,
    flags=re.M,
)
if "$base_url" not in config or "SERVER_NAME" in config:
    raise SystemExit("config.sample.php shape changed — base_url override failed")
print("site/config.php written")
