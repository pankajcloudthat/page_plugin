<?php

defined('MOODLE_INTERNAL') || die();

$functions = [

    'local_training_create_page' => [
        'classname'   => 'local_training_external',
        'methodname'  => 'create_page',
        'description' => 'Create a Moodle Page activity.',
        'type'        => 'write',
        'ajax'        => false,
        'capabilities'=> 'moodle/course:manageactivities',
    ],

];
