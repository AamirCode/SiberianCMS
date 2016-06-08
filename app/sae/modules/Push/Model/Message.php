<?php

class Push_Model_Message extends Core_Model_Default {

    const DISPLAYED_PER_PAGE = 10;

    protected $_is_cachable = false;

    protected $_types = array(
        'iphone' => 'Push_Model_Iphone_Message',
        'android' => 'Push_Model_Android_Message'
    );

    protected $_instances;

    protected $_messageType;

    public function __construct($datas = array()) {
        parent::__construct($datas);
        $this->_db_table = 'Push_Model_Db_Table_Message';

        $this->_initInstances();

        $this->_initMessageType();
    }

    public function delete() {
        $message_id = $this->getId();

        parent::delete();
        $this->getTable()->deleteLog($message_id);
    }

    public function deleteFeature($option_value) {
        $app = $this->getApplication();

        $this->setMessageTypeByOptionValue($option_value);
        $this->getTable()->deleteAllLogs($app->getId(),$this->getMessageType());
        $this->getTable()->deleteAllMessages($app->getId(),$this->getMessageType());

    }

    public function getInstance($type = null) {
        if(!empty($this->_instances[$type])) return $this->_instances[$type];
        else return null;
    }

    public function getInstances() {
        return $this->_instances;
    }

    public function getMessageType() {
        return $this->_messageType;
    }

    public function getMessages() {
        return $this->getTable()->getMessages($this->_messageType);
    }

    public function getTitle() {
        return mb_convert_encoding($this->getData('title'), 'UTF-8', 'UTF-8');
    }

    public function getText() {
        return mb_convert_encoding($this->getData('text'), 'UTF-8', 'UTF-8');
    }

    public function markAsRead($device_uid,$message_id = null) {
        return $this->getTable()->markAsRead($device_uid,$message_id);
    }

    public function markAsDisplayed($device_id, $message_id) {
        return $this->getTable()->markAsDisplayed($device_id, $message_id);
    }

    public function findByDeviceId($device_id, $app_id, $offset = 0) {
        $allowed_categories = null;
        if($this->_messageType==2) {

            $subscription = new Topic_Model_Subscription();
            $allowed_categories = $subscription->findAllowedCategories($device_id);

        }

        return $this->getTable()->findByDeviceId($device_id, $this->_messageType, $app_id, $offset, $allowed_categories);
    }

    public function countByDeviceId($device_id) {
        return $this->getTable()->countByDeviceId($device_id, $this->_messageType);
    }

    public function findLastPushMessage($device_id) {
        $row = $this->getTable()->findLastPushMessage($device_id);
        $this->_prepareDatas($row);
        return $this;
    }

    public function findLastInAppMessage($app_id, $device_id) {
        $subscription = new Topic_Model_Subscription();
        $allowed_categories = $subscription->findAllowedCategories($device_id);

        $row = $this->getTable()->findLastInAppMessage($app_id,$device_id,$allowed_categories);
        $this->_prepareDatas($row);
        return $this;
    }

    public function markInAppAsRead($app_id, $device_id, $device_type) {
        return $this->getTable()->markInAppAsRead($app_id, $device_id, $device_type);
    }

    public function push() {
        $errors = array();
        $this->updateStatus('sending');
        foreach($this->_instances as $type => $instance) {
            $instance->setMessage($this)
                ->push()
            ;
            if($instance->getErrors()) {
                $errors[$instance->getId()] = $instance->getErrors();
            }
        }
        $this->updateStatus('delivered');

        if(!empty($errors)) {
            Zend_Registry::get("logger")->sendException(print_r($errors, true), "push_", false);
        }

        $this->setErrors($errors);

    }

    public function createLog($device, $status, $id = null) {

        if(!$id) $id = $device->getDeviceUid();
        $is_displayed = !$this->getLatitude() && !$this->getLongitude();
        $datas = array(
            'device_id' => $device->getId(),
            'device_uid' => $id,
            'device_type' => $device->getTypeId(),
            'is_displayed' => $is_displayed,
            'message_id' => $this->getId(),
            'status' => $status,
            'delivered_at' => $this->formatDate(null, 'y-MM-dd HH:mm:ss')
        );

        $this->getTable()->createLog($datas);

        return $this;
    }

    public function updateStatus($status) {

        $this->setStatus($status);
        if($status == 'delivered') {
            $this->setDeliveredAt($this->formatDate(null, 'y-MM-dd HH:mm:ss'));
        }

        $this->save();

    }

    public function setMessageType($message_type) {
        $this->_messageType = $message_type;
        return $this;
    }

    public function setMessageTypeByOptionValue($optionValue) {
        $inapp_option_id = $this->getTable()->getInAppCode();
        switch($optionValue) {
            case $inapp_option_id:
                $this->_messageType = 2;
                break;
            default:
                $this->_messageType = 1;
        }
    }

    public function getCoverUrl() {
        $cover_path = Application_Model_Application::getImagePath().$this->getCover();
        $base_cover_path = Application_Model_Application::getBaseImagePath().$this->getCover();
        if($this->getCover() AND file_exists($base_cover_path)) {
            return $cover_path;
        }
        return '';
    }

    public function getInAppCode() {
        return $this->getTable()->getInAppCode();
    }

    protected function _initInstances() {

        if(is_null($this->_instances)) {

            $this->_instances = array();
            foreach($this->_types as $device => $type) {
                $this->_instances[$device] = new $type();
            }
        }

        return $this->_instances;
    }

    public function _initMessageType() {
        if (is_null($this->_messageType)) {
            $this->_messageType = 1;
        }
    }
    /** @migration path sae */
    public static function hasTargetedNotificationsModule() {
        return file_exists(Core_Model_Directory::getBasePathTo("app/modules/Push/Model/Customer/Message.php"));
    }
}