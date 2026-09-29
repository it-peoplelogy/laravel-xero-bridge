<?php

/*
 * Builds docs/xero-bridge-usage.html from the markdown source, for circulation
 * to people who will not clone this repository.
 *
 * Paths are relative to this file, so it runs from anywhere:
 *
 *     php docs/build-xero-bridge-usage.php
 *
 * The PDF is produced separately by headless Chrome from the HTML:
 *
 *     chrome --headless --no-pdf-header-footer \
 *       --print-to-pdf=docs/xero-bridge-usage.pdf \
 *       file://<abs path>/docs/xero-bridge-usage.html
 *
 * Chrome writes the file and then hangs, so background it and verify with
 * `pdfinfo` rather than waiting for it to exit.
 */

require __DIR__.'/../vendor/autoload.php';

use League\CommonMark\GithubFlavoredMarkdownConverter;

$root = __DIR__;
$src = $root.'/xero-bridge-usage.md';
$out = $root.'/xero-bridge-usage.html';

$markdown = file_get_contents($src);

/*
 * The masthead version is READ FROM the document rather than hardcoded here.
 * It was hardcoded once, and the header went on claiming v1.0.3 long after the
 * body had moved on. The two must not be able to disagree.
 */
if (! preg_match('/Written against `peoplelogy\/laravel-xero-bridge` (v[0-9]+\.[0-9]+\.[0-9]+)/', $markdown, $versionMatch)) {
    fwrite(STDERR, "No version note found in the markdown; refusing to build a mislabelled document.\n");
    exit(1);
}

$version = $versionMatch[1];

/* ------------------------------------------------------------------ */
/* markdown -> html */
/* ------------------------------------------------------------------ */

$converter = new GithubFlavoredMarkdownConverter([
    'html_input' => 'allow',
    'allow_unsafe_links' => false,
]);

$body = (string) $converter->convert($markdown);

/*
 * GFM does not emit heading ids, so the in-page Contents links would all be
 * dead. Reproduce GitHub's own slug rule: lowercase, drop anything that is
 * not alphanumeric / space / hyphen (which removes em dashes, backticks and
 * full stops), then spaces to hyphens. "Flow 2.1 — create a DRAFT invoice"
 * becomes "flow-21--create-a-draft-invoice", matching the anchors already
 * written in the document.
 */
$slugCounts = [];

