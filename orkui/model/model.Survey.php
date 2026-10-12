<?php

/**
 * Model_Survey — thin snake_case facade over the three survey domain classes
 * (Survey, SurveyResponse, SurveyReport). No logic; every method is a one-line
 * delegate. This is the ONLY place under orkui/ that may instantiate those
 * classes (Global Constraints, spec §5 "Model").
 */
class Model_Survey extends Model
{
    // -----------------------------------------------------------------------
    // Survey — definition, lifecycle, auth, images
    // -----------------------------------------------------------------------

    public function is_ork_admin(int $uid): bool
    {
        return $this->_survey()->isOrkAdmin($uid);
    }

    public function can_create(int $uid, string $scopeType, int $scopeId): bool
    {
        return $this->_survey()->canCreate($uid, $scopeType, $scopeId);
    }

    public function can_manage(int $uid, array $surveyRow): bool
    {
        return $this->_survey()->canManage($uid, $surveyRow);
    }

    public function manageable_scopes(int $uid): array
    {
        return $this->_survey()->manageableScopes($uid);
    }

    public function scope_name(string $scopeType, int $scopeId): string
    {
        return $this->_survey()->scopeName($scopeType, $scopeId);
    }

    public function manager_label(string $scopeType, int $scopeId): string
    {
        return $this->_response()->managerLabel($scopeType, $scopeId);
    }

    public function get_row(int $surveyId): ?array
    {
        return $this->_survey()->getRow($surveyId);
    }

    public function get_by_slug(string $slug): ?array
    {
        return $this->_survey()->getBySlug($slug);
    }

    public function survey_for_page(int $pageId): ?array
    {
        return $this->_survey()->surveyForPage($pageId);
    }

    public function survey_for_question(int $questionId): ?array
    {
        return $this->_survey()->surveyForQuestion($questionId);
    }

    public function survey_for_image(int $imageId): ?array
    {
        return $this->_survey()->surveyForImage($imageId);
    }

    public function is_structure_locked(array $surveyRow): bool
    {
        return $this->_survey()->isStructureLocked($surveyRow);
    }

    /** Message the domain uses when a structural mutation hits a locked survey. */
    public function locked_error(): string
    {
        return Survey::LOCKED_ERROR;
    }

    public function get(int $surveyId): array
    {
        return $this->_survey()->get($surveyId);
    }

    public function list_manageable(int $uid, ?string $scopeType = null, ?int $scopeId = null): array
    {
        return $this->_survey()->listManageable($uid, $scopeType, $scopeId);
    }

    public function create(int $uid, string $scopeType, int $scopeId, string $title): array
    {
        return $this->_survey()->create($uid, $scopeType, $scopeId, $title);
    }

    public function update(int $surveyId, array $fields): array
    {
        return $this->_survey()->update($surveyId, $fields);
    }

    public function set_status(int $surveyId, string $status): array
    {
        return $this->_survey()->setStatus($surveyId, $status);
    }

    public function clone_survey(int $surveyId, int $uid): array
    {
        return $this->_survey()->cloneSurvey($surveyId, $uid);
    }

    public function delete(int $surveyId): array
    {
        return $this->_survey()->delete($surveyId);
    }

    /** Clear Results: delete every response (credits already posted stay); $dryRun only counts. */
    public function clear_results(int $surveyId, int $uid, bool $dryRun = false): array
    {
        return $this->_survey()->clearResults($surveyId, $uid, $dryRun);
    }

    public function page_add(int $surveyId): array
    {
        return $this->_survey()->pageAdd($surveyId);
    }

    public function page_update(int $pageId, array $fields): array
    {
        return $this->_survey()->pageUpdate($pageId, $fields);
    }

    public function page_delete(int $pageId): array
    {
        return $this->_survey()->pageDelete($pageId);
    }

    public function page_reorder(int $surveyId, array $pageIds): array
    {
        return $this->_survey()->pageReorder($surveyId, $pageIds);
    }

    public function question_add(int $surveyId, int $pageId, string $type, ?int $afterQuestionId): array
    {
        return $this->_survey()->questionAdd($surveyId, $pageId, $type, $afterQuestionId);
    }

    public function question_update(int $questionId, array $fields): array
    {
        return $this->_survey()->questionUpdate($questionId, $fields);
    }

    public function question_delete(int $questionId): array
    {
        return $this->_survey()->questionDelete($questionId);
    }

    public function question_reorder(int $pageId, array $questionIds): array
    {
        return $this->_survey()->questionReorder($pageId, $questionIds);
    }

    public function question_move(int $questionId, int $pageId, int $index): array
    {
        return $this->_survey()->questionMove($questionId, $pageId, $index);
    }

