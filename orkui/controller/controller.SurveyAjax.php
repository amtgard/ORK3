<?php

/**
 * Controller_SurveyAjax — JSON contract for the survey module (design spec §6).
 *
 * Common envelope: {status:0}+payload on success; {status:1,error} bad
 * request/validation ({errors{}} when per-field); {status:3,error} not
 * authorized; {status:5,error} not logged in. Every action calls
 * requireLogin() first; every manage action loads the survey by id and checks
 * canManage against ITS OWN scope (never a scope taken from the request).
 *
 * The three domain classes (Survey, SurveyResponse, SurveyReport) return a
 * QualTest-style envelope keyed PascalCase; this controller is the seam that
 * renders the spec's fixed lowercase wire contract, so the frontend phase can
 * code against the spec table rather than against these files.
 */
class Controller_SurveyAjax extends Controller
{
    /**
     * Actions exempt from the CSRF check: read-only, except `dismiss_banner`.
     * Every other action mutates state (or, for submit/draft_save, binds
     * identity and spends the player's one response), so it must present the
     * session token in X-CSRF-Token — a header a cross-site form cannot set
     * (#43). Exactly the contract's list: `types`, though a pure read, is not
     * on it, so the builder sends the token with it like every other builder
     * call.
     *
     * `dismiss_banner` is the one deliberate WRITE exemption: the survey banner
     * renders site-wide (default.theme svBannerDismiss) on pages that load no
     * survey CSRF token, and the worst a forged request can do is hide one
     * survey's banner for the victim. The session cookie is set with no
     * SameSite attribute (class.Session.php), so only a browser's own Lax
     * default, where it has one, stops a cross-site POST carrying it; do not
     * count on that.
     *
     * `definition` (inserts ork_survey_start) and `rows` (writes a rows_view
     * audit row) are NOT here: both write, and every caller already sends the
     * token. SurveyPureTest pins this list.
     */
    private const CSRF_EXEMPT = [
        'available', 'get', 'scopes', 'results', 'help',
        'preview_md', 'dismiss_banner', 'event_options', 'credit_status',
    ];

    /**
     * POST keys `update` forwards verbatim to Survey::update(). It must list
     * every scalar field the domain accepts (a missing one is dropped while the
     * call still answers status 0); AudienceKingdomIds is JSON-decoded
     * separately. SurveyPureTest pins this list to Survey::UPDATE_FIELDS.
     */
    private const UPDATE_SCALAR_FIELDS = [
        'Title', 'Description', 'WelcomeMd', 'WelcomeImageId', 'ThanksMd', 'ThanksImageId',
        'OpenAt', 'CloseAt', 'AudienceActiveOnly', 'AudienceMinTenureMonths',
        'AudienceRecentMonths', 'AudienceEventCalendardetailId',
        'DataGateEnabled', 'ResultsShare', 'ResultsShareTiming', 'ShowBanner', 'ShowProgress', 'AllowResume', 'AccentColor',
    ];

