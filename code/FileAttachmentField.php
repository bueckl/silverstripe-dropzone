<?php

/**
 * Defines the FileAttachementField form field type
 *
 * @package  unclecheese/silverstripe-dropzone
 * @author  Uncle Cheese <unclecheese@leftandmain.com>
 */
class FileAttachmentField extends FileField {

    /**
     * The allowed actions for the RequestHandler
     * @var array
     */
    private static $allowed_actions = array (
        'upload',
        'handleSelect',
        'handleItem',
    );
    
    /* JOCHEN NOTE TO MYSELF
    /* Weireldly i had to overwrite the Link method for the sort action!
    /* the URL always had Locale="de_DE". This stopped the url from working. I stripped it off!!!
    */
	public function Link($action = null) {
        $FormAction = strtok($this->form->FormAction(), '?');
		return Controller::join_links($FormAction, 'field/' . $this->name, $action);
	}
    
	public function UploadFieldEditLink($ID = null) {
        $FormAction = strtok($this->form->FormAction(), '?');
		return Controller::join_links($this->Link(), 'item' , $ID, 'edit');
	}
	


    private static $url_handlers = array (
      'select' => 'handleSelect',
      'item/$ID' => 'handleItem',
      '$Action!' => '$Action'
    );


    /**
     * A list of settings for this instance
     * @var array
     */
    protected $settings = array ();

    /**
     * Extra params to send to the server with the POST request
     * @var array
     */
    protected $params = array ();

    /**
     * The record that this FormField is editing
     * @var DataObject
     */
    protected $record = null;

    /**
     * A list of custom permissions for this instance
     * Options available:
     *  - upload
     *  - attach (select from existing)
     *  - detach (remove from record, but don't delete)
     *  -delete (delete from files)
     * @var array
     */
    protected $permissions = array ();

    /**
     * The style of uploader. Options: "grid", "list"
     * @var string
     */
    protected $view = 'list';

    /**
     * The preview template for uploaded files. Does not necessarily apply
     * to files that were on the record at load time, but rather to files
     * that have been attached to the uploader client side
     * @var string
     */
    protected $previewTemplate = 'FileAttachmentField_preview';

    /**
     * UploadField compatability. Used for the select handler, when KickAssets
     * is not installed
     * @var string
     */
    protected $displayFolderName;
    
	/**
	 * Template to use for the edit form
	 *
	 * @var string
	 */
	protected $templateFileEdit = 'FileAttachmentField_FileEdit';
    
    
	/**
	 * Set name of template used for the edit (inline & popup) of a file file (without path or extension)
	 *
	 * @param string
	 */
	public function setTemplateFileEdit($template) {
		$this->templateFileEdit = $template;
		return $this;
	}

	/**
	 * @return string
	 */
	public function getTemplateFileEdit() {
		return $this->templateFileEdit;
	}
    
    
    
    /**
     * Helper function to translate underscore_case to camelCase
     * @param  string $str
     * @return string
     */
    public static function camelise($str) {
        return preg_replace_callback('/_([a-z])/', function ($c) {
                return strtoupper($c[1]);
        }, $str);
    }

    /**
     * Translate camelCase to underscore_case
     * @param  string $str
     * @return string
     */
    public static function underscorise($str) {
        $str[0] = strtolower($str[0]);

        return preg_replace_callback('/([A-Z])/', function ($c) {
            return "_" . strtolower($c[1]);
        }, $str);
    }

    /**
     * Looks at the php.ini and takes the lower of two values, translates it into
     * an int representing the number of bytes allowed per upload
     *
     * @return int
     */
    public static function get_filesize_from_ini() {
        $bytes = min(array(
            File::ini2bytes(ini_get('post_max_size') ?: '8M'),
            File::ini2bytes(ini_get('upload_max_filesize') ?: '2M')
        ));

        return floor($bytes/(1000*1000));
    }

    /**
     * Constructor. Sets some default permissions
     * @param string $name
     * @param string $title
     * @param string $value
     * @param Form $form
     */
    public function __construct($name, $title = null, $value = null, $form = null) {
        $instance = $this;

        $this->permissions['upload'] = true;
        $this->permissions['detach'] = true;
        $this->permissions['delete'] = function () use ($instance) {
            return Injector::inst()->get('File')->canDelete() && $instance->isCMS();
        };
        $this->permissions['attach'] = function () use ($instance) {
            return $instance->isCMS();
        };

        parent::__construct($name, $title, $value, $form);
    }

