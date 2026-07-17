<?php

class Model_QualTest extends Model
{
    public function can_manage(int $mundaneId, int $kingdomId): bool
    {
        return $this->_qual_test()->canManage($mundaneId, $kingdomId);
    }

    public function has_takeable_version(int $kingdomId, string $testType): bool
    {
        return $this->_qual_test()->hasTakeableVersion($kingdomId, $testType);
    }

    public function player_results(int $mundaneId, int $kingdomId): array
    {
        return $this->_qual_test()->getPlayerResults($mundaneId, $kingdomId);
    }

    public function config(int $kingdomId, string $testType): array
    {
        return $this->_qual_test()->getConfig($kingdomId, $testType);
    }

    public function test_results(int $kingdomId, string $testType): array
    {
        return $this->_qual_test()->getTestResults($kingdomId, $testType);
    }

    public function report_stats(int $kingdomId, string $testType): array
    {
        return $this->_qual_test()->getTestReportStats($kingdomId, $testType);
    }

    public function kingdom_name(int $kingdomId): string
    {
        return $this->_qual_test()->getKingdomName($kingdomId);
    }

    public function managers(int $kingdomId): array
    {
        return $this->_qual_test()->getManagers($kingdomId);
    }

    public function count_active_questions(int $kingdomId, string $testType): int
    {
        return $this->_qual_test()->countActiveQuestions($kingdomId, $testType);
    }

    public function published_set(int $kingdomId, string $testType)
    {
        return $this->_qual_test()->getPublishedSet($kingdomId, $testType);
    }

    public function draft_set(int $kingdomId, string $testType)
    {
        return $this->_qual_test()->getDraftSet($kingdomId, $testType);
    }

    public function all_questions(int $kingdomId, string $testType): array
    {
        return $this->_qual_test()->getAllQuestions($kingdomId, $testType);
    }

    public function sets(int $kingdomId, string $testType): array
    {
        return $this->_qual_test()->getSets($kingdomId, $testType);
    }

    public function question(int $questionId)
    {
        return $this->_qual_test()->getQuestion($questionId);
    }

    public function retake_count(int $playerId, int $kingdomId, string $testType): int
    {
        return $this->_qual_test()->getRetakeCount($playerId, $kingdomId, $testType);
    }

    public function player_attempts(int $playerId, int $kingdomId = 0, $testType = null): array
    {
        return $this->_qual_test()->getPlayerAttempts($playerId, $kingdomId, $testType);
    }

    public function set_by_id(int $setId)
    {
        return $this->_qual_test()->getSetById($setId);
    }

    public function set_questions(int $setId): array
    {
        return $this->_qual_test()->getSetQuestions($setId);
    }

    public function save_config(
        int $kingdomId,
        string $testType,
        int $questionCount,
        int $passPercent,
        int $validDays,
        $validUntil = null,
        int $maxRetakes = 0,
        int $shareQuestions = 0,
        $instructions = null,
        $rulesVersion = null,
        int $showCorrectOnIncorrect = 0
    ) {
        return $this->_qual_test()->saveConfig(
            $kingdomId,
            $testType,
            $questionCount,
            $passPercent,
            $validDays,
            $validUntil,
            $maxRetakes,
            $shareQuestions,
            $instructions,
            $rulesVersion,
            $showCorrectOnIncorrect
        );
    }

    public function save_question(int $questionId, array $data)
    {
        return $this->_qual_test()->saveQuestion($questionId, $data);
    }

    public function set_question_status(int $questionId, string $status)
    {
        return $this->_qual_test()->setQuestionStatus($questionId, $status);
    }

    public function reset_question_stats(int $questionId)
    {
        return $this->_qual_test()->resetQuestionStats($questionId);
    }

    public function report_question(int $questionId, int $playerId, string $reason)
    {
        return $this->_qual_test()->reportQuestion($questionId, $playerId, $reason);
    }

    public function report_counts(int $questionId): array
    {
        return $this->_qual_test()->getReportCounts($questionId);
    }

    public function clear_reports(int $questionId)
    {
        return $this->_qual_test()->clearReports($questionId);
    }

    public function library_questions(int $excludingKingdomId, &$stats = null): array
    {
        return $this->_qual_test()->getLibraryQuestions($excludingKingdomId, $stats);
    }

    public function copy_question_to_kingdom(
        int $sourceQuestionId,
        int $destKingdomId,
        int $createdBy = 0,
        int $setId = 0
    ) {
        return $this->_qual_test()->copyQuestionToKingdom(
            $sourceQuestionId,
            $destKingdomId,
            $createdBy,
            $setId
        );
    }

    public function reset_all_retakes(int $kingdomId, string $testType)
    {
        return $this->_qual_test()->resetAllRetakes($kingdomId, $testType);
    }

