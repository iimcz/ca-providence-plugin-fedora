<?php
require_once(__CA_MODELS_DIR__.'/ca_attribute_values.php');
require_once(__CA_MODELS_DIR__.'/ca_metadata_elements.php');
require_once(__CA_LIB_DIR__."/Media.php");

class fileToFedoraPlugin extends BaseApplicationPlugin {
    private $config;

    public function __construct($plugin_path) {
        $this->description = _t('Modifies uploaded files by pushing them to a Fedora repository and replacing appropriate bundle attributes.');
		$this->config = Configuration::load($plugin_path . DIRECTORY_SEPARATOR . 'conf' . DIRECTORY_SEPARATOR . 'fileToFedora.conf');
        $this->ebucore_mapping = json_decode(file_get_contents($plugin_path . DIRECTORY_SEPARATOR . 'conf' . DIRECTORY_SEPARATOR . 'ebucore-mapping.json'), true);
        $this->type_mapping = json_decode(file_get_contents($plugin_path . DIRECTORY_SEPARATOR . 'conf' . DIRECTORY_SEPARATOR . 'type-mapping.json'), true);
        $this->format_mapping = json_decode(file_get_contents($plugin_path . DIRECTORY_SEPARATOR . 'conf' . DIRECTORY_SEPARATOR . 'format-mapping.json'), true);

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
        $valid_config = !$this->config->isError();
        $valid_config = $valid_config && $this->config->exists('source_element_id');
        $valid_config = $valid_config && $this->config->exists('target_element_id');
        $valid_config = $valid_config && $this->config->exists('fedora_repo');
        $valid_config = $valid_config && $this->config->exists('collection_path');
        $valid_config = $valid_config && $this->config->exists('username');
        $valid_config = $valid_config && $this->config->exists('password');
        $valid_config = $valid_config && $this->config->exists('file_size_element_id');
        $valid_config = $valid_config && $this->config->exists('file_format_element_id');
        $valid_config = $valid_config && $this->config->exists('file_hash_element_id');


        $errors = [];
        if (!$valid_config)
            array_push($errors, 'Invalid config - missing keys. Check config file.');


        $mediainfo_path = caGetExternalApplicationPath('mediainfo');
	    if (!caIsValidFilePath($mediainfo_path)) {
            $valid_config = false;
            array_push($errors, 'Mediainfo is not available - it is required to extract metadata.');    
        }

        return array(
            'description' => $this->getDescription(),
            'errors' => $errors,
            'warnings' => [],
            'available' => $valid_config,
        );
    }

    public function hookBeforeSaveItem(&$pa_params) {
        $va_request = $pa_params['request'];
        $va_instance =& $pa_params['instance'];

        $source_element_id = intval($this->config->get('source_element_id'));
        $target_element_id = intval($this->config->get('target_element_id'));

        $file_name_element_id = intval($this->config->get('file_name_element_id'));
        $file_type_element_id = intval($this->config->get('file_type_element_id'));
        $file_format_element_id = intval($this->config->get('file_format_element_id'));
        $file_size_element_id = intval($this->config->get('file_size_element_id'));
        $file_quality_element_id = intval($this->config->get('file_quality_element_id'));
        $file_hash_element_id = intval($this->config->get('file_hash_element_id'));

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
                // TODO: actually use the extracted metadata and save it somewhere (Fedora, maybe to CA as well?)
                fileToFedoraPlugin::_harvestMetadata($_FILES[$files_key]['tmp_name'], $metadata, $file_type);

                $upload_obj = new FedoraUpload(
                    $this->config->get('fedora_repo'),
                    $this->config->get('collection_path'),
                    $_FILES[$files_key]['name'],
                    $_FILES[$files_key]['tmp_name'],
                    $this->config->get('username'),
                    $this->config->get('password')
                );
                $media_url = $upload_obj->execute_upload();

                foreach ($metadata as $key => $value) {
                    $upload_obj->add_metadata($key, $value);
                }
                $upload_obj->execute_update();


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

                    // Also add harvested metadata to the configured attributes.
                    if ($file_name_element_id > 0) {
                        $va_instance->replaceAttribute(array(
                            $file_name_element_id => $_FILES[$files_key]['name']
                        ), $file_name_element_id);
                    }

                    if ($file_type_element_id > 0) {
                        $ca_type = $file_type;
                        if (array_key_exists($file_type, $this->type_mapping))
                            $ca_type = $this->type_mapping[$file_type];
                        else if (array_key_exists('Unknown', $this->type_mapping))
                            $ca_type = $this->type_mapping['Unknown'];
                        $va_instance->replaceAttribute(array(
                            $file_type_element_id => $ca_type
                        ), $file_type_element_id);
                    }

                    if ($file_format_element_id > 0 && array_key_exists('ebucore:hasFormat', $metadata)) {
                        $ca_format = $metadata['ebucore:hasFormat'];
                        if (array_key_exists($metadata['ebucore:hasFormat'], $this->format_mapping))
                            $ca_format = $this->format_mapping[$metadata['ebucore:hasFormat']];
                        else if (array_key_exists('Unknown', $this->format_mapping))
                            $ca_format = $this->format_mapping['Unknown'];
                        $va_instance->replaceAttribute(array(
                            $file_format_element_id => $ca_format
                        ), $file_format_element_id);
                    }

                    if ($file_size_element_id > 0 && array_key_exists('ebucore:fileSize', $metadata)) {
                        $va_instance->replaceAttribute(array(
                            $file_size_element_id => $metadata['ebucore:fileSize']
                        ), $file_size_element_id);
                    }
                    
                    if ($file_quality_element_id > 0 && array_key_exists('ebucore:width', $metadata)) {
                        $va_instance->replaceAttribute(array(
                            $file_quality_element_id => $metadata['ebucore:width'] . 'x' . $metadata['ebucore:height']
                        ), $file_quality_element_id);
                    }
                    // TODO: also include a hash?
                }

