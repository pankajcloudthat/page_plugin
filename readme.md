# **REST API: Create Moodle Page activity**

Moodle's current external API model requires the function to be declared in `db/services.php`, with parameters/return values defined in the external function implementation. ([Moodle Developer Resources][1])

---

# 1. Final architecture

```text
Python / Azure Function / Any Application
                |
                | HTTP POST
                v
       Moodle REST Endpoint
                |
                v
   local_training plugin
                |
                v
 local_training_create_page()
                |
                v
       Moodle Page Activity
                |
                v
    Azure Blob SAS URL
                |
                v
          Video Recording
```

For example, the API creates:

```html
<p>
    <a class="external-media-provider"
       href="https://store.blob.core.windows.net/par/14-08-2026/Discussion.mp4?...SAS...">
        Discussion on Learner Feedback
    </a>
</p>
```

---

# 2. Moodle installation location

Your Moodle installation is:

```text
/srv/www/moodle
```

But your actual Moodle web root is:

```text
/srv/www/moodle/public
```

Therefore the plugin must be located here:

```text
/srv/www/moodle/public/local/training
```

Not here:

```text
/srv/www/moodle/local/training
```

This was an important issue we discovered during setup.

---

# 3. Create plugin directory

Run:

```bash
sudo mkdir -p /srv/www/moodle/public/local/training/db
sudo mkdir -p /srv/www/moodle/public/local/training/lang/en
```

Set ownership:

```bash
sudo chown -R www-data:www-data /srv/www/moodle/public/local/training
```

Verify:

```bash
ls -la /srv/www/moodle/public/local/training
```

Expected:

```text
db
lang
```

---

# 4. Create `version.php`

Create:

```bash
sudo nano /srv/www/moodle/public/local/training/version.php
```

Put:

```php
<?php

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'local_training';
$plugin->version   = 2026081400;
$plugin->requires  = 2024042200;
$plugin->maturity  = MATURITY_STABLE;
$plugin->release   = '1.0';
```

Check syntax:

```bash
php -l /srv/www/moodle/public/local/training/version.php
```

Expected:

```text
No syntax errors detected
```

---

# 5. Create language file

Create:

```bash
sudo nano /srv/www/moodle/public/local/training/lang/en/local_training.php
```

Add:

```php
<?php

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Training Integration';
```

---

# 6. Create `db/services.php`

This is the most important file for registering the REST function.

Create:

```bash
sudo nano /srv/www/moodle/public/local/training/db/services.php
```

Use:

```php
<?php

defined('MOODLE_INTERNAL') || die();

$functions = [

    'local_training_create_page' => [

        'classname' => 'local_training_external',

        'methodname' => 'create_page',

        'classpath' => 'local/training/externallib.php',

        'description' => 'Create a Moodle Page activity.',

        'type' => 'write',

        'capabilities' => 'moodle/course:manageactivities',

    ],

];
```

Moodle discovers external functions from `db/services.php` during installation/upgrade. ([Moodle Developer Resources][2])

---

# 7. Create `externallib.php`

Create:

```bash
sudo nano /srv/www/moodle/public/local/training/externallib.php
```

Use this version, which is the version we tested successfully with your Moodle 5.2 installation:

