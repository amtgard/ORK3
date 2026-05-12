-- Digital Waivers: A/B test the builder UX.
-- Variant 'a' = Trix WYSIWYG (HTML). Variant 'b' = Markdown editor with GH-style toolbar.
-- Both variants render the SAME *_html at sign time; B additionally stores the raw
-- markdown source in *_markdown so the editor can round-trip it.
-- The "one active row" rule becomes per (kingdom, scope, variant) so the two variants
-- can be authored side-by-side without overwriting each other.

ALTER TABLE ork_waiver_template
  ADD COLUMN variant         enum('a','b') NOT NULL DEFAULT 'a' AFTER scope,
  ADD COLUMN header_markdown mediumtext    NULL                 AFTER header_html,
  ADD COLUMN body_markdown   mediumtext    NULL                 AFTER body_html,
  ADD COLUMN footer_markdown mediumtext    NULL                 AFTER footer_html,
  ADD COLUMN minor_markdown  mediumtext    NULL                 AFTER minor_html;
