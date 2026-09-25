<?php

/**
 * Controller_Survey — survey module page routes (spec §5, §7).
 *
 * Route=Survey/index[/Kingdom|Park/{id}]  survey list for a scope
 * Route=Survey/build/{id}                 builder
 * Route=Survey/take/{id}[/preview]        runner
 * Route=Survey/s/{slug}                   runner, resolved by share slug
 * Route=Survey/results/{id}               reporting
 * Route=Survey/export/{id}?filters=<json> CSV download
 *
 * Every structural mutation lives in Controller_SurveyAjax; this controller
 * only renders pages and gates them with Model_Survey::can_manage().
 */
class Controller_Survey extends Controller
{
    public function __construct($call = null, $id = null)
    {
        parent::__construct($call, $id);
        $this->load_model('Survey');
        // Every survey page posts to SurveyAjax, whose mutations require this
        // token in X-CSRF-Token (the templates hand it to SvConfig.csrf).
        $this->data['SurveyCsrf'] = $this->Survey->csrf_token();
    }

    /**
     * The theme echoes page_title into <title> unescaped, and survey titles are
     * user input, so escape here.
     */
    private function setPageTitle(string $title): void
    {
        $this->data['page_title'] = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    }

    private function uid(): int
    {
        return isset($this->session->user_id) ? (int) $this->session->user_id : 0;
    }

    /**
     * Runner pages only: restrict where images may load from, so survey copy
     * cannot carry a remote tracking pixel. img-src only — scripts, styles and
     * fonts (cdnjs, Google Fonts, analytics) are left alone. data: is kept for
     * the inline SVG/PNG backgrounds in orkui.css and theme.jui.css; the Google
     * hosts are the site-wide analytics beacons in default.theme.
     */
    private function sendRunnerImageCsp(): void
    {
        if (!headers_sent()) {
            header("Content-Security-Policy: img-src 'self' data: https://*.google-analytics.com https://*.googletagmanager.com");
        }
    }

    /**
     * Runner pages only: the SurveyAjax/definition payload, embedded in the page
     * so the runner draws without a second round trip on a weak park signal.
     * definition_for_respondent() records the survey_start row itself, exactly
     * as the definition action does. Null on any failure: the runner then falls
     * back to POSTing definition, which renders the proper error notice.
     */
    private function embeddedDefinition(int $surveyId, int $uid, bool $preview): ?array
    {
        $r = $this->Survey->definition_for_respondent($surveyId, $uid, $preview);
        if ((int) ($r['Status'] ?? 1) !== 0) {
            return null;
        }
        return [
            'status'   => 0,
            'survey'   => $r['Survey'],
            'pages'    => $r['Pages'],
            'draft'    => $r['Draft'],
            'eligible' => (bool) $r['Eligible'],
            'reason'   => $r['Reason'],
        ];
    }

    /**
     * Runner <title>: the survey title only where the respondent payload shows
     * it (a hidden survey keeps its title unpublished), or to a manager.
     */
    private function setRunnerTitle(?array $definition, ?array $managedRow): void
    {
        $title = (string) ($definition['survey']['title'] ?? ($managedRow['title'] ?? ''));
        if ($title !== '') {
            $this->setPageTitle($title);
        }
    }

