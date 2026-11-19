<?php

class Treasury extends Ork3
{
    public function __construct()
    {
        parent::__construct();
        $this->split = new yapo($this->db, DB_PREFIX . 'split');
        $this->account = new yapo($this->db, DB_PREFIX . 'account');
        $this->transaction = new yapo($this->db, DB_PREFIX . 'transaction');
    }

    public function RecordTransaction($request)
    {
        if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) > 0) {
            // A session alone used to be enough to post an arbitrary split to any
            // set of books. Authority is resolved from the accounts the splits
            // actually touch, and EVERY split must pass -- not just one of them.
            $splits = array();
            foreach (array($request['SplitOne'], $request['SplitTwo']) as $split) {
                if (!valid_id($split['AccountId'])
                        || !$this->has_account_authority($mundane_id, $split['AccountId'])) {
                    return NoAuthorization();
                }
                if (!isset($split['Amount']) || !is_numeric($split['Amount'])
                        || !isset($split['DrCr'])
                        || !in_array($split['DrCr'], array(TreasuryDrCr::Dr, TreasuryDrCr::Cr), true)) {
                    return InvalidParameter('Each split needs a numeric Amount and a DrCr of Dr or Cr.');
                }
                $split['SrcMundaneId'] = $mundane_id;
                $split['IsDues'] = isset($split['IsDues']) ? $split['IsDues'] : 0;
                $split['DuesThrough'] = isset($split['DuesThrough']) ? $split['DuesThrough'] : '';
                $splits[] = $split;
            }
            // Double entry is enforced HERE because record_transaction()'s imbalance
            // branch cannot recover: it writes the transaction and both splits first,
            // and there is no rollback when the Imbalance account lookup misses.
            if ($splits[0]['DrCr'] === $splits[1]['DrCr']
                    || abs(abs($splits[0]['Amount']) - abs($splits[1]['Amount'])) > 0.005) {
                return InvalidParameter('Debits must equal credits.');
            }
            // record_split() has never existed: this entry point fatalled on every
            // successful call. record_transaction() is the real writer, and it
            // re-checks authority per split on its own.
            $r = $this->record_transaction(
                array(
                    'RecordedBy' => $mundane_id,
                    'Description' => isset($request['Description']) ? $request['Description'] : '',
                    'Memo' => isset($request['Memo']) ? $request['Memo'] : '',
                ),
                $splits,
                isset($request['TransactionDate']) ? $request['TransactionDate'] : null
            );
            return is_array($r) ? $r : Success();
        } else {
            return NoAuthorization();
        }
    }

    public function RemoveTransaction($request)
    {

    }

    public function dues_through($mundane_id, $kingdom_id, $park_id, $startdate)
    {
        // mysql_real_escape_string() is a no-op shim in this codebase, so the ids
        // are hard-cast instead of being handed to it.
        $kingdom_id = (int)$kingdom_id;
        $park_id = (int)$park_id;
        $mundane_id = (int)$mundane_id;
        $sql = "
			SELECT split.dues_through
				FROM 
					`" . DB_PREFIX . "split` split 
						left join " . DB_PREFIX . "account account on split.account_id = account.account_id
				where (kingdom_id = " . $kingdom_id . " or park_id = " . $park_id . ") and src_mundane_id = " . $mundane_id . " and is_dues = 1
				order by dues_through desc 
				limit 1
		";
        $lastdues = $this->db->query($sql);
        if ($lastdues != false && $lastdues->size() == 1) {
            if (strtotime($lastdues->dues_through) > strtotime($startdate)) {
                return $lastdues->dues_through;
            }
        }
        return $startdate;
    }

    public function RemoveLastDuesPaid($request)
    {
        logtrace('RemoveLastDuesPaid', $request);
        if (($player = Ork3::$Lib->player->player_info($request['MundaneId'])) === false) {
            return InvalidParameter('Player could not be found.');
        }
        logtrace('Found Player', $request);

        if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) > 0
                && Ork3::$Lib->authorizationgate->checkPermissionOrAuthority($mundane_id, 'park.dues.manage', 'park', $player['ParkId'], AUTH_EDIT)) {
            // mysql_real_escape_string() is a no-op shim in this codebase, so the id
            // is hard-cast instead of being handed to it.
            $target_mundane_id = (int)$request['MundaneId'];
            $sql = "select
			                s.transaction_id
			            from " . DB_PREFIX . "split s
			                left join " . DB_PREFIX . "transaction t on s.transaction_id = t.transaction_id
			            where
			                src_mundane_id = " . $target_mundane_id . " and is_dues = 1 order by t.date_created desc limit 1";
            logtrace('Passed Security', $sql);
            $lastdues = $this->db->query($sql);
            if ($lastdues != false && $lastdues->size() == 1) {
                $this->remove_transaction($lastdues->transaction_id);
                return Success('Transaction ' . $lastdues->transaction_id . ' removed.');
            }
        }
        return NoAuthorization('You lack authoratah.');
    }

    public function DuesPaidToPark($request)
    {
        logtrace('DuesPaidToPark', $request);
        if (($player = Ork3::$Lib->player->player_info($request['MundaneId'])) === false) {
            return InvalidParameter('Player could not be found.');
        }

        if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) > 0
                && Ork3::$Lib->authorizationgate->checkPermissionOrAuthority($mundane_id, 'park.dues.manage', 'park', $player['ParkId'], AUTH_EDIT)) {

            $park_info = Ork3::$Lib->park->GetParkShortInfo(array('ParkId' => $player['ParkId']));
            if ($park_info['Status']['Status'] > 0) {
                return InvalidParameter('Park information could not be fetched.');
            }

            $configuration = Common::get_configs($park_info['ParkInfo']['KingdomId']);
            if (!isset($configuration['DuesAmount']) || !isset($configuration['KingdomDuesTake'])) {
                return ProcessingError('Kingdom is missing DuesAmount or KingdomDuesTake configuration.');
            }

            $full_name = $player['GivenName'] . ' ' . $player['Surname'];

            if (false !== ($pointers = $this->fetch_account_pointers(AUTH_PARK, $player['ParkId']))) {
                logtrace('record_transaction is free to enter dues', null);
                $duestart = $this->dues_through($request['MundaneId'], $player['KingdomId'], $player['ParkId'], $request['TransactionDate']);
                $throughdate = date("Y-m-d H:i:s", strtotime('+' . (6 * ceil($request['Semesters'])) . ' months', strtotime($duestart)));
                $r = $this->record_transaction(
                    array(
                        'RecordedBy' => $mundane_id,
                        'Description' => 'Dues Paid for ' . $full_name,
                        'Memo' => 'Dues Paid for ' . $full_name
                        ),
                    array(
                        array(
                            'AccountId' => $pointers['DuesPaid'],
                            'IsDues' =>  1,
                            'SrcMundaneId' => $request['MundaneId'],
                            'DrCr' => TreasuryDrCr::Cr,
                            'Amount' => $configuration['DuesAmount']['Value'] * $request['Semesters'],
                            'DuesThrough' =>  $throughdate
                            ),
                        array(
                            'AccountId' => $pointers['Cash'],
                            'IsDues' =>  0,
                            'SrcMundaneId' => $request['MundaneId'],
                            'DrCr' => TreasuryDrCr::Dr,
                            'Amount' => $configuration['DuesAmount']['Value'] * $request['Semesters'],
                            ),
                        array(
                            'AccountId' => $pointers['DuesOwed'],
                            'IsDues' =>  0,
                            'SrcMundaneId' => $request['MundaneId'],
                            'DrCr' => TreasuryDrCr::Cr,
                            'Amount' => $configuration['KingdomDuesTake']['Value'] * $request['Semesters']
                            ),
                        array(
                            'AccountId' => $pointers['KingdomTake'],
                            'IsDues' =>  0,
                            'SrcMundaneId' => $request['MundaneId'],
                            'DrCr' => TreasuryDrCr::Dr,
                            'Amount' => $configuration['KingdomDuesTake']['Value'] * $request['Semesters']
                            )),
                    $request['TransactionDate']
                );
                logtrace('Recording info: ', $r);
                return $r;
            } else {
                logtrace('Dues not paid: -EINVAL');
                return InvalidParameter();
            }
        } else {
            logtrace('Dues not paid: no authority; ', 0);
            return NoAuthorization('You lack authoratah.');
        }
    }

    public function DuesPaidToKingdom($request)
    {

    }

    public function KingdomTithe($request)
    {

    }

    public function KingdomLevy($request)
    {

    }

    public function Donation($request)
    {

    }

    public function EventFee($request)
    {

    }

    public function PurchaseSupplies($request)
    {

    }

    public function EventExpense($request)
    {
    }

    public function CreateAccount($request)
    {
        if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) > 0) {
            // Gate on the account's OWNER, not on session validity.
            if (!$this->treasury_permission_check($mundane_id, ucfirst((string)$request['OwnerType']), $request['Id'], AUTH_CREATE)) {
                return NoAuthorization();
            }
            // Falling off the end returned null, which JsonServer turns into a
            // Status-less envelope a caller reads as Success.
            $account_id = $this->create_account($mundane_id, $request['ParentId'], $request['AccountName'], $request['AccountType'], $request['OpeningBalance'], $request['OwnerType'], $request['Id'], $request['KingdomId']);
            if (false === $account_id) {
                return ProcessingError();
            }
            return Success($account_id);
        } else {
            return NoAuthorization();
        }
    }

    public function CreateAccounts($request)
    {
        if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) > 0) {
            // Gate on the accounts' OWNER, not on session validity.
            if (!$this->treasury_permission_check($mundane_id, ucfirst((string)$request['Type']), $request['Id'], AUTH_CREATE)) {
                return NoAuthorization();
            }
            if (false === $this->create_accounts($mundane_id, $request['Type'], $request['Id'], $request['KingdomId'])) {
                return ProcessingError();
            }
            return Success();
        } else {
            return NoAuthorization();
        }
    }

    public function fetch_kingdom_details($id)
    {
        $config = new yapo($this->db, DB_PREFIX . 'configuration');
        $config->clear();
        $config->type = 'Kingdom';
        $config->id = $id;
        $config->in('key', "'DuesPeriod','DuesAmount','KingdomDuesTake'");
        if ($config->find()) {
            return json_decode($config->value);
        } else {
            return false;
        }
    }

    public function fetch_account_pointers($type, $id)
    {
        $config = new yapo($this->db, DB_PREFIX . 'configuration');
        $config->clear();
        $config->type = ucfirst($type);
        $config->id = $id;
        $config->key = 'AccountPointers';
        if ($config->find()) {
            return json_decode($config->value, true);
        } else {
            return false;
        }
    }

    public function create_accounts($mundane_id, $type, $id, $kingdom_id = 0)
    {
        // Every pointer below must be defined on every branch: a null in this map is
        // persisted and later fails account->find() in record_transaction().
        $kingdom_parkdues = 0;
        if ('park' == $type && $kingdom_id == 0) {
            return false;
        } elseif ('park' == $type) {
            $pointers = $this->fetch_account_pointers('kingdom', $kingdom_id);
            if (false === $pointers) {
                return false;
            }
            // Legacy parent maps carry a null (or absent) ParkDues; persisting that
            // reproduces exactly the null pointer this guard exists to prevent.
            $kingdom_parkdues = (isset($pointers['ParkDues']) && valid_id($pointers['ParkDues']))
                ? $pointers['ParkDues']
                : 0;
        }

        $imbalance = $this->create_account($mundane_id, 0, 'Imbalance', TreasuryAccountType::Imbalance, 0.0, $type, $id, $kingdom_id);

        $equity = $this->create_account($mundane_id, 0, 'Equity', TreasuryAccountType::Equity, 0.0, $type, $id, $kingdom_id);

        $asset = $this->create_account($mundane_id, 0, 'Assets', TreasuryAccountType::Asset, 0.0, $type, $id, $kingdom_id);
        $cash = $this->create_account($mundane_id, $asset, 'Cash', TreasuryAccountType::Asset, 0.0, $type, $id, $kingdom_id);
        $checking = $this->create_account($mundane_id, $asset, 'Checking', TreasuryAccountType::Asset, 0.0, $type, $id, $kingdom_id);
        if ('kingdom' == $type) {
            $kingdom_parkdues = $this->create_account($mundane_id, $asset, 'Park Dues', TreasuryAccountType::Asset, 0.0, $type, $id, $kingdom_id);
        }

        $income = $this->create_account($mundane_id, 0, 'Income', TreasuryAccountType::Income, 0.0, $type, $id, $kingdom_id);
        $duespaid = $this->create_account($mundane_id, $income, 'Dues Paid', TreasuryAccountType::Income, 0.0, $type, $id, $kingdom_id);
        $donations = $this->create_account($mundane_id, $income, 'Donations', TreasuryAccountType::Income, 0.0, $type, $id, $kingdom_id);

        $expense = $this->create_account($mundane_id, 0, 'Expenses', TreasuryAccountType::Expense, 0.0, $type, $id, $kingdom_id);
        $supplies = $this->create_account($mundane_id, $expense, 'Supplies', TreasuryAccountType::Expense, 0.0, $type, $id, $kingdom_id);
        // Parks need a Kingdom Take expense account too: DuesPaidToPark() posts its
        // fourth split to $pointers['KingdomTake'] on the PARK's books.
        $kingdomtake = $this->create_account($mundane_id, $expense, 'Kingdom Take', TreasuryAccountType::Expense, 0.0, $type, $id, $kingdom_id);
        $events = $this->create_account($mundane_id, $expense, 'Events', TreasuryAccountType::Expense, 0.0, $type, $id, $kingdom_id);
        $food = $this->create_account($mundane_id, $events, 'Food', TreasuryAccountType::Expense, 0.0, $type, $id, $kingdom_id);
        $site = $this->create_account($mundane_id, $events, 'Site', TreasuryAccountType::Expense, 0.0, $type, $id, $kingdom_id);
        $misc = $this->create_account($mundane_id, $events, 'Miscellaneous', TreasuryAccountType::Expense, 0.0, $type, $id, $kingdom_id);

        $liability = $this->create_account($mundane_id, 0, 'Liability', TreasuryAccountType::Liability, 0.0, $type, $id, $kingdom_id);
        $duesowed = $this->create_account($mundane_id, $liability, 'Dues Owed', TreasuryAccountType::Liability, 0.0, $type, $id, $kingdom_id);

        $account_pointers = array(
            'Imbalance' => $imbalance,
            'Equity' => $equity,
            'Asset' => $asset,
            'Cash' => $cash,
            'Checking' => $checking,
            'ParkDues' => $kingdom_parkdues,
            'Income' => $income,
            'DuesPaid' => $duespaid,
            'Donations' => $donations,
            'Expense' => $expense,
            'Supplies' => $supplies,
            'KingdomTake' => $kingdomtake,
            'Events' => $events,
            'Food' => $food,
            'Site' => $site,
            'Miscellaneous' => $misc,
            'Liability' => $liability,
            'DuesOwed' => $duesowed,
            'Kingdom_ParkDues' => $kingdom_parkdues
        );
        // create_account() returns false on its own permission check. Persisting a
        // map of falses and reporting Success leaves the books permanently broken,
        // so a partial run is a failure and nothing is written.
        foreach ($account_pointers as $pointer) {
            if (false === $pointer) {
                return false;
            }
        }

        $c = new Common();
        $c->add_config($mundane_id, ucfirst($type), 'mixed', $id, 'AccountPointers', $account_pointers, 0);
        return true;
    }

    /**
     * Authority over an account, and therefore over every transaction that touches it.
     *
     * park.dues.manage covers a player's dues ROW; this is the books those rows post to,
     * and it had no permission at all -- so the Treasurer role, whose whole purpose is
     * this ledger, could not reach any of it without a legacy authority row.
     * park.treasury.manage is declared at park scope, but scope_type is only a key's
     * narrowest DIRECT-check scope: the cascade resolves a CHECK upward, so a
     * kingdom-scope grant of this key covers kingdom, park, event and unit accounts
     * alike. It does not work the other way -- a park-scope grant satisfies nothing
     * checked at kingdom scope.
     */
    public function has_account_authority($mundane_id, $account_id)
    {
        $this->account->clear();
        $this->account->account_id = $account_id;
        $this->account->find();
        list($type, $id) = $this->DetermineAuthType();
        logtrace('has_account_authority', array($mundane_id, $account_id, $type, $id));
        return $this->treasury_permission_check($mundane_id, $type, $id, AUTH_EDIT);
    }

    /**
     * park.treasury.manage at the RBAC scope matching a legacy AUTH_* owner type, with
     * the legacy authority row as the fallback arm. Accounts hang off four different
     * owner types, so the mapping lives here rather than being spelled at each call.
     *
     * @param int    $mundane_id
     * @param string $type         AUTH_PARK / AUTH_KINGDOM / AUTH_EVENT / AUTH_UNIT
     * @param int    $id           The owner's id
     * @param string $legacy_role  AUTH_EDIT or AUTH_CREATE
     * @return bool
     */
    private function treasury_permission_check($mundane_id, $type, $id, $legacy_role)
    {
        $scopes = [
            AUTH_PARK    => 'park',
            AUTH_KINGDOM => 'kingdom',
            AUTH_EVENT   => 'event',
            AUTH_UNIT    => 'unit',
        ];
        if (!is_string($type) || !isset($scopes[$type]) || !valid_id($id)) {
            return false;
        }

        return Ork3::$Lib->authorizationgate->checkPermissionOrAuthority(
            $mundane_id,
            'park.treasury.manage',
            $scopes[$type],
            $id,
            $legacy_role
        );
    }

    private function DetermineAuthType()
    {
        $type = 'None';
        $id = 0;
        if ($this->account->park_id > 0) {
            $type = AUTH_PARK;
            $id = $this->account->park_id;
        };
        if ($this->account->event_id > 0) {
            $type = AUTH_EVENT;
            $id = $this->account->event_id;
        };
        if ($this->account->unit_id > 0) {
            $type = AUTH_UNIT;
            $id = $this->account->unit_id;
        };
        if ($type == 'None') {
            if ($this->account->kingdom_id > 0) {
                $type = AUTH_KINGDOM;
                $id = $this->account->kingdom_id;
            }
        };
        return array( $type, $id );
    }

    public function remove_transaction($trx_id)
    {
        $this->split->clear();
        $this->split->transaction_id = $trx_id;
        $this->split->delete();
        $this->transaction->clear();
        $this->transaction->transaction_id = $trx_id;
        $this->transaction->delete();
    }

    public function record_transaction($trn, $splits, $trx_date = null)
    {
        logtrace('record_transaction', array($trn, $splits, $trx_date));
        $trx_date = is_null($trx_date) ? date('Y-m-d') : date('Y-m-d', strtotime($trx_date));
        // EVERY split must be authorized, not just one of them: an OR here let a
        // treasurer authorized on one park's books post the other half of the
        // transaction into any other park's, kingdom's, event's or unit's account.
        $authority = !empty($splits);
        foreach ($splits as $s => $split) {
            $authority = $authority && $this->has_account_authority($trn['RecordedBy'], $split['AccountId']);
            $this->account->clear();
            $this->account->account_id = $split['AccountId'];
            if (!$this->account->find()) {
                return InvalidParameter(print_r(array($split['AccountId'],true)));
            } else {
                $splits[$s]['AccountType'] = $this->account->type;
            }
        }
        if (!$authority) {
            return NoAuthorization(print_r(array($trn['RecordedBy'], $split['AccountId']), true));
        }

        $this->transaction->clear();
        $this->transaction->recorded_by = $trn['RecordedBy'];
        $this->transaction->date_created = date("Y-m-d H:i:s");
        $this->transaction->description = $trn['Description'];
        $this->transaction->memo = $trn['Memo'];
        $this->transaction->transaction_date = $trx_date;
        $this->transaction->save();

        $debit = 0.0;
        $credit = 0.0;

        foreach ($splits as $s => $split) {
            $this->split->clear();
            $this->split->transaction_id = $this->transaction->transaction_id;
            $this->split->account_id = $split['AccountId'];
            $this->split->is_dues = $split['IsDues'];
            $this->split->src_mundane_id = $split['SrcMundaneId'];
            $this->split->dues_through = strlen($split['DuesThrough']) > 0 ? $split['DuesThrough'] : null;
            $this->split->amount = round($split['DrCr'] == $this->dr_cr_sign_convention($split['AccountType'], $split['Amount']) ? $split['Amount'] : -$split['Amount'], 3);

            $this->split->save();

            if ($this->dr_cr_sign_convention($split['AccountType'], $split['Amount']) == TreasuryDrCr::Dr) {
                $debit += $this->split->amount;
            } else {
                $credit += $this->split->amount;
            }
        }

        if (abs($debit - $credit) > 0.005) {
            $this->account->clear();
            $this->account->account_id = $this->split->account_id;
            $this->account->find();
            $k = $this->account->kingdom_id;
            $idt = $this->DetermineAuthType().'_id';
            $id = $this->account->$idt;
            $this->account->clear();
            $this->account->kingdom_id = $k;
            $this->account->$idt = $id;
            $this->account->type = TreasuryAccountType::Imbalance;
            if ($this->account->find()) {
                $this->split->clear();
                $this->split->transaction_id = $this->transaction->transaction_id;
                $this->split->account_id = $split['AccountId'];
                $this->split->is_dues = 0;
                $this->split->src_mundane_id = $split['SrcMundaneId'];
                $this->split->amount = $credit - $debit;

                $this->split->save();
            } else {
                // crap
                return InvalidParameter('Canno record split.');
            }
        }
    }

    public function create_account($mundane_id, $parent_id, $account_name, $account_type, $opening_balance, $owner_type, $owner_id, $kingdom_id = 0)
    {
        if (!$this->treasury_permission_check($mundane_id, ucfirst($owner_type), $owner_id, AUTH_CREATE)) {
            return false;
        }
        $owner = $owner_type . '_id';
        $this->account->clear();
        $this->account->kingdom_id = $kingdom_id;
        $this->account->$owner = $owner_id;
        $this->account->name = $account_name;
        $this->account->parent_id = $parent_id;
        $this->account->type = $account_type;
        if ($this->account->find()) {
            // fuck off, seriously?
            $new_account = $this->account->account_id;
            //			return false;
        } else {
            $this->account->clear();
            $this->account->kingdom_id = $kingdom_id;
            $this->account->$owner = $owner_id;
            $this->account->name = $account_name;
            $this->account->parent_id = $parent_id;
            $this->account->type = $account_type;
            $this->account->save();
            $new_account = $this->account->account_id;
        }
        $this->account->clear();
        $this->account->kingdom_id = $kingdom_id;
        $this->account->$owner = $owner_id;
        $this->account->name = 'Equity';
        $this->account->parent_id = 0;
        $this->account->type = 'equity';
        if (!$this->account->find()) {
            // Needs an equity account
            $this->account->clear();
            $this->account->kingdom_id = $kingdom_id;
            $this->account->$owner = $owner_id;
            $this->account->name = 'Equity';
            $this->account->parent_id = 0;
            $this->account->type = 'equity';
            $this->account->save();
        }
        return $new_account;
    }

    /*********************************************

    Account Type	Normal Balance	Increase	Decrease
    Asset			Dr				Dr			Cr
    Expense			Dr				Dr			Cr
    Draws			Dr				Dr			Cr* For completeness
    Liability		Cr				Cr			Dr
    Equity			Cr				Cr			Dr
    Revenue			Cr				Cr			Dr
    Imbalance		Cr				Cr			Dr* Treated as an equity account

    Dr/Cr

    *********************************************/


    public function normalize_sign($t, $amt)
    {
        switch ($t) {
            case TreasuryAccountType::Asset:
            case TreasuryAccountType::Expense:
            case TreasuryAccountType::Draw:
                return $amt >= 0 ? array(TreasuryDrCr::Dr,$amt) : array(TreasuryDrCr::Cr,-$amt);
            case TreasuryAccountType::Liability:
            case TreasuryAccountType::Income:
            case TreasuryAccountType::Equity:
            case TreasuryAccountType::Imbalance:
                return $amt >= 0 ? array(TreasuryDrCr::Cr,$amt) : array(TreasuryDrCr::Dr,-$amt);
        }
    }

    public function sign_convention($t1, $amt, $t2)
    {
        if (0.0 == $amt) {
            return 0.0;
        }
        $drcr = $this->dr_cr_sign_convention($t1, $amt);
        if ($drcr == TreasuryDrCr::Dr) {
            $drcr = TreasuryDrCr::Cr;
        } else {
            $drcr = TreasuryDrCr::Dr;
        }
        switch ($t2) {
            case TreasuryAccountType::Asset:
            case TreasuryAccountType::Expense:
            case TreasuryAccountType::Draw:
                return $drcr == TreasuryDrCr::Dr ? $amt : -$amt;
            case TreasuryAccountType::Liability:
            case TreasuryAccountType::Income:
            case TreasuryAccountType::Equity:
            case TreasuryAccountType::Imbalance:
                return $drcr == TreasuryDrCr::Dr ? -$amt : $amt;
        }
    }

    public function dr_cr_sign_convention($t, $sign)
    {
        switch ($t) {
            case TreasuryAccountType::Asset:
            case TreasuryAccountType::Expense:
            case TreasuryAccountType::Draw:
                return $sign >= 0 ? TreasuryDrCr::Dr : TreasuryDrCr::Cr;
            case TreasuryAccountType::Liability:
            case TreasuryAccountType::Income:
            case TreasuryAccountType::Equity:
            case TreasuryAccountType::Imbalance:
                return $sign >= 0 ? TreasuryDrCr::Cr : TreasuryDrCr::Dr;
        }
    }
}

class TreasuryDrCr
{
    public const Dr = "Dr";
    public const Cr = "Cr";
}

class TreasuryAccountType
{
    public const Income = 'Income';
    public const Expense = 'Expense';
    public const Draw = 'Draw';
    public const Liability = 'Liability';
    public const Asset = 'Asset';
    public const Equity = 'Equity';
    public const Imbalance = 'Imbalance';
}
