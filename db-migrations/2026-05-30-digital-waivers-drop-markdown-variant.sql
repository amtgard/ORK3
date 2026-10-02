-- Digital Waivers: drop the A/B Markdown variant.
-- The Trix (HTML) builder is now the single standard. The `variant` column and the
-- raw `*_markdown` source columns were only used by the removed Markdown variant.
-- The 2026-05-11 AB migration notes no production waivers existed; nothing of value is lost.

ALTER TABLE ork_waiver_template
  DROP COLUMN variant,
  DROP COLUMN header_markdown,
  DROP COLUMN body_markdown,
  DROP COLUMN footer_markdown,
  DROP COLUMN minor_markdown;
