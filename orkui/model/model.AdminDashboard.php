<?php

class Model_AdminDashboard extends Model
{
    public function dashboard_stats(): array
    {
        return $this->_report()->GetAdminDashboardStats();
    }

    public function global_admin_grants(): array
    {
        return $this->_administration()->GetGlobalAdminGrants();
    }

    public function active_kingdoms_for_permissions(): array
    {
        return $this->_administration()->GetActiveKingdomsForPermissions();
    }

    public function scoped_auths(string $type, int $id): array
    {
        return $this->_administration()->GetScopedAuths($type, $id);
    }

    public function kingdom_park_auths(int $kingdomId): array
    {
        return $this->_administration()->GetKingdomParkAuths($kingdomId);
    }

    public function event_inherited_permissions(int $eventId): array
    {
        return $this->_administration()->GetEventInheritedPermissions($eventId);
    }

    public function audit_log(array $filters): array
    {
        return $this->_dangeraudit()->ListAuditLog($filters);
    }

    public function audit_methods(): array
    {
        return $this->_dangeraudit()->ListAuditMethods();
    }

    public function server_health_db_status(array $wanted): array
    {
        return $this->_administration()->GetServerHealthDbStatus($wanted);
    }

    public function server_health_processes(int $limit = 20): array
    {
        return $this->_administration()->GetServerHealthProcesses($limit);
    }

    public function server_health_weather_summary(): array
    {
        return $this->_administration()->GetServerHealthWeatherSummary();
    }

    public function api_stats(int $days = 3): array
    {
        return $this->_weather()->api_stats($days);
    }

    public function admin_refresh_with_prior(): array
    {
        return $this->_weather()->AdminRefreshWithPrior();
    }

    public function load_test_kingdom_targets(int $limit = 10): array
    {
        return $this->_administration()->GetActiveKingdomLoadTestTargets($limit);
    }

    public function infer_suspended_by_id(int $mundaneId, int $submittedById, int $sessionUserId): int
    {
        return $this->_player()->InferSuspendedById($mundaneId, $submittedById, $sessionUserId);
    }

    public function park_abbr_check(int $parkId, int $kingdomId): array
    {
        $abbr = $this->_park_profile()->GetParkAbbreviation($parkId);
        if ($abbr === null) {
            return ['status' => 1, 'error' => 'Park not found.'];
        }
        $conflict = $this->_park_profile()->GetParkAbbreviationConflict($kingdomId, $abbr, $parkId);

        return [
            'status' => 0,
            'abbr' => $abbr,
            'taken' => $conflict !== null,
            'conflictName' => $conflict ?? '',
        ];
    }

    public function kingdom_abbr_check(string $abbr, int $excludeKingdomId): array
    {
        $conflictName = $this->_kingdom_profile()->GetKingdomAbbreviationConflict($abbr, $excludeKingdomId);

        return [
            'status' => 0,
            'taken' => $conflictName !== null,
            'name' => $conflictName ?? '',
        ];
    }

    public function state_of_amtgard_bootstrap(): array
    {
        return $this->_state_of_amtgard()->GetPageBootstrap();
    }

    public function state_of_amtgard_validate_date_range(string $startInput, string $endInput, ?string $environment = null): array
    {
        return $this->_state_of_amtgard()->ValidateDateRange($startInput, $endInput, $environment);
    }

    public function state_of_amtgard_chart_section(string $section, string $start, string $end, array $kingdomIds): ?array
    {
        return $this->_state_of_amtgard()->DispatchChartSection($section, $start, $end, $kingdomIds);
    }

    public function audit_display_maps(array $auditRows, int $byWhomFilter, int $entityFilter, string $entityTypeFilter): array
    {
        return $this->_dangeraudit()->ResolveAuditDisplayMaps($auditRows, $byWhomFilter, $entityFilter, $entityTypeFilter);
    }

    public function list_all_kingdom_names(): array
    {
        return $this->_administration()->ListAllKingdomNames();
    }

    private function _report(): Report
    {
        return new Report();
    }

    private function _administration(): Administration
    {
        return new Administration();
    }

    private function _dangeraudit(): Dangeraudit
    {
        return new Dangeraudit();
    }

    private function _player(): Player
    {
        return new Player();
    }

    private function _park_profile(): ParkProfile
    {
        return new ParkProfile();
    }

    private function _kingdom_profile(): KingdomProfile
    {
        return new KingdomProfile();
    }

    private function _state_of_amtgard(): StateOfAmtgard
    {
        return new StateOfAmtgard();
    }

    private function _weather(): Weather
    {
        return new Weather();
    }
}
