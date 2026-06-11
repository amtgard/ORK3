-- Generic in-app notification store. v1 writers: recommendation-granted
-- notifications to recommenders + seconders (see class.Notification.php).
CREATE TABLE IF NOT EXISTS ork_notification (
    notification_id  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    mundane_id       INT UNSIGNED NOT NULL,            -- who sees this notification
    type             VARCHAR(40)  NOT NULL,            -- 'rec_granted' | 'second_granted'
    message          VARCHAR(400) NOT NULL,            -- rendered sentence (denormalized)
    link             VARCHAR(255) NULL DEFAULT NULL,   -- where clicking navigates
    read_at          TIMESTAMP NULL DEFAULT NULL,
    dismissed_at     TIMESTAMP NULL DEFAULT NULL,
    created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (notification_id),
    KEY idx_user_active (mundane_id, dismissed_at, read_at, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
