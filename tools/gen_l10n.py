#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 ZEDD
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Writes l10n/<lang>.js from l10n/<lang>.json (the json files are the source of truth)."""
import json
import sys
from pathlib import Path

root = Path(__file__).resolve().parent.parent / "l10n"
for source in sorted(root.glob("*.json")):
    data = json.loads(source.read_text(encoding="utf-8"))
    lines = []
    for key, value in data["translations"].items():
        lines.append("    %s : %s" % (json.dumps(key, ensure_ascii=False), json.dumps(value, ensure_ascii=False)))
    body = ",\n".join(lines)
    out = 'OC.L10N.register(\n    "folderwatch",\n    {\n%s\n},\n%s);\n' % (body, json.dumps(data["pluralForm"]))
    source.with_suffix(".js").write_text(out, encoding="utf-8")
    print("wrote", source.with_suffix(".js").name, file=sys.stderr)
