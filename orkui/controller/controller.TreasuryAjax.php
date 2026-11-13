<?php

class Controller_TreasuryAjax extends Controller
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

        $this->load_model('Treasury');
        $tok = $this->session->token;

        switch ($action) {
            case 'ledger':
                $filters = [
                    'from' => $_GET['from'] ?? null, 'to' => $_GET['to'] ?? null,
                    'category' => $_GET['category'] ?? null, 'direction' => $_GET['direction'] ?? null,
                    'page' => $_GET['page'] ?? 1, 'per' => $_GET['per'] ?? 25,
                ];
                return $this->out($this->Treasury->get_ledger($tok, $owner_type, $owner_id, $filters));
            case 'summary':
                return $this->out($this->Treasury->get_summary($tok, $owner_type, $owner_id, $_GET['from'] ?? null, $_GET['to'] ?? null));
            case 'series':
                return $this->out($this->Treasury->get_series($tok, $owner_type, $owner_id));
            case 'reconciliations':
                return $this->out($this->Treasury->get_reconciliations($tok, $owner_type, $owner_id));
            case 'rev':
                return $this->out($this->Treasury->get_revision($tok, $owner_type, $owner_id));
            case 'getentry':
                return $this->out($this->Treasury->get_entry($tok, $owner_type, $owner_id, (int)($_GET['id'] ?? 0)));
            case 'addentry':
            case 'editentry':
                $data = $this->entryData($owner_type, $owner_id);
                if ($action === 'editentry') {
                    $data['id'] = (int)($_POST['id'] ?? 0);
                }
                return $this->out($this->Treasury->save_entry($tok, $data));
            case 'deleteentry':
                return $this->out($this->Treasury->delete_entry($tok, $owner_type, $owner_id, (int)($_POST['id'] ?? 0)));
            case 'addreconciliation':
                return $this->out($this->Treasury->save_reconciliation($tok, [
                    'owner_type' => $owner_type, 'owner_id' => $owner_id,
                    'as_of_date' => $_POST['as_of_date'] ?? '', 'actual_balance' => $_POST['actual_balance'] ?? 0,
                    'explanation' => $_POST['explanation'] ?? '',
                ]));
            case 'export':
                return $this->exportCsv($tok, $owner_type, $owner_id);
            default:
                echo json_encode(['status' => 4, 'error' => 'Unknown action']);
                exit;
        }
    }

    private function entryData($owner_type, $owner_id)
    {
        return [
            'owner_type' => $owner_type, 'owner_id' => $owner_id,
            'entry_date' => $_POST['entry_date'] ?? '', 'direction' => $_POST['direction'] ?? 'credit',
            'amount' => $_POST['amount'] ?? 0, 'category' => $_POST['category'] ?? '',
            'payment_method' => $_POST['payment_method'] ?? '', 'description' => $_POST['description'] ?? '',
            'counterparty' => $_POST['counterparty'] ?? '', 'reference_no' => $_POST['reference_no'] ?? '',
            'counterparty_player_id' => $_POST['counterparty_player_id'] ?? 0,
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

    private function exportCsv($tok, $owner_type, $owner_id)
    {
        $res = $this->Treasury->get_ledger($tok, $owner_type, $owner_id, ['per' => 100000, 'page' => 1,
            'from' => $_GET['from'] ?? null, 'to' => $_GET['to'] ?? null,
            'category' => $_GET['category'] ?? null, 'direction' => $_GET['direction'] ?? null]);
        if (($res['Status'] ?? 4) !== 0) {
            echo json_encode(['status' => $res['Status'] ?? 4, 'error' => 'Denied']);
            exit;
        }
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="treasury_' . $owner_type . '_' . $owner_id . '.csv"');
        $rows = array_reverse($res['Detail']['Rows']); // chronological for export
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Date', 'Direction', 'Amount', 'Category', 'Payment Method', 'Description', 'Counterparty', 'Reference', 'Running Balance']);
        foreach ($rows as $r) {
            fputcsv($out, [$r['Date'], $r['Direction'], number_format($r['Amount'], 2, '.', ''),
                $r['Category'], $r['PaymentMethod'], $r['Description'], $r['Counterparty'], $r['ReferenceNo'],
                number_format($r['RunningBalance'], 2, '.', '')]);
        }
        fclose($out);
        exit;
    }
}