    public function option_set(int $questionId, string $role, array $options): array
    {
        return $this->_survey()->optionSet($questionId, $role, $options);
    }

    /** Server-side copy of one question (and its options) in one transaction. */
    public function question_duplicate(int $questionId): array
    {
        return $this->_survey()->questionDuplicate($questionId);
    }

    /** Event occurrences in the survey's scope, for the builder's event-audience picker. */
    public function event_options(array $surveyRow): array
    {
        return $this->_survey()->eventOptions($surveyRow);
    }

    public function image_add(int $surveyId, int $uid, string $tmpPath, string $clientName): array
    {
        return $this->_survey()->imageAdd($surveyId, $uid, $tmpPath, $clientName);
    }

    public function image_delete(int $imageId): array
    {
        return $this->_survey()->imageDelete($imageId);
    }

    public function image_url(array $imageRow): string
    {
        return $this->_survey()->imageUrl($imageRow);
    }

    public function render_markdown(?string $md): string
    {
        return $this->_survey()->renderMarkdown($md);
    }

    /**
     * The question type catalogue the builder and the runner draw from, so the
     * client never keeps its own copy of SurveyTypes (spec §4).
     *
     * @return array{types: string[], show_if_sources: string[], option_roles: array<string, string[]>, other_max_length: int}
     */
    public function type_catalog(): array
    {
        return [
            'types'            => SurveyTypes::TYPES,
            'show_if_sources'  => SurveyTypes::SHOW_IF_SOURCES,
            'option_roles'     => SurveyTypes::OPTION_ROLES,
            'other_max_length' => SurveyTypes::OTHER_MAX_LENGTH,
        ];
    }

    /** @return string[] Question types that record answers (no section / image). */
    public function answerable_types(): array
    {
        return SurveyTypes::ANSWERABLE;
    }

    // -----------------------------------------------------------------------
    // SurveyResponse — eligibility, drafts, consent, submit
    // -----------------------------------------------------------------------

    /** Byte ceiling the domain applies to an answers JSON payload. */
    public function max_answer_bytes(): int
    {
        return SurveyResponse::MAX_DRAFT_BYTES;
    }

    public function tenure_months(int $uid): int
    {
        return $this->_response()->tenureMonths($uid);
    }

    public function eligibility(array $surveyRow, int $uid): array
    {
        return $this->_response()->eligibility($surveyRow, $uid);
    }

    public function definition_for_respondent(int $surveyId, int $uid, bool $preview): array
    {
        return $this->_response()->definitionForRespondent($surveyId, $uid, $preview);
    }

    public function draft_save(int $surveyId, int $uid, array $answers, int $pageIndex): array
    {
        return $this->_response()->draftSave($surveyId, $uid, $answers, $pageIndex);
    }

    public function draft_load(int $surveyId, int $uid): ?array
    {
        return $this->_response()->draftLoad($surveyId, $uid);
    }

    public function draft_delete(int $surveyId, int $uid): void
    {
        $this->_response()->draftDelete($surveyId, $uid);
    }

    public function validate_submission(array $definition, array $answers): array
    {
        return $this->_response()->validateSubmission($definition, $answers);
    }

    public function submit(int $surveyId, int $uid, array $answers, string $consent, int $durationSeconds, bool $isTest, bool $creditNotice = false): array
    {
        return $this->_response()->submit($surveyId, $uid, $answers, $consent, $durationSeconds, $isTest, $creditNotice);
    }

    public function available_for(int $uid): array
    {
        return $this->_response()->availableFor($uid);
    }

    public function banner_for(int $uid): ?array
    {
        return $this->_response()->bannerFor($uid);
    }

    /** @return array{available: list<array<string, mixed>>, banner: ?array<string, mixed>} */
    public function available_and_banner_for(int $uid): array
    {
        return $this->_response()->availableAndBannerFor($uid);
    }

    public function dismiss_banner(int $surveyId, int $uid): void
    {
        $this->_response()->dismissBanner($surveyId, $uid);
    }

    // -----------------------------------------------------------------------
    // CSRF — per-session token for SurveyAjax POST mutations (X-CSRF-Token)
    // -----------------------------------------------------------------------

    /** Mint the session's survey CSRF token once, then keep returning it. */
    public function csrf_token(): string
    {
        $token = $this->session->survey_csrf;
        if (!is_string($token) || strlen($token) !== 64) {
            $token = bin2hex(random_bytes(32));
            $this->session->survey_csrf = $token;
        }
        return $token;
    }

    /** True when $presented matches the session token. Never mints one. */
    public function csrf_valid($presented): bool
    {
        $token = $this->session->survey_csrf;
        return is_string($token) && strlen($token) === 64
            && is_string($presented) && hash_equals($token, $presented);
    }