    /**
     * Renders the form field, loads requirements. Sets file size based on php.ini
     * Adds the security token
     *
     * @param array $attributes [description]
     * @return  SSViewer
     */
    public function FieldHolder($attributes = array ()) {
        Requirements::javascript(DROPZONE_DIR.'/javascript/dropzone.js');
        Requirements::javascript(DROPZONE_DIR.'/javascript/file_attachment_field.js');
        if($this->isCMS()) {
            Requirements::javascript(DROPZONE_DIR.'/javascript/file_attachment_field_backend.js');
        }
        Requirements::css(DROPZONE_DIR.'/css/file_attachment_field.css');

        if(!$this->getSetting('url')) {
            $this->settings['url'] = $this->Link('upload');
        }

        if(!$this->getSetting('maxFilesize')) {
            $this->settings['maxFilesize'] = static::get_filesize_from_ini();
        }
        // The user may not have opted into a multiple upload. If the form field
        // is attached to a record that has a multi relation, set that automatically.
        $this->settings['uploadMultiple'] = $this->IsMultiple();

        // Auto filter images if assigned to an Image relation
        if($class = $this->getFileClass()) {
            if(Injector::inst()->get($class) instanceof Image) {
                $this->imagesOnly();
            }
        }

        if($token = $this->getForm()->getSecurityToken()) {
            $this->addParam($token->getName(), $token->getSecurityID());
        }

        $this->extend('onBeforeRender', $this);

        return parent::FieldHolder($attributes);
    }

    /**
     * Saves the field into a record
     * @param  DataObjectInterface $record
     * @return FileAttachmentField
     */
    public function saveInto(DataObjectInterface $record) {
        $fieldname = $this->getName();
        if(!$fieldname) return $this;

        // Handle deletions. This is a bit of a hack. A workaround for having a single form field
        // post two params.
        $deletions = Controller::curr()->getRequest()->postVar('__deletion__'.$this->getName());
        
        if($deletions) {
            foreach($deletions as $id) {
                $this->deleteFileByID($id);
            }
        }
        
        $PeopleImages = $this->Value();
        
        

        if ($record) {
            
            $x = 0;

                foreach ($PeopleImages as $ID) {

                    //debug::dump($ID);
                    // ONLY ADD, IF THERE IS NO PEOPLE IMAGE WITH THAT ID
                    
                    if ( PeopleImage::get()->filter('ID', $ID )->Count() == 1) {
                        
                    } else {
                        $PeopleImage = new PeopleImage();
                        $PeopleImage->ClassName = 'PeopleImage';
                        $PeopleImage->ImageID = $ID;
                        $PeopleImage->PeopleID = $record->ID;
                        $PeopleImage->write();
                        $record->PeopleImages()->add($PeopleImage);
                        $x++;
                        
                    }
                    
                }
                $record->write();
            
        }
        
         return $this;
        

    }

    /**
     * Set the form method, e.g. PUT
     * @param string $method
     * @return  FileAttachmentField
     */
    public function setMethod($method) {
        $this->settings['method'] = $method;

        return $this;
    }

    /**
     * Sets number of allowed parallel uploads
     * @param int $num
     * @return  FileAttachmentField
     */
    public function setParallelUploads($num) {
        $this->settings['parallelUploads'] = $num;

        return $this;
    }

    /**
     * Allow multiple files
     * @param boolean $bool
     * @return  FileAttachmentField
     */
    public function setMultiple($bool) {
        $this->settings['uploadMultiple'] = $bool;

        return $this;
    }

    /**
     * Max filesize for uploads, in megabytes.
     * Defaults to upload_max_filesize
     * @param string $num
     * @return  FileAttachmentField
     */
    public function setMaxFilesize($num) {
        $this->settings['maxFilesize'] = $num;

        return $this;
    }

    /**
     * Sets the name of the upload parameter, e.g. "Files"
     * @param string $name
     * @return  FileAttachmentField
     */
    public function setParamName($name) {
        $this->settings['paramName'] = $name;

        return $this;
    }

    /**
     * Allow or disallow image thumbnails created client side
     * @param boolean $bool
     * @return  FileAttachmentField
     */
    public function setCreateImageThumbnails($bool) {
        $this->settings['createImageThumbnails'] = $bool;

        return $this;
    }

    /**
     * Set the threshold at which to not create an image thumbnail
     * @param int $num
     * @return  FileAttachmentField
     */
    public function setMaxThumbnailFilesize($num) {
        $this->settings['thumbnailFilesize'] = $num;

        return $this;
    }

    /**
     * The thumbnail width
     * @param int $num
     * @return  FileAttachmentField
     */
    public function setThumbnailWidth($num) {
        $this->settings['thumbnailWidth'] = $num;

        return $this;
    }

    /**
     * The thumbnail height
     * @param int $num
     * @return  FileAttachmentField
     */
    public function setThumbnailHeight($num) {
        $this->settings['thumbnailHeight'] = $num;

        return $this;
    }

