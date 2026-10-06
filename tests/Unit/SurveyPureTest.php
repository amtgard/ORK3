<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for the DB-free pieces of the Survey domain class
 * (survey module, spec §5). Everything else in class.Survey.php is SQL and is
 * covered by the smoke run and the integration suite; these two helpers are
 * shared by every surface, so they are pinned here.
 */
final class SurveyPureTest extends TestCase
{
    private function survey(): Survey
    {
        return new Survey();
    }

    // ------------------------------------------------------------- markdown

    public function testRenderMarkdownEmitsHtmlAndStripsRawHtml(): void
    {
        $html = $this->survey()->renderMarkdown('**a** <script>x</script>');
        $this->assertStringContainsString('<strong>a</strong>', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }

    public function testRenderMarkdownNullIsEmptyString(): void
    {
        $this->assertSame('', $this->survey()->renderMarkdown(null));
        $this->assertSame('', $this->survey()->renderMarkdown('   '));
    }

    public function testRenderMarkdownKeepsOnlySurveyUploadImages(): void
    {
        $own  = HTTP_SURVEY_IMAGE . '000007-0123456789abcdef.png';
        $html = $this->survey()->renderMarkdown(
            "![ours]($own)\n\n![pixel](https://evil.example/t.gif?u=1)\n\n"
            . "![ref][r]\n\n[r]: //evil.example/p.png\n\n"
            . '![up](' . HTTP_SURVEY_IMAGE . '../players/1.png) ![data](data:image/png;base64,AAAA)'
        );
        $this->assertStringContainsString('src="' . htmlspecialchars($own, ENT_QUOTES) . '"', $html);
        $this->assertSame(1, substr_count($html, '<img'));
        $this->assertStringNotContainsString('evil.example', $html);
        $this->assertStringNotContainsString('players', $html);
        $this->assertStringContainsString('pixel', $html); // alt text survives as text
    }

    public function testIsSurveyImageSrcAllowlist(): void
    {
        $s    = $this->survey();
        $path = (string) parse_url(HTTP_SURVEY_IMAGE, PHP_URL_PATH);
        $this->assertTrue($s->isSurveyImageSrc(HTTP_SURVEY_IMAGE . '000009.jpg'));
        $this->assertTrue($s->isSurveyImageSrc($path . '000009.jpg'));
        $this->assertFalse($s->isSurveyImageSrc('https://evil.example' . $path . '000009.jpg'));
        $this->assertFalse($s->isSurveyImageSrc(HTTP_SURVEY_IMAGE . '000009.jpg?x=1'));
        $this->assertFalse($s->isSurveyImageSrc(HTTP_SURVEY_IMAGE . 'sub/000009.jpg'));
        $this->assertFalse($s->isSurveyImageSrc(HTTP_SURVEY_IMAGE . '000009.jpg' . "\n"));
        $this->assertFalse($s->isSurveyImageSrc('//evil.example/x.png'));
        $this->assertFalse($s->isSurveyImageSrc(''));
    }

    // ---------------------------------------------------------------- images

    public function testImageUrlUsesZeroPaddedIdAndExtension(): void
    {
        $url = $this->survey()->imageUrl(['image_id' => 7, 'ext' => 'png']);
        $this->assertSame(HTTP_SURVEY_IMAGE . '000007.png', $url);
    }

    public function testImageUrlFallsBackToJpgForAnUnknownExtension(): void
    {
        $url = $this->survey()->imageUrl(['image_id' => 12, 'ext' => 'gif']);
        $this->assertSame(HTTP_SURVEY_IMAGE . '000012.jpg', $url);
    }

    public function testImageUrlUsesTheTokenNameWhenTheRowHasOne(): void
    {
        $url = $this->survey()->imageUrl(['image_id' => 7, 'ext' => 'png', 'token' => '0123456789abcdef']);
        $this->assertSame(HTTP_SURVEY_IMAGE . '000007-0123456789abcdef.png', $url);
    }

    public function testImageUrlFallsBackToTheLegacyNameForAnEmptyOrMalformedToken(): void
    {
        $s = $this->survey();
        $this->assertSame(HTTP_SURVEY_IMAGE . '000009.jpg', $s->imageUrl(['image_id' => 9, 'ext' => 'jpg', 'token' => '']));
        // Never let a stored value put path characters into the file name.
        $this->assertSame(HTTP_SURVEY_IMAGE . '000009.jpg', $s->imageUrl(['image_id' => 9, 'ext' => 'jpg', 'token' => '../../etc/passwd']));
        $this->assertSame(HTTP_SURVEY_IMAGE . '000009.jpg', $s->imageUrl(['image_id' => 9, 'ext' => 'jpg', 'token' => 'abc']));
    }

    public function testImageBudgetConstants(): void
    {
        $this->assertSame(40, Survey::MAX_IMAGES_PER_SURVEY);
        $this->assertSame(40 * 1024 * 1024, Survey::MAX_IMAGE_BYTES_PER_SURVEY);
    }

    // ---------------------------------------------------------------- events

    public function testEventLabelIsNameDashLongDate(): void
    {
        $this->assertSame('Coronation — March 14, 2026', Survey::eventLabel('Coronation', '2026-03-14 10:00:00'));
        $this->assertSame('Event', Survey::eventLabel('  ', '0000-00-00 00:00:00'));
    }

    // ------------------------------------------------------------ structure

    public function testIsStructureLockedFollowsOpenedAt(): void
    {
        $s = $this->survey();
        $this->assertFalse($s->isStructureLocked(['opened_at' => null]));
        $this->assertFalse($s->isStructureLocked([]));
        $this->assertTrue($s->isStructureLocked(['opened_at' => '2026-09-09 10:00:00']));
    }

    // --------------------------------------------------------- activity log

    public function testLogActivityWithNoActorWritesNothing(): void
    {
        $db = new SurveyPureFakeDb();
        $s  = $this->withFakes($db, null, fn () => new Survey());
        $this->withFakes($db, null, fn () => $s->logActivity(5, 'update', ['a' => 1]));
        $this->assertSame([], $db->writes);
    }

    public function testLogActivityWritesOneRowForTheActor(): void
    {
        $db = new SurveyPureFakeDb();
        $this->withFakes($db, null, function () {
            $s = new Survey();
            $s->setActor(46193);
            $s->logActivity(5, 'export', ['consent' => 'any']);
            $s->logActivity(5, 'not_an_action');
        });
        $this->assertCount(1, $db->writes);
        $this->assertStringContainsString('INSERT INTO ' . DB_PREFIX . 'survey_activity', $db->writes[0]);
        $this->assertStringContainsString("46193, 'export', '{\"consent\":\"any\"}'", $db->writes[0]);
    }

    // ------------------------------------------------- transactions (#37)

    /** A failed statement mid-retype rolls back and reports failure; nothing commits. */
    public function testRetypeRollsBackWhenAStatementFails(): void
    {
        $db = new SurveyPureFakeDb();
        $db->routes['/FROM ' . DB_PREFIX . 'survey_question WHERE question_id = 11/'] = [[
            'question_id' => 11, 'survey_id' => 5, 'page_id' => 3, 'sort_order' => 0, 'type' => 'single',
            'prompt' => 'Q', 'required' => 1, 'settings' => '{}', 'show_if_question_id' => null, 'show_if_option_id' => null,
        ]];
        $db->routes['/FROM ' . DB_PREFIX . 'survey WHERE survey_id = 5/'] = [['survey_id' => 5, 'opened_at' => null]];
        $db->failOn = '/^DELETE FROM ' . DB_PREFIX . 'survey_option/';

        $r = $this->withFakes($db, null, fn () => (new Survey())->questionUpdate(11, ['Type' => 'rating']));

        $this->assertSame(1, $r['Status']);
        $this->assertContains('ROLLBACK', $db->writes);
        $this->assertNotContains('COMMIT', $db->writes);
        foreach ($db->writes as $sql) {
            $this->assertStringNotContainsString("SET type = 'rating'", $sql);
        }
    }

    public function testQuestionDuplicateIsRefusedOnALockedSurvey(): void
    {
        $db = new SurveyPureFakeDb();
        $db->routes['/FROM ' . DB_PREFIX . 'survey_question WHERE question_id = 11/'] = [[
            'question_id' => 11, 'survey_id' => 5, 'page_id' => 3, 'sort_order' => 0, 'type' => 'single',
            'prompt' => 'Q', 'required' => 0, 'settings' => '{}',
        ]];
        $db->routes['/FROM ' . DB_PREFIX . 'survey WHERE survey_id = 5/'] = [['survey_id' => 5, 'opened_at' => '2026-09-01 10:00:00']];

        $r = $this->withFakes($db, null, fn () => (new Survey())->questionDuplicate(11));

        $this->assertSame(1, $r['Status']);
        $this->assertSame(Survey::LOCKED_ERROR, $r['Error']);
        $this->assertSame([], $db->writes);
    }

    // ---------------------------------- results sharing timing (after close)

    private function at(string $stamp): int
    {
        return (int) strtotime($stamp);
    }

    public function testSharingDoesNotOpenWhileTheSurveyIsTakingResponses(): void
    {
        $now = $this->at('2026-09-20 12:00:00');
        $this->assertNull(Survey::sharingOpensAt(['status' => 'open', 'close_at' => null, 'closed_at' => null], $now));
        $this->assertNull(Survey::sharingOpensAt(['status' => 'open', 'close_at' => '2026-09-25 00:00:00', 'closed_at' => null], $now));
    }

    public function testSharingOpensADayAfterAManualClose(): void
    {
        $now = $this->at('2026-09-20 12:00:00');
        $row = ['status' => 'closed', 'close_at' => null, 'closed_at' => '2026-09-18 15:30:00'];
        $this->assertSame('2026-09-19 15:30:00', Survey::sharingOpensAt($row, $now));
    }

    public function testSharingOpensADayAfterAScheduledCloseThatHasPassed(): void
    {
        $now = $this->at('2026-09-20 12:00:00');
        $row = ['status' => 'open', 'close_at' => '2026-09-20 09:00:00', 'closed_at' => null];
        $this->assertSame('2026-09-21 09:00:00', Survey::sharingOpensAt($row, $now));
    }

    public function testTheEarlierEndWinsWhenBothExist(): void
    {
        $now = $this->at('2026-09-20 12:00:00');
        $closedEarly = ['status' => 'closed', 'close_at' => '2026-09-30 00:00:00', 'closed_at' => '2026-09-15 10:00:00'];
        $this->assertSame('2026-09-16 10:00:00', Survey::sharingOpensAt($closedEarly, $now));
        $closedLate = ['status' => 'closed', 'close_at' => '2026-09-10 00:00:00', 'closed_at' => '2026-09-12 08:00:00'];
        $this->assertSame('2026-09-11 00:00:00', Survey::sharingOpensAt($closedLate, $now));
    }

    public function testAReopenedSurveyHidesSharingAgainUntilItEnds(): void
    {
        // setStatus('open') clears closed_at; a future close date is not an end.
        $now = $this->at('2026-09-20 12:00:00');
        $this->assertNull(Survey::sharingOpensAt(['status' => 'open', 'close_at' => '2026-10-01 00:00:00', 'closed_at' => null], $now));
    }

    public function testDraftsAndArchivedSurveysNeverOpenSharing(): void
    {
        $now = $this->at('2026-09-20 12:00:00');
        $this->assertNull(Survey::sharingOpensAt(['status' => 'draft', 'close_at' => '2026-09-01 00:00:00', 'closed_at' => null], $now));
        $this->assertNull(Survey::sharingOpensAt(['status' => 'archived', 'close_at' => null, 'closed_at' => '2026-09-01 00:00:00'], $now));
    }

    public function testPendingTextNamesTheOpeningTimeOnceTheSurveyHasEnded(): void
    {
        $this->assertSame(
            'Results will be shared with you on September 21, 2026 at 3:00 PM, 24 hours after the survey closed.',
            Survey::sharingPendingText('2026-09-21 15:00:00')
        );
    }

    public function testPendingTextWhileTheSurveyIsStillOpen(): void
    {
        $this->assertSame('Results will be shared with you 24 hours after the survey closes.', Survey::sharingPendingText(null));
    }

    // ------------------------------------------------ schedule sanity (#11)

    public function testScheduleProblemRefusesACloseAtOrBeforeTheOpen(): void
    {
        $msg = 'The closing date must be after the opening date.';
        $this->assertSame($msg, Survey::scheduleProblem('2026-10-01 12:00:00', '2026-09-30 12:00:00'));
        $this->assertSame($msg, Survey::scheduleProblem('2026-10-01 12:00:00', '2026-10-01 12:00:00'));
        $this->assertSame('', Survey::scheduleProblem('2026-10-01 12:00:00', '2026-10-01 12:01:00'));
        // Either side unset is no conflict.
        $this->assertSame('', Survey::scheduleProblem(null, '2026-09-30 12:00:00'));
        $this->assertSame('', Survey::scheduleProblem('2026-10-01 12:00:00', null));
        $this->assertSame('', Survey::scheduleProblem('', ''));
    }

    public function testScheduleInstantsRoundTripThroughTheWallTime(): void
    {
        $wall = '2026-09-28 17:00:00';
        $ts   = Survey::wallToInstant($wall);
        // Same reading the runner's close_ts uses (strtotime on PHP's clock).
        $this->assertSame(strtotime($wall), $ts);
        $this->assertSame($wall, Survey::instantToWall($ts));
        $this->assertNull(Survey::wallToInstant(null));
        $this->assertNull(Survey::wallToInstant(''));
        $this->assertNull(Survey::wallToInstant('0000-00-00 00:00:00'));

        $row = Survey::withInstants(['open_at' => null, 'close_at' => $wall]);
        $this->assertNull($row['open_ts']);
        $this->assertSame($ts, $row['close_ts']);
        $this->assertNull(Survey::withInstants(null));
    }

    public function testNormalizeDateTimeTakesAnInstantOrTheSqlString(): void
    {
        $m = new ReflectionMethod(Survey::class, 'normalizeDateTime');
        $ts = (int) strtotime('2026-09-28 17:00:00');
        $this->assertSame('2026-09-28 17:00:00', $m->invoke($this->survey(), (string) $ts));
        // Older callers that send the wall string keep working.
        $this->assertSame('2026-09-28 17:00:00', $m->invoke($this->survey(), '2026-09-28 17:00:00'));
        $this->assertSame('2026-09-28 17:00:00', $m->invoke($this->survey(), '2026-09-28T17:00'));
        $this->assertNull($m->invoke($this->survey(), 'soon'));
    }

    public function testCloseAtPassed(): void
    {
        $now = $this->at('2026-09-20 12:00:00');
        $this->assertTrue(Survey::closeAtPassed(['close_at' => '2026-09-20 12:00:00'], $now));
        $this->assertTrue(Survey::closeAtPassed(['close_at' => '2026-09-01 00:00:00'], $now));
        $this->assertFalse(Survey::closeAtPassed(['close_at' => '2026-09-20 12:00:01'], $now));
        $this->assertFalse(Survey::closeAtPassed(['close_at' => null], $now));
        $this->assertFalse(Survey::closeAtPassed([], $now));
    }

    public function testOpenAtPending(): void
    {
        $now = $this->at('2026-09-20 12:00:00');
        $this->assertTrue(Survey::openAtPending(['open_at' => '2026-09-20 12:00:01'], $now));
        $this->assertTrue(Survey::openAtPending(['open_at' => '2026-10-01 00:00:00'], $now));
        $this->assertFalse(Survey::openAtPending(['open_at' => '2026-09-20 12:00:00'], $now));
        $this->assertFalse(Survey::openAtPending(['open_at' => '2026-09-01 00:00:00'], $now));
        $this->assertFalse(Survey::openAtPending(['open_at' => null], $now));
        $this->assertFalse(Survey::openAtPending(['open_at' => '0000-00-00 00:00:00'], $now));
        $this->assertFalse(Survey::openAtPending([], $now));
    }

    public function testOpeningPastTheScheduledCloseIsRefused(): void
    {
        foreach (['draft', 'closed'] as $from) {   // first open and reopen
            $db = new SurveyPureFakeDb();
            $db->routes['/FROM ' . DB_PREFIX . 'survey WHERE survey_id = 5/'] = [[
                'survey_id' => 5, 'status' => $from, 'opened_at' => null, 'close_at' => date('Y-m-d H:i:s', time() - 60),
            ]];

            $r = $this->withFakes($db, null, fn () => (new Survey())->setStatus(5, 'open'));

            $this->assertSame(1, $r['Status']);
            $this->assertSame('The closing date has passed; change or clear it before opening.', $r['Error']);
            $this->assertSame([], $db->writes);
        }
    }

    // ------------------------------------------------ status transitions

    /** @return list<array{string, bool, string}> [from, opened?, to] */
    public static function allowedTransitions(): array
    {
        return [
            ['draft', false, 'open'],
            ['draft', false, 'archived'],
            ['open', true, 'closed'],
            ['open', true, 'archived'],
            ['closed', true, 'open'],
            ['closed', true, 'archived'],
            ['archived', true, 'open'],   // the builder's "Reopen survey"
            ['archived', false, 'open'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('allowedTransitions')]
    public function testStatusTransitionAllowed(string $from, bool $opened, string $to): void
    {
        $row = ['status' => $from, 'opened_at' => $opened ? '2026-09-01 10:00:00' : null];
        $this->assertNull(Survey::statusTransitionError($row, $to));
    }

    /** @return list<array{string, bool, string, string}> [from, opened?, to, error] */
    public static function refusedTransitions(): array
    {
        $back = 'This survey has been opened, so it cannot go back to draft.';
        return [
            ['open', true, 'draft', $back],
            ['closed', true, 'draft', $back],
            ['archived', true, 'draft', $back],
            ['archived', false, 'draft', 'A survey that is archived cannot be moved to draft.'],
            ['draft', false, 'draft', 'This survey is already draft.'],
            ['draft', false, 'closed', 'A survey that is draft cannot be moved to closed.'],
            ['open', true, 'open', 'This survey is already open.'],
            ['closed', true, 'closed', 'This survey is already closed.'],
            ['archived', true, 'closed', 'A survey that is archived cannot be moved to closed.'],
            ['archived', true, 'archived', 'This survey is already archived.'],
            ['', false, 'open', 'A survey that is in an unknown state cannot be moved to open.'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refusedTransitions')]
    public function testStatusTransitionRefused(string $from, bool $opened, string $to, string $error): void
    {
        $row = ['status' => $from, 'opened_at' => $opened ? '2026-09-01 10:00:00' : null];
        $this->assertSame($error, Survey::statusTransitionError($row, $to));
    }

    public function testSetStatusRefusesDraftOnAnOpenedSurveyWithoutWriting(): void
    {
        $db = new SurveyPureFakeDb();
        $db->routes['/FROM ' . DB_PREFIX . 'survey WHERE survey_id = 5/'] = [[
            'survey_id' => 5, 'status' => 'closed', 'opened_at' => '2026-09-01 10:00:00', 'close_at' => null,
        ]];

        $r = $this->withFakes($db, null, fn () => (new Survey())->setStatus(5, 'draft'));

        $this->assertSame(1, $r['Status']);
        $this->assertSame('This survey has been opened, so it cannot go back to draft.', $r['Error']);
        $this->assertSame([], $db->writes);
    }

    // ------------------------------------------ event audience picker (#7)

    public function testEventPickerExcludesCreditPlaceholdersAndUsesThePhpClock(): void
    {
        $m   = new ReflectionMethod(Survey::class, 'eventOccurrenceSql');
        $sql = $m->invoke($this->survey(), ['scope_type' => 'park', 'scope_id' => 9], '', true);

        $prefix = SurveyCredit::EVENT_PREFIX;
        $this->assertStringContainsString(
            "LEFT(e.name, " . mb_strlen($prefix) . ") <> '" . str_replace("'", "''", $prefix) . "'",
            $sql
        );
        $this->assertStringNotContainsString('NOW()', $sql);
        $this->assertMatchesRegularExpression("/BETWEEN '\\d{4}-\\d\\d-\\d\\d \\d\\d:\\d\\d:\\d\\d' - INTERVAL 12 MONTH/", $sql);
    }

    public function testRowStampsUseThePhpClockNotSqlNow(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/system/lib/ork3/class.Survey.php');
        $code = preg_replace('~//[^\n]*|/\*.*?\*/~s', '', $src);
        $this->assertStringNotContainsString('NOW()', $code);

        $m = new ReflectionMethod(Survey::class, 'stampSql');
        $before = date('Y-m-d H:i:s');
        $stamp  = $m->invoke($this->survey());
        $this->assertMatchesRegularExpression("/^updated_at = '(\\d{4}-\\d\\d-\\d\\d \\d\\d:\\d\\d:\\d\\d)'/", $stamp);
        preg_match("/'(.+?)'/", $stamp, $mm);
        $this->assertGreaterThanOrEqual($before, $mm[1]);
    }

    public function testUpdateRefusesACloseBeforeTheStoredOpen(): void
    {
        $db = new SurveyPureFakeDb();
        $db->routes['/FROM ' . DB_PREFIX . 'survey WHERE survey_id = 5/'] = [[
            'survey_id' => 5, 'scope_type' => 'kingdom', 'open_at' => '2026-10-10 09:00:00', 'close_at' => null,
        ]];

        $r = $this->withFakes($db, null, fn () => (new Survey())->update(5, ['CloseAt' => '2026-10-01 09:00']));

        $this->assertSame(1, $r['Status']);
        $this->assertSame('The closing date must be after the opening date.', $r['Error']);
        $this->assertSame([], $db->writes);
    }

    // -------------------------------------------- manageable scopes (#47)

    /**
     * manageableScopes() nominates candidates from the grant rows but offers
     * only what canCreate() — the HasAuthority walk create() is gated by —
     * allows. A principality the walk refuses and a park in it are NOT offered.
     */
    public function testManageableScopesOffersOnlyWhatCanCreateAllows(): void
    {
        $db = new SurveyPureFakeDb();
        $db->routes['/FROM ' . DB_PREFIX . 'authorization/'] = [
            ['park_id' => 0, 'kingdom_id' => 17],
            ['park_id' => 500, 'kingdom_id' => 0],
        ];
        $db->routes['/parent_kingdom_id IN \(17\)/'] = [['kingdom_id' => 99]];
        $db->routes['/SELECT kingdom_id, name FROM ' . DB_PREFIX . 'kingdom/'] = [
            ['kingdom_id' => 17, 'name' => 'Kingdom'],
            ['kingdom_id' => 99, 'name' => 'Deep Principality'],
        ];
        $db->routes['/FROM ' . DB_PREFIX . 'park/'] = [
            ['park_id' => 1, 'kingdom_id' => 17, 'name' => 'Home Park'],
            ['park_id' => 2, 'kingdom_id' => 99, 'name' => 'Principality Park'],
            ['park_id' => 500, 'kingdom_id' => 40, 'name' => 'Held Park'],
        ];
        $allowed = ['Kingdom:17' => true, 'Park:1' => true, 'Park:500' => true];
        $auth    = new SurveyPureFakeAuth($allowed);

        [$scopes, $consistent] = $this->withFakes($db, $auth, function () {
            $s      = new Survey();
            $scopes = $s->manageableScopes(46193);
            $ok     = true;
            foreach ($scopes as $sc) {
                $ok = $ok && $s->canCreate(46193, $sc['scope_type'], $sc['scope_id']);
            }
            return [$scopes, $ok];
        });

        $offered = array_map(fn ($sc) => $sc['scope_type'] . ':' . $sc['scope_id'], $scopes);
        sort($offered);
        $this->assertSame(['kingdom:17', 'park:1', 'park:500'], $offered);
        $this->assertTrue($consistent);
    }

    /**
     * SurveyAjax/update forwards an explicit allowlist of POST keys. A field the
     * domain accepts but the allowlist omits is silently dropped while update
     * still answers status 0 (ResultsShare was, sharing-and-credits review).
     * Every scalar Survey::update() field must be forwarded; AudienceKingdomIds
     * is the one field the controller JSON-decodes separately.
     */
    public function testSurveyAjaxUpdateForwardsEveryFieldTheDomainAccepts(): void
    {
        require_once DIR_UI . 'controller/controller.SurveyAjax.php';
        $domain = array_keys((new ReflectionClass(Survey::class))->getConstant('UPDATE_FIELDS'));
        $domain = array_values(array_diff($domain, ['AudienceKingdomIds']));
        $forwarded = (new ReflectionClass(Controller_SurveyAjax::class))->getConstant('UPDATE_SCALAR_FIELDS');
        $this->assertIsArray($forwarded, 'the controller allowlist is a class constant');
        sort($domain);
        sort($forwarded);
        $this->assertSame($domain, $forwarded);
        $this->assertContains('ResultsShare', $forwarded);
    }

    /**
     * CSRF_EXEMPT holds only read-only actions plus the owner-approved
     * dismiss_banner write. `definition` (ork_survey_start) and `rows` (audit
     * row) write, so they must present X-CSRF-Token.
     */
    public function testSurveyAjaxCsrfExemptIsReadOnlyPlusDismissBanner(): void
    {
        require_once DIR_UI . 'controller/controller.SurveyAjax.php';
        $exempt = (new ReflectionClass(Controller_SurveyAjax::class))->getConstant('CSRF_EXEMPT');
        $this->assertIsArray($exempt);
        sort($exempt);
        $expected = [
            'available', 'credit_status', 'dismiss_banner', 'event_options', 'get',
            'help', 'preview_md', 'results', 'scopes',
        ];
        $this->assertSame($expected, $exempt);
        $this->assertNotContains('definition', $exempt);
        $this->assertNotContains('rows', $exempt);
    }

    /**
     * Run $fn with $GLOBALS['DB'] (and optionally Ork3::$Lib->authorization)
     * replaced by fakes, restoring both afterwards.
     */
    private function withFakes(SurveyPureFakeDb $db, ?SurveyPureFakeAuth $auth, callable $fn)
    {
        $oldDb  = $GLOBALS['DB'] ?? null;
        $oldLib = Ork3::$Lib;
        $GLOBALS['DB'] = $db;
        if ($auth !== null) {
            $lib = new stdClass();
            $lib->authorization = $auth;
            Ork3::$Lib = $lib;
        }
        try {
            return $fn();
        } finally {
            $GLOBALS['DB'] = $oldDb;
            Ork3::$Lib     = $oldLib;
        }
    }
}

/** Minimal stand-in for YapoMysql: routes reads by regex, records writes. */
final class SurveyPureFakeDb
{
    /** @var array<string, list<array<string, mixed>>> regex => rows */
    public array $routes = [];
    /** @var list<string> */
    public array $writes = [];
    public string $failOn = '';

    public function Clear(): void
    {
    }

    public function DataSet(string $sql): SurveyPureFakeResult
    {
        $sql = preg_replace('/\s+/', ' ', $sql);
        foreach ($this->routes as $re => $rows) {
            if (preg_match($re, $sql)) {
                return new SurveyPureFakeResult($rows);
            }
        }
        return new SurveyPureFakeResult([]);
    }

    public function ExecuteChecked(string $sql): bool
    {
        $sql = trim(preg_replace('/\s+/', ' ', $sql));
        $this->writes[] = $sql;
        return !($this->failOn !== '' && preg_match($this->failOn, $sql));
    }

    public function Execute(string $sql): void
    {
        $this->ExecuteChecked($sql);
    }
}

final class SurveyPureFakeResult
{
    private int $i = -1;

    /** @param list<array<string, mixed>> $rows */
    public function __construct(private array $rows)
    {
    }

    public function Next(): bool
    {
        return ++$this->i < count($this->rows);
    }

    /** @return array<string, mixed> */
    public function CurrentFieldSet(): array
    {
        return $this->rows[$this->i];
    }
}

/** HasAuthority stand-in: grants exactly the "Type:id" keys it is given. */
final class SurveyPureFakeAuth
{
    /** @param array<string, bool> $allowed */
    public function __construct(private array $allowed)
    {
    }

    public function HasAuthority($uid, $type, $id, $role): bool
    {
        return !empty($this->allowed[$type . ':' . (int) $id]);
    }
}