                // Clear the upload in any case, so the file does not get uploaded to the original attribute.
                fileToFedoraPlugin::clearUpload($files_key);
            }
        }
    }

    protected function _harvestMetadata($pa_file, &$result,  &$file_type) {
        $mediainfo_path = caGetExternalApplicationPath('mediainfo');
	    if (!caIsValidFilePath($mediainfo_path)) { return false; }

        caExec($mediainfo_path." --output=JSON  ".caEscapeShellArg($pa_file), $va_output, $vn_return);
        $va_output = implode("\n", $va_output);

        // Parse JSON
        $metadata = json_decode($va_output, true);
        if ($metadata === null) {
            return false;
        }
        $metadata_tracks = $metadata['media']['track'];

        $type_is_final = false;

        $result = array();
        foreach ($metadata_tracks as $track) {
            if ($track['@type'] === 'Video') {
                $track_mapping = $this->ebucore_mapping['Video'];
                $file_type = 'Video';
                $type_is_final = true;
            }
            else if ($track['@type'] === 'Audio') {
                $track_mapping = $this->ebucore_mapping['Audio'];
                if (!$type_is_final) $file_type = 'Audio';
            }
            else if ($track['@type'] === 'Image') {
                $track_mapping = $this->ebucore_mapping['Image'];
                $file_type = 'Image';
                $type_is_final = true;
            }
            else if ($track['@type'] === 'Text') {
                $track_mapping = $this->ebucore_mapping['Text'];
                $file_type = 'Text';
                $type_is_final = true;
            }
            else {
                // Assume General
                $track_mapping = $this->ebucore_mapping['General'];
                $file_type = 'Unknown'; // TODO: something else here?
            }

            foreach ($track as $key => $value) {
                if (array_key_exists($key, $track_mapping)) {
                    $result[$track_mapping[$key]] = $value;
                }
            }
        }
    }
}

class FedoraUpload {
    private static string $update_prefix = "PREFIX premis: <http://www.loc.gov/premis/rdf/v1#>
PREFIX test: <info:fedora/test/>
PREFIX memento: <http://mementoweb.org/ns#>
PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>
PREFIX webac: <http://fedora.info/definitions/v4/webac#>
PREFIX acl: <http://www.w3.org/ns/auth/acl#>
PREFIX vcard: <http://www.w3.org/2006/vcard/ns#>
PREFIX xsi: <http://www.w3.org/2001/XMLSchema-instance>
PREFIX xmlns: <http://www.w3.org/2000/xmlns/>
PREFIX rdf: <http://www.w3.org/1999/02/22-rdf-syntax-ns#>
PREFIX fedora: <http://fedora.info/definitions/v4/repository#>
PREFIX xml: <http://www.w3.org/XML/1998/namespace>
PREFIX ebucore: <http://www.ebu.ch/metadata/ontologies/ebucore/ebucore#>
PREFIX ldp: <http://www.w3.org/ns/ldp#>
PREFIX dcterms: <http://purl.org/dc/terms/>
PREFIX iana: <http://www.iana.org/assignments/relation/>
PREFIX xs: <http://www.w3.org/2001/XMLSchema>
PREFIX fedoraconfig: <http://fedora.info/definitions/v4/config#>
PREFIX foaf: <http://xmlns.com/foaf/0.1/>
PREFIX dc: <http://purl.org/dc/elements/1.1/>

DELETE { }
INSERT {
";
    private static string $update_suffix = "
}
WHERE { }";

    private string $filename;
    private string $filepath;
    private string $repo_url;
    private string $collection_path;
    private string $username;
    private string $password;
    private string $metadata;
    private string $file_url;

    public function __construct($repo_url, $collection_path, $filename, $filepath, $username, $password) {
        $this->repo_url = $repo_url;
        $this->collection_path = $collection_path;
        $this->filename = $filename;
        $this->filepath = $filepath;
        $this->username = $username;
        $this->password = $password;
        $this->metadata = "";
        $this->file_url = "";
    }

    public function add_metadata($key, $value) {
        $this->metadata .= " <> " . $key . " \"" . $value . "\".\n"; 
    }

    public function execute_upload(): string {
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
        $this->file_url = $result = curl_exec($curl);
        curl_close($curl);
        fclose($in);
        return $result;
    }

    public function execute_update() {
        if (!$this->file_url) {
            return false;
        }

        $request = FedoraUpload::$update_prefix . $this->metadata . FedoraUpload::$update_suffix;

        $curl = curl_init();
        curl_setopt( $curl, CURLOPT_URL, $this->file_url . '/fcr:metadata' );
        curl_setopt( $curl, CURLOPT_CUSTOMREQUEST, 'PATCH' );
        curl_setopt( $curl, CURLOPT_HTTPHEADER, [
            'Content-Type: application/sparql-update'
        ]);
        curl_setopt( $curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC );
        curl_setopt( $curl, CURLOPT_USERPWD, $this->username . ':' . $this->password);
        curl_setopt( $curl, CURLOPT_POSTFIELDS, $request );
        curl_setopt( $curl, CURLOPT_RETURNTRANSFER, true );
        $result = curl_exec($curl);
        curl_close($curl);
        return $result === null;
    }
}
?>