    // -----------------------------------------------------------------------
    // index — manageable surveys list, optionally scoped to one org
    // Route: Survey/index, Survey/index/Kingdom/17, Survey/index/Park/1049
    // -----------------------------------------------------------------------
    public function index($scope = null)
    {
        $this->setPageTitle('Surveys');
        $uid = $this->uid();

        $parts     = explode('/', trim((string) $scope, '/'));
        $scopeType = null;
        $scopeId   = null;
        if (isset($parts[0]) && in_array($parts[0], ['Kingdom', 'Park'], true)) {
            $scopeType = strtolower($parts[0]);
            $scopeId   = (int) preg_replace('/[^0-9]/', '', $parts[1] ?? '');
        }

        $scopes = $this->Survey->manageable_scopes($uid);
        if (empty($scopes)) {
            $this->no_authorization('', 'You do not have permission to manage any surveys.');
            return;
        }

        // An org page needs CREATE on that org (sharing spec §1); the picker's
        // scope list stays the create-modal's source.
        if ($scopeType !== null && !$this->Survey->is_ork_admin($uid) && !$this->Survey->can_create($uid, $scopeType, (int) $scopeId)) {
            $this->no_authorization('', 'You do not have permission to see surveys for this ' . $scopeType . '.');
            return;
        }

        // The picker list (manageable_scopes) only carries active kingdoms/parks,
        // so deriving the label from it left a blank scope chip for a retired org
        // or a hand-typed/bookmarked scope. scope_name() answers from
        // ork_kingdom/ork_park regardless of active.
        $scopeName = 'All of Amtgard';
        if ($scopeType !== null) {
            $scopeName = $this->Survey->scope_name($scopeType, $scopeId);
            if ($scopeName === '') {
                $scopeName = ucfirst($scopeType) . ' not found';
            }
        }

        $buckets = $this->Survey->list_for_scope($uid, $scopeType, $scopeId);
        $this->data['Buckets']    = $buckets;
        $this->data['Surveys']    = array_merge($buckets['Rows']['ork'], $buckets['Rows']['kingdom'], $buckets['Rows']['park']);
        $this->data['Scopes']     = $scopes;
        $this->data['ScopeType']  = $scopeType;
        $this->data['ScopeId']    = $scopeId;
        $this->data['ScopeName']  = $scopeName;
        $this->data['IsOrkAdmin'] = $this->Survey->is_ork_admin($uid);
    }

    // -----------------------------------------------------------------------
    // build — survey builder
    // Route: Survey/build/{id}
    // -----------------------------------------------------------------------
    public function build($id = null)
    {
        $uid      = $this->uid();
        $surveyId = (int) preg_replace('/[^0-9]/', '', (string) $id);
        $this->setPageTitle('Survey Builder');

        $row = $this->Survey->get_row($surveyId);
        if ($row === null) {
            $this->data['Error'] = 'Survey not found.';
            return;
        }
        if (!$this->Survey->can_manage($uid, $row)) {
            $this->no_authorization('', 'You do not have permission to manage this survey.');
            return;
        }
        if ((string) ($row['title'] ?? '') !== '') {
            $this->setPageTitle('Survey Builder: ' . $row['title']);
        }

        $result = $this->Survey->get($surveyId);
        if ((int) ($result['Status'] ?? 1) !== 0) {
            $this->data['Error'] = $result['Error'] ?? 'Survey not found.';
            return;
        }

        $this->data['Survey']   = $result;
        $this->data['SurveyId'] = $surveyId;
    }

    // -----------------------------------------------------------------------
    // take — survey runner
    // Route: Survey/take/{id}, Survey/take/{id}/preview
    // -----------------------------------------------------------------------
    public function take($p = null)
    {
        $this->sendRunnerImageCsp();
        $this->setPageTitle('Survey');
        $uid = $this->uid();
        if ($uid <= 0) {
            $this->no_authorization('Survey/take/' . ltrim((string) $p, '/'));
            return;
        }

        $parts    = explode('/', trim((string) $p, '/'));
        $surveyId = (int) preg_replace('/[^0-9]/', '', $parts[0] ?? '');
        $preview  = isset($parts[1]) && $parts[1] === 'preview';

        $row = $this->Survey->get_row($surveyId);
        if ($row === null) {
            $this->data['Error'] = 'Survey not found.';
            return;
        }
        $canManage = $this->Survey->can_manage($uid, $row);
        if ($preview && !$canManage) {
            $this->no_authorization('', 'You do not have permission to preview this survey.');
            return;
        }

        $this->data['SurveyId']   = $surveyId;
        $this->data['IsPreview']  = $preview;
        $this->data['CanManage']  = $canManage;
        // Keys the runner's sessionStorage mirror to this player (shared devices).
        $this->data['ViewerId']   = $uid;
        $this->data['Definition'] = $this->embeddedDefinition($surveyId, $uid, $preview);
        $this->setRunnerTitle($this->data['Definition'], $canManage ? $row : null);
    }

