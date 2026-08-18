<?php

class Model_Unit extends Model
{
    public function __construct()
    {
        parent::__construct();
        $this->Unit = new APIModel('Unit');
        $this->Report = new APIModel('Report');
        $this->Authorization = new APIModel('Authorization');
        $this->Heraldry = new APIModel('Heraldry');
    }

    public function merge($request)
    {
        return $this->Unit->MergeUnits($request);
    }

    public function convert_to_household($unit_id)
    {
        return $this->Unit->ConvertToHousehold(array(
                'Token' => $this->session->token,
                'UnitId' => $unit_id
            ));
    }

    public function convert_to_company($unit_id)
    {
        return $this->Unit->ConvertToCompany(array(
                'Token' => $this->session->token,
                'UnitId' => $unit_id
            ));
    }

    public function create_unit($request)
    {
        return $this->Unit->CreateUnit($request);
    }

    public function get_heraldry($unit_id, $size = null)
    {
        return $this->Heraldry->GetHeraldryUrl(array( 'Type' => 'Unit', 'Id' => $unit_id, 'Size' => $size ));
    }

    public function set_unit_details($request)
    {
        return $this->Unit->SetUnit($request);
    }

    public function upload_unit_heraldry($request)
    {
        return $this->Heraldry->SetUnitHeraldry($request);
    }

    public function remove_unit_heraldry($request)
    {
        return $this->Heraldry->RemoveUnitHeraldry($request);
    }

    public function add_unit_auth($request)
    {
        logtrace("add_unit_auth()", $request);
        return $this->Authorization->AddAuthorization($request);
    }

    public function set_unit_member($request)
    {
        logtrace("set_unit_member()", $request);
        return $this->Unit->SetMember($request);
    }

    public function add_unit_member($request)
    {
        return $this->Unit->AddMember($request);
    }

    public function retire_unit_member($request)
    {
        return $this->Unit->RetireMember($request);
    }

    public function remove_unit_member($request)
    {
        return $this->Unit->RemoveMember($request);
    }

    public function del_unit_auth($request)
    {
        logtrace("del_unit_auth()", $request);
        return $this->Authorization->RemoveAuthorization($request);
    }

    public function retire_unit($request)
    {
        return $this->Unit->RetireUnit($request);
    }

    public function restore_unit($request)
    {
        return $this->Unit->RestoreUnit($request);
    }

    public function claim_unit($request)
    {
        return $this->Unit->ClaimUnit($request);
    }

    public function transfer_ownership($request)
    {
        return $this->Unit->TransferOwnership($request);
    }

    public function get_unit_list($request)
    {
        return $this->Report->UnitSummary($request);
    }

    public function get_unit($unit_id)
    {
        return $this->Unit->GetUnit(array('UnitId' => $unit_id));
    }

    public function get_unit_details($unit_id)
    {
        return array(
                'Details' => $this->Unit->GetUnit(array( 'UnitId' => $unit_id )),
                'Members' => $this->Report->GetPlayerRoster(array( 'Type' => AUTH_UNIT, 'Id' => $unit_id )),
                'Authorizations' => $this->Report->GetAuthorizations(array( 'Type' => AUTH_UNIT, 'Id' => $unit_id, 'Token' => $this->session->token ))
            );
    }

}
