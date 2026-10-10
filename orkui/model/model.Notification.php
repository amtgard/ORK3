<?php

/**
 * Thin pass-through to the Notification domain class
 * (system/lib/ork3/class.Notification.php).
 *
 * Controllers must not reach into Ork3::$Lib->notification directly; the
 * notification bell's dismiss endpoints go through this model. No SQL and no
 * business logic belongs here -- each method forwards its arguments unchanged.
 */
class Model_Notification extends Model
{
    public function dismiss($notification_id, $mundane_id)
    {
        return $this->_notification()->Dismiss((int)$notification_id, (int)$mundane_id);
    }

    public function dismiss_all($mundane_id)
    {
        return $this->_notification()->DismissAll((int)$mundane_id);
    }

    private function _notification(): Notification
    {
        return new Notification();
    }
}
