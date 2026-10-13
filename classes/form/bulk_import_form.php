<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace qbank_importasversion\form;

use moodleform;
use qformat_xml;
use stdClass;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');
require_once($CFG->dirroot . '/question/format.php');
require_once($CFG->dirroot . '/question/format/xml/format.php');

/**
 * Form to import many questions from one file, each as a new version of an existing question.
 *
 * The options are those of the core question import, plus the options of the single-question import
 * of this plugin.
 *
 * @package   qbank_importasversion
 * @copyright 2026 LT&C
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bulk_import_form extends moodleform {
    #[\Override]
    protected function definition() {
        $mform = $this->_form;
        $contexts = $this->_customdata['contexts'];

        $mform->addElement('header', 'general', get_string('general', 'form'));

        $mform->addElement('questioncategory', 'category', get_string('importcategory', 'question'), compact('contexts'));
        $mform->setDefault('category', $this->_customdata['defaultcategory']);
        $mform->addHelpButton('category', 'importcategory', 'question');

        $categorygroup = [];
        $categorygroup[] = $mform->createElement('checkbox', 'catfromfile', '', get_string('getcategoryfromfile', 'question'));
        $categorygroup[] = $mform->createElement('checkbox', 'contextfromfile', '', get_string('getcontextfromfile', 'question'));
        $mform->addGroup($categorygroup, 'categorygroup', '', '', false);
        $mform->disabledIf('categorygroup', 'catfromfile', 'notchecked');
        $mform->setDefault('catfromfile', 1);
        $mform->setDefault('contextfromfile', 1);

        $matchgrades = [
            'error' => get_string('matchgradeserror', 'question'),
            'nearest' => get_string('matchgradesnearest', 'question'),
        ];
        $mform->addElement('select', 'matchgrades', get_string('matchgrades', 'question'), $matchgrades);
        $mform->addHelpButton('matchgrades', 'matchgrades', 'question');
        $mform->setDefault('matchgrades', 'error');

        $mform->addElement('selectyesno', 'stoponerror', get_string('stoponerror', 'question'));
        $mform->setDefault('stoponerror', 1);
        $mform->addHelpButton('stoponerror', 'stoponerror', 'question');

        $mform->addElement('advcheckbox', 'mergetags', get_string('mergetags', 'qbank_importasversion'));
        $mform->setDefault('mergetags', 0);
        $mform->setType('mergetags', PARAM_BOOL);

        $mform->addElement('advcheckbox', 'force', get_string('forceimport', 'qbank_importasversion'));
        $mform->addHelpButton('force', 'forceimport', 'qbank_importasversion');
        $mform->setDefault('force', 0);
        $mform->setType('force', PARAM_BOOL);

        $mform->addElement('header', 'importfileupload', get_string('bulkimport', 'qbank_importasversion'));
        $mform->addElement('static', 'bulkimportintro', '', get_string('bulkimportintro', 'qbank_importasversion'));

        $mform->addElement('filepicker', 'newfile', get_string('import'), null, ['accepted_types' => '.xml']);
        $mform->addRule('newfile', null, 'required', null, 'client');

        $buttons = [];
        $buttons[] = $mform->createElement('submit', 'submitbutton', get_string('previewimport', 'qbank_importasversion'));
        $buttons[] = $mform->createElement('cancel');
        $mform->addGroup($buttons, 'buttonar', '', [' '], false);
        $mform->closeHeaderBefore('buttonar');
    }

    /**
     * Check that a file has been uploaded, and that it is a plausible Moodle XML file.
     *
     * @param array $data the submitted data.
     * @param array $errors the errors so far.
     * @return array the updated errors.
     */
    protected function validate_uploaded_file($data, $errors) {
        if (empty($data['newfile'])) {
            $errors['newfile'] = get_string('required');
            return $errors;
        }

        $files = $this->get_draft_files('newfile');
        if (!is_array($files) || count($files) < 1) {
            $errors['newfile'] = get_string('required');
            return $errors;
        }

        $qformat = new qformat_xml();
        $file = reset($files);
        if (!$qformat->can_import_file($file)) {
            $a = new stdClass();
            $a->actualtype = $file->get_mimetype();
            $a->expectedtype = $qformat->mime_type();
            $errors['newfile'] = get_string('importwrongfiletype', 'question', $a);
            return $errors;
        }

        $fileerrors = $qformat->validate_file($file);
        if ($fileerrors) {
            $errors['newfile'] = $fileerrors;
        }
        return $errors;
    }

    #[\Override]
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        return $this->validate_uploaded_file($data, $errors);
    }
}