```php
<?php

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/course/modlib.php');

class local_training_external extends external_api {

    /**
     * Parameters.
     */
    public static function create_page_parameters() {

        return new external_function_parameters([

            'courseid' => new external_value(
                PARAM_INT,
                'Course ID'
            ),

            'name' => new external_value(
                PARAM_TEXT,
                'Page activity name'
            ),

            'bloburl' => new external_value(
                PARAM_RAW_TRIMMED,
                'Azure Blob SAS URL'
            ),

            'linktext' => new external_value(
                PARAM_TEXT,
                'Text displayed for the video link'
            ),

            'section' => new external_value(
                PARAM_INT,
                'Course section number',
                VALUE_DEFAULT,
                1
            ),

            'visible' => new external_value(
                PARAM_BOOL,
                'Whether activity is visible',
                VALUE_DEFAULT,
                true
            ),

        ]);
    }


    /**
     * Create Page activity.
     */
    public static function create_page(
        $courseid,
        $name,
        $bloburl,
        $linktext,
        $section = 1,
        $visible = true
    ) {

        global $DB;

        /*
         * Validate parameters.
         */
        $params = self::validate_parameters(
            self::create_page_parameters(),
            [
                'courseid' => $courseid,
                'name' => $name,
                'bloburl' => $bloburl,
                'linktext' => $linktext,
                'section' => $section,
                'visible' => $visible,
            ]
        );

        /*
         * Get course.
         */
        $course = $DB->get_record(
            'course',
            ['id' => $params['courseid']],
            '*',
            MUST_EXIST
        );

        /*
         * Course context.
         */
        $context = context_course::instance($course->id);

        self::validate_context($context);

        /*
         * Check permission.
         */
        require_capability(
            'moodle/course:manageactivities',
            $context
        );

        /*
         * Make sure user is logged in.
         */
        require_login($course);

        /*
         * Get Page module.
         */
        $module = $DB->get_record(
            'modules',
            ['name' => 'page'],
            '*',
            MUST_EXIST
        );

        /*
         * Build Page content.
         */
        $content =
            '<p>' .
            '<a class="external-media-provider" href="' .
            s($params['bloburl']) .
            '">' .
            s($params['linktext']) .
            '</a>' .
            '</p>';

        /*
         * Activity data.
         */
        $data = new stdClass();

        $data->modulename = 'page';
        $data->module = $module->id;
        $data->course = $course->id;

        $data->name = $params['name'];

        $data->intro = '';
        $data->introformat = FORMAT_HTML;

        $data->content = $content;
        $data->contentformat = FORMAT_HTML;

        /*
         * Page settings.
         */
        $data->display = 5;
        $data->printintro = 0;
        $data->printlastmodified = 0;

        /*
         * Visibility.
         */
        $data->visible = $params['visible'] ? 1 : 0;

        /*
         * Course section.
         */
        $data->section = $params['section'];

        /*
         * Create Page activity.
         *
         * Moodle 5.2 returns a stdClass.
         */
        $cm = add_moduleinfo(
            $data,
            $course
        );

        /*
         * Moodle 5.2 return values:
         *
         * instance     = Page activity ID
         * coursemodule = Course module ID
         */
        $pageid = $cm->instance;
        $cmid = $cm->coursemodule;

        /*
         * Page URL.
         */
        $pageurl = new moodle_url(
            '/mod/page/view.php',
            [
                'id' => $cmid
            ]
        );

        return [

            'success' => true,

            'courseid' => $course->id,

            'cmid' => $cmid,

            'pageid' => $pageid,

            'name' => $params['name'],

            'url' => $pageurl->out(false),

            'content' => $content,

        ];
    }


    /**
     * Return structure.
     */
    public static function create_page_returns() {

        return new external_single_structure([

            'success' => new external_value(
                PARAM_BOOL,
                'Success status'
            ),

            'courseid' => new external_value(
                PARAM_INT,
                'Course ID'
            ),

            'cmid' => new external_value(
                PARAM_INT,
                'Course module ID'
            ),

            'pageid' => new external_value(
                PARAM_INT,
                'Page activity ID'
            ),

            'name' => new external_value(
                PARAM_TEXT,
                'Page name'
            ),

            'url' => new external_value(
                PARAM_URL,
                'Page URL'
            ),

            'content' => new external_value(
                PARAM_RAW,
                'Page content'
            ),

        ]);
    }

}
```

Moodle's external API requires parameter validation and context/capability checks for external functions. ([Moodle Developer Resources][1])

---

# 8. Check PHP files

Run:

```bash
php -l /srv/www/moodle/public/local/training/version.php
```

Then:

```bash
php -l /srv/www/moodle/public/local/training/externallib.php
```

Then:

```bash
php -l /srv/www/moodle/public/local/training/db/services.php
```

All three should say:

```text
No syntax errors detected
```

---

# 9. Set ownership

Run:

```bash
sudo chown -R www-data:www-data /srv/www/moodle/public/local/training
```

Check:

```bash
ls -la /srv/www/moodle/public/local/training
```

---

# 10. Upgrade Moodle

This is what causes Moodle to discover the plugin and the REST function.

Run:

```bash
sudo -u www-data php /srv/www/moodle/admin/cli/upgrade.php
```

You should see something like:

```text
-->local_training
++ Success
```

This is the important confirmation that Moodle discovered the plugin.

Moodle's own documentation notes that increasing the plugin version triggers the upgrade/discovery process and makes the new web service available. ([Moodle Developer Resources][1])

---

# 11. Purge Moodle caches

Run:

```bash
sudo -u www-data php /srv/www/moodle/admin/cli/purge_caches.php
```

Expected:

```text
Purging caches:
...
```

---

# 12. Confirm plugin installation

You can check the database:

```bash
sudo -u www-data php -r '
define("CLI_SCRIPT", true);
require "/srv/www/moodle/config.php";

global $DB;

$r = $DB->get_record(
    "config_plugins",
    ["plugin" => "local_training"],
    "plugin,name,value"
);

var_dump($r);
'
```

You should get a record instead of:

```text
bool(false)
```

---

# 13. Enable Moodle Web Services

Go to:

**Site administration → Advanced features**

Enable:

```text
Web services
```

Save.

Then:

**Site administration → Server → Web services → Manage protocols**

Enable:

```text
REST protocol
```

---

# 14. Create external service

Go to:

**Site administration → Server → Web services → External services**

Create a service.

For example:

```text
Name:
Training Integration API
```

Short name:

```text
trainingintegration
```

Enable:

```text
Enabled = Yes
```

If you're manually managing authorized users:

```text
Restricted users = Yes
```

---

# 15. Add function to service

Open:

**Training Integration API → Functions**

Add:

```text
local_training_create_page
```

You should see:

| Function                     | Description                    | Required capability              |
| ---------------------------- | ------------------------------ | -------------------------------- |
| `local_training_create_page` | Create a Moodle Page activity. | `moodle/course:manageactivities` |

You already successfully reached this stage during our setup.

---

# 16. Create Moodle user for API

I recommend **not using the Moodle administrator account** for production integration.

Create a dedicated user, for example:

```text
trainingapi
```

Give this user only the required permissions.

The user must be able to manage activities in the target course.

---

# 17. Generate token

Go to:

**Site administration → Server → Web services → Manage tokens**

Create a token for:

```text
User:
trainingapi

Service:
Training Integration API
```

Copy the token.

### Important

Do not put the token into GitHub or source code.

For Python, use:

```python
import os

MOODLE_TOKEN = os.environ["MOODLE_TOKEN"]
```

---

# 18. Test with CURL

Use a simple Blob URL first:

```bash
curl -X POST "http://23.167.17.167/webser/rest/server.php" \
  -d "wstoken=YOUR_NEW_TOKEN" \
  -d "wsfunction=local_training_create_page" \
  -d "moodlewsrestformat=json" \
  -d "courseid=2" \
  -d "name=Discussion on Learner Feedback" \
  -d "bloburl=https://store.blob.core.windows.net/par/14-08-2026/Discussion.mp4" \
  -d "linktext=Discussion on Learner" \
  -d "section=1" \
  -d "visible=1"
```

Successful response:

```json
{
    "success": true,
    "courseid": 2,
    "cmid": 8,
    "pageid": 7,
    "name": "Discussion on Learner Feedback",
    "url": "http://23.167.17.167/webser/mod/page/view.php?id=8",
    "content": "<p><a class=\"external-media-provider\" href=\"https://store.blob.core.windows.net/par/14-08-2026/Discussion.mp4\">Discussion on Learner</a></p>"
}
```

We actually tested this successfully on your Moodle server.

---

# 20. Python implementation

Install requests:

```bash
pip install requests
```

Python:

```python
import os
import requests


MOODLE_URL = ""

MOODLE_TOKEN = os.environ["MOODLE_TOKEN"]

COURSE_ID = 2

BLOB_URL = ""


endpoint = f"{MOODLE_URL}/webservice/rest/server.php"


payload = {
    "wstoken": MOODLE_TOKEN,
    "wsfunction": "local_training_create_page",
    "moodlewsrestformat": "json",

    "courseid": COURSE_ID,

    "name": "Discussion on Learner Feedback",

    "bloburl": BLOB_URL,

    "linktext": "Discussion on Learner Feedback",

    "section": 1,

    "visible": 1
}


response = requests.post(
    endpoint,
    data=payload,
    timeout=30
)


response.raise_for_status()

result = response.json()


if "exception" in result:

    print("Moodle API Error:")
    print(result)

else:

    print("Page created successfully!")

    print("Course ID:", result["courseid"])
    print("Page ID:", result["pageid"])
    print("CM ID:", result["cmid"])
    print("Page URL:", result["url"])
```

`requests` will safely form-encode the SAS URL when it is supplied as a value in `data`.

---

# 21. Set the Moodle token on Linux

For your current VM:

```bash
export MOODLE_TOKEN="YOUR_NEW_TOKEN"
```

Check:

```bash
echo $MOODLE_TOKEN
```
---

# 23. Troubleshooting error

These are worth keeping as a reference.

### Error 1

```text
No upgrade needed
```

Check the plugin is actually under:

```text
/srv/www/moodle/public/local/training
```

not:

```text
/srv/www/moodle/local/training
```

---

### Error 2

```text
Call to undefined function add_moduleinfo()
```

Add:

```php
require_once($CFG->dirroot . '/course/modlib.php');
```

---

### Error 3

```text
Argument #1 ($position) must be of type int, null given
```

Don't use:

```text
section=0
```

For your implementation use:

```text
section=1
```

and:

```php
$data->section = $params['section'];
```

---

### Error 4

```text
Invalid database query parameter value
```

The `$data` structure was incomplete for Moodle's Page module.

The following fields were required for our Moodle 5.2 installation:

```php
$data->display = 5;
$data->printintro = 0;
$data->printlastmodified = 0;
```

---

### Error 5

```text
Undefined property: stdClass::$display
Undefined property: stdClass::$printintro
Undefined property: stdClass::$printlastmodified
```

Same solution:

```php
$data->display = 5;
$data->printintro = 0;
$data->printlastmodified = 0;
```

---

### Error 6

```text
Object of class stdClass could not be converted to string
```

`add_moduleinfo()` returned an object.

Use:

```php
$cm = add_moduleinfo($data, $course);

$pageid = $cm->instance;
$cmid = $cm->coursemodule;
```

rather than:

```php
$cmid = add_moduleinfo(...);
```

---

[Moodle 5.2 Web Services — Writing a new service](https://moodledev.io/docs/5.2/apis/subsystems/external/writing-a-service?utm_source=chatgpt.com)
[Moodle 5.2 Web Services — Service creation](https://moodledev.io/docs/5.2/apis/subsystems/external/advanced/custom-services?utm_source=chatgpt.com)

[1]: https://moodledev.io/docs/5.2/apis/subsystems/external/writing-a-service?utm_source=chatgpt.com "Writing a new service | Moodle Developer Resources"
[2]: https://moodledev.io/docs/5.2/apis/subsystems/external/advanced/custom-services?utm_source=chatgpt.com "Service creation | Moodle Developer Resources"