$body = preg_replace_callback(
    '/<h([23])>(.*?)<\/h\1>/s',
    function (array $m) use (&$slugCounts): string {
        $text = html_entity_decode(strip_tags($m[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $slug = mb_strtolower(trim($text));
        $slug = preg_replace('/[^a-z0-9 \-]/u', '', $slug);
        $slug = str_replace(' ', '-', (string) $slug);

        // GitHub appends -1, -2 … to a repeated heading.
        if (isset($slugCounts[$slug])) {
            $slug .= '-'.$slugCounts[$slug]++;
        } else {
            $slugCounts[$slug] = 1;
        }

        return sprintf('<h%1$s id="%2$s">%3$s</h%1$s>', $m[1], $slug, $m[2]);
    },
    $body
);

/* Pull the title out of the H1 so the shell can use it. */
$title = 'Using peoplelogy/laravel-xero-bridge';

/* ------------------------------------------------------------------ */
/* the shell */
/* ------------------------------------------------------------------ */

$generated = date('j F Y');

$css = <<<'CSS'
:root {
    --ink: #1b1f24;
    --muted: #5b6672;
    --faint: #8a939e;
    --rule: #e3e7ec;
    --accent: #0b6e8f;
    --accent-soft: #f0f8fb;
    --code-bg: #f6f8fa;
    --code-ink: #1b1f24;
    --warn-bg: #fff8e6;
    --warn-rule: #e0b44a;
    --page: #ffffff;
}

* { box-sizing: border-box; }

html { -webkit-text-size-adjust: 100%; }

body {
    margin: 0;
    background: #eef1f4;
    color: var(--ink);
    font: 16px/1.65 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
}

.sheet {
    max-width: 860px;
    margin: 32px auto 64px;
    padding: 56px 64px 72px;
    background: var(--page);
    border: 1px solid var(--rule);
    border-radius: 6px;
}

/* ---------- type ---------- */

h1, h2, h3, h4 { line-height: 1.25; font-weight: 650; }

h1 {
    margin: 0 0 6px;
    font-size: 30px;
    letter-spacing: -0.015em;
}

h2 {
    margin: 44px 0 14px;
    padding-bottom: 8px;
    font-size: 21px;
    border-bottom: 2px solid var(--rule);
    letter-spacing: -0.01em;
}

h3 {
    margin: 30px 0 10px;
    font-size: 16.5px;
    color: var(--accent);
}

p { margin: 0 0 14px; }

a { color: var(--accent); text-decoration: none; border-bottom: 1px solid rgba(11,110,143,.28); }
a:hover { border-bottom-color: var(--accent); }

ul, ol { margin: 0 0 14px; padding-left: 24px; }
li { margin-bottom: 6px; }
li > ul, li > ol { margin-top: 6px; }

strong { font-weight: 650; }

hr { border: 0; border-top: 1px solid var(--rule); margin: 36px 0; }

/* ---------- the masthead ---------- */

.masthead {
    margin-bottom: 30px;
    padding-bottom: 20px;
    border-bottom: 3px solid var(--ink);
}
.masthead .kicker {
    font-size: 11px;
    letter-spacing: .13em;
    text-transform: uppercase;
    color: var(--faint);
    margin-bottom: 10px;
}
.masthead .meta {
    margin-top: 10px;
    font-size: 13px;
    color: var(--muted);
}

/* ---------- code ---------- */

code, pre, kbd {
    font-family: ui-monospace, SFMono-Regular, "SF Mono", Menlo, Consolas, "Liberation Mono", monospace;
}

:not(pre) > code {
    background: var(--code-bg);
    border: 1px solid var(--rule);
    border-radius: 4px;
    padding: 1px 5px;
    font-size: .875em;
    white-space: nowrap;
}

pre {
    background: var(--code-bg);
    border: 1px solid var(--rule);
    border-left: 3px solid var(--accent);
    border-radius: 5px;
    padding: 14px 16px;
    overflow-x: auto;
    margin: 0 0 16px;
    font-size: 13px;
    line-height: 1.55;
}
pre code {
    background: none;
    border: 0;
    padding: 0;
    font-size: inherit;
    white-space: pre;
    color: var(--code-ink);
}

/* ---------- tables ---------- */

table {
    width: 100%;
    border-collapse: collapse;
    margin: 0 0 18px;
    font-size: 14px;
}
th, td {
    text-align: left;
    vertical-align: top;
    padding: 8px 11px;
    border: 1px solid var(--rule);
}
th {
    background: var(--accent-soft);
    font-weight: 650;
    color: var(--ink);
}
tbody tr:nth-child(even) { background: #fafbfc; }
td code, th code { white-space: normal; }

/* ---------- blockquote callouts ---------- */

blockquote {
    margin: 0 0 18px;
    padding: 14px 18px;
    background: var(--warn-bg);
    border: 1px solid var(--warn-rule);
    border-left: 4px solid var(--warn-rule);
    border-radius: 5px;
}
blockquote > :last-child { margin-bottom: 0; }
blockquote h3 {
    margin: 0 0 8px;
    color: #8a5b00;
    font-size: 15px;
}

/* ---------- print ---------- */

@page {
    size: A4;
    margin: 16mm 14mm 18mm;
}

@media print {
    body { background: #fff; font-size: 10.5pt; line-height: 1.5; }

    .sheet {
        max-width: none;
        margin: 0;
        padding: 0;
        border: 0;
        border-radius: 0;
    }

    a { color: var(--ink); border-bottom: 0; }

    /* Deliberately NO css grid anywhere: a grid container will not paginate,
       and everything past page one silently disappears. */

    h1, h2, h3 { break-after: avoid-page; page-break-after: avoid; }
    h2 { margin-top: 26px; font-size: 15pt; }
    h3 { margin-top: 18px; font-size: 11.5pt; }

    pre, blockquote, table { break-inside: avoid-page; page-break-inside: avoid; }
    tr, li { break-inside: avoid-page; page-break-inside: avoid; }

    pre { font-size: 8.6pt; background: #f6f8fa !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    th { background: var(--accent-soft) !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    blockquote { background: var(--warn-bg) !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    tbody tr:nth-child(even) { background: #fafbfc !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }

    table { font-size: 9pt; }
    :not(pre) > code { font-size: .86em; background: #f6f8fa !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }

    /* A long code line must wrap on paper -- there is no horizontal scroll. */
    pre code { white-space: pre-wrap; word-break: break-word; }
}
CSS;

$html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$title}</title>
<style>
{$css}
</style>
</head>
<body>
<div class="sheet">
<div class="masthead">
  <div class="kicker">Developer reference</div>
  <div class="meta">Generated {$generated} &middot; package version {$version}</div>
</div>
{$body}
</div>
</body>
</html>
HTML;

file_put_contents($out, $html);

echo "wrote {$out}  (".number_format(strlen($html))." bytes)\n";

/* A quick integrity check: every in-page anchor must have a target. */
preg_match_all('/href="#([^"]+)"/', $html, $links);
preg_match_all('/ id="([^"]+)"/', $html, $ids);

$missing = array_diff(array_unique($links[1]), $ids[1]);

echo 'anchors: '.count(array_unique($links[1])).' links, '.count($ids[1])." targets\n";
echo $missing === []
    ? "all in-page links resolve\n"
    : 'BROKEN LINKS: '.implode(', ', $missing)."\n";