    /**
     * The layout of the uploader, either "grid" or "list"
     * @param string $view
     * @return  FileAttachmentField
     */
    public function setView($view) {
        if(!in_array($view, array ('grid','list'))) {
            throw new Exception("FileAttachmentField::setView - View must be one of 'grid' or 'list'");
        }

        $this->view = $view;

        return $this;
    }

    /**
     * Gets the current view
     * @return string
     */
    public function getView() {
        return $this->view;
    }

    /**
     * Set the selector for the clickable element. Use a boolean for the
     * entire dropzone.
     * @param string|bool $val
     * @return  FileAttachmentField
     */
    public function setClickable($val) {
        $this->settings['clickable'] = $val;

        return $this;
    }

    /**
     * A list of accepted file extensions
     * @param array $files
     * @return  FileAttachmentField
     */
    public function setAcceptedFiles($files = array ()) {
        if(is_array($files)) {
            $files = implode(',', $files);
        }
        $this->settings['acceptedFiles'] = str_replace(' ', '', $files);

        return $this;
    }

    /**
     * A helper method to only allow images files
     * @return FileAttachmentField
     */
    public function imagesOnly() {
        $this->setAcceptedFiles(array('.png','.gif','.jpeg','.jpg'));

        return $this;
    }

    /**
     * Sets the allowed mime types
     * @param array $types
     * @return  FileAttachmentField
     */
    public function setAcceptedMimeTypes($types = array ()) {
        if(is_array($types)) {
            $types = explode(',', $types);
        }
        $this->settings['acceptedMimeTypes'] = $types;

        return $this;
    }

    /**
     * Set auto-processing. If true, uploads happen on addition to the queue
     * @param boolean $bool
     * @return  FileAttachmentField
     */
    public function setAutoProcessQueue($bool) {
        $this->settings['autoProcessQueue'] = $bool;

        return $this;
    }

    /**
     * Set the selector for the container element that holds all of the
     * uploaded files
     * @param string $val
     * @return  FileAttachmentField
     */
    public function setPreviewsContainer($val) {
        $this->settings['previewsContainer'] = $val;

        return $this;
    }

    /**
     * Sets the max resolution for images, in pixels
     * @param int $pixels
     */
    public function setMaxResolution($pixels) {
    	$this->settings['maxResolution'] = $pixels;

    	return $this;
    }

    /**
     * Sets selector for the preview template
     * @param string $template
     * @return  FileAttachmentField
     */
    public function setPreviewTemplate($template) {
        $this->previewTemplate = $template;

        return $this;
    }

    /**
     * Adds an arbitrary key/val params to send to the server with the upload
     * @param string $key
     * @param mixed $val
     * @return  FileAttachmentField
     */
    public function addParam($key, $val) {
        $this->params[$key] = $val;

        return $this;
    }

    /**
     * Sets permissions for this uploader: "detach", "upload", "delete", "attach"
     * Permissions can be boolean or Callable
     * @param array $perms
     * @return  FileAttachmentField
     */
    public function setPermissions($perms) {
        foreach($perms as $perm => $val) {
            if(!isset($this->permissions[$perm])) {
                throw new Exception("FileAttachmentField::setPermissions - Permission $perm is not allowed");
            }
            $this->permissions[$perm] = $val;
        }

        return $this;
    }

    /**
     * Sets a specific permission for this uploader: "detach", "upload", "delete", "attach"
     * Permissions can be boolean or Callable
     *
     * @param string $perm
     * @param boolean|Callable $val
     * @return  FileAttachmentField
     */
    public function setPermission($perm, $val) {
        return $this->setPermissions(array(
            $perm => $val
        ));
    }

	/**
	 * @param String
	 */
	public function setDisplayFolderName($name) {
		$this->displayFolderName = $name;
		return $this;
	}

	/**
	 * @return String
	 */
	public function getDisplayFolderName() {
		return $this->displayFolderName;
	}

    /**
     * Returns true if the uploader is being used in CMS context
     * @return boolean
     */
    public function isCMS() {
        return Controller::curr() instanceof LeftAndMain;
    }