    public function __construct($call = null, $id = null)
    {
        parent::__construct($call, $id);
        $this->load_model('Survey');
        $this->requireCsrf(strtolower((string) $this->method));
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function jsonOut($data)
    {
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }

    private function requireLogin(): int
    {
        if (!isset($this->session->user_id) || (int) $this->session->user_id <= 0) {
            $this->jsonOut(['status' => 5, 'error' => 'Not logged in.']);
        }
        return (int) $this->session->user_id;
    }

    /**
     * Refuse a non-exempt action whose X-CSRF-Token does not match the session
     * token. A logged-out caller is left to the action's requireLogin(), so it
     * still gets status 5 rather than a token error.
     */
    private function requireCsrf(string $action): void
    {
        if (in_array($action, self::CSRF_EXEMPT, true)) {
            return;
        }
        if (!isset($this->session->user_id) || (int) $this->session->user_id <= 0) {
            return;
        }
        if (!$this->Survey->csrf_valid($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
            $this->jsonOut([
                'status' => 3,
                'csrf'   => true,
                'error'  => 'Your security token expired. Reload the page and try again.',
            ]);
        }
    }

    /** Loads the survey by id and checks canManage against ITS OWN scope. */
    private function requireManage(int $uid, int $surveyId): array
    {
        return $this->requireManageRow($uid, $this->Survey->get_row($surveyId));
    }

    /** Same check, starting from a survey row already resolved from a child id. */
    private function requireManageRow(int $uid, ?array $row): array
    {
        if ($row === null) {
            $this->jsonOut(['status' => 1, 'error' => 'Survey not found.']);
        }
        if (!$this->Survey->can_manage($uid, $row)) {
            $this->jsonOut(['status' => 3, 'error' => 'You do not have permission to manage this survey.']);
        }
        return $row;
    }

    private function requireUnlocked(array $surveyRow): void
    {
        if ($this->Survey->is_structure_locked($surveyRow)) {
            $this->jsonOut(['status' => 1, 'error' => $this->Survey->locked_error()]);
        }
    }

    /**
     * Decode a JSON-bearing POST field into an array, or bail with status 1.
     *
     * $maxBytes caps the RAW string before json_decode: the draft path caps the
     * re-encoded answers inside the domain, but submit fed whatever
     * post_max_size allowed straight into per-entry validation, so an absurd
     * multi-select array was decoded and walked before being rejected.
     */
    private function jsonField(string $key, $default = [], int $maxBytes = 0): array
    {
        $raw = $_POST[$key] ?? null;
        if ($raw === null || $raw === '') {
            return $default;
        }
        if ($maxBytes > 0 && strlen((string) $raw) > $maxBytes) {
            $this->jsonOut(['status' => 1, 'error' => $key . ' payload is too large.']);
        }
        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            $this->jsonOut(['status' => 1, 'error' => $key . ' must be valid JSON.']);
        }
        return $decoded;
    }

    private function truthy($v): bool
    {
        return $v === true || $v === 1 || $v === '1' || $v === 'on' || $v === 'true';
    }

    /** 'Kingdom/17' | 'Park/1049' -> ['type'=>'kingdom'|'park','id'=>int], anything else -> null. */
    private function orgParam($raw): ?array
    {
        if (!preg_match('~^(Kingdom|Park)/(\d+)$~', trim((string) $raw), $m)) {
            return null;
        }
        return ['type' => strtolower($m[1]), 'id' => (int) $m[2]];
    }

    /** Map a Survey-domain envelope {Status,Error,...} straight through on failure. */
    private function envelopeFail(array $r): void
    {
        $out = ['status' => (int) ($r['Status'] ?? 1), 'error' => (string) ($r['Error'] ?? 'Request failed.')];
        if (isset($r['Errors'])) {
            $out['errors'] = $r['Errors'];
        }
        if (isset($r['Reason'])) {
            $out['reason'] = $r['Reason'];
        }
        $this->jsonOut($out);
    }

    /** A question row from the Survey domain (Options capitalised) -> wire shape (options lowercase). */
    private function renderQuestion(array $q): array
    {
        $q['options'] = $q['Options'] ?? [];
        unset($q['Options']);
        return $q;
    }

    /** An image row from the Survey domain (Url capitalised) -> wire shape (url lowercase). */
    private function renderImage(array $img): array
    {
        $img['url'] = $img['Url'] ?? '';
        unset($img['Url']);
        return $img;
    }

    // -----------------------------------------------------------------------
    // help — render docs/survey-guide.md for the in-app help modal
    // POST: Doc ('surveys')
    // -----------------------------------------------------------------------
    public function help($p = null)
    {
        $this->requireLogin();

        // WHITELIST, not a path — never interpolate user input into a filename.
        $docs = [
            'surveys' => 'survey-guide.md',
        ];
        $key = (string) ($_POST['Doc'] ?? '');
        if (!isset($docs[$key])) {
            $this->jsonOut(['status' => 1, 'error' => 'Unknown help topic.']);
        }

        $path = DIR_BASENAME . 'docs/' . $docs[$key];
        if (!is_readable($path)) {
            $this->jsonOut(['status' => 1, 'error' => 'Help document is missing.']);
        }

        require_once DIR_SYSTEM . 'lib/Parsedown.php';
        $pd = new Parsedown();
        $pd->setSafeMode(true);
        $pd->setBreaksEnabled(false);

        $this->jsonOut(['status' => 0, 'html' => $pd->text(file_get_contents($path))]);
    }

    // =========================================================================
    // Builder
    // =========================================================================

    /**
     * The question type catalogue (SurveyTypes) — the client's only source for
     * the type list, the show_if sources, the option roles and the write-in cap.
     */
    public function types($p = null)
    {
        $this->requireLogin();
        $this->jsonOut(['status' => 0, 'catalog' => $this->Survey->type_catalog()]);
    }

    public function scopes($p = null)
    {
        $uid = $this->requireLogin();
        $out = ['status' => 0, 'scopes' => $this->Survey->manageable_scopes($uid)];
        // The builder's consent preview names who can manage its survey,
        // worded exactly as the runner's Any ORK Data option.
        $surveyId = (int) ($_POST['SurveyId'] ?? 0);
        if ($surveyId > 0) {
            $row = $this->requireManage($uid, $surveyId);
            $out['manager_label'] = $this->Survey->manager_label((string) $row['scope_type'], (int) $row['scope_id']);
        }
        $this->jsonOut($out);
    }

    public function create($p = null)
    {
        $uid       = $this->requireLogin();
        $scopeType = (string) ($_POST['ScopeType'] ?? '');
        $scopeId   = (int) ($_POST['ScopeId'] ?? 0);
        $title     = (string) ($_POST['Title'] ?? '');

        $r = $this->Survey->create($uid, $scopeType, $scopeId, $title);
        if ((int) $r['Status'] !== 0) {
            $this->envelopeFail($r);
        }
        $this->jsonOut(['status' => 0, 'survey_id' => (int) $r['SurveyId']]);
    }

    public function get($p = null)
    {
        $uid      = $this->requireLogin();
        $surveyId = (int) ($_POST['SurveyId'] ?? 0);
        $this->requireManage($uid, $surveyId);

        $r = $this->Survey->get($surveyId);
        if ((int) $r['Status'] !== 0) {
            $this->envelopeFail($r);
        }

        $this->jsonOut([
            'status'    => 0,
            'survey'    => $r['Survey'],
            'pages'     => $r['Pages'],
            'questions' => array_map([$this, 'renderQuestion'], $r['Questions']),
            'images'    => array_map([$this, 'renderImage'], $r['Images']),
            'locked'    => (bool) $r['Locked'],
        ]);
    }

    public function update($p = null)
    {
        $uid      = $this->requireLogin();
        $surveyId = (int) ($_POST['SurveyId'] ?? 0);
        $this->requireManage($uid, $surveyId);

        $fields = [];
        foreach (self::UPDATE_SCALAR_FIELDS as $key) {
            if (array_key_exists($key, $_POST)) {
                $fields[$key] = $_POST[$key];
            }
        }
        if (array_key_exists('AudienceKingdomIds', $_POST)) {
            $fields['AudienceKingdomIds'] = $this->jsonField('AudienceKingdomIds', []);
        }

        $r = $this->Survey->update($surveyId, $fields);
        if ((int) $r['Status'] !== 0) {
            $this->envelopeFail($r);
        }
        // The acting manager's memoised banner / Available Surveys must not
        // keep offering the survey as it was before this change.
        $this->bust_survey_banner_cache();
        $this->jsonOut(['status' => 0, 'survey' => $r['Survey']]);
    }

    /**
     * Event occurrences in the survey's scope (12 months back to 6 ahead) for
     * the builder's "attended event" audience picker.
     */
    public function event_options($p = null)
    {
        $uid      = $this->requireLogin();
        $surveyId = (int) ($_POST['SurveyId'] ?? 0);
        $survey   = $this->requireManage($uid, $surveyId);

        $this->jsonOut(['status' => 0, 'events' => $this->Survey->event_options($survey)]);
    }

    public function set_status($p = null)
    {
        $uid      = $this->requireLogin();
        $surveyId = (int) ($_POST['SurveyId'] ?? 0);
        $this->requireManage($uid, $surveyId);

        $status = (string) ($_POST['Status'] ?? '');
        $r      = $this->Survey->set_status($surveyId, $status);
        if ((int) $r['Status'] !== 0) {
            $this->envelopeFail($r);
        }
        // The acting manager's memoised banner / Available Surveys must not
        // keep offering the survey as it was before this change.
        $this->bust_survey_banner_cache();
        $this->jsonOut(['status' => 0, 'survey' => $r['Survey']]);
    }

    public function clone($p = null)
    {
        $uid      = $this->requireLogin();
        $surveyId = (int) ($_POST['SurveyId'] ?? 0);
        $this->requireManage($uid, $surveyId);

        $r = $this->Survey->clone_survey($surveyId, $uid);
        if ((int) $r['Status'] !== 0) {
            $this->envelopeFail($r);
        }
        $this->jsonOut(['status' => 0, 'survey_id' => (int) $r['SurveyId']]);
    }

    public function delete($p = null)
    {
        $uid      = $this->requireLogin();
        $surveyId = (int) ($_POST['SurveyId'] ?? 0);
        $this->requireManage($uid, $surveyId);

        $r = $this->Survey->delete($surveyId);
        if ((int) $r['Status'] !== 0) {
            $this->envelopeFail($r);
        }
        // The acting manager's memoised banner / Available Surveys must not
        // keep offering the survey as it was before this change.
        $this->bust_survey_banner_cache();
        $this->jsonOut(['status' => 0]);
    }

    /**
     * Clear Results (manager-only, CSRF-checked): every response goes; posted
     * credits stay. Without Confirm=1 it only counts what would go (the
     * confirm modal's number), so a request missing the flag deletes nothing.
     */
    public function clear_results($p = null)
    {
        $uid      = $this->requireLogin();
        $surveyId = (int) ($_POST['SurveyId'] ?? 0);
        $this->requireManage($uid, $surveyId);

        $confirm = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $this->truthy($_POST['Confirm'] ?? 0);
        $r       = $this->Survey->clear_results($surveyId, $uid, !$confirm);
        if ((int) $r['Status'] !== 0) {
            $this->envelopeFail($r);
        }
        if ($confirm) {
            // The manager's own cleared response no longer counts as done.
            $this->bust_survey_banner_cache();
        }
        $this->jsonOut($confirm
            ? ['status' => 0, 'cleared' => (int) $r['Cleared']]
            : ['status' => 0, 'count' => (int) $r['Count']]);
    }

    public function page_add($p = null)
    {
        $uid      = $this->requireLogin();
        $surveyId = (int) ($_POST['SurveyId'] ?? 0);
        $survey   = $this->requireManage($uid, $surveyId);
        $this->requireUnlocked($survey);

        $r = $this->Survey->page_add($surveyId);
        if ((int) $r['Status'] !== 0) {
            $this->envelopeFail($r);
        }
        $this->jsonOut(['status' => 0, 'page' => $r['Page']]);
    }

    public function page_update($p = null)
    {
        $uid    = $this->requireLogin();
        $pageId = (int) ($_POST['PageId'] ?? 0);

        // The page's own survey decides authority, never a survey id from the request.
        $this->requireManageRow($uid, $this->Survey->survey_for_page($pageId));

        $fields = [];
        foreach (['Title', 'DescriptionMd', 'ShowIfQuestionId', 'ShowIfOptionId'] as $key) {
            if (array_key_exists($key, $_POST)) {
                $fields[$key] = $_POST[$key];
            }
        }

        $r = $this->Survey->page_update($pageId, $fields);
        if ((int) $r['Status'] !== 0) {
            $this->envelopeFail($r);
        }
        $this->jsonOut(['status' => 0, 'page' => $r['Page']]);
    }

    public function page_delete($p = null)
    {
        $uid    = $this->requireLogin();
        $pageId = (int) ($_POST['PageId'] ?? 0);
        $this->requireManageRow($uid, $this->Survey->survey_for_page($pageId));

        $r = $this->Survey->page_delete($pageId);
        if ((int) $r['Status'] !== 0) {
            $this->envelopeFail($r);
        }
        $this->jsonOut(['status' => 0]);
    }

    public function page_reorder($p = null)
    {
        $uid      = $this->requireLogin();
        $surveyId = (int) ($_POST['SurveyId'] ?? 0);
        $this->requireManage($uid, $surveyId);

        $pageIds = $this->jsonField('PageIds', []);
        $r       = $this->Survey->page_reorder($surveyId, $pageIds);
        if ((int) $r['Status'] !== 0) {
            $this->envelopeFail($r);
        }
        $this->jsonOut(['status' => 0]);
    }

    public function question_add($p = null)
    {
        $uid      = $this->requireLogin();
        $surveyId = (int) ($_POST['SurveyId'] ?? 0);
        $survey   = $this->requireManage($uid, $surveyId);
        $this->requireUnlocked($survey);

        $pageId          = (int) ($_POST['PageId'] ?? 0);
        $type            = (string) ($_POST['Type'] ?? '');
        $afterQuestionId = isset($_POST['AfterQuestionId']) && $_POST['AfterQuestionId'] !== ''
            ? (int) $_POST['AfterQuestionId'] : null;

        $r = $this->Survey->question_add($surveyId, $pageId, $type, $afterQuestionId);
        if ((int) $r['Status'] !== 0) {
            $this->envelopeFail($r);
        }
        $this->jsonOut(['status' => 0, 'question' => $this->renderQuestion($r['Question'])]);
    }

    /**
     * Update one question. `Type` retypes the card in place (builder footer type
     * picker) and is refused on a locked survey by the domain, like every other
     * structural field.
     */
    public function question_update($p = null)
    {
        $uid        = $this->requireLogin();
        $questionId = (int) ($_POST['QuestionId'] ?? 0);
        $this->requireManageRow($uid, $this->Survey->survey_for_question($questionId));

        $fields = [];
        foreach (['Type', 'Prompt', 'HelpMd', 'ImageId', 'Required', 'ShowIfQuestionId', 'ShowIfOptionId'] as $key) {
            if (array_key_exists($key, $_POST)) {
                $fields[$key] = $_POST[$key];
            }
        }
        if (array_key_exists('Settings', $_POST)) {
            $fields['Settings'] = $this->jsonField('Settings', []);
        }

        $r = $this->Survey->question_update($questionId, $fields);
        if ((int) $r['Status'] !== 0) {
            $this->envelopeFail($r);
        }
        $this->jsonOut(['status' => 0, 'question' => $this->renderQuestion($r['Question'])]);
    }

    /**
     * Copy one question (prompt, help, image, required, settings, show-if and
     * options) to just after itself in one server-side transaction, replacing
     * the builder's serial add/update/option_set chain (#15).
     */
    public function question_duplicate($p = null)
    {
        $uid        = $this->requireLogin();
        $questionId = (int) ($_POST['QuestionId'] ?? 0);
        $survey     = $this->requireManageRow($uid, $this->Survey->survey_for_question($questionId));
        $this->requireUnlocked($survey);

        $r = $this->Survey->question_duplicate($questionId);
        if ((int) $r['Status'] !== 0) {
            $this->envelopeFail($r);
        }
        $this->jsonOut([
            'status'   => 0,
            'question' => $this->renderQuestion($r['Question']),
            'order'    => array_map('intval', $r['Order'] ?? []),
        ]);
    }

    public function question_delete($p = null)
    {
        $uid        = $this->requireLogin();
        $questionId = (int) ($_POST['QuestionId'] ?? 0);
        $this->requireManageRow($uid, $this->Survey->survey_for_question($questionId));

        $r = $this->Survey->question_delete($questionId);
        if ((int) $r['Status'] !== 0) {
            $this->envelopeFail($r);
        }
        $this->jsonOut(['status' => 0]);
    }

    public function question_reorder($p = null)
    {
        $uid    = $this->requireLogin();
        $pageId = (int) ($_POST['PageId'] ?? 0);
        $this->requireManageRow($uid, $this->Survey->survey_for_page($pageId));

        $questionIds = $this->jsonField('QuestionIds', []);
        $r           = $this->Survey->question_reorder($pageId, $questionIds);
        if ((int) $r['Status'] !== 0) {
            $this->envelopeFail($r);
        }
        $this->jsonOut(['status' => 0]);
    }

    public function question_move($p = null)
    {
        $uid        = $this->requireLogin();
        $questionId = (int) ($_POST['QuestionId'] ?? 0);
        $this->requireManageRow($uid, $this->Survey->survey_for_question($questionId));

        $pageId = (int) ($_POST['PageId'] ?? 0);
        $index  = (int) ($_POST['Index'] ?? 0);

        $r = $this->Survey->question_move($questionId, $pageId, $index);
        if ((int) $r['Status'] !== 0) {
            $this->envelopeFail($r);
        }
        $this->jsonOut(['status' => 0]);
    }

    public function option_set($p = null)
    {
        $uid        = $this->requireLogin();
        $questionId = (int) ($_POST['QuestionId'] ?? 0);
        $this->requireManageRow($uid, $this->Survey->survey_for_question($questionId));

        $role    = (string) ($_POST['Role'] ?? '');
        $options = $this->jsonField('Options', []);

        $r = $this->Survey->option_set($questionId, $role, $options);
        if ((int) $r['Status'] !== 0) {
            $this->envelopeFail($r);
        }
        $this->jsonOut(['status' => 0, 'options' => $r['Options']]);
    }

    public function image_upload($p = null)
    {
        $uid      = $this->requireLogin();
        $surveyId = (int) ($_POST['SurveyId'] ?? 0);
        $this->requireManage($uid, $surveyId);

        $file = $_FILES['Image'] ?? null;
        // PHP drops an over-limit file before we see it (tmp_name is empty), so
        // say why instead of "No file was uploaded."
        $err = is_array($file) ? (int) ($file['error'] ?? UPLOAD_ERR_OK) : UPLOAD_ERR_NO_FILE;
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
            $this->jsonOut(['status' => 1, 'error' => 'That image is too large (max 2 MB).']);
        }
        if ($err !== UPLOAD_ERR_OK && $err !== UPLOAD_ERR_NO_FILE) {
            $this->jsonOut(['status' => 1, 'error' => 'The upload failed. Try again.']);
        }
        if (!is_array($file) || !isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            $this->jsonOut(['status' => 1, 'error' => 'No file was uploaded.']);
        }

        $r = $this->Survey->image_add($surveyId, $uid, (string) $file['tmp_name'], (string) ($file['name'] ?? ''));
        if ((int) $r['Status'] !== 0) {
            $this->envelopeFail($r);
        }
        $this->jsonOut([
            'status'   => 0,
            'image_id' => (int) $r['ImageId'],
            'url'      => (string) $r['Url'],
            'width'    => (int) $r['Width'],
            'height'   => (int) $r['Height'],
        ]);
    }

