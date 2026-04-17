<?php

class Controller_Waiver extends Controller {

	public function __construct($call = null, $id = null) {
		parent::__construct($call, $id);
	}

	public function index($id = null) {
		// fallthrough — redirect to builder if admin, else kingdom profile
		$this->redirect('Kingdom/index/' . (int)($id ?? 0));
	}

	public function builder($kingdom_id = null)  { $this->data['_wv'] = ['kingdom_id' => (int)$kingdom_id]; $this->template = '../revised-frontend/Waiver_builder.tpl'; }
	public function sign($scope = null, $id = null) { $this->data['_wv'] = ['scope' => $scope, 'id' => (int)$id]; $this->template = '../revised-frontend/Waiver_sign.tpl'; }
	public function queue($scope = null, $id = null) { $this->data['_wv'] = ['scope' => $scope, 'id' => (int)$id]; $this->template = '../revised-frontend/Waiver_queue.tpl'; }
	public function review($signature_id = null) { $this->data['_wv'] = ['signature_id' => (int)$signature_id]; $this->template = '../revised-frontend/Waiver_review.tpl'; }
	public function printable($signature_id = null) { $this->data['_wv'] = ['signature_id' => (int)$signature_id]; $this->template = '../revised-frontend/Waiver_print.tpl'; }
}

?>