    /**
     * Action to handle upload of a single file
     *
     * @param SS_HTTPRequest $request
     * @return SS_HTTPResponse
     * @return SS_HTTPResponse
     */
    public function upload(SS_HTTPRequest $request) { 
        if($this->isDisabled() || $this->isReadonly() || !$this->CanUpload()) {
            return $this->httpError(403);
        }

        if($this->getForm()) {
            $token = $this->getForm()->getSecurityToken();
            if(!$token->checkRequest($request)) return $this->httpError(400);
        }

        $name = $this->getSetting('paramName');
        $files = $_FILES[$name];
        $tmpFiles = array();

        // Sort the files out into a list of arrays containing each property
        if(!empty($files['tmp_name']) && is_array($files['tmp_name'])) {
            for($i = 0; $i < count($files['tmp_name']); $i++) {
                if(empty($files['tmp_name'][$i])) continue;
                $tmpFile = array();
                foreach(array('name', 'type', 'tmp_name', 'error', 'size') as $field) {
                    $tmpFile[$field] = $files[$field][$i];
                }
                $tmpFiles[] = $tmpFile;
            }
        }
        elseif(!empty($files['tmp_name'])) {
            $tmpFiles[] = $files;
        }

        $ids = array ();
        foreach($tmpFiles as $tmpFile) {
            if($tmpFile['error']) {
                return $this->httpError(400, $tmpFile['error']);
            }
            if($relationClass = $this->getFileClass($tmpFile['name'])) {
                $fileObject = SS_Object::create($relationClass);
            }

            try {
                $this->upload->loadIntoFile($tmpFile, $fileObject, $this->getFolderName());
                $ids[] = $fileObject->ID;
            } catch (Exception $e) {
                return $this->httpError(400, $e->getMessage());
            }

            if ($this->upload->isError()) {
                return $this->httpError(400, implode(' ' . PHP_EOL, $this->upload->getErrors()));
            }
        }

        return new SS_HTTPResponse(implode(',', $ids), 200);
    }


	/**
	 * @param SS_HTTPRequest $request
	 * @return UploadField_ItemHandler
	 */
	public function handleItem(SS_HTTPRequest $request) {
		return $this->getItemHandler($request->param('ID'));
	}

	/**
	 * @param int $itemID
	 * @return UploadField_ItemHandler
	 */
	public function getItemHandler($itemID) {
		return FileAttachmentField_ItemHandler::create($this, $itemID);
	}
    
    

    /**
     * @param SS_HTTPRequest $request
     * @return UploadField_ItemHandler
     */
    public function handleSelect(SS_HTTPRequest $request) {
        if($this->isDisabled() || $this->isReadonly() || !$this->CanAttach()) {
            return $this->httpError(403);
        }

        return FileAttachmentField_SelectHandler::create($this, $this->getFolderName());
    }


    /**
     * Deletes a file. Ensures user has permissions and the file is part
     * of the current record, so as not to allow arbitrary deletion of files
     *
     * @param  int $id
     * @return boolean
     */
    protected function deleteFileByID($id) {
        if($this->CanDelete() && $record = $this->getRecord()) {
            if($relation = $this->getRelation()) {
                $file = $relation->byID($id);
            }
            else if($record->has_one($this->getName())) {
                $file = $record->{$this->getName()}();
            }

            if($file && $file->canDelete()) {
                $file->delete();

                return true;
            }
        }

        return false;
    }

    /**
     * A template accessor that determines if the uploader is in "multiple" mode
     *
     * @return  boolean
     */
    public function IsMultiple() {
        if($this->getSetting('uploadMultiple')) {
            return true;
        }

        if($record = $this->getRecord()) {
            return ($record->many_many($this->getName()) || $record->has_many($this->getName()));
        }

        return false;
    }

    /**
     * The name of the input, e.g. the "has_one" or "many_many" relation name
     *
     * @return  string
     */
    public function InputName() {
        return $this->IsMultiple() ? $this->getName()."[]" : $this->getName();
    }

    /**
     * Gets a list of all the files that are attached to the record
     *
     * @return  SS_List
     */
    public function AttachedFiles() {
        if($record = $this->getRecord()) {
            if($record->hasMethod($this->getName())) {
                $result = $record->{$this->getName()}();
                if($result instanceof SS_List) {
                    $this->extend('updateAttachedFiles', $result);
                    return $result;
                }
                else if($result->exists()) {
                    $result = ArrayList::create(array($result));
                    $this->extend('updateAttachedFiles', $result);
                    return $result;
                }
            }
        }

        if ($ids = $this->dataValue()) {
            if (!is_array($ids)) {
                $ids = explode(',', $ids);
            }

            $attachments = ArrayList::create();
            foreach ($ids as $id) {
                $file = File::get()->byID((int) $id);
                if ($file && $file->canView()) {
                    $attachments->push($file);
                }
            }
            $this->extend('updateAttachedFiles', $attachments);
            return $attachments;
        }

        return false;
    }

    /**
     * Gets the directory that contains all the file icons organised into sizes
     *
     * @return  string
     */
    public function RootThumbnailsDir() {
        return $this->getSetting('thumbnailsDir') ?: DROPZONE_DIR.'/images/file-icons';
    }

    /**
     * Gets the directory to the file icons for the current thumbnail size
     *
     * @return  string
     */
    public function ThumbnailsDir() {
        return $this->RootThumbnailsDir().'/'.$this->TemplateThumbnailSize()."px";
    }


