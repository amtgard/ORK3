<?php

class Model_Treasury extends Model
{
    public function __construct()
    {
        parent::__construct();
        $this->Treasury = new APIModel('Treasury');
    }

    public function has_opening($token, $ot, $oid)            { return $this->Treasury->HasOpeningBalance($token, $ot, $oid); }
    public function get_owner_name($token, $ot, $oid)         { return $this->Treasury->GetOwnerName($token, $ot, $oid); }
    public function get_revision($token, $ot, $oid)           { return $this->Treasury->GetRevision($token, $ot, $oid); }
    public function get_ledger($token, $ot, $oid, $filters)   { return $this->Treasury->GetLedger($token, $ot, $oid, $filters); }
    public function get_entry($token, $ot, $oid, $id)         { return $this->Treasury->GetEntry($token, $ot, $oid, $id); }
    public function save_entry($token, $data)                 { return $this->Treasury->SaveEntry($token, $data); }
    public function delete_entry($token, $ot, $oid, $id)      { return $this->Treasury->DeleteEntry($token, $ot, $oid, $id); }
    public function get_summary($token, $ot, $oid, $f, $t)    { return $this->Treasury->GetSummary($token, $ot, $oid, $f, $t); }
    public function get_series($token, $ot, $oid)             { return $this->Treasury->GetBalanceSeries($token, $ot, $oid); }
    public function get_reconciliations($token, $ot, $oid)    { return $this->Treasury->GetReconciliations($token, $ot, $oid); }
    public function save_reconciliation($token, $data)        { return $this->Treasury->SaveReconciliation($token, $data); }
}