    // -----------------------------------------------------------------------
    // s — runner resolved by share slug
    // Route: Survey/s/{slug}
    // -----------------------------------------------------------------------
    public function s($slug = null)
    {
        $this->sendRunnerImageCsp();
        $this->setPageTitle('Survey');
        $uid = $this->uid();
        if ($uid <= 0) {
            $this->no_authorization('Survey/s/' . ltrim((string) $slug, '/'));
            return;
        }

        // Set before the lookup: the default template for this action
        // (Survey_s.tpl) does not exist, so the not-found notice needs it too.
        $this->template = 'Survey_take.tpl';
        $row = $this->Survey->get_by_slug((string) $slug);
        if ($row === null) {
            $this->data['Error'] = 'Survey not found.';
            return;
        }

        $this->data['SurveyId']  = (int) $row['survey_id'];
        $this->data['IsPreview'] = false;
        $this->data['CanManage'] = $this->Survey->can_manage($uid, $row);
        $this->data['ViewerId']  = $uid;
        $this->data['Definition'] = $this->embeddedDefinition((int) $row['survey_id'], $uid, false);
        $this->setRunnerTitle($this->data['Definition'], $this->data['CanManage'] ? $row : null);
    }

    // -----------------------------------------------------------------------
    // results — reporting
    // Route: Survey/results/{id}
    // -----------------------------------------------------------------------
    public function results($id = null)
    {
        $this->setPageTitle('Survey Results');
        $uid = $this->uid();

        // Survey/results/{id}[/Kingdom|Park/{orgId}] — segments past the third
        // collapse into this one string, so split before reading the id.
        $parts    = explode('/', trim((string) $id, '/'));
        $surveyId = (int) preg_replace('/[^0-9]/', '', $parts[0] ?? '');
        $context  = null;
        if (isset($parts[1]) && in_array($parts[1], ['Kingdom', 'Park'], true)) {
            $context = ['type' => strtolower($parts[1]), 'id' => (int) preg_replace('/[^0-9]/', '', $parts[2] ?? '')];
        }

        $row = $this->Survey->get_row($surveyId);
        if ($row === null) {
            $this->data['Error'] = 'Survey not found.';
            return;
        }
        $access = $this->Survey->results_access($uid, $row, $context);
        if ($access === null) {
            // Held by after-close timing: say when, rather than "no permission".
            $pending = $this->Survey->results_pending($uid, $row, $context);
            if ($pending !== null) {
                if ((string) ($row['title'] ?? '') !== '') {
                    $this->setPageTitle('Survey Results: ' . $row['title']);
                }
                $this->data['SurveyId']           = $surveyId;
                $this->data['Survey']             = ['Survey' => $row];
                $this->data['ResultsPendingText'] = $this->Survey->sharing_pending_text($pending['opens_at']);
                return;
            }
            $this->no_authorization('', 'You do not have permission to view results for this survey.');
            return;
        }

        $result = $this->Survey->get($surveyId);
        if ((string) ($row['title'] ?? '') !== '') {
            $this->setPageTitle('Survey Results: ' . $row['title']);
        }

        // The kingdom filter offers only kingdoms that actually appear in this
        // survey's responses, with counts (#33) — not every kingdom the viewer
        // manages. scope_type/scope_id are kept as aliases of kingdom_id so the
        // existing template keeps rendering until it reads kingdom_id/count.
        // Under a kingdom or park lens, the filter is already fixed by the lens.
        $kingdoms = [];
        if (empty($access['lens']['kingdom_ids']) && empty($access['lens']['park_id'])) {
            // A shared viewer is offered only the one-kingdom picks the server
            // allows (complementary suppression), with the counts those views
            // serve (an ongoing share's snapshot, not the live count).
            $choices = ($access['level'] ?? 'manage') === 'shared'
                ? $this->Survey->shared_kingdom_choices($surveyId, (array) ($access['lens'] ?? []))
                : null;
            foreach ($this->Survey->kingdoms_present($surveyId) as $k) {
                if ($choices !== null) {
                    if (!isset($choices[(int) $k['kingdom_id']])) {
                        continue;
                    }
                    $k['count'] = (int) $choices[(int) $k['kingdom_id']];
                }
                $k['scope_type'] = 'kingdom';
                $k['scope_id']   = (int) $k['kingdom_id'];
                $kingdoms[]      = $k;
            }
        }

        $this->data['SurveyId']       = $surveyId;
        $this->data['Survey']         = $result;
        $this->data['Kingdoms']       = $kingdoms;
        $this->data['Questions']      = $result['Questions'] ?? [];
        $this->data['ResultsAccess']  = $access;
        $this->data['ResultsContext'] = $context !== null ? ucfirst($context['type']) . '/' . $context['id'] : '';
        // The survey's own scope names the chip; empty for ORK scope, so the
        // template's 'ORK-wide' branch applies.
        $scopeName                    = (string) $row['scope_type'] === 'ork' ? '' : $this->Survey->scope_name((string) $row['scope_type'], (int) $row['scope_id']);
        $this->data['ScopeName']      = $scopeName;
        $this->data['OwnerName']      = $scopeName ?: 'All of Amtgard';
        $this->data['AnswerableTypes'] = $this->Survey->answerable_types();
        $this->data['CrosstabSources'] = $this->Survey->crosstab_sources();
        $this->data['CrosstabTargets'] = $this->Survey->crosstab_targets();
    }