    public function CSSSize() {
        $w = $this->getSelectedThumbnailWidth();
        if($w < 150) return "small";
        if($w < 250) return "medium";

        return "large";
    }


    /**
     * The directory that the module is installed to. A template accessor
     *
     * @return  string
     */
    public function DropzoneDir() {
        return DROPZONE_DIR;
    }

    /**
     * Gets the value
     *
     * @return  string|array
     */
    public function Value() {
        return $this->dataValue();
    }

    /**
     * Returns true if the "upload" permission returns true
     *
     * @return  boolean
     */
    public function CanUpload() {
        return $this->checkPerm('upload');
    }

    /**
     * Returns true if the "delete" permission returns true
     *
     * @return  boolean
     */
    public function CanDelete() {
        return $this->checkPerm('delete');
    }

    /**
     * Returns true if the "detach" permission returns true
     *
     * @return  boolean
     */
    public function CanDetach() {
        return $this->checkPerm('detach');
    }

    /**
     * Returns true if the "attach" permission returns true
     *
     * @return  boolean
     */
    public function CanAttach() {
        return $this->checkPerm('attach');
    }

    /**
     * Renders the preview template, optionally for a given file
     * @param int $fileID
     */
    public function PreviewTemplate($fileID = null) {
        return $this->renderWith($this->previewTemplate);

    }

    /**
     * Gets the closest thumbnail size for the template, given the list of
     * icon_sizes (e.g. 32px, 64px, 128px)
     *
     * @return  int
     */
    public function TemplateThumbnailSize() {
        $w = $this->getSelectedThumbnailWidth();

        foreach($this->config()->icon_sizes as $size) {
            if($w <= $size) return $size;
        }
    }

    /**
     * Returns true if the uploader auto-processes
     *
     * @return  boolean
     */
    public function AutoProcess() {
        $result = (bool) $this->getSetting('autoProcessQueue');

        return $result;
    }

    /**
     * Checks for a given permission. If it is a closure, invoke the method
     * @param  string $perm
     * @return boolean
     */
    protected function checkPerm($perm) {
        if(!isset($this->permissions[$perm])) return false;

        if(is_callable($this->permissions[$perm])) {
            return $this->permissions[$perm]();
        }

        return $this->permissions[$perm];
    }

    /**
     * Gets the classname for the file, e.g. from the declared
     * file relation on the record.
     *
     * If given a filename, look at the extension and upgrade it
     * to an Image if necessary.
     *
     * @param  string $filename
     * @return string
     */
    public function getFileClass($filename = null) {
        $name = $this->getName();
        $record = $this->getRecord();

        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        $defaultClass = File::get_class_for_file_extension($ext);

        if(empty($name) || empty($record)) {
            return $defaultClass;
        }

        if($record) {
    	    $class = $record->getRelationClass($name);
        	if(!$class) $class = "File";
    	}

        if($filename) {
            if($defaultClass == "Image" &&
               $this->config()->upgrade_images &&
               !Injector::inst()->get($class) instanceof Image
            ) {
                $class = "Image";
            }
        }

        return $class;
    }

    /**
     * Get the record that this form field is editing
     * @return DataObject
     */
    public function getRecord() {
        if (!$this->record && $this->form) {
            if (($record = $this->form->getRecord()) && ($record instanceof DataObject)) {
                $this->record = $record;
            }
            elseif (($controller = $this->form->Controller())
                && $controller->hasMethod('data')
                && ($record = $controller->data())
                && ($record instanceof DataObject)
            ) {
                $this->record = $record;
            }
        }

        return $this->record;
    }

    /**
     * Gets the name of the relation, if attached to a record
     * @return string
     */
    protected function getRelation($record = null) {
        if(!$record) $record = $this->getRecord();

        if($record) {
            $fieldname = $this->getName();
            $relation = $record->hasMethod($fieldname) ? $record->$fieldname() : null;

            return ($relation && ($relation instanceof RelationList || $relation instanceof UnsavedRelationList)) ? $relation : false;
        }

        return false;
    }
    
    /**
     * Gets a given setting. Falls back on Config defaults
     *
     * Note: config settings are in underscore_case
     *
     * @param  string $setting
     * @return mixed
     */
    public function getSetting($setting) {
        if(isset($this->settings[$setting])) {
            return $this->settings[$setting];
        }

        $config = Config::inst()->get(__CLASS__, "defaults");
        $configName = static::underscorise($setting);

        return isset($config[$configName]) ? $config[$configName] : null;
    }
    
