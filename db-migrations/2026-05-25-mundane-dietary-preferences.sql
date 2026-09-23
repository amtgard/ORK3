-- Per-player feast dietary preferences. One row per mundane; upserted on save.
-- is_anonymous=1 (default): organizers see only aggregate counts.
-- is_anonymous=0: organizers see this player by name in the feast summary.
-- no_restrictions=1: player explicitly confirmed no concerns; distinguishes from never having set preferences.
-- Diet and restriction columns are binary (0/1).
-- Allergen columns are 3-state: 0=OK, 1=Mild, 2=Severe.
CREATE TABLE IF NOT EXISTS `ork_mundane_dietary` (
    `mundane_id`         INT(11)   NOT NULL,
    `is_anonymous`       TINYINT(1) NOT NULL DEFAULT 1,
    `no_restrictions`    TINYINT(1) NOT NULL DEFAULT 0,
    -- Diet (lifestyle / religious)
    `diet_vegetarian`    TINYINT(1) NOT NULL DEFAULT 0,
    `diet_vegan`         TINYINT(1) NOT NULL DEFAULT 0,
    `diet_halal`         TINYINT(1) NOT NULL DEFAULT 0,
    `diet_kosher`        TINYINT(1) NOT NULL DEFAULT 0,
    `diet_keto`          TINYINT(1) NOT NULL DEFAULT 0,
    `diet_paleo`         TINYINT(1) NOT NULL DEFAULT 0,
    -- Restrictions (won't eat)
    `restrict_dairy`     TINYINT(1) NOT NULL DEFAULT 0,
    `restrict_eggs`      TINYINT(1) NOT NULL DEFAULT 0,
    `restrict_fish`      TINYINT(1) NOT NULL DEFAULT 0,
    `restrict_honey`     TINYINT(1) NOT NULL DEFAULT 0,
    `restrict_poultry`   TINYINT(1) NOT NULL DEFAULT 0,
    `restrict_beef`      TINYINT(1) NOT NULL DEFAULT 0,
    `restrict_pork`      TINYINT(1) NOT NULL DEFAULT 0,
    `restrict_shellfish` TINYINT(1) NOT NULL DEFAULT 0,
    -- Allergens (0=OK, 1=Mild, 2=Severe)
    `allergen_milk`      TINYINT(1) NOT NULL DEFAULT 0,
    `allergen_eggs`      TINYINT(1) NOT NULL DEFAULT 0,
    `allergen_fish`      TINYINT(1) NOT NULL DEFAULT 0,
    `allergen_shellfish` TINYINT(1) NOT NULL DEFAULT 0,
    `allergen_treenuts`  TINYINT(1) NOT NULL DEFAULT 0,
    `allergen_peanuts`   TINYINT(1) NOT NULL DEFAULT 0,
    `allergen_wheat`     TINYINT(1) NOT NULL DEFAULT 0,
    `allergen_soy`       TINYINT(1) NOT NULL DEFAULT 0,
    `allergen_sesame`    TINYINT(1) NOT NULL DEFAULT 0,
    `allergen_garlic`    TINYINT(1) NOT NULL DEFAULT 0,
    `allergen_gluten`    TINYINT(1) NOT NULL DEFAULT 0,
    `allergen_onion`     TINYINT(1) NOT NULL DEFAULT 0,
    `allergen_mushroom`  TINYINT(1) NOT NULL DEFAULT 0,
    `allergen_corn`      TINYINT(1) NOT NULL DEFAULT 0,
    `allergen_coconut`   TINYINT(1) NOT NULL DEFAULT 0,
    `allergen_cocoa`      TINYINT(1) NOT NULL DEFAULT 0,
    `allergen_nightshades` TINYINT(1) NOT NULL DEFAULT 0,
    `modified`           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`mundane_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Idempotent guard for installs where the table already existed before allergen_gluten was added.
ALTER TABLE `ork_mundane_dietary`
    ADD COLUMN IF NOT EXISTS `allergen_gluten`      TINYINT(1) NOT NULL DEFAULT 0 AFTER `allergen_garlic`;
ALTER TABLE `ork_mundane_dietary`
    ADD COLUMN IF NOT EXISTS `allergen_nightshades` TINYINT(1) NOT NULL DEFAULT 0 AFTER `allergen_cocoa`;
