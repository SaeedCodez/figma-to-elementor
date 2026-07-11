# Figma → Elementor design-token sync (offline)

Two independent plugins that move a Figma design system's **colors** and **typography**
into Elementor's **Global Colors** and **Global Fonts**. There is **no network connection**
between them — the Figma plugin produces a JSON string you copy, and the WordPress plugin
accepts that JSON pasted into an admin field.

```
Figma plugin  →  extract variables + styles  →  JSON string  →  "Copy JSON"
                                                                    │  (you copy)
                                                                    ▼
WP admin page →  paste JSON  →  "Preview"  →  visual demo  →  "Confirm & Import"
                                                                    │
                                                                    ▼
                                        write to Elementor active kit + rebuild CSS
```

## 1. Figma plugin (`figma-plugin/`)

Extracts local variables (colors, font families, weights, sizes) and local text styles,
resolves alias chains, and emits the copy-paste payload.

**Install (development):**
1. Figma desktop app → **Plugins → Development → Import plugin from manifest…**
2. Choose `figma-plugin/manifest.json`.
3. Open your design file and run **Figma → Elementor Token Export**.
4. Click **Copy JSON**.

The manifest declares no `networkAccess` — the plugin never makes a request.

### JSON contract

```json
{
  "version": 1,
  "source": "figma-niikak",
  "generatedAt": "2026-07-11T00:00:00.000Z",
  "systemColors": { "primary": "#38add1", "secondary": "#fd797b", "accent": "#feab28", "text": "#273645" },
  "customColors": [ { "name": "primary/50", "hex": "#eff8fb" }, { "name": "primary/500", "hex": "#38add1" } ],
  "typography": {
    "titleFamily": "Shazde",
    "bodyFamily": "IRANYekanX",
    "weights": { "300": 300, "400": 400, "500": 500, "600": 600, "700": 700, "800": 800, "900": 900 },
    "sizes":   { "xs": 12, "sm": 14, "base": 16, "lg": 18, "xl": 20, "2xl": 24, "3xl": 30, "4xl": 36, "5xl": 48 }
  }
}
```

`weights` and `sizes` are optional extras; the WordPress importer ignores them for now but
does not choke on them.

**System-color mapping (with fallbacks):**

| Slot      | Source variable          | Fallback        |
|-----------|--------------------------|-----------------|
| primary   | `seed/primary`           | `primary/500`   |
| secondary | `seed/secondary`         | `secondary/500` |
| accent    | `seed/accent`            | `accent/500`    |
| text      | `semantic/black-ink`     | `ink/900`       |

## 2. WordPress plugin (`wordpress-plugin/figma-token-import/`)

Adds **Settings → Figma Token Import**. Paste JSON → **Preview** (visual demo of colors and
both fonts) → **Confirm & Import**. Nothing is written to the database before you confirm.

**Install:**
1. Copy the `figma-token-import` folder into `wp-content/plugins/`.
2. Activate **Figma Token Import** in Plugins.
3. Requires Elementor active and an existing active kit.

**What Confirm writes to the active kit** (`_elementor_page_settings`):
- `system_colors` — four slots: Primary, Secondary, Text, Accent.
- `custom_colors` — the full palette, **merged by title** (re-imports update in place, no duplicates).
- `system_typography` — Primary → title/700, Secondary → title/600, Text → body/400, Accent → body/500.

Then Elementor CSS is rebuilt via `files_manager->clear_cache()`.

### Notes & assumptions
- **Fonts are set by name only.** `IRANYekanX` / `Shazde` are custom Persian fonts, not Google
  Fonts — upload the actual font files separately (Elementor Custom Fonts or a font plugin).
- **Family-name mismatch** (`font/family` = `IRANYekanX` vs. text styles using `IRANYekanXFaNum`)
  is exposed as editable Title/Body family fields on the preview screen — correct them before importing.
- Targets the **Classic / Editor V3** kit structure. If the **Editor V4 (atomic)** experiment is
  enabled the structure differs; the preview still renders but results may not appear as expected.
- Security: nonce + `manage_options` on import, hex normalised to `#rrggbb`, font/title names run
  through `sanitize_text_field`, all preview/admin output escaped.

### Internationalization (i18n)

The WordPress plugin is fully translatable (text domain **`figma-token-import`**). Every
admin-facing string — including the ones rendered by the preview JavaScript — is passed through
WordPress translation functions, so a single `.mo` file localises the whole UI (no separate JS
translation JSON is required).

```
wordpress-plugin/figma-token-import/languages/
├── figma-token-import.pot            # translation template (all source strings)
├── figma-token-import-fa_IR.po       # Persian (Iran) source
└── figma-token-import-fa_IR.mo       # Persian (Iran) compiled — loaded at runtime
```

A complete **Persian (`fa_IR`)** translation is included. When the site locale is `fa_IR`
(Settings → General → Site Language), the whole admin page renders in Persian and RTL; the
paste field, hex swatches, and font names stay left-to-right where that is correct.

To add another language, copy `figma-token-import.pot` to
`figma-token-import-{locale}.po`, translate the `msgstr` lines, and compile it to `.mo`
(`msgfmt figma-token-import-{locale}.po -o figma-token-import-{locale}.mo`, or Loco Translate /
Poedit). The plugin loads translations from this `languages/` folder via
`load_plugin_textdomain()`.

## Testing without Figma

Paste `sample-payload.json` (in this folder) into the WordPress preview screen to exercise the
full preview → confirm flow with representative token data.