    /**
     * Sets a given setting.
     *
     * Note: config settings are in underscore_case
     *
     * @param  string $setting
     * @param  string $value
     */
    public function setSetting($setting, $value) {
    	$this->settings[static::underscorise($setting)] = $value;
    	
    	return $this;
    }

    /**
     * Gets the default settings in the actual Javascript object so that
     * the config JSON doesn't get polluted with default settings
     *
     * @return array
     */
    protected function getDefaults() {
        $file_path = BASE_PATH.'/'.DROPZONE_DIR.'/'.$this->config()->default_config_path;
        if(!file_exists($file_path)) {
            throw new Exception("FileAttachmentField::getDefaults() - There is no config json file at $file_path");
        }

        return Convert::json2array(file_get_contents($file_path));
    }

    /**
     * Gets the thumbnail width given the current view type
     * @return int
     */
    public function getSelectedThumbnailWidth() {
        if($w = $this->getSetting('thumbnailWidth')) {
            return $w;
        }

        $setting = $this->view == "grid" ? 'grid_thumbnail_width' : 'list_thumbnail_width';

        return $this->config()->$setting;
    }

    /**
     * Gets the thumbnail height given the current view type
     * @return int
     */
    public function getSelectedThumbnailHeight() {
        if($h = $this->getSetting('thumbnailHeight')) {
            return $h;
        }

        $setting = $this->view == "grid" ? 'grid_thumbnail_height' : 'list_thumbnail_height';

        return $this->config()->$setting;
    }

    /**
     * Creates a JSON representation of the settings. Augments the list with various
     * parameters calculated at run time.
     *
     * @return string
     */
    public function getConfigJSON() {
        $data = $this->settings;
        $defaults = $this->getDefaults();
        foreach($this->config()->defaults as $setting => $value) {
            $js_name = static::camelise($setting);

            // If the setting has been set on the instance, use that value
            if(isset($data[$js_name])) {
                continue;
            }

            // Only include the setting in the JSON if it differs from the core default value
            if(!isset($defaults[$js_name]) || ($defaults[$js_name] !== $value)) {
                $data[$js_name] = $value;
            }
        }

        $data['params'] = $this->params;
        $data['thumbnailsDir'] = $this->ThumbnailsDir();
        $data['thumbnailWidth'] = $this->getSelectedThumbnailWidth();
        $data['thumbnailHeight'] = $this->getSelectedThumbnailHeight();        

        if(!$this->IsMultiple()) {
            $data['maxFiles'] = 1;
        }

        if($this->isCMS()) {
            $data['urlSelectDialog'] = $this->Link('select');
            if($this->getFolderName()) {
            	$data['folderID'] = Folder::find_or_make($this->getFolderName())->ID;
            }
        }

        return Convert::array2json($data);
    }
    
    
	protected $fileEditFields = null;
    
    // /**
    //  * FieldList $fields or string $name (of a method on File to provide a fields) for the EditForm
    //  * @example 'getCMSFields'
    //  *
    //  * @param FieldList|string
    //  * @return Uploadfield Self reference
    //  */
    // public function setFileEditFields($fileEditFields) {
    //     $this->fileEditFields = $fileEditFields;
    //     return $this;
    // }

    
    // /**
    //  * FieldList $fields for the EditForm
    //  * @example 'getCMSFields'
    //  *
    //  * @param File $file File context to generate fields for
    //  * @return FieldList List of form fields
    //  */
    //
    //     public function getFileEditFields(File $file) {
    //
    //     // Empty actions, generate default
    //     if(empty($this->fileEditFields)) {
    //         $fields = $file->getCMSFields();
    //         // Only display main tab, to avoid overly complex interface
    //         if($fields->hasTabSet() && ($mainTab = $fields->findOrMakeTab('Root.Main'))) {
    //             $fields = $mainTab->Fields();
    //         }
    //         return $fields;
    //     }
    //
    //     // Fields instance
    //     if ($this->fileEditFields instanceof FieldList) return $this->fileEditFields;
    //
    //     // Method to call on the given file
    //     if($file->hasMethod($this->fileEditFields)) {
    //         return $file->{$this->fileEditFields}();
    //     }
    //
    //     user_error("Invalid value for UploadField::fileEditFields", E_USER_ERROR);
    // }
    //
    //   
     
	/**
	 * FieldList $actions or string $name (of a method on File to provide a actions) for the EditForm
	 * @example 'getCMSActions'
	 *
	 * @var FieldList|string
	 */
	protected $fileEditActions = null;
    
    
    
