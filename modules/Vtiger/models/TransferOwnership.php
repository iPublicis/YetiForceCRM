<?php

/**
 * Vtiger TransferOwnership model class.
 *
 * @copyright YetiForce Sp. z o.o
 * @license   YetiForce Public License 3.0 (licenses/LicenseEN.txt or yetiforce.com)
 */
class Vtiger_TransferOwnership_Model extends \App\Base
{
	protected $skipModules = [];

	public function getSkipModules()
	{
		return $this->skipModules;
	}

	public function getRelatedModuleRecordIds(\App\Request $request, $recordIds, $relModData)
	{
		$basicModule = $request->getModule();
		$parentModuleModel = Vtiger_Module_Model::getInstance($basicModule);
		$relatedIds = [];
		$relModData = explode('::', $relModData);
		$relatedModule = $relModData[0];
		$type = $relModData[1];
		switch ($type) {
			case 0:

				$field = $relModData[2];
				foreach ($recordIds as $recordId) {
					$recordModel = Vtiger_Record_Model::getInstanceById($recordId, $basicModule);
					if ($recordModel->get($field) != 0 && \App\Record::getType($recordModel->get($field)) == $relatedModule) {
						$relatedIds[] = $recordModel->get($field);
					}
				}

				break;
			case 1:
				$tablename = Vtiger_Relation_Model::getInstance($parentModuleModel, Vtiger_Module_Model::getInstance($relatedModule))->getRelationField()->get('table');
				$tabIndex = CRMEntity::getInstance($relatedModule)->table_index;
				$relIndex = $this->getRelatedColumnName($relatedModule, $basicModule);
				if (!$relIndex) {
					break;
				}
				$relatedIds = (new \App\Db\Query())->select([$tabIndex])->from($tablename)->where([$relIndex => $recordIds])->column();
				break;
			case 2:
				foreach ($recordIds as $recordId) {
					$recordModel = Vtiger_Record_Model::getInstanceById($recordId, $basicModule);
					$relationListView = Vtiger_RelationListView_Model::getInstance($recordModel, $relatedModule);
					$relatedIds = $relationListView->getRelationQuery()->select(['vtiger_crmentity.crmid'])
						->distinct()
						->column();
				}
				break;
			default:
				break;
		}
		return array_unique($relatedIds);
	}

	public function transferRecordsOwnership($module, $transferOwnerId, $relatedModuleRecordIds)
	{
		$db = \App\Db::getInstance();
		$oldOwners = \vtlib\Functions::getCRMRecordMetadata($relatedModuleRecordIds);
		$db->createCommand()->update('vtiger_crmentity', [
			'smownerid' => $transferOwnerId,
			'modifiedby' => \App\User::getCurrentUserId(),
			'modifiedtime' => date('Y-m-d H:i:s'),
		], ['crmid' => $relatedModuleRecordIds]
		)->execute();
		Vtiger_Loader::includeOnce('~modules/ModTracker/ModTracker.php');
		$flag = ModTracker::isTrackingEnabledForModule($module);
		if ($flag) {
			foreach ($relatedModuleRecordIds as $record) {
				if (\App\Privilege::isPermitted($module, 'DetailView', $record)) {
					$db->createCommand()->insert('vtiger_modtracker_basic', [
						'crmid' => $record,
						'module' => $module,
						'whodid' => \App\User::getCurrentUserId(),
						'changedon' => date('Y-m-d H:i:s', time()),
					])->execute();
					$id = $db->getLastInsertID('vtiger_modtracker_basic_id_seq');
					$db->createCommand()->insert('vtiger_modtracker_detail', [
						'id' => $id,
						'fieldname' => 'assigned_user_id',
						'postvalue' => $transferOwnerId,
						'prevalue' => $oldOwners[$record]['smownerid'],
					])->execute();
				}
			}
		}
	}

	public static function getInstance($module)
	{
		$instance = Vtiger_Cache::get('transferOwnership', $module);
		if (!$instance) {
			$modelClassName = Vtiger_Loader::getComponentClassName('Model', 'TransferOwnership', $module);
			$instance = new $modelClassName();
			$instance->set('module', $module);
			Vtiger_Cache::set('transferOwnership', $module, $instance);
		}
		return $instance;
	}

	public function getRelationsByFields($privileges = true)
	{
		$module = $this->get('module');
		$moduleModel = Vtiger_Module_Model::getInstance($module);
		$relatedModelFields = $moduleModel->getFields();

		$relatedModules = [];
		foreach ($relatedModelFields as $fieldName => $fieldModel) {
			if ($fieldModel->isReferenceField()) {
				$referenceList = $fieldModel->getReferenceList();
				foreach ($referenceList as $relation) {
					if (\App\Privilege::isPermitted($relation, 'EditView')) {
						$relatedModules[] = ['name' => $relation, 'field' => $fieldName];
					}
				}
			}
		}
		return $relatedModules;
	}

	public function getRelationsByRelatedList($privileges = true)
	{
		$module = $this->get('module');
		$moduleModel = Vtiger_Module_Model::getInstance($module);
		$relatedModules = [];
		$relations = $moduleModel->getRelations();
		foreach ($relations as $relation) {
			$relationModule = $relation->getRelationModuleName();
			if (\App\Privilege::isPermitted($relationModule, 'EditView')) {
				$relatedModules[] = [
					'name' => $relationModule,
					'type' => $relation->getRelationType(),
				];
			}
		}
		return $relatedModules;
	}

	public function getRelatedColumnName($relatedModule, $findModule)
	{
		$relatedModuleModel = Vtiger_Module_Model::getInstance($relatedModule);
		$relatedModelFields = $relatedModuleModel->getFields();
		foreach ($relatedModelFields as $fieldModel) {
			if ($fieldModel->isReferenceField()) {
				$referenceList = $fieldModel->getReferenceList();
				if (in_array($findModule, $referenceList)) {
					return $fieldModel->get('column');
				}
			}
		}
		return false;
	}
}
