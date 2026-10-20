#!/usr/bin/env python3
"""
Erzeugt aus den Markdown-Dateien unter docs/ ein Gesamt-PDF (docs/handbuch.pdf)
mit Titelseite, Inhaltsverzeichnis (inkl. Seitenzahlen) und Screenshots.

Die Kapitelreihenfolge stammt aus der Dokumentationsliste der README.md.

Voraussetzungen (nur für den Build, nicht für den Betrieb der Anwendung):

    pip install weasyprint==63.1 markdown

Aufruf aus dem Projektverzeichnis:

    python3 bin/build-docs-pdf.py
"""
from __future__ import annotations

import html
import re
import sys
from pathlib import Path

import markdown
from weasyprint import HTML

ROOT = Path(__file__).resolve().parent.parent
DOCS = ROOT / "docs"
OUTPUT = DOCS / "handbuch.pdf"
TITLE = "KLINQ – Handbuch"

CSS = """
@page {
    size: A4;
    margin: 20mm 18mm 18mm 18mm;
    @bottom-center { content: counter(page); font-size: 9pt; color: #64748b; }
}
@page :first { margin: 0; @bottom-center { content: none; } }
body { font-family: "DejaVu Sans", sans-serif; font-size: 10pt; line-height: 1.45; color: #1f2937; }
h1 { font-size: 20pt; margin: 0 0 8mm; padding-bottom: 2mm; border-bottom: 2px solid #1d4ed8; color: #1e3a8a; }
h2 { font-size: 14pt; margin: 8mm 0 3mm; color: #1e3a8a; }
h3 { font-size: 11.5pt; margin: 6mm 0 2mm; color: #1f2937; }
h4 { font-size: 10.5pt; margin: 4mm 0 2mm; }
p, ul, ol { margin: 0 0 3mm; }
li { margin-bottom: 1mm; }
code { font-family: "DejaVu Sans Mono", monospace; font-size: 8.5pt; background: #f1f5f9; padding: 0 1mm; }
pre { font-family: "DejaVu Sans Mono", monospace; font-size: 8pt; background: #f8fafc; border: 1px solid #e2e8f0;
      border-left: 3px solid #1d4ed8; padding: 2mm 3mm; white-space: pre-wrap; page-break-inside: avoid; }
pre code { background: none; padding: 0; }
table { width: 100%; border-collapse: collapse; font-size: 8.5pt; margin: 0 0 4mm; page-break-inside: avoid; }
th, td { border: 1px solid #cbd5e1; padding: 1.2mm 2mm; text-align: left; vertical-align: top; }
th { background: #eff6ff; }
img { max-width: 100%; border: 1px solid #cbd5e1; margin: 2mm 0; }
a { color: #1d4ed8; text-decoration: none; }
blockquote { border-left: 3px solid #cbd5e1; margin: 0 0 3mm; padding-left: 3mm; color: #475569; }
.cover { height: 297mm; padding: 60mm 25mm 0; background: #1e3a8a; color: #ffffff; }
.cover h1 { font-size: 30pt; border: none; color: #ffffff; margin-bottom: 4mm; }
.cover p { font-size: 12pt; color: #dbeafe; }
.chapter { page-break-before: always; }
.toc { page-break-before: always; }
.toc h1 { border-bottom: none; }
.toc ul { list-style: none; padding-left: 0; margin: 0; }
.toc ul ul { padding-left: 6mm; font-size: 9pt; }
.toc li { margin-bottom: 1mm; }
.toc a { color: #1f2937; }
.toc a::after { content: " " leader('.') " " target-counter(attr(href), page); color: #64748b; }
.mermaid-note { font-size: 8pt; color: #64748b; margin: 0 0 1mm; }
"""


def slug(path: Path) -> str:
    return path.stem.lower().replace("_", "-")


def chapter_files() -> list[Path]:
    """Kapitelreihenfolge aus der Dokumentationsliste der README.md."""
    readme = (ROOT / "README.md").read_text(encoding="utf-8")
    names = re.findall(r"\]\(docs/([A-Za-z0-9_-]+\.md)\)", readme)
    files = [DOCS / name for name in dict.fromkeys(names) if (DOCS / name).is_file()]
    files += sorted(p for p in DOCS.glob("*.md") if p not in files)
    return files