	/**
	 * Validator (eg RequiredFields) or string $name (of a method on File to provide a Validator) for the EditForm
	 * @example 'getCMSValidator'
	 *
	 * @var RequiredFields|string
	 */
	protected $fileEditValidator = null;
    
    
	/**
	 * Determines the validator to use for the edit form
	 * @example 'getCMSValidator'
	 *
	 * @param File $file File context to generate validator from
	 * @return Validator Validator object
	 */
	public function getFileEditValidator(File $file) {
		// Empty validator
		if(empty($this->fileEditValidator)) return null;

		// Validator instance
		if($this->fileEditValidator instanceof Validator) return $this->fileEditValidator;

		// Method to call on the given file
		if($file->hasMethod($this->fileEditValidator)) {
			return $file->{$this->fileEditValidator}();
		}

		user_error("Invalid value for UploadField::fileEditValidator", E_USER_ERROR);
	}

	/**
	 * Validator (eg RequiredFields) or string $name (of a method on File to provide a Validator) for the EditForm
	 * @example 'getCMSValidator'
	 *
	 * @param Validator|string
	 * @return Uploadfield Self reference
	 */
	public function setFileEditValidator($fileEditValidator) {
		$this->fileEditValidator = $fileEditValidator;
		return $this;
	}
    
    
}

class FileAttachmentField_SelectHandler extends UploadField_SelectHandler {

    private static $allowed_actions = array (
        'filesbyid',
    );


    /**
     * @param $folderID The ID of the folder to display.
     * @return FormField
     */
    protected function getListField($folderID) {
        // Generate the folder selection field.
        $folderField = new TreeDropdownField('ParentID', _t('HtmlEditorField.FOLDER', 'Folder'), 'Folder');
        $folderField->setValue($folderID);

        // Generate the file list field.
        $config = GridFieldConfig::create();
        $config->addComponent(new GridFieldSortableHeader());
        $config->addComponent(new GridFieldFilterHeader());
        $config->addComponent($columns = new GridFieldDataColumns());
        $columns->setDisplayFields(array(
            'StripThumbnail' => '',
            'Name' => 'Name',
            'Title' => 'Title'
        ));
        $config->addComponent(new GridFieldPaginator(8));

        // If relation is to be autoset, we need to make sure we only list compatible objects.
        $baseClass = $this->parent->getFileClass();

        // Create the data source for the list of files within the current directory.
        $files = DataList::create($baseClass)->filter('ParentID', $folderID);

        $fileField = new GridField('Files', false, $files, $config);
        $fileField->setAttribute('data-selectable', true);
        if($this->parent->IsMultiple()) {
            $fileField->setAttribute('data-multiselect', true);
        }

        $selectComposite = new CompositeField(
            $folderField,
            $fileField
        );

        return $selectComposite;
    }


    public function filesbyid(SS_HTTPRequest $r) {
        $ids = $r->getVar('ids');
        $files = File::get()->byIDs(explode(',',$ids));

        $json = array ();
        foreach($files as $file) {
            $template = new SSViewer('FileAttachmentField_attachments');
            $html = $template->process(ArrayData::create(array(
                'File' => $file,
                'Scope' => $this->parent
            )));

            $json[] = array (
                'id' => $file->ID,
                'html' => $html->forTemplate()
            );
        }

        return Convert::array2json($json);
    }
    
    

}



/**
 * RequestHandler for actions (edit, remove, delete) on a single item (File) of the UploadField
 *
 * @author Zauberfisch
 * @package forms
 * @subpackages fields-files
 */
class FileAttachmentField_ItemHandler extends RequestHandler {

	/**
	 * @var UploadFIeld
	 */
	protected $parent;

	/**
	 * @var int FileID
	 */
	protected $itemID;

	private static $url_handlers = array(
		'$Action!' => '$Action',
		'' => 'index',
	);

	private static $allowed_actions = array(
		'delete',
		'edit',
		'EditForm'
	);
    
    
	/**
	 * @param UploadField $parent
	 * @param int $itemID
	 */
	public function __construct($parent, $itemID) {
		$this->parent = $parent;
		$this->itemID = $itemID;

		parent::__construct();
	}

	/**
	 * @return File
	 */
	public function getItem() {
		return DataObject::get_by_id('File', $this->itemID);
	}

	/**
	 * @param string $action
	 * @return string
	 */
	public function Link($action = null) {
		return Controller::join_links($this->parent->Link(), '/item/', $this->itemID, $action);
	}

	/**
	 * @return string
	 */
	public function DeleteLink() {
		$token = $this->parent->getForm()->getSecurityToken();
		return $token->addToUrl($this->Link('delete'));
	}

	/**
	 * @return string
	 */
	public function EditLink() {
		return $this->Link('edit');
	}

