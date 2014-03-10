<?php
/**
 * ProwlStack
 * @author Ronnie Moore
 * @version 1.0.0
 *
 * Receives webhook notifications from Formstack of newly-submitted
 * web forms and alerts user via Prowl iPhone notification system.
 */


class ProwlStack
{
    /**
     * Holds all Prowl configuration data
     * @var array
     */
    var $prowlData;

    /**
     * Holds all Formstack configuration data
     * @var array
     */
    var $formstackData;


    /**
     * Sets up defaults with configuration data for FormStack and Prowl data containers
     */
    public function __construct()
    {
        /* Formstack Data */
        $this->formstackData = array();

        // defaults
        $this->formstackData['SecurityCheckPassed'] = false; // default to not yet passing security check
        $this->formstackData['RequiredFormFields'] = array('FormID', 'UniqueID', 'HandshakeKey'); // minimum fields

        $this->formstackData['ApiEndpoint'] = 'https://www.formstack.com/api/v2/'; // API v2


        /* Prowl Data */
        $this->prowlData = array();
        $this->prowlData['MessageApplication'] = "ProwlStack"; // 256 char limit
        $this->prowlData['MessagePriority'] = 0; // range: [-2, 2]  where  -2 = Very Low. 0 = Normal.  2 = Emergency.

        $this->prowlData['MessageEvent'] = "Submission #{$_POST['UniqueID']}"; // 1024 char limit
        $this->prowlData['MessageDescription'] = "A new form submission was received at %DATE%"; // 10000 char limit

        // api response info
        $this->prowlData['ResponseCode'] = null;
        $this->prowlData['ResponseMessage'] = null;
	}

    /**
     * Gets Formstack configuration data
     * @param $property
     * @return bool|string|array
     */
    public function getFormstackData($property)
    {
        if (isset($this->formstackData) && is_string($property) && array_key_exists($property, $this->formstackData))
        {
            return $this->formstackData[$property];
        }
        return false;
    }

    /**
     * Sets Formstack configuration data
     * @param $property
     * @param null $args
     * @return $this|bool
     */
    public function setFormstackData($property, $args = null)
    {
        if (! is_string($property)) return false;
        $this->formstackData[$property] = $args;
        return $this; // for chaining
    }

    /**
     * Gets Prowl configuration data
     * @param $property
     * @return bool|string|array
     */
    public function getProwlData($property)
    {
        if (isset($this->prowlData) && is_string($property) && array_key_exists($property, $this->prowlData))
        {
            return $this->prowlData[$property];
        }
        return false;
    }

    /**
     * Set Prowl configuration data
     * @param $property
     * @param null $args
     * @return $this|bool
     */
    public function setProwlData($property, $args = null)
    {
        if (! is_string($property)) return false;
        $this->prowlData[$property] = $args;
        return $this; // for chaining
    }


    /**
     * Validates REQUEST_METHOD used (POST), Formstack HandshakeKey, and minimum required fields
     * @return bool
     */
    private function securityCheck()
    {
        // already passed, don't check again
        if ($this->getFormstackData('SecurityCheckPassed')) {
            return true;
        }

        // only proceed if we get a POST with our Formstack HandshakeKey
        if (/*"POST" != $_SERVER['REQUEST_METHOD'] || */ !isset($_POST['HandshakeKey'])
            || $this->getFormstackData('HandshakeKey') != $_POST['HandshakeKey']) {
            echo "Server REQUEST_METHOD and/or HandshakeKey were not accepted.";
            return false;
        }

        // validate defined required fields
        foreach ($this->getFormstackData('RequiredFormFields') as $field) {
            if (!isset($_POST[$field]) || empty($_POST[$field])) {
                echo "Required field: {$field} was not found in this POST submission.";
                return false;
            }
        }
        return true; // passed security check
    }

    /**
     * Entry point by webhook listener file to perform security checks and then send the prowl notification
     * @return bool
     */
    public function processFormStackWebHook()
    {
        // exit if security check fails
        $this->setFormstackData('SecurityCheckPassed', $this->securityCheck());

        if (! $this->getFormstackData('SecurityCheckPassed')) {
            return false;
        }

        // send prowl message
        $this->sendProwlNotification();
    }

