<?php

class Controller_InventoryAjax extends Controller
{
    public function handle($p = null)
    {
        header('Content-Type: application/json');
        $parts      = explode('/', $p ?? '');
        $owner_type = ($parts[0] ?? '') === 'park' ? 'park' : 'kingdom';
        $owner_id   = (int)preg_replace('/[^0-9]/', '', $parts[1] ?? '');
        $action     = $parts[2] ?? '';

        if (!isset($this->session->user_id)) {
            echo json_encode(['status' => 5, 'error' => 'Not logged in']);
            exit;
        }
        if (!valid_id($owner_id)) {
            echo json_encode(['status' => 4, 'error' => 'Invalid org']);
            exit;
        }

        $this->load_model('Inventory');
        $tok = $this->session->token;

        switch ($action) {
            case 'items':
                return $this->out($this->Inventory->get_items($tok, $owner_type, $owner_id, $this->itemFilters()));
            case 'summary':
                return $this->out($this->Inventory->get_summary($tok, $owner_type, $owner_id, [
                    'category' => $_GET['category'] ?? null, 'condition' => $_GET['condition'] ?? null,
                    'q' => $_GET['q'] ?? null, 'location' => $_GET['location'] ?? null,
                    'held_by_player_id' => $_GET['held_by_player_id'] ?? null, 'held_by' => $_GET['held_by'] ?? null,
                    'stale' => $_GET['stale'] ?? null,
                ]));
            case 'locations':
                return $this->out($this->Inventory->get_locations($tok, $owner_type, $owner_id));
            case 'rev':
                return $this->out($this->Inventory->get_revision($tok, $owner_type, $owner_id));
            case 'getitem':
                return $this->out($this->Inventory->get_item($tok, $owner_type, $owner_id, (int)($_GET['id'] ?? 0)));
            case 'history':
                return $this->out($this->Inventory->get_item_history($tok, $owner_type, $owner_id, (int)($_GET['id'] ?? 0)));
            case 'audit':
                return $this->out($this->Inventory->get_audit($tok, $owner_type, $owner_id, [
                    'action' => $_GET['action'] ?? '', 'page' => $_GET['page'] ?? 1, 'per' => $_GET['per'] ?? 50,
                ]));
            case 'additem':
            case 'edititem':
                $data = $this->itemData($owner_type, $owner_id);
                if ($action === 'edititem') {
                    $data['id'] = (int)($_POST['id'] ?? 0);
                    // Optional: the quantity the edit form loaded; the lib rejects the save if it changed since.
                    if (isset($_POST['expected_quantity']) && $_POST['expected_quantity'] !== '') {
                        $data['expected_quantity'] = (int)$_POST['expected_quantity'];
                    }
                }
                return $this->out($this->Inventory->save_item($tok, $data));
            case 'removeitem':
                return $this->out($this->Inventory->remove_item(
                    $tok,
                    $owner_type,
                    $owner_id,
                    (int)($_POST['id'] ?? 0),
                    $_POST['removal_reason'] ?? '',
                    $_POST['removal_note'] ?? '',
                    (int)($_POST['units'] ?? 0),
                    [
                        'disposal_date' => $_POST['disposal_date'] ?? '', 'disposal_value' => $_POST['disposal_value'] ?? '',
                        'disposed_to' => $_POST['disposed_to'] ?? '', 'record_treasury' => $_POST['record_treasury'] ?? '',
                        'treasury_category' => $_POST['treasury_category'] ?? '', 'treasury_method' => $_POST['treasury_method'] ?? '',
                    ]
                ));
            case 'verifyitems':
                // Filter-wide verify only on an explicit by_filter=1; otherwise ids are required (lib enforces).
                $byFilter = (string)($_POST['by_filter'] ?? '') === '1';
                $ids = $byFilter ? [] : array_filter(array_map('intval', explode(',', (string)($_POST['ids'] ?? ''))));
                return $this->out($this->Inventory->verify_items($tok, $owner_type, $owner_id, array_values($ids), [
                    'by_filter' => $byFilter,
                    'category' => $_POST['category'] ?? null, 'condition' => $_POST['condition'] ?? null,
                    'q' => $_POST['q'] ?? null, 'location' => $_POST['location'] ?? null,
                    'held_by_player_id' => $_POST['held_by_player_id'] ?? null, 'held_by' => $_POST['held_by'] ?? null,
                    'stale' => $_POST['stale'] ?? null,
                ]));
            case 'splititem':
                return $this->out($this->Inventory->split_item($tok, $owner_type, $owner_id, (int)($_POST['id'] ?? 0), (int)($_POST['units'] ?? 0)));
            case 'restoreitem':
                return $this->out($this->Inventory->restore_item($tok, $owner_type, $owner_id, (int)($_POST['id'] ?? 0)));
            case 'deleteitem':
                return $this->out($this->Inventory->delete_item($tok, $owner_type, $owner_id, (int)($_POST['id'] ?? 0), $_POST['delete_note'] ?? ''));
            case 'undeleteitem':
                return $this->out($this->Inventory->undelete_item($tok, $owner_type, $owner_id, (int)($_POST['id'] ?? 0)));
            case 'export':
                return $this->exportCsv($tok, $owner_type, $owner_id);
            default:
                echo json_encode(['status' => 4, 'error' => 'Unknown action']);
                exit;
        }
    }