    public function reset_player_retakes(int $playerId, int $kingdomId, string $testType)
    {
        return $this->_qual_test()->resetPlayerRetakes($playerId, $kingdomId, $testType);
    }

    public function mundane_display(int $mundaneId)
    {
        return $this->_qual_test()->getMundaneDisplay($mundaneId);
    }

    public function add_manager(int $kingdomId, int $mundaneId)
    {
        return $this->_qual_test()->addManager($kingdomId, $mundaneId);
    }

    public function remove_manager(int $kingdomId, int $mundaneId)
    {
        return $this->_qual_test()->removeManager($kingdomId, $mundaneId);
    }

    public function correct_answers(array $questionIds, int $kingdomId = 0, string $testType = ''): array
    {
        return $this->_qual_test()->getCorrectAnswers($questionIds, $kingdomId, $testType);
    }

    public function score_test(array $correctMap, array $submitted): array
    {
        return $this->_qual_test()->scoreTest($correctMap, $submitted);
    }

    public function questions_for_test(int $kingdomId, string $testType, int $limit)
    {
        return $this->_qual_test()->getQuestionsForTest($kingdomId, $testType, $limit);
    }

    public function try_consume_retake(int $playerId, int $kingdomId, string $testType, int $maxRetakes): bool
    {
        return $this->_qual_test()->tryConsumeRetake($playerId, $kingdomId, $testType, $maxRetakes);
    }

    public function record_question_stats(array $correctMap, array $submitted)
    {
        return $this->_qual_test()->recordQuestionStats($correctMap, $submitted);
    }

    public function record_attempt(
        int $playerId,
        int $kingdomId,
        string $testType,
        $scorePercent,
        int $passPercent,
        bool $passed,
        array $submitted,
        string $rulesVersion = ''
    ) {
        return $this->_qual_test()->recordAttempt(
            $playerId,
            $kingdomId,
            $testType,
            $scorePercent,
            $passPercent,
            $passed,
            $submitted,
            $rulesVersion
        );
    }

    public function record_result(
        int $playerId,
        int $kingdomId,
        string $testType,
        $scorePercent,
        int $validDays,
        $validUntil = null,
        string $rulesVersion = ''
    ) {
        return $this->_qual_test()->recordResult(
            $playerId,
            $kingdomId,
            $testType,
            $scorePercent,
            $validDays,
            $validUntil,
            $rulesVersion
        );
    }

    public function sync_mundane_qual(int $playerId, string $testType, $expiresDate)
    {
        return $this->_qual_test()->syncMundaneQual($playerId, $testType, $expiresDate);
    }

    public function set_question_status_batch(int $kingdomId, array $questionIds, string $status)
    {
        return $this->_qual_test()->setQuestionStatusBatch($kingdomId, $questionIds, $status);
    }

    public function duplicate_question(int $questionId, int $kingdomId)
    {
        return $this->_qual_test()->duplicateQuestion($questionId, $kingdomId);
    }

    public function questions_for_preview(int $kingdomId, string $testType, int $limit, int $setId = 0)
    {
        return $this->_qual_test()->getQuestionsForPreview($kingdomId, $testType, $limit, $setId);
    }

    public function save_question_batch(
        int $kingdomId,
        string $testType,
        array $questionsArray,
        int $createdBy = 0,
        int $setId = 0
    ): array {
        return $this->_qual_test()->saveQuestionBatch(
            $kingdomId,
            $testType,
            $questionsArray,
            $createdBy,
            $setId
        );
    }

    public function create_draft(
        int $kingdomId,
        string $testType,
        string $name,
        string $rulesVersion = '',
        int $createdBy = 0
    ): int {
        return $this->_qual_test()->createDraft(
            $kingdomId,
            $testType,
            $name,
            $rulesVersion,
            $createdBy
        );
    }

    public function update_set(int $setId, string $name, string $rulesVersion)
    {
        return $this->_qual_test()->updateSet($setId, $name, $rulesVersion);
    }

    public function publish_set(int $setId): array
    {
        return $this->_qual_test()->publishSet($setId);
    }

    public function discard_draft(int $setId)
    {
        return $this->_qual_test()->discardDraft($setId);
    }

    public function add_question_to_set(int $setId, int $questionId)
    {
        return $this->_qual_test()->addQuestionToSet($setId, $questionId);
    }

    public function remove_question_from_set(int $setId, int $questionId)
    {
        return $this->_qual_test()->removeQuestionFromSet($setId, $questionId);
    }

    public function attempt_detail(int $attemptId)
    {
        return $this->_qual_test()->getAttemptDetail($attemptId);
    }

    private function _qual_test(): QualTest
    {
        return new QualTest();
    }
}