    // -----------------------------------------------------------------------
    // SurveyReport — aggregation, rows, CSV
    // -----------------------------------------------------------------------

    /** @return string[] Question types aggregate() accepts as a cross-tab source. */
    public function crosstab_sources(): array
    {
        return SurveyReport::CROSSTAB_SOURCES;
    }

    /** @return string[] Question types a cross-tab splits by its source. */
    public function crosstab_targets(): array
    {
        return SurveyReport::CROSSTAB_TARGETS;
    }

    public function summary(int $surveyId, array $filters): array
    {
        return $this->_report()->summary($surveyId, $filters);
    }

    public function aggregate(int $surveyId, array $filters): array
    {
        return $this->_report()->aggregate($surveyId, $filters);
    }

    /** Summary + per-question aggregation for the access level results_access() granted. */
    public function results_for(int $surveyId, $filters, array $access): array
    {
        return $this->_report()->resultsFor($surveyId, $filters, $access);
    }

    public function rows(int $surveyId, array $filters, int $offset, int $limit): array
    {
        return $this->_report()->rows($surveyId, $filters, $offset, $limit, (int) ($this->session->user_id ?? 0));
    }

    public function csv(int $surveyId, array $filters): string
    {
        return $this->_report()->csv($surveyId, $filters, (int) ($this->session->user_id ?? 0));
    }

    /** Emit the CSV (header, then 500-row batches) through $emit instead of one string. */
    public function csv_stream(int $surveyId, array $filters, callable $emit): void
    {
        $this->_report()->csvStream($surveyId, $filters, $emit, (int) ($this->session->user_id ?? 0));
    }

    /** Emit the coded analysis CSV (#36) in 500-row batches through $emit. */
    public function analysis_stream(int $surveyId, array $filters, callable $emit): void
    {
        $this->_report()->analysisStream($surveyId, $filters, $emit, (int) ($this->session->user_id ?? 0));
    }

    /** The analysis export's codebook CSV (structure only, no response data). */
    public function analysis_codebook(int $surveyId): string
    {
        return $this->_report()->codebookCsv($surveyId);
    }

    /** Kingdoms present in the survey's non-test responses, with counts (results filter). */
    public function kingdoms_present(int $surveyId): array
    {
        return $this->_report()->kingdomsPresent($surveyId);
    }

    /** A shared viewer's allowed one-kingdom picks: kingdom_id => served count. */
    public function shared_kingdom_choices(int $surveyId, array $lens): array
    {
        return $this->_report()->sharedKingdomChoices($surveyId, $lens);
    }

    // -----------------------------------------------------------------------
    // Org sections, sharing, credits (sharing-and-credits spec)
    // -----------------------------------------------------------------------

    public function list_for_scope(int $uid, ?string $scopeType, ?int $scopeId): array
    {
        return $this->_survey()->listForScope($uid, $scopeType, $scopeId);
    }

    public function results_access(int $uid, array $surveyRow, ?array $context): ?array
    {
        return $this->_survey()->resultsAccess($uid, $surveyRow, $context);
    }

    /** ['opens_at' => ?string] for a shared viewer held by after-close timing, else null. */
    public function results_pending(int $uid, array $surveyRow, ?array $context): ?array
    {
        return $this->_survey()->resultsPending($uid, $surveyRow, $context);
    }

    public function sharing_pending_text(?string $opensAt): string
    {
        return Survey::sharingPendingText($opensAt);
    }

    public function credit_status(int $uid, int $surveyId, ?array $grantor): array
    {
        return $this->_credit()->status($uid, $surveyId, $grantor);
    }

    public function credit_enable(int $uid, int $surveyId, ?array $grantor, string $mode, bool $confirm): array
    {
        return $this->_credit()->enable($uid, $surveyId, $grantor, $mode, $confirm);
    }

    public function credit_reconcile(int $uid, int $surveyId, ?array $grantor): array
    {
        return $this->_credit()->reconcileAs($uid, $surveyId, $grantor);
    }

    // -----------------------------------------------------------------------
    // Factories
    // -----------------------------------------------------------------------

    /** Every Survey instance acts as the session user, so writes carry updated_by and log activity. */
    private function _survey(): Survey
    {
        $survey = new Survey();
        $survey->setActor(isset($this->session->user_id) ? (int) $this->session->user_id : 0);
        return $survey;
    }

    private function _response(): SurveyResponse
    {
        return new SurveyResponse();
    }

    private function _report(): SurveyReport
    {
        return new SurveyReport();
    }

    private function _credit(): SurveyCredit
    {
        return new SurveyCredit();
    }
}