    public function image_delete($p = null)
    {
        $uid     = $this->requireLogin();
        $imageId = (int) ($_POST['ImageId'] ?? 0);
        $this->requireManageRow($uid, $this->Survey->survey_for_image($imageId));

        $r = $this->Survey->image_delete($imageId);
        if ((int) $r['Status'] !== 0) {
            $this->envelopeFail($r);
        }
        $this->jsonOut(['status' => 0]);
    }

    public function preview_md($p = null)
    {
        $this->requireLogin();
        $md = (string) ($_POST['Md'] ?? '');
        $this->jsonOut(['status' => 0, 'html' => $this->Survey->render_markdown($md)]);
    }

    // =========================================================================
    // Respondent
    // =========================================================================

    public function definition($p = null)
    {
        $uid      = $this->requireLogin();
        $surveyId = (int) ($_POST['SurveyId'] ?? 0);
        $preview  = $this->truthy($_POST['Preview'] ?? 0);

        if ($preview) {
            // Early 'not found' / permission envelope; the domain re-checks
            // canManage itself before bypassing the audience gate.
            $this->requireManage($uid, $surveyId);
        }
        // Nothing below writes the session: release its lock (as export() does).
        session_write_close();

        $r = $this->Survey->definition_for_respondent($surveyId, $uid, $preview);
        if ((int) ($r['Status'] ?? 0) !== 0) {
            $this->envelopeFail($r);
        }

        $this->jsonOut([
            'status'   => 0,
            'survey'   => $r['Survey'],
            'pages'    => $r['Pages'],
            'draft'    => $r['Draft'],
            'eligible' => (bool) $r['Eligible'],
            'reason'   => $r['Reason'],
        ]);
    }

