#!/usr/bin/env python3
"""Form-contract audit — finds the 'save always fails' bug class site-wide.

For every controller that validates input, and every blade form:
  A. REQUIRED-NOT-RENDERED: validation requires a field no blade form submits.
  B. RENDERED-NOT-READ: a form submits fields the controller never reads
     (silent data loss on save).
  C. DOMAIN-MISMATCH: blade radio/select values vs the controller's in: rule.
  D. LEGACY-DIVERGENCE: field sets / value domains vs the legacy form+process.

Legacy source of truth:
  forms:    LEGACY_DIR/admin/*.admin.php, LEGACY_DIR/pub/*.pub.php,
            LEGACY_DIR/sections/*.sec.php
  handlers: LEGACY_DIR/includes/process/*.inc.php  ($_POST reads)

Usage: python3 tools/parity/form_audit.py [LEGACY_DIR]
Exit 1 if any A/C/D finding (hard save-breakers). B is informational.
"""
import re
import sys
import pathlib

LEGACY = pathlib.Path(sys.argv[1] if len(sys.argv) > 1 else
                      "/home/faraaz/dev/bcoe/brewcompetitiononlineentry")
PORT = pathlib.Path(__file__).resolve().parents[2]

# ── collect port contracts ────────────────────────────────────────────
ctrl_rules = {}   # file -> {field: {'rules': [...], 'in': set()|None}}
for f in sorted((PORT / "app/Http/Controllers").rglob("*.php")):
    src = f.read_text()
    if "Validator" not in src and "required" not in src and "in:" not in src:
        continue
    fields = {}
    for m in re.finditer(r"'(\w+)'\s*=>\s*\[((?:[^\[\]]|\[[^\]]*\])*)\]", src):
        name, rules = m.group(1), m.group(2)
        inmatch = re.search(r"in:([\w,\-]+)", rules)
        fields[name] = {
            "required": "required" in rules or "required_with" in rules,
            "in": set(inmatch.group(1).split(",")) if inmatch else None,
        }
    if fields:
        ctrl_rules[str(f.relative_to(PORT))] = fields

form_fields = {}  # view file -> {field: {'tag','values'}}
for f in sorted((PORT / "resources/views").rglob("*.blade.php")):
    src = f.read_text()
    fields = {}
    for m in re.finditer(
            r'<(input|select|textarea)\b[^>]*?name="([\w]+)(\[\])?"[^>]*>?', src):
        tag, name, arr = m.group(1), m.group(2), m.group(3) or ""
        chunk = m.group(0)
        vals = set(re.findall(r'value="([^"]*)"', chunk))
        fields.setdefault(name, {"tag": tag, "values": set(), "array": bool(arr)})
        if vals:
            fields[name]["values"] |= vals
    # dynamic names: name="{{ $field }}" loops; when the foreach key list is
    # a literal array right above, resolve its quoted keys as concrete names.
    dyn = set(re.findall(r'name="\{\{\s*\$(\w+)', src))
    for m in re.finditer(r"@foreach\s*\(\[([^\]]+)\]\s*as\s*\$(\w+)\s*=>", src):
        keys = re.findall(r"'(\w+)'\s*=>", m.group(1))
        if keys:
            dyn |= set(keys)
    if fields or dyn:
        form_fields[str(f.relative_to(PORT))] = {"fields": fields, "dynamic": dyn}

all_static = {n for v in form_fields.values() for n in v["fields"]}
all_dynamic = {n for v in form_fields.values() for n in v["dynamic"]}

# ── collect legacy $_POST field reads per process script ──────────────
legacy_post = {}   # process file -> set(fields)
for f in sorted((LEGACY / "includes/process").glob("*.inc.php")):
    src = f.read_text()
    legacy_post[f.name] = set(re.findall(r"\$_POST\[.([\w]+).\]", src))

# legacy form name= occurrences per admin/pub/sections file
legacy_form_fields = {}
for glob in ("admin/*.admin.php", "pub/*.pub.php", "sections/*.sec.php"):
    for f in sorted(LEGACY.glob(glob)):
        src = f.read_text()
        names = set(re.findall(r'name="([\w]+)(?:\[\])?"', src))
        if names:
            legacy_form_fields[str(f.relative_to(LEGACY))] = names

findings = []

# A: required-not-rendered
for ctrl, fields in ctrl_rules.items():
    for name, rule in fields.items():
        if rule["required"] and name not in all_static and name not in all_dynamic:
            findings.append(("A", ctrl, name, "required by validation, no form submits it"))

# C: domain mismatch (radio/select values vs in: rule)
for ctrl, fields in ctrl_rules.items():
    for name, rule in fields.items():
        if not rule["in"]:
            continue
        for view, v in form_fields.items():
            fld = v["fields"].get(name)
            if not fld or fld["tag"] == "input" and 'type="text"' in "":
                continue
            vals = {v for v in fld["values"] if not v.startswith("{{")}
            if vals and not vals <= rule["in"]:
                bad = vals - rule["in"]
                findings.append(("C", f"{ctrl} <-> {view}", name,
                                 f"form values {sorted(bad)} rejected by in:{sorted(rule['in'])}"))

# D: legacy divergence — legacy form has fields the port form lacks for the
# same surface (name-matched controllers, e.g. site_preferences.admin.php).
surface_map = {
    "site_preferences.admin.php": "resources/views/admin/site-preferences.blade.php",
}
for legacy_file, port_view in surface_map.items():
    lf = legacy_form_fields.get(LEGACY / "admin" / legacy_file and f"admin/{legacy_file}")
    if lf is None:
        continue
    pf = form_fields.get(port_view, {"fields": {}, "dynamic": set()})
    missing = lf - set(pf["fields"]) - pf["dynamic"]
    missing = {m for m in missing if not m.startswith(("go", "action", "section", "redirect", "loginUsername", "loginPassword", "token"))}
    for m in sorted(missing):
        findings.append(("D", port_view, m, f"legacy {legacy_file} renders it, port does not"))

hard = [f for f in findings if f[0] in "ACD"]
for sev, where, field, msg in findings:
    print(f"{sev}  {where}  ::  {field}  ::  {msg}")
print(f"\n{len(findings)} findings ({len(hard)} hard save-breakers)")
sys.exit(1 if hard else 0)
