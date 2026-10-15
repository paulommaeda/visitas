#!/usr/bin/env python3
"""Build an installable WordPress plugin ZIP, excluding development and test files."""
from pathlib import Path
from zipfile import ZipFile, ZIP_DEFLATED
from hashlib import sha256
from html import escape
import json
import re
import sys

root = Path(__file__).resolve().parents[1]
headers = (root / 'visitas.php').read_text()
readme = (root / 'readme.txt').read_text()


def header(text, name):
    match = re.search(r'^\s*(?:\*\s*)?' + re.escape(name) + r':\s*(.+)$', text, re.M)
    if not match:
        raise SystemExit(f'Missing header: {name}')
    return match.group(1).strip()


version = header(headers, 'Version')
if header(readme, 'Stable tag') != version or f"define('VISITAS_VERSION', '{version}')" not in headers:
    raise SystemExit('Plugin version, constant and stable tag must match.')
output = Path(sys.argv[1]).resolve() if len(sys.argv) > 1 else root / 'dist' / f'visitas-{version}.zip'
if output.name != f'visitas-{version}.zip':
    raise SystemExit(f'Expected filename visitas-{version}.zip')
excluded = {'.git', '.github', 'tests', 'bin', 'dist', '__pycache__', 'node_modules'}
files = [p for p in root.rglob('*') if p.is_file() and not any(x in excluded for x in p.relative_to(root).parts) and not p.name.startswith('.') and p.name != 'AGENTS.md' and p.suffix not in {'.zip', '.log'}]
output.parent.mkdir(parents=True, exist_ok=True)
with ZipFile(output, 'w', ZIP_DEFLATED, compresslevel=9) as archive:
    for p in sorted(files):
        archive.write(p, 'visitas/' + str(p.relative_to(root)))
print(f'{output} ({len(files)} files, {output.stat().st_size} bytes)')

changelog = []
for line in readme.split('== Changelog ==', 1)[1].strip().splitlines():
    if not line.strip():
        continue
    title = re.fullmatch(r'= (.+) =', line)
    changelog.append(f'<h4>{escape(title.group(1))}</h4>' if title else f'<p>{escape(line)}</p>')
manifest = {
    'slug': 'visitas', 'version': version,
    'requires': header(headers, 'Requires at least'),
    'requires_php': header(headers, 'Requires PHP'),
    'tested': header(readme, 'Tested up to'),
    'download_url': f'https://raw.githubusercontent.com/paulommaeda/visitas/main/dist/{output.name}',
    'sha256': sha256(output.read_bytes()).hexdigest(),
    'sections': {
        'description': '<p>Portal de acolhimento de visitantes com quadros Kanban, listas, cartões, checklists, perfis, identidade visual e notificações.</p>',
        'installation': '<p>Envie o ZIP em Plugins → Adicionar plugin → Enviar plugin. A partir da versão 1.0.2, use Atualizar agora na lista de plugins para instalar novas versões publicadas no GitHub.</p>',
        'changelog': '\n'.join(changelog),
    },
}
manifest_path = output.parent / 'update.json'
manifest_path.write_text(json.dumps(manifest, ensure_ascii=False, indent=2) + '\n')
print(f'{manifest_path} (release metadata and SHA-256)')