    private function itemFilters()
    {
        return [
            'category' => $_GET['category'] ?? null, 'condition' => $_GET['condition'] ?? null,
            'q' => $_GET['q'] ?? null, 'location' => $_GET['location'] ?? null,
            'held_by_player_id' => $_GET['held_by_player_id'] ?? null, 'held_by' => $_GET['held_by'] ?? null,
            'stale' => $_GET['stale'] ?? null, 'status' => $_GET['status'] ?? 'active',
            'sort' => $_GET['sort'] ?? 'name', 'dir' => $_GET['dir'] ?? 'asc',
            'page' => $_GET['page'] ?? 1, 'per' => $_GET['per'] ?? 25,
        ];
    }

    private function itemData($owner_type, $owner_id)
    {
        return [
            'owner_type' => $owner_type, 'owner_id' => $owner_id,
            'name' => $_POST['name'] ?? '', 'category' => $_POST['category'] ?? '',
            'quantity' => $_POST['quantity'] ?? 1, 'condition' => $_POST['condition'] ?? 'good',
            'unit_value' => $_POST['unit_value'] ?? 0, 'location' => $_POST['location'] ?? '',
            'held_by' => $_POST['held_by'] ?? '', 'held_by_player_id' => $_POST['held_by_player_id'] ?? 0,
            'acquired_date' => $_POST['acquired_date'] ?? '', 'notes' => $_POST['notes'] ?? '',
            'change_note' => $_POST['change_note'] ?? '',
        ];
    }

    /** Map a lib Service response to UI JSON {status,error,detail}. */
    private function out($res)
    {
        $status = isset($res['Status']) ? (int)$res['Status'] : 4;
        echo json_encode([
            'status' => $status,
            'error'  => $status === 0 ? null : ($res['Error'] ?? 'Error') . (isset($res['Detail']) && is_string($res['Detail']) ? ': ' . $res['Detail'] : ''),
            'detail' => $res['Detail'] ?? null,
        ]);
        exit;
    }

    /** Neutralise spreadsheet formula injection: prefix a quote to string cells starting with = + - @ tab CR. */
    private function csvSafe($row)
    {
        return array_map(function ($v) {
            return (is_string($v) && $v !== '' && strpos("=+-@\t\r", $v[0]) !== false) ? "'" . $v : $v;
        }, $row);
    }

    private function exportCsv($tok, $owner_type, $owner_id)
    {
        $filters = $this->itemFilters();
        if (($_GET['full'] ?? '') === '1') { $filters['status'] = 'all'; }
        $filters['per'] = 100000;
        $filters['page'] = 1;
        $res = $this->Inventory->get_items($tok, $owner_type, $owner_id, $filters);
        if (($res['Status'] ?? 4) !== 0) {
            echo json_encode(['status' => $res['Status'] ?? 4, 'error' => 'Denied']);
            exit;
        }
        $org = $this->Inventory->get_owner_name($tok, $owner_type, $owner_id);
        $orgName = (string)($org['Detail']['Name'] ?? '');
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="inventory_' . $owner_type . '_' . $owner_id . '_' . date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, $this->csvSafe(['Inventory Register', $orgName]));
        fputcsv($out, ['Exported', date('Y-m-d H:i')]);
        $exportedBy = (string)($org['Detail']['ViewerPersona'] ?? '');
        if ($exportedBy === '') { $exportedBy = 'Player #' . (int)$this->session->user_id; }
        fputcsv($out, $this->csvSafe(['Exported By', $exportedBy]));
        fputcsv($out, ['Status Filter', $res['Detail']['Status']]);
        fputcsv($out, []);
        fputcsv($out, ['Id', 'Name', 'Category', 'Quantity', 'Condition', 'Unit Value', 'Total Value',
            'Location', 'Held By', 'Held By Player ID', 'Acquired', 'Notes', 'Status', 'Removed On',
            'Removal Reason', 'Removal Note', 'Disposal Date', 'Proceeds', 'Disposed To', 'Treasury Entry #',
            'Added On', 'Added By']);
        $activeTotal = 0.0;
        foreach ($res['Detail']['Rows'] as $r) {
            if (!$r['RemovedAt'] && !$r['DeletedAt']) { $activeTotal += $r['TotalValue']; }
            fputcsv($out, $this->csvSafe([$r['Id'], $r['Name'], $r['CategoryLabel'], $r['Quantity'], $r['ConditionLabel'],
                number_format($r['UnitValue'], 2, '.', ''), number_format($r['TotalValue'], 2, '.', ''),
                $r['Location'], $r['HeldBy'], $r['HeldByPlayerId'], $r['AcquiredDate'], $r['Notes'],
                $r['DeletedAt'] ? 'Deleted' : ($r['RemovedAt'] ? 'Removed' : 'Active'), $r['RemovedAt'],
                $r['RemovalReasonLabel'], $r['RemovalNote'], $r['DisposalDate'],
                $r['RemovedAt'] ? number_format($r['DisposalValue'], 2, '.', '') : '', $r['DisposedTo'],
                $r['TreasuryEntryId'] ?: '', $r['CreatedAt'], $r['CreatedByName']]));
        }
        fputcsv($out, []);
        fputcsv($out, ['Active Total Value', number_format($activeTotal, 2, '.', '')]);
        fclose($out);
        exit;
    }
}
