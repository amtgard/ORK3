<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * C-01: Model_Event / Model_EventPlanning must route planning/embed APIs through
 * EventPlanning / EventEmbed — APIModel('Event') only binds plain Event.
 */
final class ModelEventDelegationTest extends TestCase
{
    private EventPlanningFixture $fixture;

    private Model_Event $model;

    private Model_EventPlanning $planningModel;

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }

        require_once DIR_UI . 'model/model.EventPlanning.php';

        $this->fixture = EventPlanningFixture::create();
        $this->model = new Model_Event();
        $this->planningModel = new Model_EventPlanning();
    }

    protected function tearDown(): void
    {
        if (isset($this->fixture)) {
            $this->fixture->cleanup();
        }
    }

    public function testPlainEventDoesNotExposePlanningOrEmbedMethods(): void
    {
        $event = new Event();
        $this->assertFalse(method_exists($event, 'GetSchedule'));
        $this->assertFalse(method_exists($event, 'GetPublishedScheduleEmbed'));
        $this->assertFalse(method_exists($event, 'GetOccurrencePageData'));
        $this->assertFalse(method_exists($event, 'SetEventStatus'));
        $this->assertTrue(method_exists(new EventPlanning(), 'GetSchedule'));
        $this->assertTrue(method_exists(new EventEmbed(), 'GetPublishedScheduleEmbed'));
    }

    public function testModelEventGetScheduleDoesNotFatal(): void
    {
        $ctx = $this->fixture->createPublishedEvent('c01-sched');
        $this->fixture->insertSchedule($ctx['detail_id'], 'Opening Court');

        $schedule = $this->model->get_schedule($ctx['detail_id']);
        $this->assertIsArray($schedule);
        $this->assertNotEmpty($schedule);
        $this->assertSame('Opening Court', $schedule[0]['Title']);
    }

    public function testModelEventPublishedEmbedDoesNotFatal(): void
    {
        $ctx = $this->fixture->createPublishedEvent('c01-embed');
        $this->fixture->insertSchedule($ctx['detail_id'], 'Embed Court');

        $embed = $this->model->get_published_schedule_embed($ctx['event_id'], $ctx['detail_id']);
        $this->assertTrue($embed['ok'] ?? false);
        $this->assertSame($ctx['event_id'], (int) $embed['event_id']);
        $this->assertNotEmpty($embed['schedule']);
        $this->assertSame('Embed Court', $embed['schedule'][0]['Title']);
    }

    public function testModelEventOccurrencePageDataDoesNotFatal(): void
    {
        $ctx = $this->fixture->createPublishedEvent('c01-occ');
        $this->fixture->insertSchedule($ctx['detail_id'], 'Page Court');

        $data = $this->model->get_occurrence_page_data($ctx['event_id'], $ctx['detail_id']);
        $this->assertIsArray($data);
        $this->assertSame(0, (int) ($data['Status']['Status'] ?? 1));
        $this->assertNotEmpty($data['ScheduleList'] ?? []);
        $this->assertSame('Page Court', $data['ScheduleList'][0]['Title']);
    }

    public function testModelEventPlanningPreviewDoesNotFatal(): void
    {
        $ctx = $this->fixture->createPublishedEvent('c01-preview');
        $preview = $this->planningModel->get_preview($ctx['event_id'], $ctx['detail_id']);
        $this->assertIsArray($preview);
        $this->assertSame(0, (int) ($preview['Status']['Status'] ?? $preview['Status'] ?? 1));
    }
}
