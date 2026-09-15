-- Digital Waivers: switch storage from markdown to HTML (Trix output).
-- No production waivers exist yet, so a destructive rename is safe.

ALTER TABLE ork_waiver_template
  CHANGE COLUMN header_markdown header_html text       NOT NULL,
  CHANGE COLUMN body_markdown   body_html   mediumtext NOT NULL,
  CHANGE COLUMN footer_markdown footer_html text       NOT NULL,
  CHANGE COLUMN minor_markdown  minor_html  text       NOT NULL;
