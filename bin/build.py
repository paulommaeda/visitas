#!/usr/bin/env python3
"""Build an installable WordPress plugin ZIP, excluding development and test files."""
from pathlib import Path
from zipfile import ZipFile, ZIP_DEFLATED
import sys

root = Path(__file__).resolve().parents[1]
output = Path(sys.argv[1]).resolve() if len(sys.argv) > 1 else root.parent / 'visitas-1.0.1.zip'
excluded = {'.git', '.github', 'tests', 'bin', 'dist', '__pycache__', 'node_modules'}
files = [p for p in root.rglob('*') if p.is_file() and not any(x in excluded for x in p.relative_to(root).parts) and not p.name.startswith('.') and p.name != 'AGENTS.md' and p.suffix not in {'.zip', '.log'}]
output.parent.mkdir(parents=True, exist_ok=True)
with ZipFile(output, 'w', ZIP_DEFLATED, compresslevel=9) as archive:
    for p in sorted(files):
        archive.write(p, 'visitas/' + str(p.relative_to(root)))
print(f'{output} ({len(files)} files, {output.stat().st_size} bytes)')
