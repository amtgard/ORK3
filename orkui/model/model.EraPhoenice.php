<?php

class Model_EraPhoenice extends Model
{
    public function __construct()
    {
        parent::__construct();
        $this->EraPhoenice = new JSONModel('EraPhoenice');
    }

    public function today(): array
    {
        return $this->EraPhoenice->GetToday();
    }

    public function date(string $iso): array
    {
        return $this->EraPhoenice->GetDate($iso);
    }

    public function holidays(): array
    {
        return $this->EraPhoenice->GetHolidays();
    }
}
