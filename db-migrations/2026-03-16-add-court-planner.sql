-- Court Planner: planned royal courts
CREATE TABLE IF NOT EXISTS ork_court (
    court_id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    kingdom_id                INT UNSIGNED NOT NULL DEFAULT 0,
    park_id                   INT UNSIGNED NOT NULL DEFAULT 0,  -- 0 = kingdom court
    name                      VARCHAR(100) NOT NULL DEFAULT '',
    court_date                DATE NULL,
    event_calendardetail_id   INT UNSIGNED NULL,               -- optional link to an event occurrence
    status                    ENUM('draft','published','complete') NOT NULL DEFAULT 'draft',
    created_by                INT UNSIGNED NOT NULL DEFAULT 0,
    modified                  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (court_id),
    KEY idx_kingdom (kingdom_id),
    KEY idx_park (park_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- Awards planned for a court
CREATE TABLE IF NOT EXISTS ork_court_award (
    court_award_id      INT UNSIGNED NOT NULL AUTO_INCREMENT,
    court_id            INT UNSIGNED NOT NULL,
    mundane_id          INT UNSIGNED NOT NULL DEFAULT 0,        -- recipient
    kingdomaward_id     INT UNSIGNED NOT NULL DEFAULT 0,
    rank                INT NOT NULL DEFAULT 0,
    recommendations_id  INT UNSIGNED NULL,                     -- source recommendation if applicable
    sort_order          INT NOT NULL DEFAULT 0,
    pass_to_local       TINYINT NOT NULL DEFAULT 0,            -- kingdom approved, park to give
    notes               TEXT,                                  -- internal monarchy notes
    status              ENUM('planned','announced','given','cancelled') NOT NULL DEFAULT 'planned',
    modified            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (court_award_id),
    KEY idx_court (court_id),
    KEY idx_mundane (mundane_id),
    KEY idx_rec (recommendations_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- Contributing artisans per court award
CREATE TABLE IF NOT EXISTS ork_court_award_artisan (
    court_award_artisan_id  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    court_award_id          INT UNSIGNED NOT NULL,
    mundane_id              INT UNSIGNED NOT NULL DEFAULT 0,
    contribution            VARCHAR(255) NOT NULL DEFAULT '',
    modified                TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (court_award_artisan_id),
    KEY idx_court_award (court_award_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
