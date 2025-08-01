<?php
require_once(__CA_MODELS_DIR__.'/ca_attribute_values.php');
require_once(__CA_LIB_DIR__."/Media.php");

class fileToFedoraPlugin extends BaseApplicationPlugin {
    private $config;

    public function __construct($plugin_path) {
        $this->description = _t('Modifies uploaded files by pushing them to a Fedora repository and replacing appropriate bundle attributes.');
		$this->config = Configuration::load($plugin_path . DIRECTORY_SEPARATOR . 'conf' . DIRECTORY_SEPARATOR . 'fileToFedora.conf');

		parent::__construct();
    }

    protected static function clearUpload($key) {
        $_FILES[$key]['error'] = UPLOAD_ERR_NO_FILE;
        $_FILES[$key]['tmp_name'] = null;
        $_FILES[$key]['full_path'] = null;
        $_FILES[$key]['type'] = null;
        $_FILES[$key]['name'] = null;
        $_FILES[$key]['size'] = 0;
    }

    public function checkStatus() {
        return array(
            'description' => $this->getDescription(),
            'errors' => [],
            'warnings' => [],
            'available' => true,
        );
    }

    public function hookBeforeSaveItem(&$pa_params) {
        error_log(print_r($_FILES, true));
        $va_request = $pa_params['request'];
        $va_instance =& $pa_params['instance'];

        $source_element_id = intval($this->config->get('source_element_id'));
        $target_element_id = intval($this->config->get('target_element_id'));

        $file_keys = array_keys($_FILES);
        $file_attr = [];
        foreach ($file_keys as $key) {
            // As per prodvidence's BundblableLabelableBaseModelWithAttributes.php:4023 (and maybe other places),
            // name of attribute request parameters are:
			// 	For new attributes
			// 		{$vs_form_prefix}_attribute_{element_set_id}_{element_id|'locale_id'}_new_{n}
			//		ex. ObjectBasicForm_attribute_6_locale_id_new_0 or ObjectBasicForm_attribute_6_desc_type_new_0
			//
			// 	For existing attributes:
			// 		{$vs_form_prefix}_attribute_{element_set_id}_{element_id|'locale_id'}_{attribute_id}

            $key_split = explode('_', $key);
            $element_id = $key_split[3]; // = element_id

            // Skip failed or empty uploads.
            if ($_FILES[$key]['error'] !== UPLOAD_ERR_OK) continue;

            $file_attr[$element_id] = $key;
        }

        // Look through all the uploaded files to catch our source element/attribute value and upload it to Fedora.
        foreach ($file_attr as $elem_id => $files_key) {
            if ($elem_id === $source_element_id && $_FILES[$files_key]['error'] === UPLOAD_ERR_OK) {
                $metadata = fileToFedoraPlugin::_harvestMetadata($_FILES[$files_key]);
                error_log(print_r($metadata, true));

                $media_url = new FedoraUpload(
                    $this->config->get('fedora_repo'),
                    $this->config->get('collection_path'),
                    $_FILES[$files_key]['name'],
                    $_FILES[$files_key]['tmp_name'],
                    $this->config->get('username'),
                    $this->config->get('password')
                )->execute();
                error_log(print_r($media_url == true, true));
                error_log(print_r($media_url == false, true));
                error_log(print_r($media_url === false, true));

                // Clear the upload in any case, so the file does not get uploaded to the original attribute.
                fileToFedoraPlugin::clearUpload($files_key);

                if (!$media_url) {
                    // For now, we borrow the error code 1970 from the FileAttributeValue class.
                    $error = new ApplicationError();
                    $error->setErrorOutput($va_instance->error_output);
                    $error->setError(1970, 'Failed to upload the selected file to Fedora (is Fedora online?).', 'fileToFedoraPlugin->hookBeforeSaveItem()');
                    $va_request->addActionError($error, 'fileToFedoraPlugin->hookBeforeSaveItem()');
                    // HACK: Do an invalid attribute replacement to trigger an error and cancel saving the object.
                    $va_instance->replaceAttribute([
                       $target_element_id => 'e://ERROR'
                    ], $target_element_id);
                } else {
                    $va_instance->replaceAttribute(array(
                        $target_element_id => $media_url
                    ), $target_element_id);
                }
            }
        }
    }

    protected static function _harvestMetadata($pa_file) {
        $m = new Media();

        // TODO: Rename the temporary filename from upload for plugins like ImageMagick which partially rely on extensions to work properly.
        $mimetype = $m->divineFileFormat($pa_file['tmp_name']);
        if ($mimetype === false)
            return false;
        
        if (!$m->read($pa_file['tmp_name'], null, ['original_filename' => $pa_file['name']]))
            return false;

        return $m->getExtractedMetadata();
    }
}

class FedoraUpload {
    private string $filename;
    private string $filepath;
    private string $repo_url;
    private string $collection_path;
    private string $username;
    private string $password;

    public function __construct($repo_url, $collection_path, $filename, $filepath, $username, $password) {
        $this->repo_url = $repo_url;
        $this->collection_path = $collection_path;
        $this->filename = $filename;
        $this->filepath = $filepath;
        $this->username = $username;
        $this->password = $password;
    }

    public function execute(): string {
        $http_headers = [
            'Content-Type: ' . mime_content_type($this->filepath),
            'Content-Disposition: attachment; filename="' . quoted_printable_encode($this->filename) . '"'
        ];

        $curl = curl_init();
        // HACK: Even though we want to POST the file, we have to set curl to PUT first to be able to give it the file directly.
        curl_setopt( $curl, CURLOPT_PUT, true );
        curl_setopt( $curl, CURLOPT_INFILESIZE, filesize($this->filepath) );
        curl_setopt( $curl, CURLOPT_READDATA, ($in=fopen($this->filepath, 'r')) );
        curl_setopt( $curl, CURLOPT_CUSTOMREQUEST, 'POST' );
        curl_setopt( $curl, CURLOPT_HTTPHEADER, $http_headers );
        curl_setopt( $curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC );
        curl_setopt( $curl, CURLOPT_USERPWD, $this->username . ':' . $this->password);
        curl_setopt( $curl, CURLOPT_URL, $this->repo_url . $this->collection_path );
        curl_setopt( $curl, CURLOPT_RETURNTRANSFER, true );
        $result = curl_exec($curl);
        curl_close($curl);
        fclose($in);
        return $result;
    }
}
?>