    // -----------------------------------------------------------------------
    // export — CSV download of the filtered rows
    // Route: Survey/export/{id}?filters=<json>
    // -----------------------------------------------------------------------
    public function export($id = null)
    {
        $uid      = $this->uid();
        $surveyId = (int) preg_replace('/[^0-9]/', '', (string) $id);

        $row = $this->Survey->get_row($surveyId);
        if ($row === null) {
            $this->data['Error'] = 'Survey not found.';
            return;
        }
        if (!$this->Survey->can_manage($uid, $row)) {
            if ($uid > 0) {
                // A CSV route should not answer 200 with an HTML page to a scripted client.
                http_response_code(403);
            }
            $this->no_authorization('', 'You do not have permission to export this survey.');
            return;
        }

        $filters = [];
        if (isset($_GET['filters'])) {
            $decoded = json_decode((string) $_GET['filters'], true);
            $filters = is_array($decoded) ? $decoded : [];
        }
        // The domain normalizes the filters and writes the export audit row.
        // format=analysis: coded wide CSV; format=codebook: its codebook (#36).
        $format = isset($_GET['format']) && in_array($_GET['format'], ['analysis', 'codebook'], true) ? $_GET['format'] : '';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="survey-' . $surveyId . ($format !== '' ? '-' . $format : '') . '.csv"');
        header('Cache-Control: no-cache, must-revalidate');
        header('X-Content-Type-Options: nosniff');

        // Stream each 500-row batch as it is built (#42) rather than holding
        // the whole file in memory. Drop any output buffers first so flush()
        // actually reaches the client and stray buffered output cannot
        // corrupt the CSV. Release the session lock too, so a long export does
        // not block the viewer's other tabs.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        session_write_close();
        if ($format === 'codebook') {
            echo $this->Survey->analysis_codebook($surveyId);
            exit();
        }
        $stream = $format === 'analysis' ? 'analysis_stream' : 'csv_stream';
        $this->Survey->$stream($surveyId, $filters, function (string $chunk): void {
            echo $chunk;
            flush();
        });
        exit();
    }
}