    public function draft_save($p = null)
    {
        $uid      = $this->requireLogin();
        $surveyId = (int) ($_POST['SurveyId'] ?? 0);
        $answers  = $this->jsonField('Answers', [], $this->Survey->max_answer_bytes());
        $pageIndex = (int) ($_POST['PageIndex'] ?? 0);

        $r = $this->Survey->draft_save($surveyId, $uid, $answers, $pageIndex);
        if ((int) $r['Status'] !== 0) {
            $this->envelopeFail($r);
        }
        // The memoised "Available Surveys" row must flip to "Continue".
        $this->bust_survey_banner_cache();
        $this->jsonOut(['status' => 0]);
    }

    public function submit($p = null)
    {
        $uid      = $this->requireLogin();
        $surveyId = (int) ($_POST['SurveyId'] ?? 0);
        $answers  = $this->jsonField('Answers', [], $this->Survey->max_answer_bytes());
        $consent  = (string) ($_POST['Consent'] ?? 'anonymous');
        $duration = (int) ($_POST['DurationSeconds'] ?? 0);
        $isTest   = $this->truthy($_POST['IsTest'] ?? 0);
        // The runner showed an attendance-credit line on the data gate (spec D1).
        $notice   = $this->truthy($_POST['CreditNotice'] ?? 0);

        $r = $this->Survey->submit($surveyId, $uid, $answers, $consent, $duration, $isTest, $notice);
        if ((int) $r['Status'] !== 0) {
            $this->envelopeFail($r);
        }
        // The banner is memoised per viewer in the session; a finished survey
        // must stop being promoted on the very next page load.
        $this->bust_survey_banner_cache();
        $this->jsonOut(['status' => 0, 'thanks_html' => $r['ThanksHtml'], 'credit' => $r['Credit'] ?? 'none']);
    }

