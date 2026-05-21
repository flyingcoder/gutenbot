# GutenBot — Prompt Construction

This document explains exactly how GutenBot assembles the prompt it sends to the AI on every page generation request. Understanding this is useful when debugging unexpected output, writing effective custom rules, or extending the plugin.

---

## Overview

Every generation request goes through the same pipeline:

```
Raw content (pasted or uploaded)
    ↓  GutenBot_Document_Processor::parse_txt()
Cleaned plain text
    ↓  GutenBot_Indexer (DB queries)
Site context (layouts, sections, styles)
    ↓  GutenBot_AI_Client::build_prompt()
Final prompt string
    ↓  Anthropic Messages API  /  Ollama /api/chat
JSON page plan  →  block markup
```

---

## Stage 1 — Content Cleaning

**Class:** `GutenBot_Document_Processor` (`includes/class-document-processor.php`)

Before anything reaches the AI the raw input is normalized:

| Input type | What happens |
|---|---|
| `.txt` / pasted text | Line endings normalized (`\r\n` → `\n`), 3+ blank lines collapsed to 2, trailing whitespace trimmed per line |
| `.md` | All of the above, plus Markdown syntax stripped: headings (`#`), bold/italic (`**`, `_`), inline code, fenced code blocks, links, images, blockquotes, horizontal rules |

The result is clean, dense plain text — no markup noise that would confuse the model.

---

## Stage 2 — Site Context Gathering

**Class:** `GutenBot_Indexer` (`includes/class-indexer.php`)

Three types of context are fetched from the plugin's DB tables before the prompt is built.

### 2a. Similar Layouts (`wp_gutenbot_layout_index`)

Up to 3 published pages whose `page_type` matches the target type are retrieved. Each record contains:

| Field | Description |
|---|---|
| `id` | Layout ID — AI can reference this as `layout_source` |
| `page_type` | `service`, `location`, `guide`, or `general` |
| `template_slug` | WordPress page template used |
| `block_structure` | JSON tree of block names and nesting depth |
| `section_order` | Ordered array of semantic section labels (e.g. `["hero","columns","cta"]`) |

These teach the AI what real pages on the site look like structurally, so generated pages feel consistent with existing content.

### 2b. Theme Style Summary (`wp_gutenbot_style_index`)

Scraped from the active theme's `theme.json`. Keys passed to the AI:

- `color.palette` — registered named colors
- `typography.fontSizes` — named font size scale
- `typography.fontFamilies` — registered font families
- `spacing.spacingSizes` — spacing scale
- `layout` — content/wide width settings

This lets the AI suggest section and block choices that will visually fit the theme without custom CSS.

### 2c. Reusable Sections (`wp_gutenbot_section_index`)

Indexed block markup keyed by section type (`hero`, `cta`, `columns`, etc.). These are looked up **after** the AI returns its plan — each section name in `plan.sections` is resolved to a real block markup snippet from the index before the final page is assembled. They are passed to the prompt as `reusable_sections` so the AI knows what section types are actually available on the site.

---

## Stage 3 — Prompt Assembly

**Method:** `GutenBot_AI_Client::build_prompt()` (`includes/class-ai-client.php`)

The prompt is built from four parts joined in this order:

```
[System role sentence]

Context:
[Site context JSON]

[Custom rules block — only present if rules exist]

[Output schema and hard constraints]
```

### Part 1 — System Role

```
You are a WordPress page planner. Given the following document content and site context, produce a JSON page plan.
```

A single sentence. The model is told exactly what it is and what it must produce.

### Part 2 — Context JSON

The following keys are JSON-encoded with `JSON_PRETTY_PRINT` and injected verbatim:

```json
{
  "document_content": "<cleaned plain text from the uploaded/pasted document>",
  "similar_layouts": [
    {
      "id": 12,
      "page_type": "service",
      "template_slug": "default",
      "block_structure": [
        { "name": "core/cover", "depth": 0 },
        { "name": "core/columns", "depth": 0 },
        { "name": "core/buttons", "depth": 0 }
      ],
      "section_order": ["hero", "columns", "cta"]
    }
  ],
  "reusable_sections": [],
  "theme_style_summary": {
    "color.palette": [
      { "slug": "primary", "color": "#1a4fa0" },
      { "slug": "accent",  "color": "#f5a623" }
    ],
    "typography.fontSizes": [
      { "slug": "small", "size": "14px" },
      { "slug": "large", "size": "24px" }
    ],
    "layout": { "contentSize": "840px", "wideSize": "1200px" }
  }
}
```

`admin_rules` is intentionally excluded from this JSON block — it is rendered as a separate, more prominent section (see Part 3) so the model treats it with higher weight.

### Part 3 — Custom Rules Block (conditional)

Only appended when at least one custom rule exists in `wp_gutenbot_rules`. Format:

```
Site-specific instructions (must be followed):
1. Always write in a friendly, conversational tone. Avoid jargon.
2. Every page must include a CTA section as the last section.
3. Use the brand name "Acme Services" — never "Acme" alone.
```

Rules are numbered and the heading uses **"must be followed"** to maximize model compliance. This phrasing is deliberate — models treat explicitly labelled mandatory instructions with higher weight than context embedded inside JSON.

Rules are managed under **GutenBot → Settings → Custom Rules & Instructions**.

### Part 4 — Output Schema and Hard Constraints

```
Respond ONLY with valid JSON matching this schema:
{
  "page_type": "service|location|guide|general",
  "title": "string",
  "sections": ["hero","intro","benefits","process","faq","cta"],
  "layout_source": null
}

Rules:
- "page_type" must be one of: service, location, guide, general
- "sections" must be an ordered array of section names
- "layout_source" is an integer ID from similar_layouts or null
- Do NOT include any text outside the JSON object
```

This section defines the exact JSON schema the response must match and explicitly forbids prose, markdown, or any text outside the JSON object.

---

## Stage 4 — Response Parsing

**Method:** `GutenBot_AI_Client::parse_plan_json()` (`includes/class-ai-client.php`)

After the AI responds, the plugin:

1. Strips any ` ```json ` / ` ``` ` code fences (some models wrap output in these even when told not to)
2. JSON-decodes the result
3. Validates that `page_type`, `title`, and `sections` are all present
4. Validates that `sections` is a non-empty array
5. Sanitizes all string values with `sanitize_text_field`

If any step fails, a specific error message is set on the client and surfaced directly to the user — no generic "something went wrong" messages.

---

## Complete Prompt Example

```
You are a WordPress page planner. Given the following document content and site context, produce a JSON page plan.

Context:
{
  "document_content": "Roof Replacement in Dallas\n\nWe replace asphalt, metal, and tile roofs across the Dallas metro.\nFree estimates. Licensed and insured. 20-year workmanship warranty.\n\nBenefits:\n- Same-day inspection\n- No-mess cleanup\n- Financing available",
  "similar_layouts": [
    {
      "id": 7,
      "page_type": "service",
      "template_slug": "default",
      "block_structure": [
        { "name": "core/cover",   "depth": 0 },
        { "name": "core/columns", "depth": 0 },
        { "name": "core/buttons", "depth": 0 }
      ],
      "section_order": ["hero", "columns", "cta"]
    }
  ],
  "reusable_sections": [],
  "theme_style_summary": {
    "color.palette": [
      { "slug": "primary", "color": "#1a4fa0" },
      { "slug": "accent",  "color": "#f5a623" }
    ]
  }
}

Site-specific instructions (must be followed):
1. Always end with a CTA section offering a free estimate.
2. Use a friendly, non-technical tone.

Respond ONLY with valid JSON matching this schema:
{
  "page_type": "service|location|guide|general",
  "title": "string",
  "sections": ["hero","intro","benefits","process","faq","cta"],
  "layout_source": null
}

Rules:
- "page_type" must be one of: service, location, guide, general
- "sections" must be an ordered array of section names
- "layout_source" is an integer ID from similar_layouts or null
- Do NOT include any text outside the JSON object
```

Expected response:

```json
{
  "page_type": "service",
  "title": "Roof Replacement Dallas",
  "sections": ["hero", "benefits", "process", "cta"],
  "layout_source": 7
}
```

---

## Writing Effective Custom Rules

Custom rules are the most direct way to shape output. Tips:

- **Be specific.** "Always include a FAQ section" works better than "make it complete."
- **Name things explicitly.** "Use the brand name 'Acme Roofing'" is more reliable than "use the correct brand name."
- **Constrain sections.** "Every page must end with a section named `cta`" directly shapes the `sections` array the AI returns.
- **Avoid contradictions.** If two rules conflict, model behaviour becomes unpredictable.
- **One concern per rule.** Short, single-purpose rules are more reliably followed than long compound instructions.

Rules are stored in `wp_gutenbot_rules` (`rule_key`, `rule_value`) and fetched fresh on every generation request — no cache to clear after saving.

---

## Source References

| Concern | File | Key method |
|---|---|---|
| Content cleaning | `includes/class-document-processor.php` | `parse_txt()`, `parse_md()` |
| Layout / section indexing | `includes/class-indexer.php` | `get_similar_layouts()`, `get_style_summary()` |
| Prompt assembly | `includes/class-ai-client.php` | `build_prompt()` |
| Response parsing | `includes/class-ai-client.php` | `parse_plan_json()` |
| Streaming — Anthropic | `includes/class-ai-client.php` | `stream_anthropic()` |
| Streaming — Ollama | `includes/class-ai-client.php` | `stream_ollama()` |
| AJAX SSE handler | `includes/class-stream-controller.php` | `handle()` |