def render_chapter(path: Path) -> tuple[str, list[tuple[int, str, str]]]:
    """Markdown → HTML; liefert HTML und die Überschriften (Ebene, Anker, Text)."""
    prefix = slug(path)
    converter = markdown.Markdown(extensions=["extra", "sane_lists", "toc"],
                                  extension_configs={"toc": {"slugify": lambda value, sep: _slugify(prefix, value)}})
    body = converter.convert(path.read_text(encoding="utf-8"))
    body = _link_chapters(body)
    body = _mark_mermaid(body)
    headings: list[tuple[int, str, str]] = []
    _flatten(converter.toc_tokens, headings)
    return f'<section class="chapter" id="{prefix}">{body}</section>', headings


def _flatten(tokens: list[dict], target: list[tuple[int, str, str]]) -> None:
    for token in tokens:
        target.append((int(token["level"]), token["id"], html.unescape(token["name"])))
        _flatten(token["children"], target)


def _slugify(prefix: str, value: str) -> str:
    value = re.sub(r"[^\w\s-]", "", value.lower(), flags=re.UNICODE).strip()
    return prefix + "-" + re.sub(r"[-\s]+", "-", value)


def _link_chapters(body: str) -> str:
    """Links auf andere Kapitel (`datei.md#anker`) werden zu PDF-internen Ankern."""
    def replace(match: re.Match[str]) -> str:
        target, anchor = match.group(1), match.group(2)
        return 'href="#' + target.lower() + (("-" + anchor.lstrip("#")) if anchor else "") + '"'

    return re.sub(r'href="([A-Za-z0-9_-]+)\.md(#[A-Za-z0-9_-]+)?"', replace, body)


def _mark_mermaid(body: str) -> str:
    return body.replace(
        '<pre><code class="language-mermaid">',
        '<p class="mermaid-note">Diagramm (Mermaid-Quelltext):</p><pre><code class="language-mermaid">',
    )


def build() -> Path:
    chapters, entries = [], []
    for path in chapter_files():
        chapter, headings = render_chapter(path)
        chapters.append(chapter)
        entries.extend(headings)
    cover = (f'<section class="cover"><h1>{html.escape(TITLE)}</h1>'
             f"<p>Assetverwaltung und Help Desk für die IT</p>"
             f"<p>Gesamtdokumentation aus dem Verzeichnis <code>docs/</code></p></section>")
    document = (f"<!DOCTYPE html><html lang=\"de\"><head><meta charset=\"utf-8\">"
                f"<title>{html.escape(TITLE)}</title><style>{CSS}</style></head>"
                f"<body>{cover}{_nested_toc(entries)}{''.join(chapters)}</body></html>")
    HTML(string=document, base_url=str(DOCS)).write_pdf(OUTPUT)
    return OUTPUT


def _nested_toc(entries: list[tuple[int, str, str]]) -> str:
    """Zweistufiges Inhaltsverzeichnis: Kapitel (H1) mit ihren Abschnitten (H2)."""
    parts = ['<section class="toc"><h1>Inhaltsverzeichnis</h1><ul>']
    open_sub = False
    for level, anchor, text in entries:
        if level > 2:
            continue
        if level == 1:
            if open_sub:
                parts.append("</ul></li>")
                open_sub = False
            parts.append(f'<li><a href="#{anchor}">{html.escape(text)}</a>')
            parts.append("<ul>")
            open_sub = True
        else:
            parts.append(f'<li><a href="#{anchor}">{html.escape(text)}</a></li>')
    parts.append("</ul></li>" if open_sub else "")
    parts.append("</ul></section>")
    return "".join(parts)


if __name__ == "__main__":
    try:
        print("PDF erzeugt: " + str(build().relative_to(ROOT)))
    except Exception as exc:  # noqa: BLE001 – Fehler verständlich melden
        print("Fehler beim Erzeugen des PDFs: " + str(exc), file=sys.stderr)
        raise SystemExit(1) from exc
