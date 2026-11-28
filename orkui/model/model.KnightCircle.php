<?php

/**
 * Thin pass-through to the KnightCircle and KnightCandidate domain classes
 * (system/lib/ork3/class.KnightCircle.php, class.KnightCandidate.php): the
 * Circle of Knights roster, settings and officers, and the candidate pipeline.
 *
 * No SQL and no business logic here; every method forwards unchanged. The
 * domain takes the acting user's id, which the controller reads from the
 * session.
 */
class Model_KnightCircle extends Model
{
    // --- KnightCircle -------------------------------------------------------

    public function access(int $uid, int $kingdomId): array
    {
        return $this->_circle()->access($uid, $kingdomId);
    }

    public function config(int $kingdomId): array
    {
        return $this->_circle()->config($kingdomId);
    }

    public function save_config(int $uid, int $kingdomId, array $fields): array
    {
        return $this->_circle()->saveConfig($uid, $kingdomId, $fields);
    }

    public function admins(int $uid, int $kingdomId): array
    {
        return $this->_circle()->admins($uid, $kingdomId);
    }

    public function set_gmok(int $uid, int $kingdomId, int $mundaneId): array
    {
        return $this->_circle()->setGmok($uid, $kingdomId, $mundaneId);
    }

    public function add_deputy(int $uid, int $kingdomId, int $mundaneId): array
    {
        return $this->_circle()->addDeputy($uid, $kingdomId, $mundaneId);
    }

    public function remove_admin(int $uid, int $kingdomId, int $circleAdminId): array
    {
        return $this->_circle()->removeAdmin($uid, $kingdomId, $circleAdminId);
    }

    public function roster(int $uid, int $kingdomId, int $orderAwardId = 0): array
    {
        return $this->_circle()->roster($uid, $kingdomId, $orderAwardId);
    }

    // --- KnightCandidate ----------------------------------------------------

    public function board(int $uid, int $kingdomId): array
    {
        return $this->_candidate()->board($uid, $kingdomId);
    }

    public function candidate(int $uid, int $candidateId): array
    {
        return $this->_candidate()->candidate($uid, $candidateId);
    }

    public function add_candidate(int $uid, int $kingdomId, int $mundaneId, int $orderAwardId, string $comment = ''): array
    {
        return $this->_candidate()->addCandidate($uid, $kingdomId, $mundaneId, $orderAwardId, $comment);
    }

    public function move_candidate(int $uid, int $candidateId, string $toStage, string $comment = '', ?array $votes = null): array
    {
        return $this->_candidate()->moveCandidate($uid, $candidateId, $toStage, $comment, $votes);
    }

    public function add_note(int $uid, int $candidateId, string $body): array
    {
        return $this->_candidate()->addNote($uid, $candidateId, $body);
    }

    public function delete_note(int $uid, int $noteId): array
    {
        return $this->_candidate()->deleteNote($uid, $noteId);
    }

    private function _circle(): KnightCircle
    {
        return new KnightCircle();
    }

    private function _candidate(): KnightCandidate
    {
        return new KnightCandidate();
    }
}
