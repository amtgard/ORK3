<?php

class Model_AdminDashboard extends Model
{
    public function dashboard_stats(): array
    {
        $report = new Report();

        return $report->GetAdminDashboardStats();
    }

    public function global_admin_grants(): array
    {
        $admin = new Administration();

        return $admin->GetGlobalAdminGrants();
    }

    public function active_kingdoms_for_permissions(): array
    {
        $admin = new Administration();

        return $admin->GetActiveKingdomsForPermissions();
    }

    public function scoped_auths(string $type, int $id): array
    {
        $admin = new Administration();

        return $admin->GetScopedAuths($type, $id);
    }

    public function kingdom_park_auths(int $kingdomId): array
    {
        $admin = new Administration();

        return $admin->GetKingdomParkAuths($kingdomId);
    }

    public function event_inherited_permissions(int $eventId): array
    {
        $admin = new Administration();

        return $admin->GetEventInheritedPermissions($eventId);
    }

    public function audit_log(array $filters): array
    {
        $audit = new Dangeraudit();

        return $audit->ListAuditLog($filters);
    }

    public function audit_methods(): array
    {
        $audit = new Dangeraudit();

        return $audit->ListAuditMethods();
    }

    public function server_health_db_status(array $wanted): array
    {
        $admin = new Administration();

        return $admin->GetServerHealthDbStatus($wanted);
    }

    public function server_health_processes(int $limit = 20): array
    {
        $admin = new Administration();

        return $admin->GetServerHealthProcesses($limit);
    }

    public function server_health_weather_summary(): array
    {
        $admin = new Administration();

        return $admin->GetServerHealthWeatherSummary();
    }

    public function load_test_kingdom_targets(int $limit = 10): array
    {
        $admin = new Administration();

        return $admin->GetActiveKingdomLoadTestTargets($limit);
    }

    public function infer_suspended_by_id(int $mundaneId, int $submittedById, int $sessionUserId): int
    {
        $player = new Player();

        return $player->InferSuspendedById($mundaneId, $submittedById, $sessionUserId);
    }

    public function park_abbr_check(int $parkId, int $kingdomId): array
    {
        $profile = new ParkProfile();
        $abbr = $profile->GetParkAbbreviation($parkId);
        if ($abbr === null) {
            return ['status' => 1, 'error' => 'Park not found.'];
        }
        $conflict = $profile->GetParkAbbreviationConflict($kingdomId, $abbr, $parkId);

        return [
            'status' => 0,
            'abbr' => $abbr,
            'taken' => $conflict !== null,
            'conflictName' => $conflict ?? '',
        ];
    }

    public function kingdom_abbr_check(string $abbr, int $excludeKingdomId): array
    {
        $profile = new KingdomProfile();
        $conflictName = $profile->GetKingdomAbbreviationConflict($abbr, $excludeKingdomId);

        return [
            'status' => 0,
            'taken' => $conflictName !== null,
            'name' => $conflictName ?? '',
        ];
    }

    public function state_of_amtgard_bootstrap(): array
    {
        return Ork3::$Lib->stateofamtgard->GetPageBootstrap();
    }
}
