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

namespace qbank_importasversion;

use context;
use core_php_time_limit;
use qformat_xml;
use question_bank;
use stdClass;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/question/format/xml/format.php');
require_once($CFG->libdir . '/questionlib.php');

/**
 * Import many questions from one Moodle XML file, each as a new version of an existing question.
 *
 * A question in the file is matched to an existing question by category and idnumber. Questions that
 * do not match are skipped, never created: this plugin only adds versions to existing questions.
 * Like the single-question import, no comparison is made with the current version.
 *
 * The same code path produces the dry run and the real import, so what the dry run reports is what
 * the import does.
 *
 * Usage: create an instance, then set up the format the way the core import does
 * (setCategory(), setContexts(), setCourse(), setCatfromfile(), setContextfromfile(), setMatchgrades(),
 * setStoponerror()), then call run().
 *
 * @package   qbank_importasversion
 * @copyright 2026 LT&C
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bulk_importer extends qformat_xml {
    /** @var string The question will be, or (dry run) would be, imported as a new version. */
    public const OUTCOME_NEWVERSION = 'newversion';

    /** @var string The question was imported as a new version. */
    public const OUTCOME_IMPORTED = 'imported';

    /** @var string The question is not imported; see the reason. */
    public const OUTCOME_SKIPPED = 'skipped';

    /** @var string Saving the question failed; nothing was saved for it. */
    public const OUTCOME_ERROR = 'error';

    /** @var string The question in the file has no idnumber, so it cannot be matched. */
    public const REASON_NOIDNUMBER = 'noidnumber';

    /** @var string The category path from the file does not exist. */
    public const REASON_CATEGORYNOTFOUND = 'categorynotfound';

    /** @var string More than one category matches the path from the file. */
    public const REASON_CATEGORYAMBIGUOUS = 'categoryambiguous';

    /** @var string No existing question has this idnumber in the category. */
    public const REASON_NOTFOUND = 'notfound';

    /** @var string More than one existing question has this idnumber in the category. */
    public const REASON_AMBIGUOUS = 'ambiguous';

    /** @var string The same category and idnumber occur more than once in the file. */
    public const REASON_DUPLICATEINFILE = 'duplicateinfile';

    /** @var string The existing question has another question type. */
    public const REASON_TYPEMISMATCH = 'typemismatch';

    /** @var string The user may not edit the existing question. */
    public const REASON_NOPERMISSION = 'nopermission';

    /** @var string The question has an answer grade that is not valid. */
    public const REASON_INVALIDGRADES = 'invalidgrades';

    /** @var string[] Parse errors, collected here instead of being printed. */
    protected $parseerrors = [];

    /** @var array[] Existing entries per category id, then per idnumber. */
    protected $entrycache = [];

    /**
     * Collect parse errors instead of printing them, so the caller decides how to show them.
     *
     * @param string $message the error message.
     * @param string $text the offending text, if any.
     * @param string $questionname the name of the question, if known.
     */
    #[\Override]
    protected function error($message, $text = '', $questionname = '') {
        $this->parseerrors[] = trim(($questionname !== '' ? $questionname . ': ' : '') . $message);
        $this->importerrors++;
    }

    /**
     * Read the file, decide for each question what happens to it and, if asked, import it.
     *
     * Nothing is saved when $execute is false, or when the result is blocked.
     *
     * @param string $file path of the Moodle XML file.
     * @param bool $execute false for a dry run, true to save the new versions.
     * @param bool $mergetags if true, merge the file's tags with the replaced version's tags.
     * @param bool $force if true, retain a version that has save warnings, as Draft.
     * @return stdClass with rows (one object per question: index, name, category, idnumber, qtype,
     *      outcome, reason, message, questionid), errors (parse errors) and blocked (true if
     *      "stop on error" applies and errors were found, so nothing may be imported).
     */
    public function run(string $file, bool $execute, bool $mergetags = false, bool $force = false): stdClass {
        if (!$this->category) {
            throw new \coding_exception('Call setCategory() before run().');
        }

        core_php_time_limit::raise();
        raise_memory_limit(MEMORY_EXTRA);

        $this->displayprogress = false;
        $this->parseerrors = [];
        $this->importerrors = 0;
        $this->entrycache = [];

        $result = new stdClass();
        $result->rows = [];
        $result->errors = [];
        $result->blocked = false;

        $lines = $this->readdata($file);
        if (!$lines) {
            $result->errors[] = get_string('cannotread', 'question');
            $result->blocked = true;
            return $result;
        }

        // readquestions() expects at least one question element.
        $hasquestions = stripos(implode('', $lines), '<question') !== false;
        $questions = $hasquestions ? $this->readquestions($lines) : false;
        unset($lines);
        $result->errors = $this->parseerrors;
        if (!$questions) {
            if (!$result->errors) {
                $result->errors[] = get_string('noquestionsinfile', 'question');
            }
            $result->blocked = true;
            return $result;
        }
        if ($this->stoponerror && $this->importerrors > 0) {
            $result->blocked = true;
            return $result;
        }

        $result->rows = $this->classify($questions, $result);
        unset($questions);
        if ($result->blocked || !$execute) {
            return $result;
        }

        foreach ($result->rows as $row) {
            if ($row->outcome === self::OUTCOME_NEWVERSION) {
                $this->save_row($row, $mergetags, $force);
            }
            unset($row->existing, $row->question);
        }
        return $result;
    }

    /**
     * Count the rows per outcome.
     *
     * @param stdClass[] $rows the rows from run().
     * @return int[] the number of rows, keyed by outcome.
     */
    public static function count_outcomes(array $rows): array {
        $counts = [
            self::OUTCOME_NEWVERSION => 0,
            self::OUTCOME_IMPORTED => 0,
            self::OUTCOME_SKIPPED => 0,
            self::OUTCOME_ERROR => 0,
        ];
        foreach ($rows as $row) {
            $counts[$row->outcome]++;
        }
        return $counts;
    }

    /**
     * Decide for each parsed question what happens to it. Does not save anything.
     *
     * @param stdClass[] $questions the parsed questions and category entries.
     * @param stdClass $result the result being built; blocked is set here when "stop on error" applies.
     * @return stdClass[] the rows.
     */
    protected function classify(array $questions, stdClass $result): array {
        $gradeoptions = question_bank::fraction_options_full();
        $rows = [];
        $gradeerrors = 0;
        $index = 0;

        // Questions before the first category in the file go to the category the user selected.
        $category = $this->category;
        $categorypath = $this->category ? $this->category->name : '';
        $categoryreason = null;

        foreach ($questions as $question) {
            if ($question->qtype === 'category') {
                if ($this->catfromfile) {
                    $categorypath = $question->category;
                    [$category, $categoryreason] = $this->find_category($categorypath);
                }
                continue;
            }

            $index++;
            $row = new stdClass();
            $row->index = $index;
            $row->name = $question->name ?? '';
            $row->category = $categorypath;
            $row->idnumber = isset($question->idnumber) ? (string) $question->idnumber : '';
            $row->qtype = $question->qtype;
            $row->outcome = self::OUTCOME_SKIPPED;
            $row->reason = null;
            $row->message = '';
            $row->questionid = null;
            $rows[] = $row;

            // Answer grades are checked like the core import does.
            if (!empty($question->fraction) && is_array($question->fraction)) {
                $fractions = $question->fraction;
                $invalid = false;
                foreach ($fractions as $key => $fraction) {
                    $newfraction = match_grade_options($gradeoptions, $fraction, $this->matchgrades);
                    if ($newfraction === false) {
                        $invalid = true;
                    } else {
                        $fractions[$key] = $newfraction;
                    }
                }
                if ($invalid) {
                    $gradeerrors++;
                    $row->reason = self::REASON_INVALIDGRADES;
                    continue;
                }
                $question->fraction = $fractions;
            }

            if ($category === null) {
                $row->reason = $categoryreason;
                continue;
            }
            if ($row->idnumber === '') {
                $row->reason = self::REASON_NOIDNUMBER;
                continue;
            }

            $matches = $this->get_entries($category->id)[$row->idnumber] ?? [];
            if (!$matches) {
                $row->reason = self::REASON_NOTFOUND;
                continue;
            }
            if (count($matches) > 1) {
                $row->reason = self::REASON_AMBIGUOUS;
                continue;
            }
            $existing = reset($matches);
            if ($existing->qtype !== $question->qtype) {
                $row->reason = self::REASON_TYPEMISMATCH;
                continue;
            }
            if (!question_has_capability_on($existing, 'edit')) {
                $row->reason = self::REASON_NOPERMISSION;
                continue;
            }

            $row->outcome = self::OUTCOME_NEWVERSION;
            $row->key = $category->id . '|' . $row->idnumber;
            $row->existing = $existing;
            $row->question = $question;
        }

        if ($this->stoponerror && $gradeerrors > 0) {
            $result->blocked = true;
        }

        // The same category and idnumber twice in the file: we cannot know which one is meant, so skip both.
        $seen = [];
        foreach ($rows as $row) {
            if ($row->outcome === self::OUTCOME_NEWVERSION) {
                $seen[$row->key] = ($seen[$row->key] ?? 0) + 1;
            }
        }
        foreach ($rows as $row) {
            if ($row->outcome === self::OUTCOME_NEWVERSION && $seen[$row->key] > 1) {
                $row->outcome = self::OUTCOME_SKIPPED;
                $row->reason = self::REASON_DUPLICATEINFILE;
                unset($row->existing, $row->question);
            }
            unset($row->key);
        }

        return $rows;
    }

    /**
     * Save one row as a new version, in its own transaction.
     *
     * @param stdClass $row the row to save.
     * @param bool $mergetags whether to merge tags.
     * @param bool $force whether to retain a version that has save warnings (as Draft).
     */
    protected function save_row(stdClass $row, bool $mergetags, bool $force): void {
        global $DB;

        core_php_time_limit::raise();

        try {
            $saved = importer::import_question_as_version(
                $this,
                $row->existing,
                $row->question,
                $mergetags,
                $force,
                true // Retained warnings are imported as Draft, as in the single-question form.
            );
        } catch (\Throwable $e) {
            // Show the user a plugin message, not the exception text, which could contain server paths.
            if ($DB->is_transaction_started()) {
                $DB->force_transaction_rollback();
            }
            debugging($e->getMessage(), DEBUG_DEVELOPER);
            $row->outcome = self::OUTCOME_ERROR;
            return;
        }

        if (!empty($saved->error)) {
            $row->outcome = self::OUTCOME_ERROR;
            $row->message = $saved->error;
            return;
        }

        $row->outcome = self::OUTCOME_IMPORTED;
        $row->questionid = end($this->questionids);
        if (!empty($saved->notice)) {
            $row->message = $saved->notice;
        }
    }

    /**
     * Find the category for a category path from the file, without creating it.
     *
     * Follows create_category_path() in the core question format, but only looks.
     *
     * @param string $catpath the category path from the file, like $course$/top/parent/child.
     * @return array [category record or null, reason code or null].
     */
    protected function find_category(string $catpath): array {
        global $DB;

        $catnames = $this->split_category_path($catpath);
        $contextid = false;
        if (preg_match('/^\$([a-z]+)\$$/', $catnames[0], $matches)) {
            $contextid = $this->translator->string_to_context($matches[1]);
            array_shift($catnames);
        }
        if (isset($catnames[0]) && (($catnames[0] != 'top') || (count($catnames) < 3))) {
            array_unshift($catnames, 'top');
        }

        if ($this->contextfromfile && $contextid !== false) {
            $context = context::instance_by_id($contextid, IGNORE_MISSING);
            if (!$context) {
                return [null, self::REASON_CATEGORYNOTFOUND];
            }
        } else {
            $context = context::instance_by_id($this->category->contextid);
        }

        $parent = 0;
        $category = null;
        foreach ($catnames as $catname) {
            if ($parent == 0) {
                $category = question_get_top_category($context->id, false);
                if (!$category) {
                    return [null, self::REASON_CATEGORYNOTFOUND];
                }
                $parent = $category->id;
                continue;
            }
            $found = $DB->get_records(
                'question_categories',
                ['name' => $catname, 'contextid' => $context->id, 'parent' => $parent],
                '',
                '*',
                0,
                2
            );
            if (!$found) {
                if ($catname == 'top') {
                    // Multiple nested top categories from an old export; core skips these too.
                    continue;
                }
                return [null, self::REASON_CATEGORYNOTFOUND];
            }
            if (count($found) > 1) {
                return [null, self::REASON_CATEGORYAMBIGUOUS];
            }
            $category = reset($found);
            $parent = $category->id;
        }

        // The top category itself holds no questions.
        if ($category === null || $category->parent == 0) {
            return [null, self::REASON_CATEGORYNOTFOUND];
        }
        return [$category, null];
    }

    /**
     * Get the latest version of each question with an idnumber in a category, keyed by idnumber.
     *
     * Each question has the properties the single-question importer needs: id (of the latest version),
     * category, contextid and questionbankentryid, plus qtype and createdby.
     *
     * @param int $categoryid the question category id.
     * @return array[] lists of question records, keyed by idnumber.
     */
    protected function get_entries(int $categoryid): array {
        global $DB;

        if (!isset($this->entrycache[$categoryid])) {
            $sql = "SELECT qbe.id AS questionbankentryid, qbe.idnumber, qbe.questioncategoryid AS category,
                           qc.contextid, qv.questionid AS id, q.qtype, q.createdby
                      FROM {question_bank_entries} qbe
                      JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
                      JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
                      JOIN {question} q ON q.id = qv.questionid
                     WHERE qbe.questioncategoryid = :categoryid
                           AND qbe.idnumber IS NOT NULL
                           AND q.parent = 0
                           AND qv.version = (SELECT MAX(qv2.version)
                                               FROM {question_versions} qv2
                                              WHERE qv2.questionbankentryid = qbe.id)";
            $entries = [];
            foreach ($DB->get_records_sql($sql, ['categoryid' => $categoryid]) as $entry) {
                $entries[(string) $entry->idnumber][] = $entry;
            }
            $this->entrycache[$categoryid] = $entries;
        }
        return $this->entrycache[$categoryid];
    }
}
