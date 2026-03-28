-- Feature 4: Scroll/regalia maker assignment
ALTER TABLE ork_court_award
    ADD COLUMN scroll_maker_id  INT UNSIGNED NULL DEFAULT NULL,
    ADD COLUMN regalia_maker_id INT UNSIGNED NULL DEFAULT NULL;

-- Feature 9: Award context preservation
ALTER TABLE ork_awards
    ADD COLUMN court_award_id  INT UNSIGNED NULL DEFAULT NULL,
    ADD COLUMN source_reason   VARCHAR(400) NULL DEFAULT NULL;

-- Feature 11: Recommendation seconding
CREATE TABLE IF NOT EXISTS ork_recommendation_support (
    support_id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    recommendations_id  INT UNSIGNED NOT NULL,
    mundane_id          INT UNSIGNED NOT NULL,
    date_added          DATE NOT NULL,
    PRIMARY KEY (support_id),
    UNIQUE KEY idx_unique_support (recommendations_id, mundane_id),
    KEY idx_rec (recommendations_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