	/**
	 * Action to handle deleting of a single file
	 *
	 * @param SS_HTTPRequest $request
	 * @return SS_HTTPResponse
	 */
	public function delete(SS_HTTPRequest $request) {
		// Check form field state
		if($this->parent->isDisabled() || $this->parent->isReadonly()) return $this->httpError(403);

		// Protect against CSRF on destructive action
		$token = $this->parent->getForm()->getSecurityToken();
		if(!$token->checkRequest($request)) return $this->httpError(400);

		// Check item permissions
		$item = $this->getItem();
		if(!$item) return $this->httpError(404);
		if($item instanceof Folder) return $this->httpError(403);
		if(!$item->canDelete()) return $this->httpError(403);

		// Delete the file from the filesystem. The file will be removed
		// from the relation on save
		// @todo Investigate if references to deleted files (if unsaved) is dangerous
		$item->delete();
	}

	/**
	 * Action to handle editing of a single file
	 *
	 * @param SS_HTTPRequest $request
	 * @return ViewableData_Customised
	 */
	public function edit(SS_HTTPRequest $request) {
		// Check form field state
		if($this->parent->isDisabled() || $this->parent->isReadonly()) return $this->httpError(403);

		// ID is PeopleImage ID
        $ID = $request->params()['ID'];
        $PeopleImage = PeopleImage::get()->filter('ID', $ID)->First();
        
        $item = DataObject::get_by_id('File', $PeopleImage->ImageID );
            
		if(!$item) return $this->httpError(404);
		if($item instanceof Folder) return $this->httpError(403);
		if(!$item->canEdit()) return $this->httpError(403);

		Requirements::css(FRAMEWORK_DIR . '/css/UploadField.css');

		return $this->customise(array(
			'Form' => $this->EditForm()
		))->renderWith($this->parent->getTemplateFileEdit());
	}
    
    
	/**
	 * FieldList $actions or string $name (of a method on File to provide a actions) for the EditForm
	 * @example 'getCMSActions'
	 *
	 * @param File $file File context to generate form actions for
	 * @return FieldList Field list containing FormAction
	 */
	public function getFileEditActions(File $file) {

		// Empty actions, generate default
		if(empty($this->fileEditActions)) {
			$actions = new FieldList($saveAction = new FormAction('doEdit', _t('UploadField.DOEDIT', 'Save')));
			$saveAction->addExtraClass('ss-ui-action-constructive icon-accept');
			return $actions;
		}

		// Actions instance
		if ($this->fileEditActions instanceof FieldList) return $this->fileEditActions;

		// Method to call on the given file
		if($file->hasMethod($this->fileEditActions)) {
			return $file->{$this->fileEditActions}();
		}

		user_error("Invalid value for UploadField::fileEditActions", E_USER_ERROR);
	}

	/**
	 * FieldList $actions or string $name (of a method on File to provide a actions) for the EditForm
	 * @example 'getCMSActions'
	 *
	 * @param FieldList|string
	 * @return Uploadfield Self reference
	 */
	public function setFileEditActions($fileEditActions) {
		$this->fileEditActions = $fileEditActions;
		return $this;
	}
    
    
    
    
	/**
	 * @return Form
	 */
	public function EditForm() {
        // Is an PeopleImage Object ID
        $ID = Controller::curr()->request->params()['ID'];
        $PeopleImage = PeopleImage::get()->filter('ID', $ID)->First();

		$file = $PeopleImage->Image();
        
        if(!$file) return $this->httpError(404);
        if($file instanceof Folder) return $this->httpError(403);
        if(!$file->canEdit()) return $this->httpError(403);
        
        // Get form components
        $fields = singleton('PeopleImage')->getCMSFields();

        // if($fields->hasTabSet() && ($mainTab = $fields->findOrMakeTab('Root.Main'))) {
//             $fields = $mainTab->Fields();
//         }

        $actions = new FieldList(
            new FormAction("doEdit", "edit")
        );

        $validator = new RequiredFields();

        $form = new Form(
			$this,
			__FUNCTION__,
			$fields,
            $actions,
            $validator
		);
		$form->loadDataFrom($PeopleImage);
		$form->addExtraClass('small');

		return $form;
	}

	/**
	 * @param array $data
	 * @param Form $form
	 * @param SS_HTTPRequest $request
	 */
	public function doEdit(array $data, Form $form, SS_HTTPRequest $request) {
		// Check form field state
		if($this->parent->isDisabled() || $this->parent->isReadonly()) return $this->httpError(403);

        $ID = Controller::curr()->request->params()['ID'];
        $item = PeopleImage::get()->filter('ID', $ID)->First();

		if(!$item) return $this->httpError(404);
        // if($item instanceof Folder) return $this->httpError(403);
        // if(!$item->canEdit()) return $this->httpError(403);
        
		$form->saveInto($item);
		$item->write();
        // debug::dump($item);
        
		$form->sessionMessage(_t('UploadField.Saved', 'Saved'), 'good');

		return $this->edit($request);
	}

}