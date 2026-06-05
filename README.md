# HTML → Markdown Site Export

A WordPress plugin that crawls the **sitemap** and saves every page as a
**Markdown file**, complete with image links and SEO metadata parsed from
the rendered `<head>`.

Content is taken from the **rendered front-end HTML** of each page (an HTTP
request to the public URL), not from `post_content`. This ensures that
**ACF fields**, **hard-coded template text**, and **Gutenberg blocks** are all
captured exactly as the visitor sees them.

---

## Features

- Auto-detects the sitemap (`wp-sitemap.xml`, `sitemap_index.xml`, `sitemap.xml`); recursively follows sitemap indexes.
- Strips `<header>`, `<footer>`, and `<nav>` — only the page body content is converted.
- Converts HTML → Markdown via [`league/html-to-markdown`](https://github.com/thephpleague/html-to-markdown): headings, lists, links, images, bold/italic, blockquotes, code, tables.
- Resolves relative image/link URLs to absolute; recovers `data-src` for lazy-loaded images; normalises `../` path segments.
- **YAML front matter** on every file: `title`, `description`, `keywords`, `canonical`, `robots`, `lang`, all `og:*` and `twitter:*` tags, an `images[]` list, and the raw JSON-LD `schema`.
- File paths mirror the URL structure: `/about/team/` → `about/team.md`, site root → `index.md`.
- Root `index.md` — a Markdown sitemap linking to every exported page.
- Batch export via AJAX with a progress bar — no PHP timeouts on large sites.

---

## Requirements

- WordPress 5.5+
- PHP 7.4+
- Composer (for local development; `vendor/` is committed for production use)

---

## Installation

1. Copy or clone the plugin folder into `wp-content/plugins/html-to-markdown/`.
2. If `vendor/` is absent, run:
   ```bash
   composer install --no-dev
   ```
3. Activate the plugin in **wp-admin → Plugins**.

---

## Usage

1. Go to **Tools → HTML → Markdown** in wp-admin.
2. Adjust settings if needed and click **Save Settings**.
3. Click **"1. Зібрати карту сайту"** (Build sitemap) — the plugin crawls the sitemap and queues all URLs.
4. Click **"2. Експортувати"** (Export) — pages are fetched and converted in batches; a progress bar and per-page log are shown.
5. Files appear in `wp-content/uploads/markdown-export/` (default).

---

## Settings

| Setting | Default | Description |
|---------|---------|-------------|
| **Sitemap URL** | *(auto-detect)* | Leave blank to try `wp-sitemap.xml` → `sitemap_index.xml` → `sitemap.xml` automatically. |
| **Export folder** | `uploads/markdown-export` | Must remain inside `wp-content/uploads/`. |
| **Batch size** | `5` | Pages per AJAX request (1–50). Lower if you hit memory limits. |
| **Content selector** | `body` | CSS-style selector for the content container. Narrow it to avoid sidebars: `main`, `#content`, `.entry-content`. |
| **Base URL override** | *(home_url)* | Override the host used for loopback requests — useful when the local hostname differs inside the server process. |
| **Basic Auth** | *(none)* | Username / password for password-protected staging environments. |

---

## Output format

Each `.md` file starts with YAML front matter followed by the page content:

```markdown
---
url: "https://example.com/about/"
slug: "about"
title: "About Us — Example"
description: "We build things."
canonical: "https://example.com/about/"
lang: "en"
og:
  og:title: "About Us"
  og:image: "https://example.com/og.jpg"
images:
  - "https://example.com/uploads/team.jpg"
schema: "[{\"@type\":\"Organization\",\"name\":\"Example\"}]"
exported_at: "2026-06-05 12:00:00"
---

# About Us

Our story starts here…
```

The root `index.md` lists all exported pages:

```markdown
# Карта сайту

> Згенеровано 2026-06-05 12:00:00 — 42 сторінок.

- [About Us](about.md) — <https://example.com/about/>
- [Contact](contact.md) — <https://example.com/contact/>
```

---

## Limitations

- **Sliders / carousels** — Markdown has no carousel equivalent; slide text is output sequentially as plain blocks.
- **Client-rendered content** — only server-rendered HTML is captured. Content injected by JavaScript after page load is not included.

---

## Development

```bash
# Install dependencies
composer install

# Lint PHP files
find . -name "*.php" -not -path "./vendor/*" | xargs php -l
```

Dependencies are managed via Composer. The `vendor/` directory is committed so the plugin works after a straight file copy with no build step required.