    public function available($p = null)
    {
        $uid = $this->requireLogin();
        // Served from the page shell's memoised entry (and warms it on a miss).
        $surveys = $this->survey_surfaces($uid)['available'];
        session_write_close();
        $this->jsonOut(['status' => 0, 'surveys' => $surveys]);
    }

    public function dismiss_banner($p = null)
    {
        $uid      = $this->requireLogin();
        $surveyId = (int) ($_POST['SurveyId'] ?? 0);
        $this->Survey->dismiss_banner($surveyId, $uid);
        $this->bust_survey_banner_cache();
        $this->jsonOut(['status' => 0]);
    }

    // =========================================================================
    // Results
    // =========================================================================

    public function results($p = null)
    {
        $uid      = $this->requireLogin();
        session_write_close();
        $surveyId = (int) ($_POST['SurveyId'] ?? 0);
        $row      = $this->Survey->get_row($surveyId);
        if ($row === null) {
            $this->jsonOut(['status' => 1, 'error' => 'Survey not found.']);
        }
        // Managers read everything; one org level down reads charts and stats
        // through the lens the domain decides (sharing spec §2).
        $context = $this->orgParam($_POST['Context'] ?? '');
        $access  = $this->Survey->results_access($uid, $row, $context);
        if ($access === null) {
            $pending = $this->Survey->results_pending($uid, $row, $context);
            $this->jsonOut($pending !== null
                ? ['status' => 3, 'pending' => true, 'opens_at' => $pending['opens_at'], 'error' => $this->Survey->sharing_pending_text($pending['opens_at'])]
                : ['status' => 3, 'error' => 'You do not have permission to view results for this survey.']);
        }
        $filters = $this->jsonField('Filters', []);
        // summary.lens is {label} for a shared viewer (spec §5), null for a manager.
        $out = $this->Survey->results_for($surveyId, $filters, $access);
        $this->jsonOut([
            'status'    => 0,
            'summary'   => $out['summary'],
            'questions' => $out['questions'],
            'access'    => $access['level'],
        ]);
    }

