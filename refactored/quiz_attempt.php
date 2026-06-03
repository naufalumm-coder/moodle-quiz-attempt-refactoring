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

namespace mod_quiz;

use action_link;
use block_contents;
use cm_info;
use coding_exception;
use context_module;
use core\di;
use core\hook;
use Exception;
use html_writer;
use mod_quiz\hook\attempt_state_changed;
use mod_quiz\output\grades\grade_out_of;
use mod_quiz\output\links_to_other_attempts;
use mod_quiz\output\renderer;
use mod_quiz\question\bank\qbank_helper;
use mod_quiz\question\display_options;
use moodle_exception;
use moodle_url;
use popup_action;
use qtype_description_question;
use question_attempt;
use question_bank;
use question_display_options;
use question_engine;
use question_out_of_sequence_exception;
use question_state;
use question_usage_by_activity;
use stdClass;

/**
 * This class represents one user's attempt at a particular quiz.
 *
 * @package   mod_quiz
 * @copyright 2008 Tim Hunt
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class quiz_attempt {
    use quiz_attempt_core_trait;
    use quiz_attempt_layout_trait;
    use quiz_attempt_getters_trait;
    use quiz_attempt_access_trait;
    use quiz_attempt_question_trait;
    use quiz_attempt_url_trait;
    use quiz_attempt_rendering_trait;
    use quiz_attempt_processing_trait;
    use quiz_attempt_event_trait;
    use quiz_attempt_question_update_trait;


    /** @var string to identify the in progress state. */
    const IN_PROGRESS = 'inprogress';
    /** @var string to identify the overdue state. */
    const OVERDUE     = 'overdue';
    /** @var string to identify the finished state. */
    const FINISHED    = 'finished';
    /** @var string to identify the abandoned state. */
    const ABANDONED   = 'abandoned';

    /** @var int maximum number of slots in the quiz for the review page to default to show all. */
    const MAX_SLOTS_FOR_DEFAULT_REVIEW_SHOW_ALL = 50;

    /** @var int amount of time considered 'immedately after the attempt', in seconds. */
    const IMMEDIATELY_AFTER_PERIOD = 2 * MINSECS;

    /** @var quiz_settings object containing the quiz settings. */
    protected $quizobj;

    /** @var stdClass the quiz_attempts row. */
    protected $attempt;

    /**
     * @var question_usage_by_activity|null the question usage for this quiz attempt.
     *
     * Only available after load_questions is called, e.g. if the class is constructed
     * with $loadquestions true (the default).
     */
    protected ?question_usage_by_activity $quba = null;

    /**
     * @var array of slot information. These objects contain ->id (int), ->slot (int),
     * ->requireprevious (bool), ->displaynumber (string) and quizgradeitemid (int) from the DB.
     * They do not contain page - get that from {@see get_question_page()} -
     * or maxmark - get that from $this->quba. It is augmented with
     * ->firstinsection (bool), ->section (stdClass from $this->sections).
     */
    protected $slots;

    /** @var array of quiz_sections rows, with a ->lastslot field added. */
    protected $sections;

    /** @var grade_calculator instance for this quiz. */
    protected grade_calculator $gradecalculator;

    /**
     * @var grade_out_of[]|null can be used to store the total grade for each section.
     *
     * This is typically done when one or more attempts are created without load_questions.
     * This lets the mark totals be passed in and later used. Format of this array should
     * match what {@see grade_calculator::compute_grade_item_totals()} would return.
     */
    protected ?array $gradeitemmarks = null;

    /** @var array page no => array of slot numbers on the page in order. */
    protected $pagelayout;

    /** @var array slot => displayed question number for this slot. (E.g. 1, 2, 3 or 'i'.) */
    protected $questionnumbers;

    /** @var array slot => page number for this slot. */
    protected $questionpages;

    /** @var display_options cache for the appropriate review options. */
    protected $reviewoptions = null;

    // Constructor =============================================================.
    /**
     * Constructor assuming we already have the necessary data loaded.
     *
     * @param stdClass $attempt the row of the quiz_attempts table.
     * @param stdClass $quiz the quiz object for this attempt and user.
     * @param cm_info $cm the course_module object for this quiz.
     * @param stdClass $course the row from the course table for the course we belong to.
     * @param bool $loadquestions (optional) if true, the default, load all the details
     * of the state of each question. Else just set up the basic details of the attempt.
     */
    public function __construct($attempt, $quiz, $cm, $course, $loadquestions = true) {
        $this->attempt = $attempt;
        $this->quizobj = new quiz_settings($quiz, $cm, $course);
        $this->gradecalculator = $this->quizobj->get_grade_calculator();

        if ($loadquestions) {
            $this->load_questions();
            $this->gradecalculator->set_slots($this->slots);
        }
    }

}
