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
                'Azure Blob URL'
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

        // Validate parameters.
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

        // Get course.
        $course = $DB->get_record(
            'course',
            ['id' => $params['courseid']],
            '*',
            MUST_EXIST
        );

        // Course context.
        $context = context_course::instance($course->id);

        // Validate context.
        self::validate_context($context);

        // Check permission.
        require_capability(
            'moodle/course:manageactivities',
            $context
        );

        // Make sure user is logged in.
        require_login($course);

        // Get Page module.
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
         * Page module settings.
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
         * Create activity.
         *
         * Moodle 5.2 returns a stdClass.
         */
        $cm = add_moduleinfo(
            $data,
            $course
        );

        /*
         * Moodle 5.2:
         *
         * $cm->instance      = Page activity ID
         * $cm->coursemodule  = Course module ID
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