    public function rows($p = null)
    {
        $uid      = $this->requireLogin();
        $surveyId = (int) ($_POST['SurveyId'] ?? 0);
        $this->requireManage($uid, $surveyId);
        session_write_close();

        $offset  = (int) ($_POST['Offset'] ?? 0);
        $limit   = (int) ($_POST['Limit'] ?? 100);

        // The domain normalizes the filters and writes the rows_view audit row.
        $out = $this->Survey->rows($surveyId, $this->jsonField('Filters', []), $offset, $limit);

        $this->jsonOut(['status' => 0, 'total' => $out['total'], 'columns' => $out['columns'], 'rows' => $out['rows']]);
    }

    // =========================================================================
    // Attendance credits (sharing-and-credits spec §3, §5)
    // =========================================================================

    public function credit_status($p = null)
    {
        $uid = $this->requireLogin();
        session_write_close();
        $r   = $this->Survey->credit_status($uid, (int) ($_POST['SurveyId'] ?? 0), $this->orgParam($_POST['Grantor'] ?? ''));
        if ((int) $r['Status'] !== 0) {
            $this->envelopeFail($r);
        }
        $this->jsonOut(['status' => 0, 'credit' => $r['Credit']]);
    }

    public function credit_enable($p = null)
    {
        $uid = $this->requireLogin();
        $r   = $this->Survey->credit_enable(
            $uid,
            (int) ($_POST['SurveyId'] ?? 0),
            $this->orgParam($_POST['Grantor'] ?? ''),
            (string) ($_POST['Mode'] ?? ''),
            $this->truthy($_POST['Confirm'] ?? 0)
        );
        if ((int) $r['Status'] !== 0) {
            $this->envelopeFail($r);
        }
        // The acting manager's memoised banner / Available Surveys must not
        // keep offering the survey as it was before this change.
        $this->bust_survey_banner_cache();
        $this->jsonOut(['status' => 0, 'credit_id' => $r['CreditId'], 'granted' => $r['Granted'],
                        'skipped_no_park' => $r['SkippedNoPark'], 'pending' => $r['Pending'],
                        'remaining' => $r['Remaining'], 'already' => $r['Already']]);
    }

    public function credit_reconcile($p = null)
    {
        $uid = $this->requireLogin();
        $r   = $this->Survey->credit_reconcile($uid, (int) ($_POST['SurveyId'] ?? 0), $this->orgParam($_POST['Grantor'] ?? ''));
        if ((int) $r['Status'] !== 0) {
            $this->envelopeFail($r);
        }
        $this->jsonOut(['status' => 0, 'granted' => $r['Granted'], 'skipped_no_park' => $r['SkippedNoPark'], 'pending' => $r['Pending'],
                        'remaining' => $r['Remaining']]);
    }
}
