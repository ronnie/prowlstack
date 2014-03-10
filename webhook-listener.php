<?php
/**
 * ProwlStack
 * @author Ronnie Moore
 * @version 1.0.0
 *
 * Receives webhook notifications from Formstack of newly-submitted
 * web forms and alerts user via Prowl iPhone notification system.
 */

require_once("class.ProwlStack.php");


/*  DEV NOTE:

    To aid in development and troubleshooting, once I submitted a form
    I saved the $_POST to a file.  Then, I just called unserialize() on
    the data and can emulate the webhook being POSTed from FormStack. :)

    // 1st
    file_put_contents("./post.data", serialize($_POST));

    // 2nd
    $_POST = unserialize(file_get_contents("./post.data"));
*/


// create ProwlStack object
$ProwlStack = new ProwlStack();

/* defaults in ProwlStack class */

// Submission must contain at least these required fields to be valid
// $ProwlStack->setFormstackData('RequiredFormFields', array('FormID', 'UniqueID', 'HandshakeKey') );
// $ProwlStack->setFormstackData('ApiEndpoint', "https://www.formstack.com/api/v2/");

// $ProwlStack->setProwlData('MessageApplication', "ProwlStack"); // 256 char limit
// $ProwlStack->setProwlData('MessageEvent', "Submission #{$_POST['UniqueID']}"); // 1024 char limit
// $ProwlStack->setProwlData('MessagePriority', 0); // range: [-2, 2]     -2 = Very Low. 0 = Normal.  2 = Emergency.


/* Custom configuration */

// Form Id with FromStack
$ProwlStack->setFormstackData('FormId', 1698076); // IT Support Requests Form

// mapping of shortcut field name to Formstack field ids values
// so we can use the field ids and not break if the labels change
$ProwlStack->setFormstackData('FieldIds', array(
        'Name'      => 24339317,
        'Email'     => 24339318,
        'Request'   => 24339319, // Request Details
        'Phone'     => 24339321,
        'File'      => 24339322 // upload a file (optional)
        )
    );

// FormStack handshake key to use to validate webhook submission
$ProwlStack->setFormstackData('HandshakeKey', "mirandatest");

// FormStack API Oauth Token
$ProwlStack->setFormstackData('ApiOauthToken', "d7647e6da442ac43c9adf2178069a9ab");

// Prowl API Key.  Must be set in your Prowl > API Keys page.  Just enter the API Key (not the API Email).
$ProwlStack->setProwlData('ApiKey', "d137e2fe83d02c8ff226f63e3f09550250eda2d5");

// Prowl Message (Description)
$description = "A new form submission was received from %EMAIL% at %DATE%:  %REQUEST%  %FILELINK%"; // 10000 char limit
$ProwlStack->setProwlData('MessageDescription', $description);

// Process FormStack WebHook, which was posted back to my server
// My PHP script processes submission, grabs fields from FormStack API
// and then sends notification via Prowl API
$ProwlStack->processFormStackWebHook();
?>