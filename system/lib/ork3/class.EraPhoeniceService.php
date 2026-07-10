<?php

/**
 * Era Phoenice public JSON service (T-LIB-05).
 */
class EraPhoeniceService
{
    public function GetToday(): array
    {
        return $this->buildPayload(new DateTimeImmutable('today'));
    }

    public function GetDate(string $date): array
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return [
                'ok' => false,
                'error' => 'date must be YYYY-MM-DD',
            ];
        }
        try {
            $d = new DateTimeImmutable($date);
        } catch (Exception $e) {
            return [
                'ok' => false,
                'error' => 'invalid calendar date',
            ];
        }

        return $this->buildPayload($d);
    }

    public function GetHolidays(): array
    {
        return [
            'ok' => true,
            'holidays' => EraPhoenice::HOLIDAYS,
        ];
    }

    private function buildPayload(DateTimeImmutable $d): array
    {
        $ep = EraPhoenice::fromDate($d);
        $im = EraPhoenice::imperiumFromDate($d);
        $last = EraPhoenice::lastHoliday($d);
        $next = EraPhoenice::nextHoliday($d);

        return [
            'ok' => true,
            'date' => $d->format('Y-m-d'),
            'ep' => [
                'year' => $ep['year'],
                'month' => $ep['month'],
                'day' => $ep['day'],
                'formatted' => EraPhoenice::format($d),
            ],
            'imperium' => [
                'year' => $im['year'],
                'month' => $im['month'],
                'day' => $im['day'],
                'formatted' => EraPhoenice::imperiumFormat($d),
            ],
            'holiday' => EraPhoenice::holiday($d),
            'last_holiday' => $last ? [
                'name' => $last['name'],
                'date' => $last['date']->format('Y-m-d'),
                'civil' => EraPhoenice::longCivil($last['date']),
            ] : null,
            'next_holiday' => $next ? [
                'name' => $next['name'],
                'date' => $next['date']->format('Y-m-d'),
                'civil' => EraPhoenice::longCivil($next['date']),
            ] : null,
        ];
    }
}