    /**
     * Builds Formstack form field id array and generates Prowl message with merge code support
     * @return bool
     */
    private function sendProwlNotification()
    {
        // figures out field labels by calling Formstack API and GET form
        // used to customize the prowl notification message
        $fieldMap = $this->getFieldMappingsFromFormstackAPI();

        // bootstrap to load ProwlPHP source files as needed
        require_once dirname(__FILE__) . '/ProwlPHP/bootstrap.php';

        $oProwl = new \Prowl\SecureConnector(); // I prefer to use SSL at all times with:  \Prowl\SecureConnector
        // You can use \Prowl\Connector to make curl NOT use SSL in above line if necessary

        $oMsg = new \Prowl\Message();

        try {
            // required: simple callback filter, needed to handle response
            $oProwl->setFilterCallback(function ($sText) {
                return $sText;
            });

            $oProwl->setIsPostRequest(true);
            $oMsg->setPriority($this->getProwlData('MessagePriority'));

            // You can ADD up to 5 api keys
            $oMsg->addApiKey($this->getProwlData('ApiKey'));
            $oMsg->setEvent($this->getProwlData('MessageEvent'));

            $application = $this->getProwlData('MessageApplication');
            if (isset($application) && !empty($application)) {
                $oMsg->setApplication($application);
            }

            // These are optional. Use if provided and not blank
            $description = $this->getProwlData('MessageDescription');
            if (isset($description) && !empty($description)) {

                // the message supports several merge codes for DATE, EMAIL, REQUEST, and FILELINK

                $fieldIds = $this->getFormstackData('FieldIds');

                // uses field mapping to find correct field id for Email and Request Details
                $description = str_replace('%DATE%', date('Y-m-d H:i:s a'), $description);
                $description = str_replace('%EMAIL%', $_POST[$fieldIds['Email']], $description);
                $description = str_replace('%REQUEST%', $_POST[$fieldIds['Request']], $description);

                // merge in FILELINK if a file parameter was found, otherwise use blank string (null)
                $fileLink = (isset($_POST[$fieldIds['File']])
                    && !empty($_POST[$fieldIds['File']]))
                    ? $_POST[$fieldIds['File']]
                    : null;
                $description = str_replace('%FILELINK%', $fileLink, $description);

                // sets the prowl message text
                $oMsg->setDescription($description);
            }

            // send the notification to Prowl API
            $oResponse = $oProwl->push($oMsg);

            // handle response
            if ($oResponse->isError()) { // error
                $this->setProwlData('ResponseCode', -1); // unsuccessful
                $this->setProwlData('ResponseMessage', $oResponse->getErrorAsString());
                echo $this->getProwlData('ResponseMessage');
            } else { // success
                $this->setProwlData('ResponseCode', 1); // successful
                $this->setProwlData('ResponseMessage', "Message sent." . PHP_EOL . "You have " . $oResponse->getRemaining() . " Messages left." . PHP_EOL . "Your counter will reset on " . date('Y-m-d H:i:s', $oResponse->getResetDate()) . PHP_EOL);
            }

            // response to user
            echo $this->getProwlData('ResponseMessage');
            return $this->getProwlData('ResponseCode');

        } catch (\InvalidArgumentException $argumentException) {
            echo $argumentException->getMessage();
        } catch (\OutOfRangeException $rangeException) {
            echo $rangeException->getMessage();
        }
    } // end function

    /**
     * @return array
     */
    private function getFieldMappingsFromFormstackAPI()
    {
         /*
            Array
            (
                [24339317] => your name
                [24339318] => email
                [24339319] => request details
                [24339320] =>
                [24339321] => phone
                [24339322] => upload a file (optional)
            )
         */

        //api method/request for my IT Support Requests form
        $formId = $this->getFormstackData('FormId');
        $token = $this->getFormstackDAta('ApiOauthToken');
        $action = "form/{$formId}.json?oauth_token={$token}";

        $url = $this->getFormstackData('ApiEndpoint') . $action;

        try {
            $curl = curl_init();
            curl_setopt($curl, CURLOPT_URL, $url);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            $apiResult = curl_exec($curl);
            curl_close($curl);

            $form  = json_decode($apiResult);
            $error = json_last_error();

            // if error or we don't get fields returned
            if ($error) {
            die("Received invalid json from Formstack API: $error <hr><pre>" . stripslashes($apiResult) . "</pre>");
            }

            // mapping table of field ids to field labels
            $fieldMap = array();
            foreach ($form->fields as $objField) {
               $fieldMap[ $objField->id ] = strtolower($objField->label);
            }
            return $fieldMap;
        } catch (\Exception $exception) {
            $exception->getMessage();
            $exception->getCode();
            die("The Formstack API Error: Code {exception->getCode()}: {$exception->getMessage()}");
        }
    }

} // end class
?>